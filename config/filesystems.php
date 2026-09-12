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

        'static' => [
            'driver' => 'ftp',
            'host' => env('STATIC_HOST', env('STATIC_FTP_HOST')),
            'username' => env('STATIC_USERNAME', env('STATIC_FTP_USERNAME')),
            'password' => env('STATIC_PASSWORD', env('STATIC_FTP_PASSWORD')),
            'url' => env('STATIC_URL', 'https://static.zanburak.ir'),
            'root' => env('STATIC_ROOT', env('STATIC_FTP_ROOT', '/public_html')),
            'passive' => true,
            'timeout' => (int) env('STATIC_FTP_TIMEOUT', 600),
            'throw' => true,
        ],

        /*
         * Shared with backend (same MEDIA_ROOT). Avoids static.zanburak.ir 100MB quota.
         */
        'media' => [
            'driver' => 'local',
            'root' => env('MEDIA_ROOT', 'D:/zanburak-media'),
            'throw' => true,
        ],

        'dl' => [
            'driver' => 'ftp',
            'host' => env('DL_HOST', env('DL_FTP_HOST')),
            'username' => env('DL_USERNAME', env('DL_FTP_USERNAME')),
            'password' => env('DL_PASSWORD', env('DL_FTP_PASSWORD')),
            'url' => env('DL_URL', 'https://dl.zanburak.ir'),
            'root' => env('DL_ROOT', env('DL_FTP_ROOT', '/public_html')),
            'passive' => true,
            'timeout' => (int) env('DL_FTP_TIMEOUT', 600),
            'throw' => true,
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
