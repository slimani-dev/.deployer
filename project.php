<?php

declare(strict_types=1);

namespace Deployer;

task('deploy:livewire:storage', function () {
    set('remote_user', get('provision_user'));

    run('mkdir -p {{deploy_path}}/shared/storage/app/private/livewire-tmp');
    run('chown www-data:www-data -R {{deploy_path}}/shared/storage/app/');
})->oncePerNode();

desc('pnpm install and build');
task('deploy:build', function () {
    run('sudo chown -R {{remote_user}}:{{remote_user}} {{current_path}}/node_modules', nothrow: true);
    run('source /etc/profile.d/fnm.sh && cd {{current_path}} && CI=true pnpm install && pnpm run build && pnpm run build:ssr');
});

desc('Publish Livewire assets');
task('artisan:publish:livewire', artisan('vendor:publish --force --tag=livewire:assets'));

desc('Deploys your project');
task('deploy', [
    'deploy:prepare',
    'deploy:vendors',
    //'env:db',
    //'artisan:storage:link',
    //'artisan:config:cache',
    //'artisan:route:cache',
    //'artisan:view:cache',
    //'artisan:event:cache',
    //'artisan:migrate',
    'deploy:publish',
    'deploy:build',
    'artisan:publish:livewire',
    'deploy:livewire:storage',
    'deploy:supervisor',
    'artisan:optimize',
]);

desc('Syncs database credentials to .env');
task('env:db', function () {
    $envFile = '{{deploy_path}}/shared/.env';

    // Ensure the file exists before editing
    if (! test("[ -f $envFile ]")) {
        throw new \RuntimeException('The .env file was not found in {{deploy_path}}/shared/.env. Run deploy:shared first.');
    }

    $dbConfig = [
        'DB_DATABASE' => get('db_name'),
        'DB_USERNAME' => get('db_user'),
        'DB_PASSWORD' => get('db_password'),
    ];

    foreach ($dbConfig as $key => $value) {
        // Escape special characters for the sed command
        $val = addslashes($value);

        // 1. Check if key exists: if yes, replace line.
        // 2. If no: append to end of file.
        run("grep -q '^$key=' $envFile &&
             sed -i 's|^$key=.*|$key=$val|' $envFile ||
             echo '$key=$val' >> $envFile");
    }

    info('Database credentials updated in .env');
});

desc('Configures supervisor for the project');
task('deploy:supervisor', function () {
    set('remote_user', get('provision_user'));

    // 2. Diff and Update Configuration
    $workerConf = parse(file_get_contents(__DIR__ . '/supervisor/worker.conf'));
    $workerPath = '/etc/supervisor/conf.d/{{domain}}-worker.conf';

    run('mkdir -p /etc/supervisor/conf.d');

    if (test("[ -f $workerPath ]")) {
        run("echo \"$workerConf\" > /tmp/worker.conf.new");
        $diff = run("diff -U5 --color=always $workerPath /tmp/worker.conf.new", nothrow: true);

        if (empty($diff)) {
            run('rm /tmp/worker.conf.new');
        } else {
            info('Found worker Configuration changes');
            writeln("\n".$diff);
            $answer = askChoice(' Which worker config to save? ', ['old', 'new'], 1); // Changed default to 'new'
            if ($answer === 'old') {
                run('rm /tmp/worker.conf.new');
            } else {
                run("mv /tmp/worker.conf.new $workerPath");
            }
        }
    } else {
        run("echo \"$workerConf\" > /tmp/worker.conf");
        run("mv /tmp/worker.conf $workerPath");
    }

    // 3. Reload Supervisor
    run('supervisorctl reread');
    run('supervisorctl update');

    // Instead of restarting EVERYTHING using all, just restart this domain's group
    // This assumes your worker.conf has a [program:{{domain}}-worker] or [group:{{domain}}]
    run('supervisorctl restart {{domain}}-worker:*');

    // 4. Show Status
    run('supervisorctl status');
});

