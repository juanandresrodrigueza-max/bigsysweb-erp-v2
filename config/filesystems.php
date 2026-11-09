<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        // Fase 78: copias de seguridad fuera del servidor. Mismas variables que el CRM (OFFSITE_S3_*): el bloque del .env se copia tal cual.
        // Backblaze B2: endpoint https://s3.<region>.backblazeb2.com; la región sale del endpoint si no se indica.
        'backblaze' => [
            'driver' => 's3',
            'key' => env('OFFSITE_S3_ACCESS_KEY_ID'),
            'secret' => env('OFFSITE_S3_SECRET_ACCESS_KEY'),
            'region' => (function () { $r = env('OFFSITE_S3_REGION'); if ($r && $r !== 'auto') return $r; return preg_match('~s3\.([a-z0-9-]+)\.backblazeb2\.com~', (string) env('OFFSITE_S3_ENDPOINT'), $m) ? $m[1] : 'us-east-1'; })(),
            'bucket' => env('OFFSITE_S3_BUCKET'),
            'endpoint' => env('OFFSITE_S3_ENDPOINT'),
            'use_path_style_endpoint' => filter_var(env('OFFSITE_S3_FORCE_PATH_STYLE', true), FILTER_VALIDATE_BOOLEAN),
            'throw' => true,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
