<?php

return [
    'storage' => [
        'disk' => env('FILE_UPLOAD_DISK', 'public'),
        'path' => env('FILE_UPLOAD_PATH', 'uploads'),
        'default_folder' => 'default',
        'cdn' => [
            'enabled' => env('FILE_UPLOAD_CDN_ENABLED', false),
            'url' => env('FILE_UPLOAD_CDN_URL', ''),
        ],
    ],
    
    'validation' => [
        'image' => 'required|image|mimes:jpeg,png,jpg,gif,webp,svg|max:2048',
        'video' => 'required|mimes:mp4,mov,avi,mkv,webm|max:10240',
        'audio' => 'required|mimes:mp3,wav,ogg|max:5120',
        'document' => 'required|mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,txt|max:5120',
        'other' => 'required|max:5120',
        'custom_fields' => [
            'file' => 'required|file|mimes:jpeg,png,jpg,gif,webp,svg,pdf,doc,docx,mp4,mov,avi,mkv,webm,mp3,wav,ogg|max:10240',
            'files' => 'required|array',
            'files.*' => 'required|file|mimes:jpeg,png,jpg,gif,webp,svg,pdf,doc,docx,mp4,mov,avi,mkv,webm,mp3,wav,ogg|max:10240',
        ],
    ],
    
    'url_download' => [
        'enabled' => true,
        'chunked' => true, // تمكين التحميل المجزأ للروابط
        'timeout' => 300, // 5 دقائق
        'max_size' => 524288000, // 500MB
        'chunk_size' => 5242880, // 5MB
        'allowed_mimes' => [
            'image' => ['jpeg', 'png', 'jpg', 'gif', 'webp', 'svg'],
            'video' => ['mp4', 'mov', 'avi', 'mkv', 'webm'],
            'audio' => ['mp3', 'wav', 'ogg'],
            'document' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt'],
        ],
    ],
    
    'processing' => [
        'image' => [
            'enabled' => true,
            'resize' => [
                'width' => 1200,
                'height' => 1200,
                'maintain_aspect_ratio' => true,
                'upsize' => false, // منع تكبير الصور الأصغر من الأبعاد المحددة
            ],
            'watermark' => [
                'enabled' => false,
                'path' => null, // مسار العلامة المائية
                'position' => 'bottom-right', // top-left, top, top-right, left, center, right, bottom-left, bottom, bottom-right
                'opacity' => 50,
                'x_offset' => 10,
                'y_offset' => 10,
            ],
            'filters' => [
                // 'brightness' => 10,
                // 'contrast' => 10,
                // 'greyscale' => false,
                // 'blur' => 0,
            ],
            'convert_to' => 'webp', // يمكن أن يكون null للاحتفاظ بالصيغة الأصلية
            'quality' => 85, // جودة الصورة بعد التحويل
        ],
        'video' => [
            'enabled' => false,
            'convert_to' => 'mp4', // يمكن أن يكون null
            'bitrate' => '1000k', // معدل البت للفيديو
            'resolution' => '1280x720', // دقة الفيديو
        ],
    ],
    
    'compression' => [
        'enabled' => false,
        'types' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'],
        'quality' => 80,
    ],
    
    'thumbnails' => [
        'enabled' => true,
        'sizes' => [
            'small' => ['width' => 150, 'height' => 150, 'crop' => true],
            'medium' => ['width' => 300, 'height' => 300, 'crop' => false],
            'large' => ['width' => 600, 'height' => 600, 'crop' => false],
        ],
        'for_videos' => false, // إنشاء ثيمب نيلز للفيديوهات (يتطلب FFmpeg)
        'seconds' => 5, // الثواني لأخذ لقطة من الفيديو
    ],
    
    'quota' => [
        'enabled' => env('FILE_UPLOAD_QUOTA_ENABLED', false),
        'max_size_per_user' => 1073741824, // 1GB
        'check_method' => 'database', // يمكن أن يكون 'session' أو 'database'
    ],
    
    'database' => [
        'enabled' => env('FILE_UPLOAD_DB_ENABLED', true),
        'model' => \App\Models\FileUpload::class,
        'table' => 'file_uploads', // اسم الجدول
        'prune_after' => 30, // عدد الأيام قبل حذف الملفات غير المستخدمة (null لتعطيل)
    ],
    
    
    'logging' => [
        'enabled' => true,
        'level' => 'info', // مستوى التسجيل
    ],
];