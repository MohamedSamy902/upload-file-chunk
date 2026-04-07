# Advanced File Upload for Laravel

[![Latest Version](https://img.shields.io/packagist/v/mohamedsamy902/advanced-file-upload.svg)](https://packagist.org/packages/mohamedsamy902/advanced-file-upload)
[![PHP Version](https://img.shields.io/badge/php-%5E8.0-blue)](https://packagist.org/packages/mohamedsamy902/advanced-file-upload)
[![Laravel](https://img.shields.io/badge/laravel-%3E%3D9.0-red)](https://packagist.org/packages/mohamedsamy902/advanced-file-upload)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE)
[![Tests](https://img.shields.io/badge/tests-33%20passing-brightgreen)](#testing)

A comprehensive Laravel package for file uploads — supports local & cloud storage, image processing, chunked uploads, thumbnails, CDN, quota management, and more.

---

## Features

- **Multiple upload sources** — HTTP Request, direct `UploadedFile`, URL download, or array of files
- **Cloud storage** — S3, Google Cloud Storage, and any Laravel-supported disk
- **Image processing** — Resize, watermark, filters, format conversion (WebP, etc.) via Intervention Image
- **Automatic thumbnails** — Generate multiple sizes in one call
- **Chunked uploads** — Handle large files reliably via Pion Laravel Chunk Upload
- **URL downloads** — Download and re-upload from remote URLs (chunked or stream)
- **Quota system** — Per-user storage limits with database tracking
- **CDN support** — Automatically rewrite URLs to your CDN domain
- **Database integration** — Optionally store file metadata (name, path, disk, MIME, size, type)
- **Security** — MIME type validation, file size limits, method-injection-safe image filters
- **Blade UI** — Ready-to-use upload form with chunked JS & progress bar

---

## Requirements

| Dependency | Version |
|---|---|
| PHP | ^8.0 |
| Laravel | >= 9.0 |
| intervention/image | ^2.7 |

> **Image processing** (resize, thumbnails, watermarks) requires the `gd` or `imagick` PHP extension.

---

## Installation

```bash
composer require mohamedsamy902/advanced-file-upload
```

### Publish config

```bash
php artisan vendor:publish --tag=config
```

### (Optional) Publish and run migration

Required only when `database.enabled = true` in the config.

```bash
php artisan vendor:publish --tag=migrations
php artisan migrate
```

### (Optional) Publish Blade UI, JS, and CSS

```bash
php artisan vendor:publish --tag=views
php artisan vendor:publish --tag=public
```

---

## Configuration

After publishing, edit `config/file-upload.php`. Key sections:

```php
'storage' => [
    'disk'           => env('FILE_UPLOAD_DISK', 'public'),
    'path'           => env('FILE_UPLOAD_PATH', 'uploads'),
    'default_folder' => 'default',
    'cdn' => [
        'enabled' => env('FILE_UPLOAD_CDN_ENABLED', false),
        'url'     => env('FILE_UPLOAD_CDN_URL', ''),
    ],
],

'database' => [
    'enabled' => env('FILE_UPLOAD_DB_ENABLED', true),
],

'processing' => [
    'image' => [
        'enabled'    => true,
        'resize'     => ['width' => 1200, 'height' => 1200, 'maintain_aspect_ratio' => true],
        'convert_to' => 'webp',
        'quality'    => 85,
    ],
],

'thumbnails' => [
    'enabled' => true,
    'sizes'   => [
        'small'  => ['width' => 150, 'height' => 150, 'crop' => true],
        'medium' => ['width' => 300, 'height' => 300, 'crop' => false],
        'large'  => ['width' => 600, 'height' => 600, 'crop' => false],
    ],
],
```

Add to your `.env`:

```env
FILE_UPLOAD_DISK=public
FILE_UPLOAD_DB_ENABLED=true
FILE_UPLOAD_CDN_ENABLED=false
FILE_UPLOAD_CDN_URL=
```

---

## Usage

### Upload from HTTP Request

The default field name is `file`. Pass `field_name` option to change it.

```php
use MohamedSamy902\AdvancedFileUpload\Facades\FileUpload;

public function store(Request $request)
{
    $result = FileUpload::upload($request);

    return response()->json($result);
}
```

**HTML form:**

```html
<form action="{{ route('upload') }}" method="POST" enctype="multipart/form-data">
    @csrf
    <input type="file" name="file">
    <button type="submit">Upload</button>
</form>
```

---

### Upload Multiple Files

```php
// HTML: <input type="file" name="files[]" multiple>

$result = FileUpload::upload($request, ['field_name' => 'files']);
// returns array of results, one per file
```

---

### Upload with Options

```php
$result = FileUpload::upload($request, [
    'disk'        => 's3',          // override storage disk
    'folder_name' => 'avatars',     // sub-folder inside base path
    'convert_to'  => 'webp',        // override image format
    'quality'     => 90,            // override image quality
    'validation_rules' => [
        'file' => 'required|image|mimes:jpg,png|max:2048',
    ],
]);
```

---

### Upload from URL

Downloads the file from a remote URL and stores it.

```php
$result = FileUpload::upload([], [
    'url' => 'https://example.com/photo.jpg',
]);

// Multiple URLs at once:
$results = FileUpload::upload([], [
    'url' => [
        'https://example.com/photo1.jpg',
        'https://example.com/photo2.jpg',
    ],
]);
```

---

### Upload Direct `UploadedFile`

```php
$file = $request->file('avatar');

$result = FileUpload::upload($file, [
    'folder_name' => 'avatars',
]);
```

---

### Chunked Upload (large files)

Works automatically when using a Request. The JS included in the package sends the file in chunks and reports progress.

```php
// Controller handles both chunked and regular uploads identically:
$result = FileUpload::upload($request);

// While chunking in progress, returns:
// { "done": 45, "status": true }

// When complete, returns the file result array.
```

---

### Delete Files

```php
// By storage path
FileUpload::delete('uploads/default/550e8400-e29b-41d4-a716.webp');

// By database ID (when database.enabled = true)
FileUpload::delete(42);

// Multiple at once
FileUpload::delete([
    'uploads/default/uuid1.webp',
    'uploads/default/uuid2.webp',
]);
```

Deleting a file also removes its associated thumbnails from storage and its record from the database (if enabled).

---

## Response Format

Every successful upload returns:

```json
{
    "status": true,
    "original_name": "photo.jpg",
    "path": "uploads/avatars/550e8400-e29b-41d4-a716.webp",
    "url": "https://your-cdn.com/uploads/avatars/550e8400-e29b-41d4-a716.webp",
    "thumbnail_urls": {
        "small":  "https://your-cdn.com/uploads/avatars/thumb_small_550e8400.webp",
        "medium": "https://your-cdn.com/uploads/avatars/thumb_medium_550e8400.webp",
        "large":  "https://your-cdn.com/uploads/avatars/thumb_large_550e8400.webp"
    },
    "mime_type": "image/jpeg",
    "type": "image"
}
```

When `database.enabled = true`, the response also includes the database record fields (`id`, `size`, `disk`, `user_id`, `created_at`, etc.).

Delete returns:

```json
{ "status": true, "message": "File deleted successfully." }
```

---

## Blade UI

Include the ready-to-use upload form anywhere in your views:

```blade
@include('advanced-file-upload::upload')
```

Or after publishing:

```blade
@include('vendor.advanced-file-upload.upload')
```

Include the assets manually if needed:

```html
<link rel="stylesheet" href="{{ asset('vendor/advanced-file-upload/advanced-file-upload.css') }}">
<script src="{{ asset('vendor/advanced-file-upload/advanced-file-upload.js') }}"></script>
```

---

## Database Model

When `database.enabled = true`, every upload creates a record in the `file_uploads` table:

```php
use MohamedSamy902\AdvancedFileUpload\Models\FileUpload;

// All files uploaded by the authenticated user
$files = FileUpload::where('user_id', auth()->id())->latest()->get();

// Find by ID
$file = FileUpload::findOrFail(42);
```

Table columns: `id`, `original_name`, `name`, `path`, `disk`, `mime_type`, `type`, `size`, `user_id`, `created_at`, `updated_at`.

---

## Testing

```bash
php8.4 vendor/bin/phpunit --testdox
```

```
Tests: 33   Assertions: 61   Failures: 0   Errors: 0
```

---

## Cloud Storage Setup

### Amazon S3

```bash
composer require league/flysystem-aws-s3-v3
```

```env
FILE_UPLOAD_DISK=s3
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=your-bucket
```

### Google Cloud Storage

```bash
composer require spatie/laravel-google-cloud-storage
```

```env
FILE_UPLOAD_DISK=gcs
GOOGLE_CLOUD_PROJECT_ID=...
GOOGLE_CLOUD_KEY_FILE=storage/app/service-account.json
GOOGLE_CLOUD_STORAGE_BUCKET=your-bucket
```

---

## Contributing

Pull requests are welcome. For major changes, please open an issue first.

```
1. Fork the repository
2. Create your branch: git checkout -b feature/my-feature
3. Commit your changes: git commit -m 'Add my feature'
4. Push: git push origin feature/my-feature
5. Open a Pull Request
```

---

## License

MIT — see [LICENSE](LICENSE) for details.
