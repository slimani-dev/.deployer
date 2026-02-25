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
    become(('{{remote_user}}'));
    run('source /etc/profile.d/fnm.sh && cd {{current_path}} && pnpm install && pnpm run build');
});

desc('Publish Livewire assets');
task('artisan:publish:livewire', artisan('vendor:publish --force --tag=livewire:assets'));

desc('Deploys your project');
task('deploy', [
    'deploy:prepare',
    'deploy:vendors',
    'env:db',
    'artisan:storage:link',
    'artisan:config:cache',
    'artisan:route:cache',
    'artisan:view:cache',
    'artisan:event:cache',
    'artisan:migrate',
    'deploy:publish',
    'artisan:publish:livewire',
    'deploy:build',
    'deploy:livewire:storage',
    'deploy:supervisor',
    //'deploy:garage',
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
        $diff = run("diff -U5 --color=always $workerPath /tmp/worker.conf.new", no_throw: true);

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

desc('Initializes Garage layout, bucket, limited user keys, and updates .env');
task('deploy:garage', function () {
    set('remote_user', 'root');

    // 1. Initialize Cluster (Assign disk space and apply)
    $nodeId = trim(run("garage node id | head -n 1 | cut -d'@' -f1"));
    run("garage layout assign -z default -c 1000G $nodeId || true");
    run("garage layout apply --version 1 || true");

    // 2. Create the Bucket
    run("garage bucket create {{garage_bucket}} || true");

    // 3. Automated Credential Management
    $keyName = get('garage_user');
    $credFile = "/var/lib/garage/.{$keyName}_credentials.json";

    if (!test("[ -f $credFile ]")) {
        // If the key exists but we don't have the saved credentials file, delete it so we can recreate and capture the secret.
        if (test("garage key list | grep -q '$keyName'")) {
            run("garage key delete '$keyName' --yes || garage key rm '$keyName' || true");
        }

        // Create the key and capture the output
        $keyOutput = run("garage key create '$keyName'");

        // Parse the GK... Access Key ID and 64-character Secret from the output
        preg_match('/(GK[a-fA-F0-9]+)/', $keyOutput, $idMatches);
        preg_match('/\b([a-fA-F0-9]{64})\b/', $keyOutput, $secretMatches);

        if (!empty($idMatches[1]) && !empty($secretMatches[1])) {
            $accessKey = $idMatches[1];
            $secretKey = $secretMatches[1];

            // Securely save them to a hidden JSON file on the server for future deployments
            $json = json_encode(['AWS_ACCESS_KEY_ID' => $accessKey, 'AWS_SECRET_ACCESS_KEY' => $secretKey]);
            run("echo " . escapeshellarg($json) . " > $credFile");
            run("chmod 600 $credFile");
            writeln("<info>Automated AWS Credentials generated and securely stored.</info>");
        } else {
            throw new \Exception("Could not parse keys from Garage output:\n$keyOutput");
        }
    }

    // 4. Read credentials back from the secure file
    $creds = json_decode(run("cat $credFile"), true);
    $accessKey = $creds['AWS_ACCESS_KEY_ID'];
    $secretKey = $creds['AWS_SECRET_ACCESS_KEY'];

    // 5. Grant Restricted Permissions (Must use the ID or the Key Name)
    run("garage bucket allow {{garage_bucket}} --key '$keyName' --read --write");

    info("Storage isolation complete for {{garage_bucket}}");

    // 6. Sync Generated AWS / Garage S3 credentials to .env
    $envFile = "{{deploy_path}}/shared/.env";

    if (!test("[ -f $envFile ]")) {
        throw new \RuntimeException("The .env file was not found in {{deploy_path}}/shared/.env. Run deploy:shared first.");
    }

    $config = [
        'AWS_ACCESS_KEY_ID' => $accessKey,
        'AWS_SECRET_ACCESS_KEY' => $secretKey,
        'AWS_DEFAULT_REGION' => 'garage',
        'AWS_BUCKET' => get('garage_bucket'),
        'AWS_USE_PATH_STYLE_ENDPOINT' => 'true',
        'AWS_URL' => 'http://' . get('garage_domain') . '/' . get('garage_bucket'),
        'AWS_ENDPOINT' => 'http://' . get('garage_domain'),
    ];

    foreach ($config as $key => $value) {
        // Escape special characters for the sed command
        $val = addslashes((string)$value);

        // 1. Check if key exists: if yes, replace line.
        // 2. If no: append to end of file.
        run("grep -q '^$key=' $envFile &&
             sed -i 's|^$key=.*|$key=$val|' $envFile ||
             echo '$key=$val' >> $envFile");
    }

    info("Generated AWS / Garage S3 credentials auto-injected into .env");
});

