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
            'root' => storage_path('app'),
            'throw' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID', 'DO00DAVX2NRPZKBPTJ47'),
            'secret' => env('AWS_SECRET_ACCESS_KEY', 'ICr+whVW6uE42mCUsW1YgzwYJnj0pmq6qbF8SoxL0vg'),
            'region' => env('AWS_DEFAULT_REGION', 'sgp1'),
            'bucket' => env('AWS_BUCKET', 'design-dev'),
            'url' => env('AWS_URL', 'https://sgp1.digitaloceanspaces.com'),
            'endpoint' => env('AWS_ENDPOINT', 'https://sgp1.digitaloceanspaces.com'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
        ],

        'do' => [
            'driver' => 's3',
            'key' => env('DO_ACCESS_KEY_ID'),
            'secret' => env('DO_SECRET_ACCESS_KEY'),
            'region' => env('DO_DEFAULT_REGION'),
            'bucket' => env('DO_BUCKET'),
            'folder' => env('DO_FOLDER'),
            'cdn_endpoint' => env('DO_CDN_ENDPOINT'),
            'cdn_design_endpoint' => env('DO_CDN_DESIGN_ENDPOINT', ''),
            'url' => env('DO_CDN_ENDPOINT'),
            'endpoint' => env('DO_ENDPOINT'),
            'use_path_style_endpoint' => env('DO_USE_PATH_STYLE_ENDPOINT', false),
            'visibility' => 'public',
        ],

        'google' => [
            'driver' => 'google',
            'clientId' => env('GOOGLE_DRIVE_CLIENT_ID'),
            'clientSecret' => env('GOOGLE_DRIVE_CLIENT_SECRET'),
            'refreshToken' => env('GOOGLE_DRIVE_REFRESH_TOKEN'),
            'folderId' => env('GOOGLE_DRIVE_FOLDER_ID'),
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
