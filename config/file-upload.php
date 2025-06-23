<?php

return [
    'storage' => [
        'disk' => env('FILE_UPLOAD_DISK', 'local'),
        'path' => 'uploads',
        'organize_by' => 'date', // 'date', 'user', or 'none'
        'cdn' => [
            'enabled' => env('FILE_UPLOAD_CDN_ENABLED', false),
            'url' => env('FILE_UPLOAD_CDN_URL', ''),
        ],
    ],
    'database' => [
        'enabled' => env('FILE_UPLOAD_DB_ENABLED', false),
        'model' => \App\Models\File::class,
    ],
    'validation' => [
        'image' => 'required|image|mimes:jpeg,png,jpg,gif,webp,avif|max:2048',
        'video' => 'required|mimes:mp4,mov,avi,wmv|max:20480',
        'document' => 'required|mimes:pdf,doc,docx,xls,xlsx|max:10240',
        'other' => 'required|max:20480',
    ],
    'processing' => [
        'image' => [
            'enabled' => true,
            'resize' => [
                'width' => 800,
                'height' => 600,
                'maintain_aspect_ratio' => true,
            ],
            'watermark' => null, // e.g., 'storage/watermark.png'
            'filters' => [], // e.g., ['blur' => 5]
            'convert_to' => null, // e.g., 'webp' or 'avif'
        ],
    ],
    'thumbnails' => [
        'enabled' => true,
        'sizes' => [
            'small' => ['width' => 100, 'height' => 100],
            'medium' => ['width' => 300, 'height' => 300],
        ],
    ],
    'compression' => [
        'enabled' => false,
        'types' => ['pdf', 'docx'],
        'quality' => 80,
    ],
    'quota' => [
        'enabled' => false,
        'max_size_per_user' => 1024 * 1024 * 100, // 100MB
    ],
];
