<?php

declare(strict_types=1);

namespace MohamedSamy902\AdvancedFileUpload\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use MohamedSamy902\AdvancedFileUpload\Models\UploadSession;
use MohamedSamy902\AdvancedFileUpload\ValueObjects\UploadResult;
use RuntimeException;

/**
 * Manages resumable chunked file uploads.
 *
 * A resumable upload works as follows:
 *
 *   1. The client calls startSession() to register the upload intent and
 *      receives a session UUID.
 *
 *   2. The client splits the file into chunks and calls uploadChunk() for each,
 *      passing the session UUID and the zero-based chunk index.
 *
 *   3. If the connection drops, the client calls getSession() to retrieve the
 *      list of missing chunks, then re-uploads only those chunks.
 *
 *   4. Once all chunks are received, completeSession() assembles them into the
 *      final file and stores it, returning a typed UploadResult.
 *
 * Chunk temp files are stored in a dedicated sub-directory per session and
 * cleaned up automatically after a successful assembly or expiry.
 */
final class ResumableUploadService
{
    public function __construct(
        private readonly StorageManager   $storageManager,
        private readonly FileValidator    $fileValidator,
        private readonly MimeTypeResolver $mimeResolver,
    ) {}

    /**
     * Initiates a new upload session and returns its UUID.
     *
     * @param string   $originalName  The client-provided filename
     * @param string   $mimeType      The MIME type declared by the client
     * @param int      $totalSize     Expected total file size in bytes
     * @param int      $totalChunks   Total number of chunks that will be sent
     * @param string   $disk          Target storage disk
     * @param string   $folder        Target storage folder
     * @return UploadSession
     */
    public function startSession(
        string $originalName,
        string $mimeType,
        int    $totalSize,
        int    $totalChunks,
        string $disk   = 'public',
        string $folder = 'uploads',
    ): UploadSession {
        $ttlHours = (int) config('file-upload.chunked.session_ttl_hours', 24);

        $session = UploadSession::create([
            'session_id'      => Str::uuid()->toString(),
            'user_id'         => auth()->id(),
            'original_name'   => $originalName,
            'disk'            => $disk,
            'folder'          => $folder,
            'mime_type'       => $mimeType,
            'total_size'      => $totalSize,
            'total_chunks'    => $totalChunks,
            'received_chunks' => [],
            'status'          => 'pending',
            'expires_at'      => now()->addHours($ttlHours),
        ]);

        Log::info("Resumable upload session started [{$session->session_id}] — {$totalChunks} chunks expected.");

        return $session;
    }

    /**
     * Stores a single chunk for an existing upload session.
     *
     * The chunk is written to a temporary directory keyed by session UUID.
     * Already-received chunks are skipped (idempotent — safe to re-send).
     *
     * @param string       $sessionId  The session UUID from startSession()
     * @param int          $chunkIndex Zero-based index of this chunk
     * @param UploadedFile $chunk      The binary chunk data
     *
     * @return array{received: int, total: int, missing: list<int>}
     * @throws RuntimeException When the session is not found, expired, or complete
     */
    public function uploadChunk(string $sessionId, int $chunkIndex, UploadedFile $chunk): array
    {
        $session = $this->findActiveSession($sessionId);

        if ($chunkIndex < 0 || $chunkIndex >= $session->total_chunks) {
            throw new RuntimeException(
                "Chunk index [{$chunkIndex}] is out of range for session [{$sessionId}] "
                . "which expects {$session->total_chunks} chunks."
            );
        }

        $chunks = $session->received_chunks ?? [];

        if (!empty($chunks[$chunkIndex])) {
            // Chunk already stored — return current state without re-writing
            Log::info("Chunk [{$chunkIndex}] for session [{$sessionId}] already received, skipping.");
        } else {
            $this->writeChunkToDisk($sessionId, $chunkIndex, $chunk);
            $session->markChunkReceived($chunkIndex);
        }

        return [
            'received' => count(array_filter($session->received_chunks, fn ($v) => $v === true)),
            'total'    => $session->total_chunks,
            'missing'  => $session->missingChunks(),
        ];
    }

    /**
     * Assembles all received chunks into a final file and stores it.
     *
     * After a successful assembly, all temporary chunk files are removed.
     * The session status is updated to "complete".
     *
     * @param string $sessionId The session UUID from startSession()
     * @param array  $options   Per-request overrides forwarded to StorageManager
     *
     * @return UploadResult
     * @throws RuntimeException When chunks are missing or assembly fails
     */
    public function completeSession(string $sessionId, array $options = []): UploadResult
    {
        $session = $this->findActiveSession($sessionId);

        if (!$session->isComplete()) {
            $missing = $session->missingChunks();
            throw new RuntimeException(
                "Cannot complete session [{$sessionId}]: "
                . count($missing) . " chunk(s) are still missing: "
                . implode(', ', $missing)
            );
        }

        $session->status = 'assembling';
        $session->save();

        $assembledPath = null;

        try {
            $assembledPath = $this->assembleChunks($session);
            $uploadedFile  = new UploadedFile(
                $assembledPath,
                $session->original_name,
                $session->mime_type,
                null,
                true,
            );

            $this->fileValidator->validate(
                $uploadedFile,
                $session->mime_type,
                'file',
                $options['validation_rules'] ?? [],
            );

            $result = $this->storageManager->store(
                $uploadedFile,
                trim($session->folder, '/'),
                $session->disk,
                $options,
            );

            $session->status        = 'complete';
            $session->assembled_path = $result->path;
            $session->save();

            Log::info("Session [{$sessionId}] completed — file stored at [{$result->path}].");

            return $result;

        } catch (\Exception $e) {
            $session->status = 'failed';
            $session->save();

            Log::error("Session [{$sessionId}] assembly failed: " . $e->getMessage());

            throw new RuntimeException("Session assembly failed: " . $e->getMessage(), 0, $e);

        } finally {
            $this->cleanupChunkDir($sessionId);

            if ($assembledPath !== null && file_exists($assembledPath)) {
                @unlink($assembledPath);
            }
        }
    }

    /**
     * Returns the current state of an upload session.
     *
     * Clients use this after a dropped connection to determine which chunks
     * must be re-sent before calling completeSession().
     *
     * @param string $sessionId The session UUID
     * @return array{session_id: string, status: string, received: int, total: int, missing: list<int>}
     * @throws RuntimeException When the session is not found or has expired
     */
    public function getSession(string $sessionId): array
    {
        $session = $this->findActiveSession($sessionId);

        return [
            'session_id' => $session->session_id,
            'status'     => $session->status,
            'received'   => count(array_filter($session->received_chunks, fn ($v) => $v === true)),
            'total'      => $session->total_chunks,
            'missing'    => $session->missingChunks(),
        ];
    }

    /**
     * Assembles sequential chunk files into a single contiguous file.
     *
     * Reads chunks in index order from the temp directory and writes
     * them to a single temp file. Returns the path to the assembled file.
     *
     * @param UploadSession $session
     * @return string Absolute path to the assembled temp file
     * @throws RuntimeException When a chunk file is missing from disk
     */
    private function assembleChunks(UploadSession $session): string
    {
        $assembledPath = sys_get_temp_dir() . '/assembled_' . $session->session_id;
        $outputHandle  = fopen($assembledPath, 'wb');

        if ($outputHandle === false) {
            throw new RuntimeException('Failed to create assembly buffer file.');
        }

        try {
            for ($i = 0; $i < $session->total_chunks; $i++) {
                $chunkPath = $this->chunkPath($session->session_id, $i);

                if (!file_exists($chunkPath)) {
                    throw new RuntimeException("Chunk [{$i}] file is missing from disk for session [{$session->session_id}].");
                }

                $chunkHandle = fopen($chunkPath, 'rb');

                if ($chunkHandle === false) {
                    throw new RuntimeException("Failed to open chunk [{$i}] for reading.");
                }

                while (!feof($chunkHandle)) {
                    fwrite($outputHandle, fread($chunkHandle, 8192));
                }

                fclose($chunkHandle);
            }
        } finally {
            fclose($outputHandle);
        }

        return $assembledPath;
    }

    /**
     * Writes a chunk's binary data to the session's temporary chunk directory.
     *
     * @param string       $sessionId
     * @param int          $chunkIndex
     * @param UploadedFile $chunk
     */
    private function writeChunkToDisk(string $sessionId, int $chunkIndex, UploadedFile $chunk): void
    {
        $dir = $this->chunkDir($sessionId);

        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException("Failed to create chunk directory: {$dir}");
        }

        $destination = $dir . "/chunk_{$chunkIndex}";
        $sourcePath  = $chunk->getRealPath();

        if ($sourcePath === false || !file_exists($sourcePath)) {
            throw new RuntimeException("Chunk source file is not accessible for index [{$chunkIndex}].");
        }

        // Copy instead of move so the source temp file remains valid if the same
        // UploadedFile instance is reused (common in test environments).
        if (!copy($sourcePath, $destination)) {
            throw new RuntimeException("Failed to write chunk [{$chunkIndex}] to: {$destination}");
        }
    }

    /**
     * Removes the temporary directory holding all chunk files for a session.
     *
     * @param string $sessionId
     */
    private function cleanupChunkDir(string $sessionId): void
    {
        $dir = $this->chunkDir($sessionId);

        if (!is_dir($dir)) {
            return;
        }

        $files = glob($dir . '/*') ?: [];

        foreach ($files as $file) {
            @unlink($file);
        }

        @rmdir($dir);
    }

    /**
     * Returns the absolute path of the temporary directory for a session's chunks.
     *
     * @param string $sessionId
     * @return string
     */
    private function chunkDir(string $sessionId): string
    {
        return sys_get_temp_dir() . '/chunks_' . $sessionId;
    }

    /**
     * Returns the absolute path for a specific chunk file.
     *
     * @param string $sessionId
     * @param int    $chunkIndex
     * @return string
     */
    private function chunkPath(string $sessionId, int $chunkIndex): string
    {
        return $this->chunkDir($sessionId) . "/chunk_{$chunkIndex}";
    }

    /**
     * Finds an upload session by UUID and validates that it is still active.
     *
     * @param string $sessionId
     * @return UploadSession
     * @throws RuntimeException When the session is not found, expired, or already complete
     */
    private function findActiveSession(string $sessionId): UploadSession
    {
        $session = UploadSession::where('session_id', $sessionId)->first();

        if ($session === null) {
            throw new RuntimeException("Upload session [{$sessionId}] was not found.");
        }

        if (!$session->isValid()) {
            throw new RuntimeException("Upload session [{$sessionId}] has expired.");
        }

        if ($session->status === 'complete') {
            throw new RuntimeException("Upload session [{$sessionId}] is already complete.");
        }

        return $session;
    }
}
