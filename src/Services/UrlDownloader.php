<?php

declare(strict_types=1);

namespace MohamedSamy902\AdvancedFileUpload\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use MohamedSamy902\AdvancedFileUpload\Contracts\SsrfValidatorContract;
use RuntimeException;

/**
 * Downloads remote files to a local temporary path for subsequent processing.
 *
 * Two download strategies are available and selected via config:
 *
 *   - "chunked": loads the full response body into memory first, then writes
 *     to disk. Supports automatic retry on HTTP 429 (rate limiting).
 *
 *   - "simple": streams the response directly to disk via a file sink,
 *     which is more memory-efficient for large files.
 *
 * Both strategies validate the SSRF safety of the URL before issuing
 * any outbound HTTP request.
 */
final class UrlDownloader
{
    public function __construct(
        private readonly SsrfValidatorContract $ssrfValidator,
        private readonly FileValidator         $fileValidator,
        private readonly MimeTypeResolver      $mimeResolver,
    ) {}

    /**
     * Downloads a file from the given URL and returns it as an UploadedFile.
     *
     * The download strategy (chunked vs. simple) is determined by the
     * "url_download.chunked" config value.
     *
     * @param string $url     The remote URL to download from
     * @param array  $options Optional per-request overrides (timeout, max_size)
     *
     * @return UploadedFile A temporary file ready for validation and storage
     *
     * @throws \MohamedSamy902\AdvancedFileUpload\Exceptions\SsrfException
     *         When the URL resolves to a blocked address
     * @throws RuntimeException
     *         When the download fails or the file exceeds the size limit
     */
    public function download(string $url, array $options = []): UploadedFile
    {
        $this->ssrfValidator->validate($url);

        $useChunked = (bool) config('file-upload.url_download.chunked', true);

        return $useChunked
            ? $this->downloadIntoMemory($url, $options)
            : $this->streamToDisk($url, $options);
    }

    /**
     * Downloads the remote file into memory, then writes to a temp file.
     *
     * Retries up to three times when the server responds with HTTP 429,
     * using exponential back-off (1s, 2s, 4s).
     *
     * @param string $url
     * @param array  $options
     * @return UploadedFile
     * @throws RuntimeException
     */
    private function downloadIntoMemory(string $url, array $options): UploadedFile
    {
        $timeout    = (int) ($options['timeout'] ?? config('file-upload.url_upload.timeout_seconds', 10));
        $maxBytes   = (int) ($options['max_size'] ?? config('file-upload.url_upload.max_size_bytes', 52428800));
        $maxRetries = 3;
        $response   = null;
        $tempPath   = null;

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
                    Log::info("HTTP 429 from [{$url}], retrying in {$delay}s (attempt {$attempt}/{$maxRetries}).");
                    sleep($delay);
                    continue;
                }

                break;
            }

            if (!$response || $response->failed()) {
                $status = $response ? $response->status() : 'unknown';
                throw new RuntimeException("Failed to download [{$url}]. HTTP status: {$status}.");
            }

            $mime = $this->mimeResolver->parseContentType($response->header('Content-Type', ''));
            $this->fileValidator->validateUrlMime($mime);

            $bodySize = strlen($response->body());
            if ($bodySize > $maxBytes) {
                $limitMb = round($maxBytes / 1048576, 2);
                throw new RuntimeException("Downloaded file exceeds the {$limitMb}MB limit.");
            }

            $ext          = $this->mimeResolver->toExtension($mime, $url);
            $originalName = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_FILENAME) ?: 'file';
            $tempPath     = sys_get_temp_dir() . '/' . Str::uuid() . '.' . $ext;

            if (file_put_contents($tempPath, $response->body()) === false) {
                throw new RuntimeException('Failed to write temporary file to disk.');
            }

            return new UploadedFile($tempPath, "{$originalName}.{$ext}", $mime, null, true);

        } catch (\Exception $e) {
            $this->cleanupTemp($tempPath);
            throw new RuntimeException('Download failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Streams the remote file directly to disk without loading it into memory.
     *
     * Performs a HEAD request first to check Content-Length and Content-Type
     * before committing to the full download.
     *
     * @param string $url
     * @param array  $options
     * @return UploadedFile
     * @throws RuntimeException
     */
    private function streamToDisk(string $url, array $options): UploadedFile
    {
        $timeout  = (int) ($options['timeout'] ?? config('file-upload.url_upload.timeout_seconds', 10));
        $maxBytes = (int) ($options['max_size'] ?? config('file-upload.url_upload.max_size_bytes', 52428800));
        $tempPath = sys_get_temp_dir() . '/' . Str::uuid() . '.tmp';

        try {
            $head = Http::timeout(10)->head($url);

            if ($head->failed()) {
                throw new RuntimeException("Cannot access [{$url}]. HTTP status: {$head->status()}.");
            }

            $contentLength = (int) $head->header('Content-Length');
            $mime          = $this->mimeResolver->parseContentType($head->header('Content-Type', ''));

            if ($contentLength > 0 && $contentLength > $maxBytes) {
                throw new RuntimeException('File exceeds the maximum allowed download size.');
            }

            $this->fileValidator->validateUrlMime($mime);

            $response = Http::timeout($timeout)
                ->withOptions(['sink' => $tempPath])
                ->get($url);

            if ($response->failed()) {
                throw new RuntimeException("Failed to download [{$url}]. HTTP status: {$response->status()}.");
            }

            $actualSize = filesize($tempPath);
            if ($actualSize > $maxBytes) {
                throw new RuntimeException('Downloaded file exceeds the maximum allowed size.');
            }

            $ext          = $this->mimeResolver->toExtension($mime, $url);
            $originalName = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_FILENAME) ?: 'file';

            return new UploadedFile($tempPath, "{$originalName}.{$ext}", $mime, null, true);

        } catch (\Exception $e) {
            $this->cleanupTemp($tempPath);
            throw new RuntimeException('Stream download failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Removes a temporary file if it exists on disk.
     *
     * Called on failure to prevent orphaned temp files from accumulating.
     *
     * @param string|null $path The absolute path to the temp file
     */
    private function cleanupTemp(?string $path): void
    {
        if ($path !== null && file_exists($path)) {
            @unlink($path);
        }
    }
}
