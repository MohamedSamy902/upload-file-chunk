# AdvancedFileUpload for Laravel

A comprehensive file upload package for Laravel, supporting local and cloud storage, image processing, file compression, format conversion, and more.

## Features

- **API and HTML Uploads**: Supports file uploads via API and HTML forms.
- **Cloud Storage**: Compatible with S3, Google Cloud Storage, and other Laravel-supported disks.
- **Image Processing**: Resize, watermark, apply filters, and convert to WebP/AVIF using Intervention Image.
- **Thumbnails**: Automatically generate thumbnails for images.
- **File Compression**: Compress documents like PDFs and DOCX (requires additional libraries).
- **Quota System**: Limit storage per user.
- **CDN Support**: Serve files via a Content Delivery Network.
- **Database Integration**: Optional storage of file metadata in a database.
- **Chunked Uploads**: Handle large files using Pion Laravel Chunk Upload.
- **Unit Tests**: Includes PHPUnit tests for reliability.

## Requirements

- PHP >= 8.0
- Laravel >= 9.0
- Composer

## Installation

1. Install the package via Composer:

   ```bash
   composer require your-vendor/advanced-file-upload

   ```

2. Publish the configuration file:

```bash
    php artisan vendor:publish --tag=config
```

3. (Optional) Publish and run the migration for file metadata storage:

```bash
   php artisan vendor:publish --tag=migrations
   php artisan migrate
```

4. Configure your storage disk in .env:
   FILE_UPLOAD_DISK=s3
   FILE_UPLOAD_DB_ENABLED=true
   FILE_UPLOAD_CDN_ENABLED=true
   FILE_UPLOAD_CDN_URL=https://your-cdn.com

Configuration

Edit config/file-upload.php to customize:

Storage: Set disk (local, s3, gcs), base path, and organization (by date or user).

Validation: Define rules for images, videos, documents, etc.

Image Processing: Enable resizing, watermarks, filters, and format conversion.

Thumbnails: Configure sizes for generated thumbnails.

Compression: Enable for specific file types.

Quota: Set storage limits per user.

CDN: Enable and set CDN URL.

Usage

In a Controller

use YourVendor\AdvancedFileUpload\Facades\FileUpload;

public function uploadFile(Request $request)
{
    $result = FileUpload::upload($request, 'profile_picture', [
'convert_to' => 'webp',
]);
return response()->json($result);
}

public function deleteFile($idOrPath)
{
    $result = FileUpload::delete($idOrPath);
return response()->json($result);
}

HTML Form

<form action="/upload" method="POST" enctype="multipart/form-data">
    @csrf
    <input type="file" name="profile_picture">
    <button type="submit">Upload</button>
</form>

API Example

curl -X POST http://your-app.com/upload \
 -F "profile_picture=@/path/to/image.jpg"

Response

{
"path": "uploads/2025/06/23/uuid.jpg",
"url": "https://your-cdn.com/uploads/2025/06/23/uuid.jpg",
"thumbnail_urls": {
"small": "https://your-cdn.com/uploads/2025/06/23/thumb_small_uuid.jpg",
"medium": "https://your-cdn.com/uploads/2025/06/23/thumb_medium_uuid.jpg"
},
"mime_type": "image/jpeg"
}

Testing

Run the included tests:

```bash
vendor/bin/phpunit
```

Contributing

Contributions are welcome! Please submit pull requests or open issues on GitHub.

License

This package is open-sourced under the MIT License.



## طريقة الاستخدام
1. **إنشاء الباكدج**:
   - أنشئ مجلدًا جديدًا باسم `advanced-file-upload`.
   - انسخ الملفات أعلاه إلى الهيكلية الموضحة.
   - استبدل `your-vendor` باسم المورد الخاص بك (مثل اسمك أو اسم شركتك).

2. **نشر الباكدج**:
   - إذا كنت ترغب في نشر الباكدج على Packagist، قم بإنشاء مستودع على GitHub وقم بربطه بـ [Packagist](https://packagist.org).
   - قم بتحديث `composer.json` باسم المورد الخاص بك.

3. **تثبيت الباكدج في مشروع Laravel**:
   - أضف الباكدج إلى مشروعك عبر:
     ```bash
     composer require your-vendor/advanced-file-upload
     ```
   - انشر ملف الكونفيج والهجرة كما هو موضح في README.md.

4. **إعداد التخزين السحابي**:
   - لاستخدام S3 أو Google Cloud، قم بتثبيت التعريفات المناسبة في `config/filesystems.php` وحدد المصادقة في `.env`.

5. **اختبار الباكدج**:
   - قم بتشغيل الاختبارات باستخدام PHPUnit للتأكد من أن كل شيء يعمل بشكل صحيح.

## الميزات التفصيلية
| الميزة                     | الوصف                                                                 |
|----------------------------|----------------------------------------------------------------------|
| **التخزين السحابي**       | يدعم S3، Google Cloud، وغيرها عبر Laravel Filesystem.               |
| **معالجة الصور**           | تغيير الحجم، العلامات المائية، الفلاتر، تحويل إلى WebP/AVIF.      |
| **الصور المصغرة**          | إنشاء تلقائي للصور المصغرة بأحجام متعددة.                          |
| **ضغط الملفات**            | دعم ضغط المستندات (يتطلب مكتبات إضافية).                          |
| **نظام الكوتا**            | تحديد حد التخزين لكل مستخدم.                                       |
| **دعم CDN**                | تقديم الملفات عبر شبكة توزيع المحتوى.                              |
| **قاعدة البيانات**         | تخزين بيانات الملفات اختياري مع جدول مخصص.                         |
| **الرفع المجزأ**            | دعم رفع الملفات الكبيرة باستخدام Pion Laravel Chunk Upload.         |

