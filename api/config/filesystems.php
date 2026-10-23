<?php

// NFR-07: tenant files go to the object store whenever a bucket is
// configured (AWS_BUCKET), with no other switch; MEDIA_DISK overrides it
// (`media`: the local disk, `s3`: the bucket).
$tenantFiles = (string) env('MEDIA_DISK', '') !== ''
    ? (string) env('MEDIA_DISK')
    : ((string) env('AWS_BUCKET', '') !== '' ? 's3' : 'media');

$objectStorage = [
    'driver' => 's3',
    'key' => env('AWS_ACCESS_KEY_ID'),
    'secret' => env('AWS_SECRET_ACCESS_KEY'),
    'region' => env('AWS_DEFAULT_REGION'),
    'bucket' => env('AWS_BUCKET'),
    // The cluster endpoint, e.g. https://eu-central-1.linodeobjects.com.
    'endpoint' => env('AWS_ENDPOINT'),
    // Linode serves both styles; virtual-hosted (false) is its default.
    // Path style (true) only for a bucket name with dots.
    'use_path_style_endpoint' => filter_var(env('AWS_USE_PATH_STYLE_ENDPOINT', false), FILTER_VALIDATE_BOOL),
    'visibility' => 'private',
    'throw' => true,
    'report' => false,
];

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
    | NFR-07, TEN-01: the disk new tenant files are written to
    | (App\Core\Files\TenantFiles::disk()): `s3` (Linode Object Storage)
    | as soon as AWS_BUCKET is set (staging and production), else `media`
    | (local, development and tests). MEDIA_DISK overrides either way.
    | app:preflight fails in production on anything but an object store it
    | can write, read and delete a probe object on.
    */
    'tenant_files' => $tenantFiles,

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
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        // NFR-07, TEN-01: Linode Object Storage (S3-compatible), one private
        // bucket per environment. Never public: no ACLs, no public URL, no
        // presigned links (App\Core\Files\TenantFiles); the API streams
        // every file after its own checks. `throw` so a failed write is an
        // error, never a silent success.
        's3' => $objectStorage,

        // MD-02 and every other tenant file, from before the object store:
        // the disk name older rows carry. Local (storage/app/media) in
        // development and tests; on the object store it is the same bucket as
        // `s3`, so those rows read the files that
        // `storage:migrate-to-object-storage` copied there under the same keys.
        'media' => $tenantFiles === 's3' ? $objectStorage : [
            'driver' => 'local',
            'root' => storage_path('app/media'),
            'visibility' => 'private',
            'serve' => false,
            'throw' => true,
            'report' => false,
        ],

        // The local media directory, always: the source of
        // `storage:migrate-to-object-storage`. Nothing else writes here.
        'media_local' => [
            'driver' => 'local',
            'root' => storage_path('app/media'),
            'visibility' => 'private',
            'serve' => false,
            'throw' => true,
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
