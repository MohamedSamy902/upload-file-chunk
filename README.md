# AdvancedFileUpload for Laravel

**A comprehensive file upload package for Laravel**, supporting local and cloud storage, image processing, file compression, format conversion, and much more.

---

## 🚀 Features

- ✅ **API & HTML Uploads** — Seamless integration for both API endpoints and HTML forms.
- ☁️ **Cloud Storage Support** — S3, Google Cloud Storage, and any Laravel-supported disk.
- 🖼️ **Image Processing** — Resize, watermark, apply filters, convert to WebP/AVIF (via Intervention Image).
- 🖼️ **Automatic Thumbnails** — Generate thumbnails in custom sizes.
- 🗜️ **File Compression** — Compress PDFs, DOCX, and other docs (requires external tools).
- 📦 **Chunked Uploads** — Handle large files with Pion Laravel Chunk Upload.
- 📊 **Quota System** — Set storage limits per user.
- 🔗 **CDN Support** — Serve files via your preferred CDN.
- 🧩 **Database Integration** — Store metadata for uploaded files (optional).
- 🧪 **Unit Testing** — PHPUnit coverage included.

---

## 📦 Requirements

- PHP >= 8.0  
- Laravel >= 9.0  
- Composer

---

## ⚙️ Installation

1. **Install the package via Composer:**

   ```bash
   composer require your-vendor/advanced-file-upload
   ```

2. **Publish the config file:**

   ```bash
   php artisan vendor:publish --tag=config
   ```

3. **(Optional)** Publish and run the migration:

   ```bash
   php artisan vendor:publish --tag=migrations
   php artisan migrate
   ```

4. **Configure your `.env` file:**

   ```env
   FILE_UPLOAD_DISK=s3
   FILE_UPLOAD_DB_ENABLED=true
   FILE_UPLOAD_CDN_ENABLED=true
   FILE_UPLOAD_CDN_URL=https://your-cdn.com
   ```

---

## 🔧 Configuration

Edit `config/file-upload.php` to customize:

| Section           | Description                                                                 |
|-------------------|-----------------------------------------------------------------------------|
| **Storage**        | Choose disk (local, s3, gcs), base path, and organization structure.        |
| **Validation**     | Define file rules by type: image, video, document, etc.                     |
| **Image Handling** | Enable resizing, filters, watermarks, format conversion (e.g., WebP).       |
| **Thumbnails**     | Set custom sizes (e.g., `small`, `medium`, `large`).                        |
| **Compression**    | Enable file compression by MIME type or extension.                          |
| **Quota**          | Set storage limits per user ID.                                             |
| **CDN**            | Enable CDN URLs and base path for serving uploaded assets.                  |

---

## 💡 Usage

### Upload via Controller

```php
use MohamedSamy902\AdvancedFileUpload\Facades\FileUpload;

public function uploadFile(Request $request)
{
    $result = FileUpload::upload($request, 'profile_picture', [
        'convert_to' => 'webp',
    ]);

    return response()->json($result);
}
```

### Delete a File

```php
public function deleteFile($idOrPath)
{
    $result = FileUpload::delete($idOrPath);

    return response()->json($result);
}
```

---

## 🧾 HTML Form Example

```html
<form action="/upload" method="POST" enctype="multipart/form-data">
    @csrf
    <input type="file" name="profile_picture">
    <button type="submit">Upload</button>
</form>
```

---

## 🧪 API Example

```bash
curl -X POST http://your-app.com/upload \
  -F "profile_picture=@/path/to/image.jpg"
```

### Response

```json
{
  "path": "uploads/2025/06/23/uuid.jpg",
  "url": "https://your-cdn.com/uploads/2025/06/23/uuid.jpg",
  "thumbnail_urls": {
    "small": "https://your-cdn.com/uploads/2025/06/23/thumb_small_uuid.jpg",
    "medium": "https://your-cdn.com/uploads/2025/06/23/thumb_medium_uuid.jpg"
  },
  "mime_type": "image/jpeg"
}
```

---

## 🧪 Testing

Run PHPUnit tests:

```bash
vendor/bin/phpunit
```

---

## 🤝 Contributing

Contributions are welcome!  
Please submit pull requests or open issues via [GitHub Issues](https://github.com/your-vendor/advanced-file-upload/issues).

---

## 📄 License

This package is open-sourced software licensed under the [MIT license](LICENSE).

---

## 📘 Usage Guide (Arabic)

### طريقة الاستخدام

#### 1. إنشاء الباكدج

- أنشئ مجلدًا باسم `advanced-file-upload`.
- ضع الملفات ضمن الهيكلية المناسبة.
- استبدل `your-vendor` باسمك أو اسم شركتك في الملفات و`composer.json`.

#### 2. نشر الباكدج

- أنشئ مستودع GitHub.
- اربطه مع [Packagist](https://packagist.org) لنشر الباكدج.
- حدّث `composer.json` باسمك كمزود.

#### 3. تثبيت الباكدج في مشروع Laravel

```bash
composer require your-vendor/advanced-file-upload
```

#### 4. إعداد التخزين السحابي

- أضف تعريف S3 أو GCS في `config/filesystems.php`.
- ضبّط بيانات الاعتماد في `.env`.

#### 5. اختبار الباكدج

```bash
vendor/bin/phpunit
```

---

## 🧩 الميزات التفصيلية

| الميزة              | الوصف                                                                 |
|---------------------|----------------------------------------------------------------------|
| التخزين السحابي     | يدعم S3، Google Cloud، وغيرها عبر Laravel Filesystem.               |
| معالجة الصور        | تغيير الحجم، العلامة المائية، فلاتر، تحويل إلى WebP/AVIF.            |
| الصور المصغرة       | إنشاء تلقائي لأحجام متعددة.                                          |
| ضغط الملفات         | ضغط ملفات PDF و DOCX (يتطلب أدوات خارجية).                          |
| نظام الكوتا         | تعيين حدود تخزين لكل مستخدم.                                         |
| دعم CDN             | تقديم الملفات عبر شبكة CDN.                                          |
| قاعدة البيانات       | تخزين بيانات الملفات اختياريًا في جدول مرتبط.                        |
| الرفع المجزأ         | دعم رفع الملفات الكبيرة باستخدام `Pion Laravel Chunk Upload`.       |