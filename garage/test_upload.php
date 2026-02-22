<?php

use Illuminate\Support\Facades\Storage;

$disk = Storage::disk('s3');
$fileName = 'garage-test-' . uniqid() . '.txt';

// Upload a test file
$disk->put($fileName, 'Garage S3 is working perfectly!');

// Generate a 5-minute presigned URL
$url = $disk->temporaryUrl($fileName, now()->addMinutes(5));

// Output the credentials so Deployer can parse them
echo "GARAGE_TEST_URL:" . $url . ":GARAGE_TEST_FILE:" . $fileName . "\n";
