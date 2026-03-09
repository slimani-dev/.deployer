<?php

declare(strict_types=1);

namespace Deployer;

/**
 * Database Backup and Download Module
 * * This script handles:
 * 1. db:backup:remote -> Creates a snapshot in the server's shared/backups folder.
 * 2. db:download      -> Dumps the DB, zips it, downloads to local database/backups/, and cleans up.
 * 3. db:backup        -> A combined task to handle both for full safety.
 */

// Local backup path configured based on your project structure
set('local_backup_path', 'database/backups');

desc('Full database backup workflow: snapshot on server and download to local');
task('db:backup', [
    'db:backup:remote',
    'db:download',
]);

desc('Dumps the remote database, zips it, and downloads it to local database/backups/');
task('db:download', function () {
    // 1. Setup variables
    $dbName = get('db_name');
    $dbUser = get('db_user');
    $dbPass = get('db_password');

    $timestamp = date('Y-m-d_H-i-s');
    $remoteFileName = "db_backup_{$dbName}_{$timestamp}.sql.gz";
    $remotePath = "/tmp/{$remoteFileName}";

    // Create local backup directory if it doesn't exist
    $localBackupDir = get('local_backup_path');
    if (!is_dir($localBackupDir)) {
        mkdir($localBackupDir, 0755, true);
    }
    $localPath = "{$localBackupDir}/{$remoteFileName}";

    info("Starting database export for: <comment>{$dbName}</comment>");

    // 2. Run mysqldump and pipe to gzip on the server
    run("mysqldump -h 127.0.0.1 -u" . escapeshellarg($dbUser) . " -p" . escapeshellarg($dbPass) . " " . escapeshellarg($dbName) . " --no-tablespaces | gzip > {$remotePath}");

    info("Backup created on server at: <comment>{$remotePath}</comment>");

    // 3. Download the file to local machine
    info("Downloading to: <comment>{$localPath}</comment>...");
    download($remotePath, $localPath);

    // 4. Cleanup the remote temporary file
    info("Cleaning up temporary file on server...");
    run("rm {$remotePath}");

    info("Database backup successfully downloaded to: {$localPath}");
})->verbose();

desc('Creates a compressed database backup in the shared folder on the server');
task('db:backup:remote', function () {
    $dbName = get('db_name');
    $dbUser = get('db_user');
    $dbPass = get('db_password');
    $backupPath = "{{deploy_path}}/shared/backups";

    // Ensure the remote backup directory exists
    run("mkdir -p $backupPath");

    $timestamp = date('Y-m-d_H-i-s');
    $fileName = "backup_{$dbName}_{$timestamp}.sql.gz";

    info("Creating remote snapshot in shared/backups...");
    run("mysqldump -h 127.0.0.1 -u" . escapeshellarg($dbUser) . " -p" . escapeshellarg($dbPass) . " " . escapeshellarg($dbName) . " --no-tablespaces | gzip > $backupPath/$fileName");

    info("Remote backup saved to: <comment>$backupPath/$fileName</comment>");
});
