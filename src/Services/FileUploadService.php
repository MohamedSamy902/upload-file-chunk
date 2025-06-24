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
use Exception;
use Symfony\Component\Mime\MimeTypes;
use Illuminate\Support\Facades\Log;

class FileUploadService
{
    public function upload($fileOrRequest, array $options = [])
    {
        $config = config('file-upload');
        $disk = $config['storage']['disk'];
        $basePath = $config['storage']['path'];
        $folderName = $options['folder_name'] ?? $config['storage']['default_folder'];
        $path = $this->generatePath($basePath, $folderName);

        // Handle URL-based upload (single or multiple URLs)
        if (isset($options['url'])) {
            return $this->handleUrlUpload($options['url'], $path, $disk, $options);
        }

        // Handle chunked upload from request
        if ($fileOrRequest instanceof Request) {
            return $this->handleRequestUpload($fileOrRequest, $path, $disk, $options);
        }

        // dd('d');
        // Handle direct file or array of files upload
        return $this->handleDirectUpload($fileOrRequest, $path, $disk, $options);
    }

    protected function handleUrlUpload($urls, string $path, string $disk, array $options)
    {
        if (is_array($urls)) {
            $results = [];
            foreach ($urls as $url) {
                try {
                    $results[] = $this->processUrlUpload($url, $path, $disk, $options);
                } catch (Exception $e) {
                    $results[] = [
                        'error' => $e->getMessage(),
                        'url' => $url,
                        'status' => false
                    ];
                }
            }
            return $results;
        }

        return $this->processUrlUpload($urls, $path, $disk, $options);
    }

    protected function processUrlUpload(string $url, string $path, string $disk, array $options)
    {
        try {
            $config = config('file-upload');

            // Use chunked download for large files if enabled
            if ($config['url_download']['chunked'] ?? true) {
                $file = $this->chunkedDownloadFromUrl($url, $options);
            } else {
                $file = $this->simpleDownloadFromUrl($url, $options);
            }

            if (!$file instanceof UploadedFile || !$file->isValid()) {
                throw new Exception('فشل تنزيل الملف أو الملف غير صالح');
            }

            if ($config['quota']['enabled']) {
                $this->checkQuota($file);
            }

            return $this->saveFile($file, $path, $disk, $options);
        } catch (Exception $e) {
            throw new Exception('فشل تنزيل الملف من الرابط: ' . $e->getMessage());
        }
    }

    protected function chunkedDownloadFromUrl(string $url, array $options): UploadedFile
    {
        $timeout = $options['timeout'] ?? config('file-upload.url_download.timeout', 300);
        $maxSize = $options['max_size'] ?? config('file-upload.url_download.max_size', 1024 * 1024 * 500); // 500MB default
        $chunkSize = $options['chunk_size'] ?? config('file-upload.url_download.chunk_size', 1024 * 1024 * 5); // 5MB chunks

        $tempDir = sys_get_temp_dir();
        $tempName = Str::uuid();
        $tempPath = "{$tempDir}/{$tempName}";
        $fileHandle = null; // <-- أضف هذا السطر

        try {
            $bytesDownloaded = 0;
            $startByte = 0;
            $fileHandle = fopen($tempPath, 'w');

            do {
                $endByte = $startByte + $chunkSize - 1;
                $response = Http::timeout($timeout)
                    ->withHeaders([
                        'Range' => "bytes={$startByte}-{$endByte}",
                        'Accept' => '*/*'
                    ])
                    ->get($url);

                if ($response->failed()) {
                    // If range not supported, try full download
                    if ($response->status() === 416 || $startByte > 0) {
                        throw new Exception('الخادم لا يدعم التحميل المجزأ');
                    }

                    // Fallback to normal download
                    if (is_resource($fileHandle)) {
                        fclose($fileHandle);
                    }
                    return $this->simpleDownloadFromUrl($url, $options);
                }

                $chunkData = $response->body();
                $bytesWritten = fwrite($fileHandle, $chunkData);
                $bytesDownloaded += $bytesWritten;
                $startByte += $bytesWritten;

                if ($bytesDownloaded > $maxSize) {
                    throw new Exception('حجم الملف يتجاوز الحد المسموح به');
                }

                $contentRange = $response->header('Content-Range');
                $totalSize = $contentRange ? (int) explode('/', $contentRange)[1] : null;
            } while (!$totalSize || $bytesDownloaded < $totalSize);

            if (is_resource($fileHandle)) {
                fclose($fileHandle);
            }

            // Determine file info
            $mimeType = $this->detectMimeType($tempPath, $response->header('Content-Type'));
            $extension = $this->getExtensionFromMime($mimeType, $url);
            $originalName = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_FILENAME) ?: $tempName;

            // Rename with proper extension
            $finalPath = "{$tempPath}.{$extension}";
            rename($tempPath, $finalPath);

            return new UploadedFile(
                $finalPath,
                "{$originalName}.{$extension}",
                $mimeType,
                null,
                true
            );
        } catch (Exception $e) {
            // Clean up
            if (is_resource($fileHandle)) {
                fclose($fileHandle);
            }
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
            if (isset($finalPath) && file_exists($finalPath)) {
                unlink($finalPath);
            }

            throw new Exception('خطأ في التحميل المجزأ: ' . $e->getMessage());
        }
    }

    protected function simpleDownloadFromUrl(string $url, array $options): UploadedFile
    {
        try {
            $timeout = $options['timeout'] ?? config('file-upload.url_download.timeout', 30);
            $maxSize = $options['max_size'] ?? config('file-upload.url_download.max_size', 1024 * 1024 * 50); // 50MB default

            $response = Http::timeout($timeout)
                ->withHeaders(['Accept' => '*/*'])
                ->get($url);

            if ($response->failed()) {
                throw new Exception('فشل تنزيل الملف من الرابط: ' . $response->status());
            }

            // Check file size
            if (strlen($response->body()) > $maxSize) {
                throw new Exception('حجم الملف يتجاوز الحد المسموح به');
            }

            $contentType = $response->header('Content-Type');
            $extension = $this->getExtensionFromMime($contentType, $url);
            $originalName = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_FILENAME);
            $tempName = Str::uuid() . ($originalName ? '_' . $originalName : '');
            $tempPath = sys_get_temp_dir() . '/' . $tempName . '.' . $extension;

            if (!file_put_contents($tempPath, $response->body())) {
                throw new Exception('فشل حفظ الملف المؤقت');
            }

            return new UploadedFile(
                $tempPath,
                "{$originalName}.{$extension}",
                $contentType,
                null,
                true
            );
        } catch (Exception $e) {
            if (isset($tempPath) && file_exists($tempPath)) {
                unlink($tempPath);
            }
            throw new Exception('خطأ أثناء تنزيل الملف: ' . $e->getMessage());
        }
    }

    protected function handleRequestUpload(Request $request, string $path, string $disk, array $options)
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
                return $this->saveFile($file, $path, $disk, $options);
            }
            $handler = $save->handler();
            return response()->json([
                'done' => $handler->getPercentageDone(),
                'status' => true
            ]);
        }

        // Handle multiple files
        $files = $request->file('files') ?? $request->file($fieldName);
        if (is_array($files)) {
            $results = [];
            foreach ($files as $file) {
                if ($file instanceof UploadedFile && $file->isValid()) {
                    if ($config['quota']['enabled']) {
                        $this->checkQuota($file);
                    }
                    $results[] = $this->saveFile($file, $path, $disk, $options);
                }
            }
            return $results;
        }

        $file = $request->file($fieldName);
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            throw new Exception('الملف غير صالح');
        }

        if ($config['quota']['enabled']) {
            $this->checkQuota($file);
        }

        return $this->saveFile($file, $path, $disk, $options);
    }

    protected function handleDirectUpload($file, string $path, string $disk, array $options)
    {
        $config = config('file-upload');

        if (is_array($file)) {
            $results = [];
            foreach ($file as $singleFile) {
                if ($singleFile instanceof UploadedFile && $singleFile->isValid()) {
                    if ($config['quota']['enabled']) {
                        $this->checkQuota($singleFile);
                    }
                    $results[] = $this->saveFile($singleFile, $path, $disk, $options);
                }
            }
            return $results;
        }

        if (!$file instanceof UploadedFile || !$file->isValid()) {
            throw new Exception('الملف غير صالح');
        }

        if ($config['quota']['enabled']) {
            $this->checkQuota($file);
        }

        return $this->saveFile($file, $path, $disk, $options);
    }

    protected function detectMimeType(string $filePath, ?string $contentType): string
    {
        $fileInfo = new \finfo(FILEINFO_MIME_TYPE);
        $detected = $fileInfo->file($filePath);

        if ($detected && $detected !== 'application/octet-stream') {
            return $detected;
        }

        if ($contentType) {
            return explode(';', $contentType)[0];
        }

        return 'application/octet-stream';
    }

    protected function getExtensionFromMime(string $mime, string $url): string
    {
        $mimeTypes = new MimeTypes();
        $extensions = $mimeTypes->getExtensions($mime);

        if (!empty($extensions)) {
            return $extensions[0];
        }

        $commonTypes = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4',
            'video/quicktime' => 'mov',
            'video/x-msvideo' => 'avi',
            'video/x-matroska' => 'mkv',
            'video/webm' => 'webm',
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        ];

        return $commonTypes[$mime] ?? pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'bin';
    }

    protected function saveFile(UploadedFile $file, string $path, string $disk, array $options)
    {
        $config = config('file-upload');
        $mime = $file->getMimeType();
        $extension = $file->getClientOriginalExtension();
        $fileName = Str::uuid() . '.' . ($options['convert_to'] ?? $extension);
        $fullPath = $path . '/' . $fileName;

        $this->validateFile($file, $mime, $options['field_name'] ?? 'file');

        $content = file_get_contents($file->getRealPath());

        // Process only if it's an image and image processing is enabled
        if (str_starts_with($mime, 'image') && $config['processing']['image']['enabled']) {
            try {
                $content = $this->processImage($content, $config['processing']['image'], $options);
            } catch (Exception $e) {
                Log::warning('Image processing failed: ' . $e->getMessage());
                // Continue with original content if processing fails
            }
        }
        // Don't attempt to compress videos or other large files
        elseif (
            $config['compression']['enabled'] &&
            in_array($extension, $config['compression']['types']) &&
            !str_starts_with($mime, 'video')
        ) {
            $content = $this->compressFile($content, $extension, $config['compression']['quality']);
        }

        Storage::disk($disk)->put($fullPath, $content);

        $thumbnailUrls = [];
        if (str_starts_with($mime, 'image') && $config['thumbnails']['enabled']) {
            $thumbnailUrls = $this->generateThumbnails($fullPath, $disk, $content, $config['thumbnails']['sizes']);
        }

        $fileData = [];
        if ($config['database']['enabled']) {
            $modelClass = $config['database']['model'];
            $fileData = $modelClass::create([
                'name' => $fileName,
                'path' => $fullPath,
                'mime_type' => $mime,
                'size' => $file->getSize(),
                'user_id' => auth()->id(),
                'type' => $this->getFileType($mime),
            ])->toArray();
        }

        $url = $this->getFileUrl($config, $disk, $fullPath);
        return array_merge([
            'path' => $fullPath,
            'url' => $url,
            'thumbnail_urls' => $thumbnailUrls,
            'mime_type' => $mime,
            'type' => $this->getFileType($mime),
        ], $fileData);
    }

    protected function getFileType(string $mime): string
    {
        if (str_starts_with($mime, 'image')) {
            return 'image';
        }
        if (str_starts_with($mime, 'video')) {
            return 'video';
        }
        if (str_starts_with($mime, 'audio')) {
            return 'audio';
        }
        if ($mime === 'application/pdf') {
            return 'pdf';
        }
        if (in_array($mime, [
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        ])) {
            return 'document';
        }

        return 'other';
    }

    protected function validateFile(UploadedFile $file, string $mimeType, string $fieldName = 'file')
    {
        $rules = config('file-upload.validation');
        $type = explode('/', $mimeType)[0];
        $rule = $rules['custom_fields'][$fieldName] ?? ($rules[$type] ?? $rules['other']);

        $validator = Validator::make([$fieldName => $file], [
            $fieldName => $rule,
        ]);

        if ($validator->fails()) {
            throw new Exception($validator->errors()->first());
        }
    }

    protected function processImage($content, array $config, array $options)
    {
        $image = Image::make($content);

        if (isset($config['resize']) && $config['resize']['width'] && $config['resize']['height']) {
            $image->resize(
                $config['resize']['width'],
                $config['resize']['height'],
                function ($constraint) use ($config) {
                    if ($config['resize']['maintain_aspect_ratio']) {
                        $constraint->aspectRatio();
                    }
                }
            );
        }

        if (isset($config['watermark']) && $config['watermark']) {
            $image->insert($config['watermark']);
        }

        if (isset($config['filters']) && !empty($config['filters'])) {
            foreach ($config['filters'] as $filter => $value) {
                if (method_exists($image, $filter)) {
                    $image->$filter($value);
                }
            }
        }

        $format = $options['convert_to'] ?? $config['convert_to'];
        if ($format) {
            $image->encode($format);
        }

        return (string) $image->encode();
    }

    protected function generateThumbnails(string $path, string $disk, $content, array $sizes)
    {
        $thumbnailUrls = [];
        $image = Image::make($content);

        foreach ($sizes as $sizeName => $dimensions) {
            $thumbnailName = "thumb_{$sizeName}_" . basename($path);
            $thumbnailPath = dirname($path) . '/' . $thumbnailName;
            $resizedImage = $image->resize($dimensions['width'], $dimensions['height']);
            Storage::disk($disk)->put($thumbnailPath, (string) $resizedImage->encode());
            $thumbnailUrls[$sizeName] = $this->getFileUrl(config('file-upload'), $disk, $thumbnailPath);
        }

        return $thumbnailUrls;
    }

    protected function compressFile($content, string $extension, int $quality)
    {
        return $content;
    }

    protected function checkQuota(UploadedFile $file)
    {
        $config = config('file-upload.quota');
        if (!$config['enabled'] || !auth()->check()) {
            return;
        }

        $userId = auth()->id();
        $modelClass = config('file-upload.database.model');
        $totalSize = $modelClass::where('user_id', $userId)->sum('size');
        $newSize = $file->getSize();

        if (($totalSize + $newSize) > $config['max_size_per_user']) {
            throw new Exception('تم تجاوز حد التخزين المسموح للمستخدم');
        }
    }

    protected function generatePath(string $basePath, string $folderName)
    {
        $path = trim($basePath . '/' . $folderName, '/');
        return $path;
    }

    protected function getFileUrl(array $config, string $disk, string $path)
    {
        $url = Storage::disk($disk)->url($path);
        if ($config['storage']['cdn']['enabled'] && $config['storage']['cdn']['url']) {
            $url = $config['storage']['cdn']['url'] . '/' . $path;
        }
        return $url;
    }

    public function delete($idOrPath)
    {

        $config = config('file-upload');
        $disk = $config['storage']['disk'];

        if ($config['database']['enabled']) {
            $modelClass = $config['database']['model'];
            // تحقق إذا كان id أو path
            $file = is_numeric($idOrPath)
                ? $modelClass::findOrFail($idOrPath)
                : $modelClass::where('path', $idOrPath)->firstOrFail();

            Storage::disk($disk)->delete($file->path);

            if ($config['thumbnails']['enabled']) {
                foreach ($config['thumbnails']['sizes'] as $sizeName => $size) {
                    $thumbnailPath = dirname($file->path) . "/thumb_{$sizeName}_" . $file->name;
                    Storage::disk($disk)->delete($thumbnailPath);
                }
            }

            $file->delete();
        } else {
            Storage::disk($disk)->delete($idOrPath);
        }
        return ['status' => 'تم حذف الملف بنجاح'];
    }
}
