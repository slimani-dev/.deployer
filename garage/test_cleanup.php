<?php

use Illuminate\Support\Facades\Storage;

// The {{fileName}} placeholder will be replaced dynamically by Deployer
Storage::disk('s3')->delete('{{fileName}}');
