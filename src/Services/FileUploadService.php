<?php

namespace MohamedSamy902\AdvancedFileUpload\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Facades\Image;
use Pion\Laravel\ChunkUpload\Receiver\FileReceiver;
use Pion\Laravel\ChunkUpload\Handler\HandlerFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\MimeTypes;
use Exception;

class FileUploadService
{
    /**
     * Upload a file or multiple files from a Request, direct UploadedFile, or URL.
     *
     * @param  UploadedFile|Request|string|array  $fileOrRequest
     * @param  array  $options  Available keys: disk, path, folder_name, field_name, validation_rules, url, convert_to, quality
     * @return array|object|null
     * @throws Exception
     */
    public function upload($fileOrRequest, array $options = [])
    {
        $config = config('file-upload');
        $disk = $options['disk'] ?? $config['storage']['disk'];
        $basePath = $options['path'] ?? $config['storage']['path'];
        $folderName = $options['folder_name'] ?? $config['storage']['default_folder'];
        $path = $folderName
            ? $this->generatePath($basePath, $folderName)
            : trim($basePath, '/');

        $customValidationRules = $options['validation_rules'] ?? [];

        if (isset($options['url'])) {
            return $this->handleUrlUpload($options['url'], $path, $disk, $options, $customValidationRules);
        }

        if ($fileOrRequest instanceof Request) {
            return $this->handleRequestUpload($fileOrRequest, $path, $disk, $options, $customValidationRules);
        }

        return $this->handleDirectUpload($fileOrRequest, $path, $disk, $options, $customValidationRules);
    }

    /**
     * Delete a file or multiple files by ID, path, or array of IDs/paths.
     *
     * @param  int|string|array  $idOrPath
     * @return array
     * @throws Exception
     */
    public function delete($idOrPath)
    {
        if (is_array($idOrPath)) {
            $results = [];
            foreach ($idOrPath as $item) {
                try {
                    $results[] = $this->processDelete($item);
                } catch (Exception $e) {
                    $results[] = ['status' => false, 'error' => $e->getMessage(), 'item' => $item];
                }
            }
            return $results;
        }

        return $this->processDelete($idOrPath);
    }

    // -------------------------------------------------------------------------
    // Upload Handlers
    // -------------------------------------------------------------------------

    /**
     * @param  string|array  $urls
     */
    protected function handleUrlUpload($urls, string $path, string $disk, array $options, array $customValidationRules)
    {
        if (is_array($urls)) {
            $results = [];
            foreach ($urls as $url) {
                try {
                    $results[] = $this->processUrlUpload($url, $path, $disk, $options, $customValidationRules);
                } catch (Exception $e) {
                    Log::error("URL upload failed [{$url}]: " . $e->getMessage());
                    $results[] = ['status' => false, 'error' => $e->getMessage(), 'url' => $url];
                }
            }
            return $results;
        }

        return $this->processUrlUpload($urls, $path, $disk, $options, $customValidationRules);
    }

    protected function processUrlUpload(string $url, string $path, string $disk, array $options, array $customValidationRules): array
    {
        $config = config('file-upload');
        $file = null;

        try {
            $useChunked = ($config['url_download']['enabled'] ?? true)
                && ($config['url_download']['chunked'] ?? true);

            $file = $useChunked
                ? $this->chunkedDownloadFromUrl($url, $options)
                : $this->simpleDownloadFromUrl($url, $options);

            if (!$file instanceof UploadedFile || !$file->isValid()) {
                throw new Exception('Downloaded file is invalid.');
            }

            if ($config['quota']['enabled']) {
                $this->checkQuota($file);
            }

            $result = $this->saveFile($file, $path, $disk, $options, $customValidationRules);

            // Clean up the temp file after successful save
            if (file_exists($file->getRealPath())) {
                @unlink($file->getRealPath());
            }

            return $result;
        } catch (Exception $e) {
            if ($file && file_exists($file->getRealPath())) {
                @unlink($file->getRealPath());
            }
            Log::error("URL upload error [{$url}]: " . $e->getMessage());
            throw new Exception('Failed to upload from URL: ' . $e->getMessage());
        }
    }

    protected function handleRequestUpload(Request $request, string $path, string $disk, array $options, array $customValidationRules)
    {
        $config = config('file-upload');
        $fieldName = $options['field_name'] ?? 'file';

        $receiver = new FileReceiver($fieldName, $request, HandlerFactory::classFromRequest($request));

        if ($receiver->isUploaded()) {
            $save = $receiver->receive();

            if ($save->isFinished()) {
                $file = $save->getFile();
                if ($config['quota']['enabled']) {
                    $this->checkQuota($file);
                }
                return $this->saveFile($file, $path, $disk, $options, $customValidationRules);
            }

            return response()->json([
                'done' => $save->handler()->getPercentageDone(),
                'status' => true,
            ]);
        }

        $files = $request->file('files') ?? $request->file($fieldName);

        if (is_array($files)) {
            $results = [];
            foreach ($files as $file) {
                if ($file instanceof UploadedFile && $file->isValid()) {
                    if ($config['quota']['enabled']) {
                        $this->checkQuota($file);
                    }
                    $results[] = $this->saveFile($file, $path, $disk, $options, $customValidationRules);
                } else {
                    $name = $file instanceof UploadedFile ? $file->getClientOriginalName() : 'unknown';
                    Log::warning("Invalid file in request: {$name}");
                    $results[] = ['status' => false, 'error' => 'Invalid file.', 'original_name' => $name];
                }
            }
            return $results;
        }

        $file = $request->file($fieldName);
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            throw new Exception('Invalid file.');
        }

        if ($config['quota']['enabled']) {
            $this->checkQuota($file);
        }

        return $this->saveFile($file, $path, $disk, $options, $customValidationRules);
    }

    protected function handleDirectUpload($file, string $path, string $disk, array $options, array $customValidationRules)
    {
        $config = config('file-upload');

        if (is_array($file)) {
            $results = [];
            foreach ($file as $singleFile) {
                if ($singleFile instanceof UploadedFile && $singleFile->isValid()) {
                    if ($config['quota']['enabled']) {
                        $this->checkQuota($singleFile);
                    }
                    $results[] = $this->saveFile($singleFile, $path, $disk, $options, $customValidationRules);
                } else {
                    $name = $singleFile instanceof UploadedFile ? $singleFile->getClientOriginalName() : 'unknown';
                    Log::warning("Invalid direct file: {$name}");
                    $results[] = ['status' => false, 'error' => 'Invalid file.', 'original_name' => $name];
                }
            }
            return $results;
        }

        if (!$file instanceof UploadedFile || !$file->isValid()) {
            throw new Exception('Invalid file.');
        }

        if ($config['quota']['enabled']) {
            $this->checkQuota($file);
        }

        return $this->saveFile($file, $path, $disk, $options, $customValidationRules);
    }

    // -------------------------------------------------------------------------
    // URL Downloaders
    // -------------------------------------------------------------------------

    /**
     * Download a file from a URL using chunked (in-memory) approach with retry on 429.
     */
    protected function chunkedDownloadFromUrl(string $url, array $options): UploadedFile
    {
        $timeout = $options['timeout'] ?? config('file-upload.url_download.timeout', 30);
        $maxSize = $options['max_size'] ?? config('file-upload.url_download.max_size', 1024 * 1024 * 50);
        $maxRetries = 3;
        $response = null;
        $tempPath = null;

        try {
            for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
                $response = Http::timeout($timeout)
                    ->withHeaders(['Accept' => '*/*'])
                    ->get($url);

                if ($response->successful()) {
                    break;
                }

                if ($response->status() === 429 && $attempt < $maxRetries) {
                    $delay = (int) pow(2, $attempt);
                    Log::info("Rate limited by {$url}, retrying in {$delay}s (attempt {$attempt}/{$maxRetries})");
                    sleep($delay);
                    continue;
                }

                break;
            }

            if (!$response || $response->failed()) {
                throw new Exception('Failed to download file. HTTP status: ' . ($response ? $response->status() : 'N/A'));
            }

            $contentType = $this->parseMimeType($response->header('Content-Type', ''));
            $this->validateAllowedMimeType($contentType);

            if (strlen($response->body()) > $maxSize) {
                throw new Exception('File exceeds maximum allowed size of ' . round($maxSize / (1024 * 1024), 2) . 'MB.');
            }

            $extension = $this->getExtensionFromMime($contentType, $url);
            $originalName = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_FILENAME) ?: 'file';
            $tempPath = sys_get_temp_dir() . '/' . Str::uuid() . '.' . $extension;

            if (file_put_contents($tempPath, $response->body()) === false) {
                throw new Exception('Failed to write temporary file.');
            }

            return new UploadedFile($tempPath, "{$originalName}.{$extension}", $contentType, null, true);
        } catch (Exception $e) {
            if ($tempPath && file_exists($tempPath)) {
                @unlink($tempPath);
            }
            Log::error("Chunked URL download error [{$url}]: " . $e->getMessage());
            throw new Exception('Download failed: ' . $e->getMessage());
        }
    }

    /**
     * Download a file from a URL by streaming directly to a temp file (memory-efficient).
     */
    protected function simpleDownloadFromUrl(string $url, array $options): UploadedFile
    {
        $timeout = $options['timeout'] ?? config('file-upload.url_download.timeout', 30);
        $maxSize = $options['max_size'] ?? config('file-upload.url_download.max_size', 1024 * 1024 * 50);
        $tempPath = sys_get_temp_dir() . '/' . Str::uuid() . '.tmp';

        try {
            $headResponse = Http::timeout(10)->head($url);

            if ($headResponse->failed()) {
                throw new Exception('Cannot access file URL. HTTP status: ' . $headResponse->status());
            }

            $contentLength = $headResponse->header('Content-Length');
            $contentType = $this->parseMimeType($headResponse->header('Content-Type', ''));

            if ($contentLength && (int) $contentLength > $maxSize) {
                throw new Exception('File exceeds maximum allowed size.');
            }

            $this->validateAllowedMimeType($contentType);

            $response = Http::timeout($timeout)
                ->withOptions(['sink' => $tempPath])
                ->get($url);

            if ($response->failed()) {
                throw new Exception('Failed to download file. HTTP status: ' . $response->status());
            }

            if (filesize($tempPath) > $maxSize) {
                @unlink($tempPath);
                throw new Exception('File exceeds maximum allowed size.');
            }

            $extension = $this->getExtensionFromMime($contentType, $url);
            $originalName = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_FILENAME) ?: 'file';

            return new UploadedFile($tempPath, "{$originalName}.{$extension}", $contentType, null, true);
        } catch (Exception $e) {
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
            Log::error("Simple URL download error [{$url}]: " . $e->getMessage());
            throw new Exception('Download failed: ' . $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Core Save Logic
    // -------------------------------------------------------------------------

    protected function saveFile(UploadedFile $file, string $path, string $disk, array $options, array $customValidationRules): array
    {
        $config = config('file-upload');
        $mime = $file->getMimeType();
        $originalName = $file->getClientOriginalName();
        $extension = $file->getClientOriginalExtension() ?: $file->extension();

        // Resolve final extension early — if image will be converted, the filename must reflect that
        $isImage = str_starts_with($mime, 'image');
        $imageProcessingEnabled = $isImage && ($config['processing']['image']['enabled'] ?? false);
        if ($imageProcessingEnabled) {
            $convertTo = $options['convert_to'] ?? $config['processing']['image']['convert_to'] ?? null;
            if ($convertTo) {
                $extension = $convertTo;
            }
        }

        $fileName = Str::uuid() . '.' . $extension;
        $fullPath = $path . '/' . $fileName;

        $this->validateFile($file, $mime, $options['field_name'] ?? 'file', $customValidationRules);

        try {
            if ($imageProcessingEnabled) {
                $image = Image::make($file->getRealPath());
                $processedContent = $this->processImage($image, $config['processing']['image'], $options);
                Storage::disk($disk)->put($fullPath, $processedContent);
            } else {
                Storage::disk($disk)->put($fullPath, file_get_contents($file->getRealPath()));
            }

            $thumbnailUrls = [];
            if ($isImage && ($config['thumbnails']['enabled'] ?? false)) {
                $thumbnailUrls = $this->generateThumbnails(
                    $fullPath,
                    $disk,
                    $file->getRealPath(),
                    $config['thumbnails']['sizes'],
                    $fileName
                );
            }

            $fileData = [];
            if ($config['database']['enabled'] ?? false) {
                $modelClass = $config['database']['model'];
                $fileData = $modelClass::create([
                    'original_name' => $originalName,
                    'name'          => $fileName,
                    'path'          => $fullPath,
                    'disk'          => $disk,
                    'mime_type'     => $mime,
                    'size'          => $file->getSize(),
                    'type'          => $this->getFileType($mime),
                    'user_id'       => auth()->id(),
                ])->toArray();
            }

            return array_merge([
                'status'         => true,
                'original_name'  => $originalName,
                'path'           => $fullPath,
                'url'            => $this->getFileUrl($config, $disk, $fullPath),
                'thumbnail_urls' => $thumbnailUrls,
                'mime_type'      => $mime,
                'type'           => $this->getFileType($mime),
            ], $fileData);
        } catch (Exception $e) {
            if (Storage::disk($disk)->exists($fullPath)) {
                Storage::disk($disk)->delete($fullPath);
            }
            Log::error("File save failed: " . $e->getMessage());
            throw new Exception('Failed to save file: ' . $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Delete Logic
    // -------------------------------------------------------------------------

    protected function processDelete($idOrPath): array
    {
        $config = config('file-upload');

        try {
            if ($config['database']['enabled'] ?? false) {
                $modelClass = $config['database']['model'];
                $file = is_numeric($idOrPath)
                    ? $modelClass::findOrFail($idOrPath)
                    : $modelClass::where('path', $idOrPath)->firstOrFail();

                $disk = $file->disk ?? $config['storage']['disk'];

                if (Storage::disk($disk)->exists($file->path)) {
                    Storage::disk($disk)->delete($file->path);
                } else {
                    Log::warning("File not found in storage during delete: {$file->path}");
                }

                if ($config['thumbnails']['enabled'] ?? false) {
                    $dir = dirname($file->path);
                    foreach (array_keys($config['thumbnails']['sizes']) as $sizeName) {
                        $thumbPath = "{$dir}/thumb_{$sizeName}_{$file->name}";
                        if (Storage::disk($disk)->exists($thumbPath)) {
                            Storage::disk($disk)->delete($thumbPath);
                        }
                    }
                }

                $file->delete();
                Log::info("File deleted (ID/Path: {$idOrPath}).");
            } else {
                if (!is_string($idOrPath)) {
                    throw new Exception('A file path (string) is required when database storage is disabled.');
                }
                $disk = $config['storage']['disk'];
                if (!Storage::disk($disk)->exists($idOrPath)) {
                    throw new Exception('File not found in storage.');
                }
                Storage::disk($disk)->delete($idOrPath);
                Log::info("File deleted from storage: {$idOrPath}.");
            }

            return ['status' => true, 'message' => 'File deleted successfully.'];
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            Log::error("File not found in database: {$idOrPath}");
            throw new Exception('File not found in database.');
        } catch (Exception $e) {
            Log::error("Delete error (ID/Path: {$idOrPath}): " . $e->getMessage());
            throw new Exception('Failed to delete file: ' . $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Image Processing
    // -------------------------------------------------------------------------

    protected function processImage(\Intervention\Image\Image $image, array $config, array $options): string
    {
        try {
            $resize = $config['resize'] ?? [];
            if (!empty($resize['width']) || !empty($resize['height'])) {
                $image->resize(
                    $resize['width'] ?? null,
                    $resize['height'] ?? null,
                    function ($constraint) use ($resize) {
                        if ($resize['maintain_aspect_ratio'] ?? true) {
                            $constraint->aspectRatio();
                        }
                        if (($resize['upsize'] ?? false) === false) {
                            $constraint->upsize();
                        }
                    }
                );
            }

            $watermark = $config['watermark'] ?? [];
            if (!empty($watermark['enabled']) && !empty($watermark['path'])) {
                $watermarkPath = public_path($watermark['path']);
                if (file_exists($watermarkPath)) {
                    $image->insert(
                        $watermarkPath,
                        $watermark['position'] ?? 'bottom-right',
                        $watermark['x_offset'] ?? 10,
                        $watermark['y_offset'] ?? 10
                    );
                    if (isset($watermark['opacity'])) {
                        $image->opacity($watermark['opacity']);
                    }
                } else {
                    Log::warning("Watermark file not found: {$watermarkPath}");
                }
            }

            $allowedFilters = ['brightness', 'contrast', 'greyscale', 'blur', 'sharpen', 'pixelate', 'flip', 'rotate'];
            foreach ($config['filters'] ?? [] as $filter => $value) {
                if (!in_array($filter, $allowedFilters, true)) {
                    Log::warning("Disallowed image filter skipped: {$filter}");
                    continue;
                }
                if (method_exists($image, $filter)) {
                    $image->$filter($value);
                }
            }

            $format = $options['convert_to'] ?? $config['convert_to'] ?? null;
            $quality = $options['quality'] ?? $config['quality'] ?? 85;

            return (string) $image->encode($format, $quality);
        } catch (Exception $e) {
            Log::error("Image processing failed: " . $e->getMessage());
            throw new Exception('Image processing failed: ' . $e->getMessage());
        }
    }

    protected function generateThumbnails(string $fullPath, string $disk, string $realPath, array $sizes, string $fileName): array
    {
        try {
            $image = Image::make($realPath);
        } catch (Exception $e) {
            Log::error("Failed to load image for thumbnails: " . $e->getMessage());
            return [];
        }

        $dir = dirname($fullPath);
        $baseName = pathinfo($fileName, PATHINFO_FILENAME);
        $ext = pathinfo($fileName, PATHINFO_EXTENSION);
        $thumbnailUrls = [];

        foreach ($sizes as $sizeName => $dimensions) {
            try {
                $thumb = clone $image;
                $width = $dimensions['width'] ?? null;
                $height = $dimensions['height'] ?? null;
                $crop = $dimensions['crop'] ?? false;

                if ($crop && $width && $height) {
                    $thumb->fit($width, $height, fn ($c) => $c->upsize());
                } elseif ($width || $height) {
                    $thumb->resize($width, $height, function ($c) {
                        $c->aspectRatio();
                        $c->upsize();
                    });
                }

                $thumbPath = "{$dir}/thumb_{$sizeName}_{$baseName}.{$ext}";
                Storage::disk($disk)->put($thumbPath, (string) $thumb->encode());
                $thumbnailUrls[$sizeName] = $this->getFileUrl(config('file-upload'), $disk, $thumbPath);
            } catch (Exception $e) {
                Log::error("Thumbnail generation failed for size [{$sizeName}]: " . $e->getMessage());
            }
        }

        return $thumbnailUrls;
    }

    // -------------------------------------------------------------------------
    // Validation & Helpers
    // -------------------------------------------------------------------------

    protected function validateFile(UploadedFile $file, string $mimeType, string $fieldName, array $customValidationRules): void
    {
        if (isset($customValidationRules[$fieldName])) {
            $rule = $customValidationRules[$fieldName];
        } else {
            $rules = config('file-upload.validation');
            $type = explode('/', $mimeType)[0];
            $rule = $rules['custom_fields'][$fieldName] ?? $rules[$type] ?? $rules['other'];
        }

        $validator = Validator::make([$fieldName => $file], [$fieldName => $rule]);

        if ($validator->fails()) {
            throw new Exception($validator->errors()->first());
        }
    }

    protected function validateAllowedMimeType(string $mimeType): void
    {
        $allowed = config('file-upload.url_download.allowed_mimes');
        if (empty($allowed)) {
            return;
        }

        $parts = explode('/', $mimeType);
        $fileType = $parts[0] ?? '';
        $fileSubtype = $parts[1] ?? '';

        $isAllowed = (isset($allowed[$fileType]) && in_array($fileSubtype, $allowed[$fileType], true))
            || (isset($allowed['other']) && (empty($allowed['other']) || in_array($fileSubtype, $allowed['other'], true)));

        if (!$isAllowed) {
            throw new Exception("MIME type [{$mimeType}] is not allowed for URL uploads.");
        }
    }

    protected function checkQuota(UploadedFile $file): void
    {
        $config = config('file-upload.quota');

        if (!($config['enabled'] ?? false) || !auth()->check()) {
            return;
        }

        $modelClass = config('file-upload.database.model');
        $totalSize = $modelClass::where('user_id', auth()->id())->sum('size');

        if (($totalSize + $file->getSize()) > $config['max_size_per_user']) {
            $maxMB = round($config['max_size_per_user'] / (1024 * 1024), 2);
            Log::warning("Storage quota exceeded for user: " . auth()->id());
            throw new Exception("Storage quota exceeded. Maximum allowed: {$maxMB}MB.");
        }
    }

    protected function parseMimeType(string $contentType): string
    {
        return trim(explode(';', $contentType)[0]);
    }

    protected function getExtensionFromMime(string $mime, string $url): string
    {
        $extensions = (new MimeTypes())->getExtensions($mime);
        if (!empty($extensions)) {
            // Prefer common short forms (jpg over jpeg)
            $preferred = ['jpeg' => 'jpg'];
            return $preferred[$extensions[0]] ?? $extensions[0];
        }

        $fallback = [
            'image/jpeg'    => 'jpg',
            'image/png'     => 'png',
            'image/gif'     => 'gif',
            'image/webp'    => 'webp',
            'video/mp4'     => 'mp4',
            'video/quicktime' => 'mov',
            'video/x-msvideo' => 'avi',
            'video/x-matroska' => 'mkv',
            'video/webm'    => 'webm',
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/vnd.ms-powerpoint' => 'ppt',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
            'text/plain'    => 'txt',
            'text/csv'      => 'csv',
            'text/xml'      => 'xml',
            'application/json' => 'json',
        ];

        return $fallback[$mime]
            ?? pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION)
            ?: 'bin';
    }

    protected function getFileType(string $mime): string
    {
        if (str_starts_with($mime, 'image')) return 'image';
        if (str_starts_with($mime, 'video')) return 'video';
        if (str_starts_with($mime, 'audio')) return 'audio';
        if ($mime === 'application/pdf') return 'pdf';
        if (in_array($mime, [
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'text/plain',
        ])) return 'document';

        return 'other';
    }

    protected function generatePath(string $basePath, string $folderName): string
    {
        return trim($basePath . '/' . $folderName, '/');
    }

    protected function getFileUrl(array $config, string $disk, string $path): string
    {
        $url = Storage::disk($disk)->url($path);

        if (($config['storage']['cdn']['enabled'] ?? false) && !empty($config['storage']['cdn']['url'])) {
            $relativePath = ltrim(parse_url($url, PHP_URL_PATH), '/');
            $url = rtrim($config['storage']['cdn']['url'], '/') . '/' . $relativePath;
        }

        return $url;
    }

    protected function compressFile(string $content, string $extension): string
    {
        $textTypes = ['txt', 'csv', 'xml', 'json'];

        if (in_array(strtolower($extension), $textTypes, true) && function_exists('gzencode')) {
            return gzencode($content, 9);
        }

        return $content;
    }
}
