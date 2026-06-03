# Advanced File Upload

A production-ready Laravel package for chunked uploads, image processing, URL downloads, multi-cloud storage, and resumable file transfers.

[![PHP](https://img.shields.io/badge/PHP-8.1%2B-blue.svg)](https://php.net)
[![Laravel](https://img.shields.io/badge/Laravel-10%20|%2011%20|%2012%20|%2013-red.svg)](https://laravel.com)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![Tests](https://github.com/MohamedSamy902/uplade-file-chunk/actions/workflows/tests.yml/badge.svg)](https://github.com/MohamedSamy902/uplade-file-chunk/actions)

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Usage](#usage)
  - [Single File Upload](#single-file-upload)
  - [Batch Upload](#batch-upload)
  - [URL Download and Upload](#url-download-and-upload)
  - [Resumable Chunked Upload](#resumable-chunked-upload)
  - [Image Processing](#image-processing)
  - [CDN URL Rewriting](#cdn-url-rewriting)
  - [Deleting Files](#deleting-files)
- [Security](#security)
- [Events](#events)
- [Testing](#testing)
- [Changelog](#changelog)
- [Contributing](#contributing)
- [License](#license)

---

## Requirements

| Requirement | Version |
|---|---|
| PHP | ^8.1 |
| Laravel | >=10.0 |
| Intervention Image | ^3.0 |
| GD or Imagick | Required for image processing |

---

## Installation

Install the package via Composer:

```bash
composer require mohamedsamy902/advanced-file-upload
```

Publish the configuration file:

```bash
php artisan vendor:publish --provider="MohamedSamy902\AdvancedFileUpload\AdvancedFileUploadServiceProvider" --tag=config
```

Publish and run the database migrations (required for file tracking or resumable uploads):

```bash
php artisan vendor:publish --provider="MohamedSamy902\AdvancedFileUpload\AdvancedFileUploadServiceProvider" --tag=migrations
php artisan migrate
```

For cloud storage, install the appropriate adapter:

```bash
# Amazon S3
composer require league/flysystem-aws-s3-v3

# Google Cloud Storage
composer require spatie/laravel-google-cloud-storage
```

---

## Configuration

After publishing, the configuration file is located at `config/file-upload.php`.

The key sections are:

```php
return [
    'storage' => [
        'disk'           => env('FILE_UPLOAD_DISK', 'public'),
        'path'           => env('FILE_UPLOAD_PATH', 'uploads'),
        'default_folder' => null,
        'cdn' => [
            'enabled' => env('FILE_UPLOAD_CDN_ENABLED', false),
            'url'     => env('FILE_UPLOAD_CDN_URL', ''),
        ],
    ],

    'url_upload' => [
        'allowed_domains' => [],        // empty = allow all public domains
        'timeout_seconds' => 10,
        'max_size_bytes'  => 52428800,  // 50 MB
    ],

    'image_driver' => env('FILE_UPLOAD_IMAGE_DRIVER', 'gd'),  // 'gd' or 'imagick'

    'processing' => [
        'image' => [
            'enabled'    => false,
            'convert_to' => null,       // 'webp', 'jpg', 'png'
            'quality'    => 85,
            'max_width'  => 1920,
            'max_height' => null,
            'upsize'     => false,
        ],
    ],

    'thumbnails' => [
        'enabled' => false,
        'sizes'   => [
            'sm' => ['width' => 150, 'height' => 150, 'crop' => true],
            'md' => ['width' => 400, 'height' => 400, 'crop' => false],
        ],
    ],

    'quota' => [
        'enabled'   => false,
        'per_user'  => 1073741824, // 1 GB
    ],

    'chunked' => [
        'session_ttl_hours' => 24,
    ],

    'database' => [
        'enabled' => false,
        'model'   => \MohamedSamy902\AdvancedFileUpload\Models\FileUpload::class,
    ],
];
```

---

## Usage

### Single File Upload

```php
use MohamedSamy902\AdvancedFileUpload\Facades\FileUpload;

public function store(Request $request)
{
    $result = FileUpload::upload($request->file('avatar'));

    // $result is a typed value object
    return response()->json([
        'path' => $result->path,
        'url'  => $result->url,
        'type' => $result->type, // image | video | audio | pdf | document | other
    ]);
}
```

The return value is an `UploadResult` object with these properties:

| Property | Type | Description |
|---|---|---|
| `status` | `bool` | Whether the upload succeeded |
| `path` | `string` | Storage path relative to the disk root |
| `url` | `string` | Public URL (with CDN rewriting if enabled) |
| `originalName` | `string` | The original client filename |
| `mimeType` | `string` | The detected MIME type |
| `type` | `string` | Logical category: image, video, audio, pdf, document, other |
| `size` | `int|null` | File size in bytes |
| `thumbnailUrls` | `array` | Map of thumbnail size name to URL |
| `databaseId` | `int|null` | Database record ID when tracking is enabled |

`UploadResult` also implements `ArrayAccess`, so existing code using array syntax continues to work:

```php
$result = FileUpload::upload($file);
$path = $result['path'];    // still works
$url  = $result['url'];     // still works
```

---

### Batch Upload

Pass an array of files. Failed items return an error array without interrupting the batch.

```php
$files   = $request->file('documents');
$results = FileUpload::upload($files, [
    'folder_name'      => 'reports',
    'validation_rules' => ['file' => 'required|file|mimes:pdf,docx|max:10240'],
]);

foreach ($results as $result) {
    if ($result instanceof \MohamedSamy902\AdvancedFileUpload\ValueObjects\UploadResult) {
        // success
        echo $result->url;
    } else {
        // failure — $result is an array with 'status', 'error', 'original_name'
        echo $result['error'];
    }
}
```

---

### URL Download and Upload

Download a remote file and store it, with SSRF protection applied before any HTTP request.

```php
$result = FileUpload::upload([], [
    'url'         => 'https://cdn.example.com/photo.jpg',
    'folder_name' => 'avatars',
]);
```

Multiple URLs in a single call:

```php
$results = FileUpload::upload([], [
    'url' => [
        'https://cdn.example.com/image1.jpg',
        'https://cdn.example.com/image2.jpg',
        'https://cdn.example.com/image3.jpg',
    ],
    'folder_name' => 'gallery',
]);
```

**Restricting permitted domains:**

```php
// config/file-upload.php
'url_upload' => [
    'allowed_domains' => ['cdn.myapp.com', 'assets.partner.com'],
],
```

---

### Resumable Chunked Upload

When a client uploads a large file and the connection is interrupted, the upload can resume from the last successful chunk without starting over.

**Step 1 — Start a session on your backend:**

```php
use MohamedSamy902\AdvancedFileUpload\Services\ResumableUploadService;

public function startUpload(Request $request, ResumableUploadService $service)
{
    $session = $service->startSession(
        originalName: $request->input('filename'),
        mimeType:     $request->input('mime_type'),
        totalSize:    (int) $request->input('total_size'),
        totalChunks:  (int) $request->input('total_chunks'),
        folder:       'uploads',
    );

    return response()->json(['session_id' => $session->session_id]);
}
```

**Step 2 — Send each chunk:**

```php
public function uploadChunk(Request $request, ResumableUploadService $service)
{
    $state = $service->uploadChunk(
        sessionId:  $request->input('session_id'),
        chunkIndex: (int) $request->input('chunk_index'),
        chunk:      $request->file('chunk'),
    );

    return response()->json($state);
    // {"received": 3, "total": 10, "missing": [3, 4, 5, 6, 7, 8, 9]}
}
```

**Step 3 — Resume after a failure:**

The client queries which chunks are still missing:

```php
public function sessionStatus(string $sessionId, ResumableUploadService $service)
{
    return response()->json($service->getSession($sessionId));
    // {"session_id": "...", "status": "pending", "received": 3, "total": 10, "missing": [3, 4, ...]}
}
```

The client re-sends only the missing chunks, then calls complete.

**Step 4 — Assemble and store:**

```php
public function completeUpload(Request $request, ResumableUploadService $service)
{
    $result = $service->completeSession($request->input('session_id'));

    return response()->json([
        'path' => $result->path,
        'url'  => $result->url,
    ]);
}
```

---

### Image Processing

Enable image processing in the configuration:

```php
'processing' => [
    'image' => [
        'enabled'    => true,
        'convert_to' => 'webp',   // convert all uploads to WebP
        'quality'    => 85,
        'max_width'  => 1920,
        'upsize'     => false,    // never enlarge images smaller than max_width
    ],
],
```

Override per request:

```php
$result = FileUpload::upload($file, [
    'convert_to' => 'jpg',
    'quality'    => 75,
]);
```

**Watermark:**

```php
// config/file-upload.php
'processing' => [
    'image' => [
        'enabled'    => true,
        'watermark'  => [
            'enabled'   => true,
            'path'      => storage_path('app/watermark.png'),
            'position'  => 'bottom-right',
            'opacity'   => 50,
        ],
    ],
],
```

**Thumbnails:**

```php
'thumbnails' => [
    'enabled' => true,
    'sizes'   => [
        'sm' => ['width' => 150, 'height' => 150, 'crop' => true],
        'md' => ['width' => 400, 'height' => 400, 'crop' => false],
        'lg' => ['width' => 800, 'height' => null, 'crop' => false],
    ],
],
```

The thumbnail URLs are available in the result:

```php
$result = FileUpload::upload($imageFile);
echo $result->thumbnailUrls['sm']; // https://cdn.myapp.com/uploads/thumbs/sm_uuid.webp
echo $result->thumbnailUrls['md'];
```

---

### CDN URL Rewriting

When a CDN is configured, all generated URLs are automatically rewritten:

```php
// config/file-upload.php
'storage' => [
    'cdn' => [
        'enabled' => true,
        'url'     => 'https://cdn.myapp.com',
    ],
],
```

```php
$result = FileUpload::upload($file);
echo $result->url;
// https://cdn.myapp.com/uploads/uuid.jpg
```

---

### Deleting Files

By storage path (when database tracking is disabled):

```php
FileUpload::delete('uploads/uuid.jpg');
```

By database record ID (when tracking is enabled):

```php
FileUpload::delete(42);
```

Delete multiple files. Partial failures do not abort the batch:

```php
$results = FileUpload::delete([
    'uploads/file1.jpg',
    'uploads/file2.pdf',
    'uploads/file3.mp4',
]);

foreach ($results as $result) {
    if (!$result['status']) {
        logger()->error($result['error']);
    }
}
```

---

## Security

### SSRF Protection

All URL downloads are validated before any HTTP request is issued. The following are blocked automatically:

**Private IP address ranges (RFC 1918 and related):**

| Range | Description |
|---|---|
| `127.0.0.0/8` | IPv4 loopback |
| `10.0.0.0/8` | Private network |
| `172.16.0.0/12` | Private network |
| `192.168.0.0/16` | Private network |
| `169.254.0.0/16` | Link-local (AWS Metadata endpoint) |
| `100.64.0.0/10` | Shared address space (RFC 6598) |
| `::1/128` | IPv6 loopback |
| `fc00::/7` | IPv6 unique local |
| `fe80::/10` | IPv6 link-local |

**Disallowed URL schemes:**

Only `http` and `https` are permitted. The following are blocked: `file://`, `ftp://`, `gopher://`, `data:`, and all others.

**Domain allowlist:**

To restrict URL downloads to specific domains:

```php
// config/file-upload.php
'url_upload' => [
    'allowed_domains' => ['cdn.myapp.com', 'assets.partner.com'],
],
```

An empty array permits any public domain.

### Replacing Security Components

Every service is bound to a contract interface. To replace the SSRF validator with your own implementation:

```php
// In AppServiceProvider::register()
use MohamedSamy902\AdvancedFileUpload\Contracts\SsrfValidatorContract;

$this->app->bind(SsrfValidatorContract::class, MyCustomSsrfValidator::class);
```

The same applies to `ImageProcessorContract`, `QuotaManagerContract`, and `FileUploadContract`.

---

## Testing

Run the full test suite:

```bash
composer test
```

Run static analysis:

```bash
composer analyse
```

The package ships with 140 tests covering:

- Security attack simulation (SSRF, disguised files, DoS)
- Performance benchmarks (single file, batch of 50, memory usage)
- Error handling and edge cases
- Image processing with Intervention Image v3
- Resumable upload session management
- All public API surface

---

## Changelog

### v2.0.0

- Upgraded Intervention Image from v2 to v3
- Minimum PHP version raised to 8.1
- Minimum Laravel version raised to 10
- Moved cloud storage packages (`league/flysystem-aws-s3-v3`, `spatie/laravel-google-cloud-storage`) to `suggest`
- Implemented comprehensive SSRF protection for URL downloads
- Added `UploadResult` typed value object (implements `ArrayAccess` for backward compatibility)
- Added resumable chunked upload system with session management
- Refactored monolithic service into focused, contract-based components
- Added 107 new tests (total: 140 tests, 345 assertions)
- Added GitHub Actions CI matrix for PHP 8.1–8.4 and Laravel 10–13

### v1.0.0

- Initial release

---

## Contributing

Contributions are welcome. Please open an issue first to discuss the change you intend to make.

1. Fork the repository
2. Create a feature branch: `git checkout -b feature/your-feature`
3. Write tests for your change
4. Run the test suite: `composer test`
5. Submit a pull request

---

## License

The MIT License (MIT). Please see [LICENSE](LICENSE) for more information.
