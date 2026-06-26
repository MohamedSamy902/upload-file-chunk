<?php

declare(strict_types=1);

namespace MohamedSamy902\AdvancedFileUpload\Services;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use MohamedSamy902\AdvancedFileUpload\Contracts\FileUploadContract;
use MohamedSamy902\AdvancedFileUpload\Contracts\QuotaManagerContract;
use MohamedSamy902\AdvancedFileUpload\ValueObjects\UploadResult;
use RuntimeException;

/**
 * Orchestrates file upload operations.
 *
 * This class does not implement any storage, validation, downloading, or
 * processing logic itself. It delegates each concern to a focused dependency:
 *
 *   - UrlDownloader     : fetches files from remote URLs
 *   - FileValidator     : validates MIME types and Laravel validation rules
 *   - StorageManager    : writes files to disk, generates URLs, handles deletion
 *   - QuotaManagerContract : enforces per-user storage limits
 *
 * The upload() method accepts three source types:
 *   1. A URL string (or array of URLs) — delegates to UrlDownloader
 *   2. An Illuminate Request — supports chunked and multi-file uploads
 *   3. A direct UploadedFile (or array of files)
 */
class FileUploadService implements FileUploadContract
{
    public function __construct(
        private readonly UrlDownloader         $urlDownloader,
        private readonly FileValidator         $fileValidator,
        private readonly StorageManager        $storageManager,
        private readonly QuotaManagerContract  $quotaManager,
    ) {}

    /**
     * Uploads a file from the given source to the configured storage disk.
     *
     * Supported source types:
     *   - UploadedFile             : single direct file upload
     *   - UploadedFile[]           : batch direct upload
     *   - Request                  : handles chunked and multi-file form submissions
     *   - Any other value, when $options['url'] is set : URL download and upload
     *
     * @param mixed $source  The upload source
     * @param array $options {
     *     @type string $disk             Override the storage disk
     *     @type string $path             Override the base storage path
     *     @type string $folder_name      Override the destination folder
     *     @type string $field_name       Form field name (default: "file")
     *     @type array  $validation_rules Per-field custom validation rules
     *     @type string $url              Remote URL or array of URLs to download
     *     @type string $convert_to       Target image format (e.g. "webp")
     *     @type int    $quality          Image compression quality (1–100)
     * }
     *
     * @return UploadResult|array<int, UploadResult|array<string, mixed>>
     * @throws RuntimeException When the source is invalid or the upload fails
     */
    #[\Override]
    public function upload(mixed $source, array $options = []): UploadResult|array
    {
        $config     = config('file-upload');
        $disk       = $options['disk']        ?? $config['storage']['disk'];
        $basePath   = $options['path']        ?? $config['storage']['path'];
        $folderName = $options['folder_name'] ?? $config['storage']['default_folder'];

        $this->assertCloudDependenciesInstalled($disk);

        $storagePath = $folderName
            ? trim($basePath, '/') . '/' . trim($folderName, '/')
            : trim($basePath, '/');

        $customRules = $options['validation_rules'] ?? [];

        if (isset($options['url'])) {
            return $this->handleUrlSource($options['url'], $storagePath, $disk, $options, $customRules);
        }

        if ($source instanceof Request) {
            return $this->handleRequestSource($source, $storagePath, $disk, $options, $customRules);
        }

        return $this->handleDirectSource($source, $storagePath, $disk, $options, $customRules);
    }

    /**
     * Deletes a file or a batch of files.
     *
     * Accepts an integer database record ID, a storage path string, or an array
     * of either. Returns a single result array for scalar input or an array of
     * result arrays for batch input.
     *
     * @param int|string|array<int, int|string> $idOrPath
     * @return array<string, mixed>|array<int, array<string, mixed>>
     */
    #[\Override]
    public function delete(int|string|array $idOrPath): array
    {
        if (!is_array($idOrPath)) {
            return $this->storageManager->delete($idOrPath);
        }

        $results = [];

        foreach ($idOrPath as $item) {
            try {
                $results[] = $this->storageManager->delete($item);
            } catch (\Exception $e) {
                $results[] = ['status' => false, 'error' => $e->getMessage(), 'item' => $item];
            }
        }

        return $results;
    }

    /**
     * Handles uploads initiated from one or more remote URLs.
     *
     * Each URL is validated for SSRF safety by the UrlDownloader before
     * any HTTP request is issued. Failed URLs produce error entries in the
     * result array without interrupting remaining downloads.
     *
     * @param string|array $urls
     * @param string       $storagePath
     * @param string       $disk
     * @param array        $options
     * @param array        $customRules
     * @return UploadResult|array<int, UploadResult|array<string, mixed>>
     */
    private function handleUrlSource(
        string|array $urls,
        string       $storagePath,
        string       $disk,
        array        $options,
        array        $customRules,
    ): UploadResult|array {
        if (!is_array($urls)) {
            return $this->processSingleUrl($urls, $storagePath, $disk, $options, $customRules);
        }

        $results = [];

        foreach ($urls as $url) {
            try {
                $results[] = $this->processSingleUrl($url, $storagePath, $disk, $options, $customRules);
            } catch (\Exception $e) {
                Log::error("URL upload failed [{$url}]: " . $e->getMessage());
                $results[] = ['status' => false, 'error' => $e->getMessage(), 'url' => $url];
            }
        }

        return $results;
    }

    /**
     * Downloads a single URL and stores the result.
     *
     * @param string $url
     * @param string $storagePath
     * @param string $disk
     * @param array  $options
     * @param array  $customRules
     * @return UploadResult
     */
    private function processSingleUrl(
        string $url,
        string $storagePath,
        string $disk,
        array  $options,
        array  $customRules,
    ): UploadResult {
        $file = null;

        try {
            $file = $this->urlDownloader->download($url, $options);

            $this->enforceQuota($file);

            $this->fileValidator->validate($file, $file->getMimeType() ?? '', 'file', $customRules);

            return $this->storageManager->store($file, $storagePath, $disk, $options);

        } finally {
            $this->cleanupTempFile($file);
        }
    }

    /**
     * Handles uploads submitted through an HTTP Request object.
     *
     * Supports three sub-cases:
     *   1. Chunked upload (via pion/laravel-chunk-upload) — returns progress JSON
     *      for incomplete chunks and a result for the final chunk.
     *   2. Multiple files under a "files" field.
     *   3. Single file under the configured field name.
     *
     * @param Request $request
     * @param string  $storagePath
     * @param string  $disk
     * @param array   $options
     * @param array   $customRules
     * @return UploadResult|array
     */
    private function handleRequestSource(
        Request $request,
        string  $storagePath,
        string  $disk,
        array   $options,
        array   $customRules,
    ): UploadResult|array {
        $fieldName = $options['field_name'] ?? 'file';

        // Chunked upload via pion/laravel-chunk-upload is optional.
        // When the package is not installed, Request-based chunked uploads fall through
        // to normal single/multi-file processing.
        if (
            class_exists('Pion\Laravel\ChunkUpload\Receiver\FileReceiver')
            && class_exists('Pion\Laravel\ChunkUpload\Handler\HandlerFactory')
        ) {
            $receiver = new \Pion\Laravel\ChunkUpload\Receiver\FileReceiver(
                $fieldName,
                $request,
                \Pion\Laravel\ChunkUpload\Handler\HandlerFactory::classFromRequest($request),
            );

            if ($receiver->isUploaded()) {
                return $this->handleChunkedReceiver($receiver, $storagePath, $disk, $options, $customRules);
            }
        }

        $files = $request->file('files') ?? $request->file($fieldName);

        if (is_array($files)) {
            return $this->processFileArray($files, $storagePath, $disk, $options, $customRules);
        }

        $file = $request->file($fieldName);

        if (!$file instanceof UploadedFile || !$file->isValid()) {
            throw new RuntimeException('The uploaded file is invalid or missing.');
        }

        $this->enforceQuota($file);

        $this->fileValidator->validate($file, $file->getMimeType() ?? '', $fieldName, $customRules);

        return $this->storageManager->store($file, $storagePath, $disk, $options);
    }

    /**
     * Processes a chunked upload receiver from pion/laravel-chunk-upload.
     *
     * Returns a JSON progress response for incomplete chunks or a typed
     * UploadResult when the final chunk completes the file.
     *
     * @param object $receiver A FileReceiver instance (type-erased to avoid hard dependency)
     * @param string $storagePath
     * @param string $disk
     * @param array  $options
     * @param array  $customRules
     * @return UploadResult|\Illuminate\Http\JsonResponse
     */
    private function handleChunkedReceiver(
        object $receiver,
        string $storagePath,
        string $disk,
        array  $options,
        array  $customRules,
    ): UploadResult|\Illuminate\Http\JsonResponse {
        $save = $receiver->receive();

        if ($save->isFinished()) {
            $file = $save->getFile();
            $this->enforceQuota($file);
            $this->fileValidator->validate($file, $file->getMimeType() ?? '', 'file', $customRules);
            return $this->storageManager->store($file, $storagePath, $disk, $options);
        }

        return response()->json([
            'done'   => $save->handler()->getPercentageDone(),
            'status' => true,
        ]);
    }

    /**
     * Handles a direct UploadedFile or array of UploadedFiles passed by the caller.
     *
     * @param mixed  $source
     * @param string $storagePath
     * @param string $disk
     * @param array  $options
     * @param array  $customRules
     * @return UploadResult|array
     */
    private function handleDirectSource(
        mixed  $source,
        string $storagePath,
        string $disk,
        array  $options,
        array  $customRules,
    ): UploadResult|array {
        if (is_array($source)) {
            return $this->processFileArray($source, $storagePath, $disk, $options, $customRules);
        }

        if (!$source instanceof UploadedFile || !$source->isValid()) {
            throw new RuntimeException('Invalid file: expected a valid UploadedFile instance.');
        }

        $this->enforceQuota($source);

        $this->fileValidator->validate($source, $source->getMimeType() ?? '', 'file', $customRules);

        return $this->storageManager->store($source, $storagePath, $disk, $options);
    }

    /**
     * Processes an array of files, collecting results and continuing on partial failures.
     *
     * Invalid or failed entries produce error arrays in the result set.
     * The batch is never aborted due to a single file failure.
     *
     * @param array  $files
     * @param string $storagePath
     * @param string $disk
     * @param array  $options
     * @param array  $customRules
     * @return array<int, UploadResult|array<string, mixed>>
     */
    private function processFileArray(
        array  $files,
        string $storagePath,
        string $disk,
        array  $options,
        array  $customRules,
    ): array {
        $results = [];

        foreach ($files as $file) {
            if (!$file instanceof UploadedFile || !$file->isValid()) {
                $name      = $file instanceof UploadedFile ? $file->getClientOriginalName() : 'unknown';
                $results[] = ['status' => false, 'error' => 'Invalid file.', 'original_name' => $name];
                continue;
            }

            try {
                $this->enforceQuota($file);
                $this->fileValidator->validate($file, $file->getMimeType() ?? '', 'file', $customRules);
                $results[] = $this->storageManager->store($file, $storagePath, $disk, $options);
            } catch (\Exception $e) {
                $results[] = [
                    'status'        => false,
                    'error'         => $e->getMessage(),
                    'original_name' => $file->getClientOriginalName(),
                ];
            }
        }

        return $results;
    }

    /**
     * Checks the quota for the authenticated user before storing a file.
     *
     * Does nothing when quota enforcement is disabled or no user is authenticated.
     *
     * @param UploadedFile $file
     * @throws \MohamedSamy902\AdvancedFileUpload\Exceptions\QuotaExceededException
     */
    private function enforceQuota(UploadedFile $file): void
    {
        // ✅ Fixed: removed extra parentheses that caused null to be treated as true
        if (!config('file-upload.quota.enabled', false) || !Auth::check()) {
            return;
        }

        $this->quotaManager->check((int) Auth::id(), (int) $file->getSize());
    }

    /**
     * Verifies that the required driver package is installed for cloud disks.
     *
     * Throws a RuntimeException with an actionable install command when the
     * adapter class is absent, rather than letting PHP throw a cryptic error.
     *
     * @param string $disk
     * @throws RuntimeException
     */
    protected function assertCloudDependenciesInstalled(string $disk): void
    {
        $requirements = [
            's3'  => [
                'class'   => 'League\Flysystem\AwsS3V3\AwsS3V3Adapter',
                'package' => 'league/flysystem-aws-s3-v3',
            ],
            'gcs' => [
                'class'   => 'Spatie\LaravelGoogleCloudStorage\GoogleCloudStorageAdapter',
                'package' => 'spatie/laravel-google-cloud-storage',
            ],
        ];

        if (!isset($requirements[$disk])) {
            return;
        }

        if (!class_exists($requirements[$disk]['class'])) {
            $pkg = $requirements[$disk]['package'];
            throw new RuntimeException(
                "Storage disk [{$disk}] requires the [{$pkg}] package. "
                . "Install it with: composer require {$pkg}"
            );
        }
    }

    /**
     * Removes a temp file left by a URL download, ignoring errors.
     *
     * @param UploadedFile|null $file
     */
    private function cleanupTempFile(?UploadedFile $file): void
    {
        if ($file === null) {
            return;
        }

        $path = $file->getRealPath();

        if ($path !== false && file_exists($path)) {
            @unlink($path);
        }
    }
}
