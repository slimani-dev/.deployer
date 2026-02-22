<?php

declare(strict_types=1);

namespace Deployer;

desc('Provision the server');
task('provision', [
    'provision:check',
    'provision:configure',
    'provision:update',
    'provision:upgrade',
    'provision:install',
    'provision:ssh',
    'provision:firewall',
    'provision:user',
    'provision:php',
    'provision:node',
    'provision:databases',
    'provision:composer',
    'provision:redis',
    'provision:server',
    'provision:puppeteer',
    'provision:garage',
    'provision:website',
    'provision:verify',
]);

desc('Adds repositories and update');
task('provision:update', function () {
    set('remote_user', get('provision_user'));

    // 1. Install the tool that provides 'apt-add-repository'
    run('apt-get update', ['env' => ['DEBIAN_FRONTEND' => 'noninteractive']]);
    run('apt-get install -y software-properties-common curl ca-certificates', ['env' => ['DEBIAN_FRONTEND' => 'noninteractive']]);
    // 2. PHP PPA
    run('apt-add-repository ppa:ondrej/php -y', ['env' => ['DEBIAN_FRONTEND' => 'noninteractive']]);

    // 4. Final Update
    run('apt-get update', ['env' => ['DEBIAN_FRONTEND' => 'noninteractive']]);
})
    ->oncePerNode()
    ->verbose();

desc('Installs packages');
task('provision:install', function () {
    set('remote_user', get('provision_user'));
    $packages = [
        'acl',
        'apt-transport-https',
        'build-essential',
        'nginx',
        'curl',
        'debian-archive-keyring',
        'debian-keyring',
        'fail2ban',
        'gcc',
        'git',
        'libmcrypt4',
        'libpcre3-dev',
        'libsqlite3-dev',
        'make',
        'ncdu',
        'nodejs',
        'pkg-config',
        'python-is-python3',
        'redis',
        'sendmail',
        'sqlite3',
        'ufw',
        'unzip',
        'uuid-runtime',
        'whois',
    ];
    run('apt-get install -y '.implode(' ', $packages), ['env' => ['DEBIAN_FRONTEND' => 'noninteractive'], 'timeout' => 900]);
})
    ->verbose()
    ->oncePerNode();

desc('Setups a deployer user');
task('provision:user', function () {
    set('remote_user', get('provision_user'));

    if (test('id deployer >/dev/null 2>&1')) {
        // TODO: Check what created deployer user configured correctly.
        // TODO: Update sudo_password of deployer user.
        // TODO: Copy ssh_copy_id to deployer ssh dir.
        info('deployer user already exist');
    } else {
        run('useradd deployer');
        run('mkdir -p /home/deployer/.ssh');
        run('mkdir -p /home/deployer/.deployer');
        run('adduser deployer sudo');

        run('chsh -s /bin/bash deployer');
        run('cp /root/.profile /home/deployer/.profile');
        run('cp /root/.bashrc /home/deployer/.bashrc');
        run('touch /home/deployer/.sudo_as_admin_successful');

        // Make color prompt.
        run("sed -i 's/#force_color_prompt=yes/force_color_prompt=yes/' /home/deployer/.bashrc");

        $password = run("mkpasswd -m sha-512 '%secret%'", ['secret' => get('sudo_password')]);
        run("usermod --password '%secret%' deployer", ['secret' => $password]);

        // Copy root public key to deployer user so user can login without password.
        run('cp /root/.ssh/authorized_keys /home/deployer/.ssh/authorized_keys');

        // Create ssh key if not already exists.
        run('ssh-keygen -f /home/deployer/.ssh/id_ed25519 -t ed25519 -N ""');
        run('chmod 700 /home/deployer/.ssh/id_ed25519');

        try {
            run('chown -R deployer:deployer /home/deployer');
            run('chmod -R 755 /home/deployer');
        } catch (\Throwable $e) {
            warning($e->getMessage());
        }

        run('usermod -a -G www-data deployer');
    }
})->oncePerNode();

desc('Installs Node.js and pnpm system-wide');
task('provision:node', function () {
    set('remote_user', get('provision_user'));

    // 1. Detect Architecture
    $arch = run('uname -m');
    if ($arch === 'arm' || str_contains($arch, 'armv7')) {
        $filename = 'fnm-arm32';
    } elseif (str_contains($arch, 'aarch') || str_contains($arch, 'armv8')) {
        $filename = 'fnm-arm64';
    } else {
        $filename = 'fnm-linux';
    }

    // 2. Install fnm to /usr/local/bin
    $url = "https://github.com/Schniz/fnm/releases/latest/download/$filename.zip";
    run("curl -sSL $url --output /tmp/fnm.zip");
    run('unzip -o /tmp/fnm.zip -d /tmp');
    run('mv /tmp/fnm /usr/local/bin/fnm');
    run('chmod +x /usr/local/bin/fnm');

    // 3. Setup Shared Directories (/opt)
    $fnmDir = '/opt/fnm';
    $corepackDir = '/opt/corepack';

    run("mkdir -p $fnmDir");
    run("mkdir -p $corepackDir");
    run("chmod 777 $fnmDir");
    run("chmod 777 $corepackDir");

    // 4. Install Node.js globally via fnm
    // We use version 22 as seen in your previous logs
    run("export FNM_DIR=$fnmDir && fnm install 22");
    run("export FNM_DIR=$fnmDir && fnm default 22");

    // 5. Create Global Profile Script
    // This makes node, fnm, and pnpm available to all users on login
    $script = "export FNM_DIR=\"$fnmDir\"\n".
        "export COREPACK_HOME=\"$corepackDir\"\n".
        "eval \"`fnm env --use-on-cd`\"\n".
        'export PATH="$FNM_DIR/aliases/default/bin:$PATH"';

    run('echo '.escapeshellarg($script).' > /etc/profile.d/fnm.sh');

    // 6. Setup Corepack and pnpm
    // - Enable shims
    // - Prepare/Download the binary to the global COREPACK_HOME
    // - Use --activate to bypass the "Do you want to download" prompt
    run("bash -l -c 'corepack enable pnpm'");
    run("bash -l -c 'corepack prepare pnpm@latest --activate'");

    // Ensure the deployer user can write to the global npm/pnpm/corepack caches
    run("chown -R root:www-data $fnmDir $corepackDir");
    run("chmod -R 775 $fnmDir $corepackDir");

    // Final verification
    $nodeV = run("bash -l -c 'node -v'");
    $pnpmV = run("bash -l -c 'pnpm -v'");

    writeln("<info>Global Node version: $nodeV</info>");
    writeln("<info>Global pnpm version: $pnpmV</info>");
    writeln("<comment>Corepack Home is set to: $corepackDir</comment>");
})->oncePerNode();

desc('Installs Composer');
task('provision:composer', function () {
    set('remote_user', get('provision_user'));

    run('curl -sS https://getcomposer.org/installer | php');
    run('mv composer.phar /usr/local/bin/composer');

    $composerV = run("bash -l -c 'composer -V'");
    writeln("<info>Global $composerV</info>");
})->oncePerNode();

desc('Installs and enables Redis');
task('provision:redis', function () {
    set('remote_user', get('provision_user'));

    // Install Redis and the PHP extension for your specific version
    run('apt-get install -y redis-server php{{php_version}}-redis', ['env' => ['DEBIAN_FRONTEND' => 'noninteractive']]);

    // Ensure it starts on boot and is running now
    run('systemctl enable redis-server');
    run('systemctl restart redis-server');

    writeln('<info>Redis has been enabled and started.</info>');
})->oncePerNode();

desc('Configures the server (Nginx, Permissions, Paths)');
task('provision:server', function () {
    set('remote_user', get('provision_user'));

    // 2. Ensure Nginx is the primary web server
    run('systemctl enable nginx');
    run('systemctl restart nginx');

    // 3. User & Group Permissions
    // Adds the deployer user to www-data so it can write to storage/cache folders
    run('usermod -a -G www-data {{remote_user}}');

    // 4. Directory Preparation
    run('mkdir -p {{deploy_path}}');
    run('chown -R {{remote_user}}:www-data {{deploy_path}}');
    run('chmod -R 775 {{deploy_path}}');

    writeln('<info>Server configured: Nginx is active and {{deploy_path}} is ready.</info>');
})->oncePerNode();

desc('Installs Puppeteer OS dependencies, Chrome, and configures CHROME_PATH');
task('provision:puppeteer', function () {
    set('remote_user', get('provision_user'));

    // 1. Install Chrome/Puppeteer system dependencies
    $packages = [
        'ca-certificates', 'fonts-liberation', 'libasound2t64', 'libatk-bridge2.0-0', 'libatk1.0-0',
        'libc6', 'libcairo2', 'libcups2', 'libdbus-1-3', 'libexpat1', 'libfontconfig1', 'libgbm1',
        'libgcc-s1', 'libglib2.0-0t64', 'libgtk-3-0', 'libnspr4', 'libnss3', 'libpango-1.0-0',
        'libpangocairo-1.0-0', 'libstdc++6', 'libx11-6', 'libx11-xcb1', 'libxcb1', 'libxcomposite1',
        'libxcursor1', 'libxdamage1', 'libxext6', 'libxfixes3', 'libxi6', 'libxrandr2', 'libxrender1',
        'libxss1', 'libxtst6', 'lsb-release', 'wget', 'xdg-utils',
    ];

    info('Installing Puppeteer OS dependencies...');
    run('apt-get update', ['env' => ['DEBIAN_FRONTEND' => 'noninteractive']]);
    run('apt-get install -y '.implode(' ', $packages), ['env' => ['DEBIAN_FRONTEND' => 'noninteractive'], 'timeout' => 900]);

    // 2. Prepare Cache Directory
    info('Setting up Chrome cache paths...');
    run('mkdir -p /var/www/.cache/puppeteer');

    // 3. Pre-install Chrome using pnpm dlx (Puppeteer Browsers)
    info('Downloading Chrome 140.0.7339.82 using pnpm...');
    $npxCmd = 'export PUPPETEER_CACHE_DIR=/var/www/.cache/puppeteer; pnpm dlx @puppeteer/browsers install chrome@140.0.7339.82';
    run("cd /var/www/.cache/puppeteer && bash -l -c '$npxCmd'");

    // 4. Ensure permissions for www-data (and deployer)
    info('Setting permissions so www-data can execute Chrome...');
    run('chown -R www-data:www-data /var/www/.cache');
    run('chmod -R 775 /var/www/.cache');

    // 4. Inject Environment Variable
    $envFile = '{{deploy_path}}/shared/.env';
    $chromePath = '/var/www/.cache/puppeteer/chrome/linux-140.0.7339.82/chrome-linux64/chrome';

    if (test("[ -f $envFile ]")) {
        run("grep -q '^CHROME_PATH=' $envFile && ".
            "sed -i 's|^CHROME_PATH=.*|CHROME_PATH=$chromePath|' $envFile || ".
            "echo 'CHROME_PATH=$chromePath' >> $envFile");
        info('Injected CHROME_PATH into .env');
    } else {
        warning("Could not find $envFile to inject CHROME_PATH. Run deploy:shared first or add it manually.");
    }
})->oncePerNode();

desc('Provision website');
task('provision:website', function () {
    // 1. Prepare Paths and User
    $provisionUser = get('provision_user');
    $deployerUser = 'deployer'; // Explicitly target your deployer user
    $deployPath = get('deploy_path');

    set('remote_user', $provisionUser);

    // Ensure directory exists
    run("[ -d $deployPath ] || mkdir -p $deployPath");

    // Use a local variable for the absolute path
    $absolutePath = run("realpath $deployPath");

    // 2. Setup Logs
    run("[ -d $absolutePath/log ] || mkdir -p $absolutePath/log");
    run("chgrp www-data $absolutePath/log");
    run("chmod g+s $absolutePath/log"); // New files in log inherit www-data group

    // 3. Nginx Configuration Logic (Your diffing logic is great, kept as is)
    $nginxConf = parse(file_get_contents(__DIR__ . '/nginx.conf'));
    $nginxPath = '/etc/nginx/sites-available/{{domain}}.conf';
    $nginxEnablePath = '/etc/nginx/sites-enabled/{{domain}}.conf';

    if (test("[ -f $nginxPath ]")) {
        run("echo \"$nginxConf\" > /tmp/nginx.conf.new");
        $diff = run("diff -U5 --color=always $nginxPath /tmp/nginx.conf.new", no_throw: true);

        if (empty($diff)) {
            run('rm /tmp/nginx.conf.new');
        } else {
            info('Found Nginx Configuration changes');
            writeln("\n".$diff);
            $answer = askChoice(' Which nginx config to save? ', ['old', 'new'], 1); // Default to 'new' (index 1)
            if ($answer === 'old') {
                run('rm /tmp/nginx.conf.new');
            } else {
                run("mv /tmp/nginx.conf.new $nginxPath");
            }
        }
    } else {
        run("echo \"$nginxConf\" > /tmp/nginx.conf");
        run("mv /tmp/nginx.conf $nginxPath");
    }

    // Enable site and restart
    run("ln -sf $nginxPath $nginxEnablePath");
    run('nginx -t && service nginx restart'); // Always test config before restarting!

    // 4. Final Permission Handover
    info("Setting ownership to $deployerUser");
    run("chown -R $deployerUser:www-data $absolutePath");
    run("chmod -R 775 $absolutePath");

    info('Website {{domain}} configured!');
})->limit(1);

desc('Verifies provision and retrieves deployer SSH key');
task('provision:verify', function () {
    // 1. Verify Web Accessibility
    // We expect a 404 because the 'current' symlink hasn't been created yet by a real deploy
    try {
        fetch('http://{{domain}}', 'get', [], null, $info, true);
        if ($info['http_code'] === 404 || $info['http_code'] === 403) {
            info('Web server is responding correctly ({{domain}})');
        }
    } catch (\Throwable $e) {
        warning('Web server not reachable yet: '.$e->getMessage());
    }

    // 2. Handle SSH Key for Git
    set('remote_user', 'deployer');
    $sshFolder = '/home/deployer/.ssh';
    $keyPath = "$sshFolder/id_ed25519";
    $pubKeyPath = "$keyPath.pub";

    // Ensure .ssh directory exists
    run("mkdir -p $sshFolder");
    run("chmod 700 $sshFolder");

    // Only generate if the private key does NOT exist
    if (! test("[ -f $keyPath ]")) {
        info('Generating new SSH key for deployer user...');
        run("ssh-keygen -t ed25519 -N '' -f $keyPath");
    } else {
        info('Existing SSH key found.');
    }

    // 3. Output the Public Key
    $publicKey = run("cat $pubKeyPath");

    writeln('');
    writeln('<comment>================= GIT DEPLOY KEY =================</comment>');
    writeln('<info>Copy the following key and add it to your Git repository (GitHub/GitLab) Deploy Keys:</info>');
    writeln('');
    writeln($publicKey);
    writeln('');
    writeln('<comment>==================================================</comment>');

    info('Provisioning complete! You can now run: dep deploy');
});

desc('Configures supervisor');
task('provision:supervisor', function () {
    set('remote_user', get('provision_user'));

    // 1. Install & Enable
    run('apt-get install -y supervisor', ['env' => ['DEBIAN_FRONTEND' => 'noninteractive']]);
    run('systemctl enable supervisor');
    run('systemctl start supervisor');
})->oncePerNode();

desc('Installs and configures Garage S3 (Root-only with Arch Detection)');
task('provision:garage', function () {
    set('remote_user', 'root');
    $localConfigPath = '.deployer/garage';

    // 1. Detect Architecture
    $arch = run('uname -m');
    if ($arch === 'x86_64') {
        $target = 'x86_64-unknown-linux-musl';
    } elseif ($arch === 'aarch64' || $arch === 'arm64') {
        $target = 'aarch64-unknown-linux-musl';
    } elseif (str_contains($arch, 'arm')) {
        $target = 'armv6l-unknown-linux-musleabihf';
    } elseif ($arch === 'i686' || $arch === 'i386') {
        $target = 'i686-unknown-linux-musl';
    } else {
        throw new \Exception("Unsupported architecture: $arch");
    }

    // 2. Install Garage Binary
    $version = get('garage_version');
    $url = "https://garagehq.deuxfleurs.fr/_releases/$version/$target/garage";

    run("wget -qO /tmp/garage $url");
    run('mv /tmp/garage /usr/local/bin/garage');
    run('chmod +x /usr/local/bin/garage');

    // 3. Prepare Storage Folders (Avoid /tmp/)
    run('mkdir -p /var/lib/garage/meta /var/lib/garage/data');

    // 4. Manage Garage Secrets (Persistent local storage)
    // We generate these once and load them into Deployer's memory so {{placeholders}} work
    $secretsFile = getcwd() . '/' . $localConfigPath . '/secrets.json';
    if (file_exists($secretsFile)) {
        $secrets = json_decode(file_get_contents($secretsFile), true);
        foreach ($secrets as $key => $value) {
            set($key, $value);
        }
    } else {
        $secrets = [
            'garage_rpc_secret' => bin2hex(random_bytes(32)),
            'garage_admin_token' => base64_encode(random_bytes(32)),
            'garage_metrics_token' => base64_encode(random_bytes(32)),
        ];
        file_put_contents($secretsFile, json_encode($secrets, JSON_PRETTY_PRINT));
        foreach ($secrets as $key => $value) {
            set($key, $value);
        }
        info("Auto-generated new Garage secrets and saved to $localConfigPath/secrets.json. Please commit this file to Git!");
    }

    // 5. Smart Sync Helper Function
    $sync = function ($local, $remote) {
        $content = parse(file_get_contents($local));
        $tmp = "/tmp/" . basename($remote) . ".new";
        run("echo " . escapeshellarg($content) . " > $tmp");

        if (test("[ -f $remote ]")) {
            $diff = run("diff -U5 --color=always $remote $tmp", no_throw: true);
            if (!empty($diff)) {
                info("Changes detected in " . basename($remote));
                writeln("\n" . $diff);
                if (askChoice(" Update " . basename($remote) . "? ", ['old', 'new'], 1) === 'new') {
                    run("mv $tmp $remote");
                    return true;
                }
            }
            run("rm $tmp");
        } else {
            run("mv $tmp $remote");
            return true;
        }
        return false;
    };

    // 6. Update Configurations
    $configChanged = $sync("$localConfigPath/garage.toml", "/etc/garage.toml");
    $serviceChanged = $sync("$localConfigPath/garage.service", "/etc/systemd/system/garage.service");

    if ($configChanged || $serviceChanged) {
        run('systemctl daemon-reload');
        run('systemctl enable garage');
        run('systemctl restart garage');
    }

    // 7. Nginx Proxy Configuration
    $nginxPath = "/etc/nginx/sites-available/{{garage_domain}}.conf";
    $nginxEnablePath = "/etc/nginx/sites-enabled/{{garage_domain}}.conf";

    if ($sync("$localConfigPath/nginx.conf", $nginxPath)) {
        run("ln -sf $nginxPath $nginxEnablePath");
        run("nginx -t && service nginx reload");
    }

    // 8. Local DNS routing for Laravel to talk to Garage directly
    $garageDomain = get('garage_domain');
    run("grep -q '$garageDomain' /etc/hosts || echo '127.0.0.1 $garageDomain' >> /etc/hosts");

    info("Garage S3 $version provisioned at {{garage_domain}}");
})->oncePerNode();
