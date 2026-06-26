<?php

return [

    // =========================================================================
    // Storage
    // =========================================================================

    'storage' => [
        'disk'           => env('FILE_UPLOAD_DISK', 'public'),
        'path'           => env('FILE_UPLOAD_PATH', 'uploads'),
        'default_folder' => 'default',
        'cdn'            => [
            'enabled' => env('FILE_UPLOAD_CDN_ENABLED', false),
            'url'     => env('FILE_UPLOAD_CDN_URL', ''),
        ],
    ],

    // =========================================================================
    // Validation rules (Laravel validation syntax)
    // =========================================================================

    'validation' => [
        'image'    => 'required|image|mimes:jpeg,png,jpg,gif,webp,svg|max:2048',
        'video'    => 'required|mimes:mp4,mov,avi,mkv,webm|max:10240',
        'audio'    => 'required|mimes:mp3,wav,ogg|max:5120',
        'document' => 'required|mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,txt|max:5120',
        'other'    => 'required|max:5120',
        'custom_fields' => [
            'file'    => 'required|file|mimes:jpeg,png,jpg,gif,webp,svg,pdf,doc,docx,mp4,mov,avi,mkv,webm,mp3,wav,ogg|max:10240',
            'files'   => 'required|array',
            'files.*' => 'required|file|mimes:jpeg,png,jpg,gif,webp,svg,pdf,doc,docx,mp4,mov,avi,mkv,webm,mp3,wav,ogg|max:10240',
        ],
    ],

    // =========================================================================
    // URL Upload / Download
    // =========================================================================

    'url_download' => [
        'enabled'       => true,
        'chunked'       => true,         // true = load into memory; false = stream to disk
        'timeout'       => 300,          // seconds (legacy key — overridden by url_upload.timeout_seconds)
        'max_size'      => 524288000,    // 500 MB (legacy key — overridden by url_upload.max_size_bytes)
        'chunk_size'    => 5242880,      // 5 MB streaming chunk
        'allowed_mimes' => [
            'image'       => ['jpeg', 'png', 'jpg', 'gif', 'webp', 'svg'],
            'video'       => ['mp4', 'mov', 'avi', 'mkv', 'webm'],
            'audio'       => ['mp3', 'wav', 'ogg'],
            'application' => [
                'pdf', 'msword', 'zip', 'x-zip-compressed',
                'vnd.openxmlformats-officedocument.wordprocessingml.document',
                'vnd.ms-excel',
                'vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'vnd.ms-powerpoint',
                'vnd.openxmlformats-officedocument.presentationml.presentation',
            ],
            'text' => ['plain', 'csv', 'xml'],
        ],
    ],

    // SSRF protection settings for URL uploads
    'url_upload' => [
        // Only allow these domains (empty = allow all public domains)
        'allowed_domains'  => [],
        // Hard timeout for the download request (seconds)
        'timeout_seconds'  => env('FILE_UPLOAD_URL_TIMEOUT', 10),
        // Maximum file size allowed from a URL (bytes) — default 50 MB
        'max_size_bytes'   => env('FILE_UPLOAD_URL_MAX_SIZE', 52428800),
    ],

    // =========================================================================
    // Image Processing (Intervention/Image v3)
    // =========================================================================

    // Driver for Intervention/Image: 'gd' (default) or 'imagick'
    'image_driver' => env('FILE_UPLOAD_IMAGE_DRIVER', 'gd'),

    'processing' => [
        'image' => [
            'enabled' => true,
            'resize'  => [
                'width'                 => 1200,
                'height'                => 1200,
                'maintain_aspect_ratio' => true,
                // false = allow upscaling; true = only downscale (default)
                'upsize'                => false,
            ],
            'watermark' => [
                'enabled'  => false,
                'path'     => null,   // relative to public_path()
                'position' => 'bottom-right',
                'opacity'  => 50,
                'x_offset' => 10,
                'y_offset' => 10,
            ],
            'filters' => [
                // 'brightness' => 10,
                // 'contrast'   => 10,
                // 'greyscale'  => true,
                // 'blur'       => 0,
            ],
            'convert_to' => 'webp',   // null = keep original format
            'quality'    => 85,
            // Requires spatie/image-optimizer: composer require spatie/image-optimizer
            'optimize'   => false,
        ],
        'video' => [
            'enabled'    => false,
            'convert_to' => 'mp4',
            'bitrate'    => '1000k',
            'resolution' => '1280x720',
        ],
    ],

    // =========================================================================
    // Thumbnails
    // =========================================================================

    'thumbnails' => [
        'enabled' => true,
        'sizes'   => [
            'small'  => ['width' => 150, 'height' => 150, 'crop' => true],
            'medium' => ['width' => 300, 'height' => 300, 'crop' => false],
            'large'  => ['width' => 600, 'height' => 600, 'crop' => false],
        ],
        'for_videos' => false,  // Requires FFmpeg
        'seconds'    => 5,      // Second to capture for video thumbnails
    ],

    // =========================================================================
    // Quota Management
    // =========================================================================

    'quota' => [
        'enabled'           => env('FILE_UPLOAD_QUOTA_ENABLED', false),
        'max_size_per_user' => 1073741824,  // 1 GB
        // Column used to identify the owner — change to 'tenant_id' for multi-tenant
        'key_column'        => 'user_id',
        // Fire QuotaWarning event when usage exceeds this fraction (0.9 = 90%)
        'warning_threshold' => 0.9,
        'check_method'      => 'database',  // 'database' | 'session'
    ],

    // =========================================================================
    // Database Tracking
    // =========================================================================

    'database' => [
        'enabled'     => env('FILE_UPLOAD_DB_ENABLED', true),
        'model'       => \MohamedSamy902\AdvancedFileUpload\Models\FileUpload::class,
        'table'       => 'file_uploads',
        'prune_after' => 30,   // Days before pruning orphaned records (null = never)
    ],

    // =========================================================================
    // Security
    // =========================================================================

    'security' => [
        // Validate file binary magic bytes against declared MIME type
        'strict_mime_validation' => true,
        'rate_limit'             => [
            'enabled'     => false,
            'max_uploads' => 60,
            'per_minutes' => 1,
        ],
        'virus_scan' => [
            'enabled' => false,
            'driver'  => 'clamav',
            'path'    => env('CLAMSCAN_PATH', '/usr/bin/clamscan'),
        ],
    ],

    // =========================================================================
    // Chunked / Resumable Uploads
    // =========================================================================

    'chunked' => [
        'session_ttl_hours' => 24,
    ],

    // =========================================================================
    // Temporary Signed URLs (for local disk)
    // =========================================================================

    'temp_url' => [
        'route_prefix' => 'file-uploads',
        'middleware'   => [],
    ],

    // =========================================================================
    // Compression
    // =========================================================================

    'compression' => [
        'enabled' => false,
        'types'   => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'],
        'quality' => 80,
    ],

    // =========================================================================
    // Logging
    // =========================================================================

    'logging' => [
        'enabled' => true,
        'level'   => 'info',
    ],

    // =========================================================================
    // Media Manager UI Dashboard
    // =========================================================================

    'ui' => [
        // Route prefix for the dashboard (e.g. /advanced-file-upload)
        'route_prefix' => env('FILE_UPLOAD_UI_PREFIX', 'advanced-file-upload'),
        // Middleware applied to all dashboard routes
        // Add 'auth' to require login, or a custom middleware like 'admin'
        'middleware'   => ['web'],
    ],

];
