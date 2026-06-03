<?php

declare(strict_types=1);

namespace MohamedSamy902\AdvancedFileUpload\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use MohamedSamy902\AdvancedFileUpload\Contracts\ImageProcessorContract;
use MohamedSamy902\AdvancedFileUpload\ValueObjects\UploadResult;
use RuntimeException;

/**
 * Persists uploaded files to the configured storage disk.
 *
 * Responsibilities:
 *   - Writing file content to the storage disk
 *   - Triggering optional image processing before storage
 *   - Delegating thumbnail generation to the ImageProcessor
 *   - Generating public URLs with optional CDN rewriting
 *   - Persisting metadata to the database when enabled
 *   - Deleting files and their associated thumbnails
 */
final class StorageManager
{
    public function __construct(
        private readonly ImageProcessorContract $imageProcessor,
        private readonly MimeTypeResolver       $mimeResolver,
    ) {}

    /**
     * Stores an uploaded file and returns a typed result object.
     *
     * Applies image processing and thumbnail generation when the file is an
     * image and the respective config flags are enabled. Writes a database
     * record when "database.enabled" is true.
     *
     * @param UploadedFile $file                  The validated uploaded file
     * @param string       $path                  The destination directory path on the disk
     * @param string       $disk                  The storage disk name
     * @param array        $options               Per-request overrides (convert_to, quality)
     *
     * @return UploadResult
     * @throws RuntimeException When the file cannot be written to storage
     */
    public function store(
        UploadedFile $file,
        string       $path,
        string       $disk,
        array        $options = [],
    ): UploadResult {
        $config       = config('file-upload');
        $mime         = $file->getMimeType() ?? 'application/octet-stream';
        $originalName = $file->getClientOriginalName();
        $extension    = $this->resolveExtension($file, $mime, $config, $options);
        $fileName     = Str::uuid() . '.' . $extension;
        $fullPath     = ltrim($path . '/' . $fileName, '/');

        try {
            $this->writeFile($file, $fullPath, $disk, $mime, $config, $options);

            $thumbnailUrls = $this->maybeGenerateThumbnails(
                $file, $fullPath, $fileName, $disk, $mime, $config
            );

            $databaseId = $this->maybeWriteRecord(
                $originalName, $fileName, $fullPath, $disk, $mime, $file->getSize(), $config
            );

            return new UploadResult(
                status:        true,
                originalName:  $originalName,
                path:          $fullPath,
                url:           $this->buildUrl($config, $disk, $fullPath),
                mimeType:      $mime,
                type:          $this->mimeResolver->toFileType($mime),
                size:          $file->getSize(),
                thumbnailUrls: $thumbnailUrls,
                databaseId:    $databaseId,
            );

        } catch (\Exception $e) {
            $this->rollbackFile($disk, $fullPath);
            Log::error("File storage failed [{$originalName}]: " . $e->getMessage());
            throw new RuntimeException('Failed to store file: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Deletes a file and its thumbnails from the storage disk.
     *
     * When database tracking is enabled, the record is located by ID or path
     * and the database row is removed alongside the physical files.
     * When disabled, a path string is required directly.
     *
     * @param int|string $idOrPath The database record ID or the file path
     * @return array{status: bool, message: string}
     * @throws RuntimeException When the file is not found or cannot be deleted
     */
    public function delete(int|string $idOrPath): array
    {
        $config = config('file-upload');

        if ($config['database']['enabled'] ?? false) {
            return $this->deleteByRecord($idOrPath, $config);
        }

        return $this->deleteByPath((string) $idOrPath, $config);
    }

    /**
     * Writes file content to the storage disk.
     *
     * When image processing is enabled, the file is processed by the
     * ImageProcessor before storage. Otherwise, the raw bytes are written.
     *
     * @param UploadedFile $file
     * @param string       $fullPath
     * @param string       $disk
     * @param string       $mime
     * @param array        $config
     * @param array        $options
     */
    private function writeFile(
        UploadedFile $file,
        string       $fullPath,
        string       $disk,
        string       $mime,
        array        $config,
        array        $options,
    ): void {
        $imageConfig     = $config['processing']['image'] ?? [];
        $processingEnabled = str_starts_with($mime, 'image')
            && ($imageConfig['enabled'] ?? false);

        if ($processingEnabled) {
            $content = $this->imageProcessor->process($file->getRealPath(), $imageConfig, $options);
        } else {
            $content = file_get_contents($file->getRealPath());
        }

        Storage::disk($disk)->put($fullPath, $content);
    }

    /**
     * Generates thumbnails for image files when the feature is enabled.
     *
     * Returns an empty array for non-image files or when thumbnails are off.
     *
     * @param UploadedFile $file
     * @param string       $fullPath
     * @param string       $fileName
     * @param string       $disk
     * @param string       $mime
     * @param array        $config
     * @return array<string, string> Map of size name to public URL
     */
    private function maybeGenerateThumbnails(
        UploadedFile $file,
        string       $fullPath,
        string       $fileName,
        string       $disk,
        string       $mime,
        array        $config,
    ): array {
        if (!str_starts_with($mime, 'image') || !($config['thumbnails']['enabled'] ?? false)) {
            return [];
        }

        return $this->generateThumbnails(
            $file->getRealPath(),
            $fullPath,
            $fileName,
            $disk,
            $config['thumbnails']['sizes'] ?? [],
            $config,
        );
    }

    /**
     * Iterates over the configured thumbnail sizes and stores each one.
     *
     * Failed thumbnails are logged and skipped without interrupting the upload.
     *
     * @param string $realPath    Absolute path to the source image on the local filesystem
     * @param string $fullPath    The stored path of the original file
     * @param string $fileName    The stored filename (UUID + extension)
     * @param string $disk        The storage disk name
     * @param array  $sizes       Thumbnail size definitions from config
     * @param array  $config      Full package config
     * @return array<string, string>
     */
    private function generateThumbnails(
        string $realPath,
        string $fullPath,
        string $fileName,
        string $disk,
        array  $sizes,
        array  $config,
    ): array {
        $dir      = dirname($fullPath);
        $baseName = pathinfo($fileName, PATHINFO_FILENAME);
        $ext      = pathinfo($fileName, PATHINFO_EXTENSION);
        $urls     = [];

        foreach ($sizes as $sizeName => $dimensions) {
            try {
                $width  = isset($dimensions['width'])  && $dimensions['width']  > 0 ? (int) $dimensions['width']  : null;
                $height = isset($dimensions['height']) && $dimensions['height'] > 0 ? (int) $dimensions['height'] : null;
                $crop   = (bool) ($dimensions['crop'] ?? false);

                $content   = $this->imageProcessor->thumbnail($realPath, $width, $height, $crop);
                $thumbPath = "{$dir}/thumb_{$sizeName}_{$baseName}.{$ext}";

                Storage::disk($disk)->put($thumbPath, $content);

                $urls[$sizeName] = $this->buildUrl($config, $disk, $thumbPath);

            } catch (\Exception $e) {
                Log::error("Thumbnail [{$sizeName}] generation failed: " . $e->getMessage());
            }
        }

        return $urls;
    }

    /**
     * Writes a record to the database when database tracking is enabled.
     *
     * @param string  $originalName
     * @param string  $fileName
     * @param string  $fullPath
     * @param string  $disk
     * @param string  $mime
     * @param int|null $size
     * @param array   $config
     * @return int|null The ID of the created record, or null when DB is disabled
     */
    private function maybeWriteRecord(
        string  $originalName,
        string  $fileName,
        string  $fullPath,
        string  $disk,
        string  $mime,
        ?int    $size,
        array   $config,
    ): ?int {
        if (!($config['database']['enabled'] ?? false)) {
            return null;
        }

        $model = $config['database']['model'];

        $record = $model::create([
            'original_name' => $originalName,
            'name'          => $fileName,
            'path'          => $fullPath,
            'disk'          => $disk,
            'mime_type'     => $mime,
            'size'          => $size,
            'type'          => $this->mimeResolver->toFileType($mime),
            'user_id'       => auth()->id(),
        ]);

        return $record->id;
    }

    /**
     * Deletes a file using its database record for path and disk resolution.
     *
     * @param int|string $idOrPath
     * @param array      $config
     * @return array{status: bool, message: string}
     */
    private function deleteByRecord(int|string $idOrPath, array $config): array
    {
        $model = $config['database']['model'];

        try {
            $record = is_numeric($idOrPath)
                ? $model::findOrFail($idOrPath)
                : $model::where('path', $idOrPath)->firstOrFail();
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            throw new RuntimeException("File [{$idOrPath}] was not found in the database.", 0, $e);
        }

        $disk = $record->disk ?? $config['storage']['disk'];

        if (Storage::disk($disk)->exists($record->path)) {
            Storage::disk($disk)->delete($record->path);
        } else {
            Log::warning("Physical file not found during delete: {$record->path}");
        }

        if ($config['thumbnails']['enabled'] ?? false) {
            $this->deleteThumbnails($disk, $record->path, $record->name, $config);
        }

        $record->delete();

        Log::info("File deleted successfully [ID/Path: {$idOrPath}].");

        return ['status' => true, 'message' => 'File deleted successfully.'];
    }

    /**
     * Deletes a file directly by its storage path without consulting the database.
     *
     * @param string $path
     * @param array  $config
     * @return array{status: bool, message: string}
     */
    private function deleteByPath(string $path, array $config): array
    {
        $disk = $config['storage']['disk'];

        if (!Storage::disk($disk)->exists($path)) {
            throw new RuntimeException("File not found in storage: {$path}");
        }

        Storage::disk($disk)->delete($path);

        Log::info("File deleted from storage: {$path}.");

        return ['status' => true, 'message' => 'File deleted successfully.'];
    }

    /**
     * Attempts to delete all generated thumbnails for a given file.
     *
     * Missing thumbnails are silently ignored, as they may not have been
     * generated originally (e.g. if processing was disabled at upload time).
     *
     * @param string $disk
     * @param string $filePath
     * @param string $fileName
     * @param array  $config
     */
    private function deleteThumbnails(string $disk, string $filePath, string $fileName, array $config): void
    {
        $dir = dirname($filePath);

        foreach (array_keys($config['thumbnails']['sizes'] ?? []) as $sizeName) {
            $thumbPath = "{$dir}/thumb_{$sizeName}_{$fileName}";
            if (Storage::disk($disk)->exists($thumbPath)) {
                Storage::disk($disk)->delete($thumbPath);
            }
        }
    }

    /**
     * Resolves the file extension to use for storage.
     *
     * When image processing converts the file to a different format,
     * the target format's extension is used instead of the original.
     *
     * @param UploadedFile $file
     * @param string       $mime
     * @param array        $config
     * @param array        $options
     * @return string
     */
    private function resolveExtension(UploadedFile $file, string $mime, array $config, array $options): string
    {
        $extension = $file->getClientOriginalExtension() ?: ($file->extension() ?: 'bin');

        $imageConfig = $config['processing']['image'] ?? [];
        $isImage     = str_starts_with($mime, 'image');

        if ($isImage && ($imageConfig['enabled'] ?? false)) {
            $convertTo = $options['convert_to'] ?? $imageConfig['convert_to'] ?? null;
            if ($convertTo) {
                return $convertTo;
            }
        }

        return $extension;
    }

    /**
     * Builds the public URL for a stored file, applying CDN rewriting if configured.
     *
     * @param array  $config
     * @param string $disk
     * @param string $path
     * @return string
     */
    private function buildUrl(array $config, string $disk, string $path): string
    {
        $url = Storage::disk($disk)->url($path);

        $cdn = $config['storage']['cdn'] ?? [];
        if (($cdn['enabled'] ?? false) && !empty($cdn['url'])) {
            $relativePath = ltrim(parse_url($url, PHP_URL_PATH), '/');
            return rtrim($cdn['url'], '/') . '/' . $relativePath;
        }

        return $url;
    }

    /**
     * Removes a file from storage if it exists, used for rollback on failure.
     *
     * @param string $disk
     * @param string $path
     */
    private function rollbackFile(string $disk, string $path): void
    {
        if (Storage::disk($disk)->exists($path)) {
            Storage::disk($disk)->delete($path);
        }
    }
}
