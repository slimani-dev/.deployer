<?php

declare(strict_types=1);

namespace Deployer;

set('domain', function () {
    return ask(' Domain: ', get('hostname'));
});

set('public_path', function () {
    return ask(' Public path: ', 'public');
});

desc('Shows access logs');
task('logs:access', function () {
    run('tail -f {{deploy_path}}/log/access.log');
})->verbose();

desc('Shows error logs');
task('logs:error', function () {
    run('tail -f {{deploy_path}}/log/error.log');
})->verbose();

desc('Shows nginx syslog');
task('logs:nginx', function () {
    run('sudo journalctl -xeu nginx.service');
})->verbose();

desc('Shows caddy syslog');
task('logs:caddy', function () {
    run('sudo journalctl -u caddy -f');
})->verbose();


desc('Tests Garage S3 configuration by uploading and downloading a file via Tinker');
task('deploy:test:s3', function () {
    $currentPath = get('deploy_path') . '/current';
    $localConfigPath = '.deployer/garage';

    if (!test("[ -d $currentPath ]")) {
        throw new \RuntimeException("The deployment path $currentPath does not exist. Please run a full deployment first.");
    }

    info('Uploading test file to Garage via Laravel S3 disk...');

    // 1. Read the local upload script and push it to a temp file on the server
    if (!file_exists("$localConfigPath/test_upload.php")) {
        throw new \RuntimeException("Missing local test script: $localConfigPath/test_upload.php");
    }
    $uploadCode = file_get_contents("$localConfigPath/test_upload.php");
    run("echo " . escapeshellarg($uploadCode) . " > /tmp/garage_test_upload.php");

    // Execute the script via Artisan Tinker using the filename
    $output = run("cd $currentPath && php artisan tinker /tmp/garage_test_upload.php");

    // Extract the URL and filename from the Tinker output
    preg_match('/GARAGE_TEST_URL:(.*?):GARAGE_TEST_FILE:(.*?)(?:\n|\r|$)/', $output, $matches);

    if (empty($matches[1]) || empty($matches[2])) {
        run("rm -f /tmp/garage_test_upload.php");
        throw new \Exception("Failed to upload file or generate URL. Tinker output:\n" . $output);
    }

    $url = trim($matches[1]);
    $fileName = trim($matches[2]);

    info("File uploaded successfully! Download URL generated.");
    writeln(" <comment>-> URL: $url</comment>");

    info("Testing download via curl...");

    // 2. Test downloading the presigned URL
    $downloadResult = run("curl -s " . escapeshellarg($url));

    if (trim($downloadResult) === 'Garage S3 is working perfectly!') {
        info("Download successful! Content matches exactly.");
    } else {
        run("rm -f /tmp/garage_test_upload.php");
        throw new \Exception("Download failed or content mismatch.\nExpected: 'Garage S3 is working perfectly!'\nGot: " . $downloadResult);
    }

    info("Cleaning up test file...");

    // 3. Read the cleanup script, inject the dynamic filename, and execute
    if (!file_exists("$localConfigPath/test_cleanup.php")) {
        run("rm -f /tmp/garage_test_upload.php");
        throw new \RuntimeException("Missing local test script: $localConfigPath/test_cleanup.php");
    }
    $cleanupCode = file_get_contents("$localConfigPath/test_cleanup.php");
    $cleanupCode = str_replace('{{fileName}}', $fileName, $cleanupCode);

    run("echo " . escapeshellarg($cleanupCode) . " > /tmp/garage_test_cleanup.php");
    run("cd $currentPath && php artisan tinker /tmp/garage_test_cleanup.php");

    // Remove the temporary Tinker scripts
    run("rm -f /tmp/garage_test_upload.php /tmp/garage_test_cleanup.php");

    info("Garage S3 test completed successfully! Everything is fully operational.");
});
