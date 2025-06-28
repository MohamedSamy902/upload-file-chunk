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
   composer require mohamedsamy902/advanced-file-upload:dev-main
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

| Section            | Description                                                           |
| ------------------ | --------------------------------------------------------------------- |
| **Storage**        | Choose disk (local, s3, gcs), base path, and organization structure.  |
| **Validation**     | Define file rules by type: image, video, document, etc.               |
| **Image Handling** | Enable resizing, filters, watermarks, format conversion (e.g., WebP). |
| **Thumbnails**     | Set custom sizes (e.g., `small`, `medium`, `large`).                  |
| **Compression**    | Enable file compression by MIME type or extension.                    |
| **Quota**          | Set storage limits per user ID.                                       |
| **CDN**            | Enable CDN URLs and base path for serving uploaded assets.            |

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

### Download Image Or Video By Url

```php
public function download()
{
    $result = FileUpload::upload([], [
      'url' => 'http://Example.com/vwdio.mp4',
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
  <input type="file" name="profile_picture" />
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

| الميزة          | الوصف                                                         |
| --------------- | ------------------------------------------------------------- |
| التخزين السحابي | يدعم S3، Google Cloud، وغيرها عبر Laravel Filesystem.         |
| معالجة الصور    | تغيير الحجم، العلامة المائية، فلاتر، تحويل إلى WebP/AVIF.     |
| الصور المصغرة   | إنشاء تلقائي لأحجام متعددة.                                   |
| ضغط الملفات     | ضغط ملفات PDF و DOCX (يتطلب أدوات خارجية).                    |
| نظام الكوتا     | تعيين حدود تخزين لكل مستخدم.                                  |
| دعم CDN         | تقديم الملفات عبر شبكة CDN.                                   |
| قاعدة البيانات  | تخزين بيانات الملفات اختياريًا في جدول مرتبط.                 |
| الرفع المجزأ    | دعم رفع الملفات الكبيرة باستخدام `Pion Laravel Chunk Upload`. |

## New

# AdvancedFileUpload for Laravel

**A comprehensive file upload package for Laravel**, supporting local and cloud storage, image processing, file compression, format conversion, chunked uploads, ready-to-use Blade UI, and more.

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
- 🎨 **Ready Blade UI** — Plug-and-play Blade upload form with chunked JS & CSS, easily customizable.
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
   composer require mohamedsamy902/advanced-file-upload:dev-main
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

4. **(Optional)** Publish Blade, JS, and CSS for customization:

   ```bash
   php artisan vendor:publish --tag=views
   php artisan vendor:publish --tag=public
   ```

5. **Configure your `.env` file:**

   ```env
   FILE_UPLOAD_DISK=public
   FILE_UPLOAD_DB_ENABLED=true
   FILE_UPLOAD_CDN_ENABLED=false
   FILE_UPLOAD_CDN_URL=
   ```

---

## 🔧 Configuration

Edit `config/file-upload.php` to customize:

| Section            | Description                                                           |
| ------------------ | --------------------------------------------------------------------- |
| **Storage**        | Choose disk (local, s3, gcs), base path, and organization structure.  |
| **Validation**     | Define file rules by type: image, video, document, etc.               |
| **Image Handling** | Enable resizing, filters, watermarks, format conversion (e.g., WebP). |
| **Thumbnails**     | Set custom sizes (e.g., `small`, `medium`, `large`).                  |
| **Compression**    | Enable file compression by MIME type or extension.                    |
| **Quota**          | Set storage limits per user ID.                                       |
| **CDN**            | Enable CDN URLs and base path for serving uploaded assets.            |

---

## 💡 Usage & Examples

### 1. **Upload via Controller (Single File)**

```php
use MohamedSamy902\AdvancedFileUpload\Facades\FileUpload;

public function upload(Request $request)
{
    $result = FileUpload::upload($request);
    return response()->json($result);
}
```

**HTML Form:**

```blade
<form action="{{ route('upload') }}" method="POST" enctype="multipart/form-data">
    @csrf
    <input type="file" name="file">
    <button type="submit">رفع</button>
</form>
```

---

### 2. **Upload Multiple Files**

```php
public function upload(Request $request)
{
    $result = FileUpload::upload($request, ['field_name' => 'files']);
    return response()->json($result);
}
```

**HTML:**

```blade
<form action="{{ route('upload') }}" method="POST" enctype="multipart/form-data">
    @csrf
    <input type="file" name="files[]" multiple>
    <button type="submit">رفع</button>
</form>
```

---

### 3. **Upload from URL**

```php
$result = FileUpload::upload([], [
    'url' => 'https://example.com/image.jpg',
]);
```

---

### 4. **Use the Ready Blade Upload UI**

Just add in your Blade view:

```blade
@include('advanced-file-upload::upload')
```

Or after publishing:

```blade
@include('vendor.advanced-file-upload.upload')
```

- This will include the ready HTML, JS, and CSS for chunked uploads and progress bar.
- You can customize the UI by editing the published files.

---

### 5. **Chunked Upload with JS (Large Files)**

The included JS (`advanced-file-upload.js`) supports chunked uploads out of the box.  
You can use it directly or customize it after publishing.

**Example:**

```blade
<input type="file" id="afu-fileInput">
<button onclick="afuUploadFile({ uploadUrl: '/upload' })">رفع</button>
<div class="afu-progress-bar"><div class="afu-progress-bar-inner" id="afu-progress-bar-inner"></div></div>
<div id="afu-status"></div>
<link rel="stylesheet" href="{{ asset('vendor/advanced-file-upload/advanced-file-upload.css') }}">
<script src="{{ asset('vendor/advanced-file-upload/advanced-file-upload.js') }}"></script>
```

---

### 6. **Customize Validation, Processing, or Storage**

```php
$result = FileUpload::upload($request, [
    'convert_to' => 'webp',
    'validation_rules' => [
        'file' => 'required|image|max:2048'
    ],
    'folder_name' => 'avatars'
]);
```

---

### 7. **Delete Files**

```php
// Delete by path
FileUpload::delete('uploads/default/uuid.jpg');

// Delete by database ID (if DB enabled)
FileUpload::delete(5);

// Delete multiple files
FileUpload::delete(['uploads/default/uuid1.jpg', 'uploads/default/uuid2.jpg']);
```

---

### 8. **Access Thumbnails**

```php
$result = FileUpload::upload($request);
$thumbUrl = $result['thumbnail_urls']['small'] ?? null;
```

---

### 9. **CDN Support**

If you enable CDN in your config:

```php
// config/file-upload.php
'cdn' => [
    'enabled' => true,
    'url' => 'https://your-cdn.com',
],
```

All returned URLs will use your CDN domain.

---

### 10. **Database Integration**

If enabled, every upload will be saved in the `file_uploads` table.  
You can access file metadata using the model:

```php
use MohamedSamy902\AdvancedFileUpload\Models\FileUpload;

$files = FileUpload::where('user_id', auth()->id())->get();
```

---

### 11. **Quota System**

Set per-user quota in config:

```php
// config/file-upload.php
'quota' => [
    'enabled' => true,
    'max_size_per_user' => 1073741824, // 1GB
],
```

If a user exceeds their quota, an exception will be thrown.

---

### 12. **Customize Blade, JS, and CSS**

After publishing:

```bash
php artisan vendor:publish --tag=views
php artisan vendor:publish --tag=public
```

- Edit `resources/views/vendor/advanced-file-upload/upload.blade.php`
- Edit `public/vendor/advanced-file-upload/advanced-file-upload.css`
- Edit `public/vendor/advanced-file-upload/advanced-file-upload.js`

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

### أمثلة الاستخدام

#### رفع ملف من فورم HTML

```blade
<form action="{{ route('upload') }}" method="POST" enctype="multipart/form-data">
    @csrf
    <input type="file" name="file">
    <button type="submit">رفع</button>
</form>
```

#### رفع ملف من رابط

```php
$result = FileUpload::upload([], ['url' => 'https://example.com/image.jpg']);
```

#### استخدام واجهة Blade الجاهزة

```blade
@include('advanced-file-upload::upload')
```

#### حذف ملف

```php
FileUpload::delete('uploads/default/uuid.jpg');
```

#### تخصيص الاستايل

بعد النشر:

- عدل CSS/JS في `public/vendor/advanced-file-upload/`
- عدل Blade في `resources/views/vendor/advanced-file-upload/`

---

## 🧩 الميزات التفصيلية

| الميزة          | الوصف                                                         |
| --------------- | ------------------------------------------------------------- |
| التخزين السحابي | يدعم S3، Google Cloud، وغيرها عبر Laravel Filesystem.         |
| معالجة الصور    | تغيير الحجم، العلامة المائية، فلاتر، تحويل إلى WebP/AVIF.     |
| الصور المصغرة   | إنشاء تلقائي لأحجام متعددة.                                   |
| ضغط الملفات     | ضغط ملفات PDF و DOCX (يتطلب أدوات خارجية).                    |
| نظام الكوتا     | تعيين حدود تخزين لكل مستخدم.                                  |
| دعم CDN         | تقديم الملفات عبر شبكة CDN.                                   |
| قاعدة البيانات  | تخزين بيانات الملفات اختياريًا في جدول مرتبط.                 |
| الرفع المجزأ    | دعم رفع الملفات الكبيرة باستخدام `Pion Laravel Chunk Upload`. |
| Blade UI        | واجهة Blade جاهزة وقابلة للتخصيص.                             |
| تخصيص كامل      | CSS/JS/Blade قابل للنشر والتعديل بسهولة.                      |
