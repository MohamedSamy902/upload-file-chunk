<?php

declare(strict_types=1);

namespace MohamedSamy902\AdvancedFileUpload\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use MohamedSamy902\AdvancedFileUpload\Models\FileUpload;
use MohamedSamy902\AdvancedFileUpload\Models\UploadSession;

/**
 * Handles the Media Manager dashboard UI.
 *
 * Routes:
 *   GET  /advanced-file-upload             → index (dashboard overview)
 *   GET  /advanced-file-upload/media        → media library
 *   GET  /advanced-file-upload/config       → config editor
 *   GET  /advanced-file-upload/sessions     → upload sessions
 *   POST /advanced-file-upload/config       → save config changes
 *   DELETE /advanced-file-upload/media/{id} → delete a file
 *   POST /advanced-file-upload/media/{id}/restore → restore soft-deleted file
 *   POST /advanced-file-upload/scan         → scan for orphaned files
 */
class MediaManagerController extends Controller
{
    /**
     * Dashboard overview with stats.
     */
    public function index(): \Illuminate\View\View
    {
        $config = config('file-upload');
        $dbEnabled = $config['database']['enabled'] ?? false;

        $stats = [
            'db_enabled'      => $dbEnabled,
            'total_files'     => 0,
            'total_size'      => 0,
            'used_files'      => 0,
            'unused_files'    => 0,
            'images'          => 0,
            'videos'          => 0,
            'documents'       => 0,
            'other'           => 0,
            'active_sessions' => 0,
            'failed_sessions' => 0,
            'disk'            => $config['storage']['disk'] ?? 'public',
            'disk_total'      => null,
            'disk_used'       => null,
        ];

        if ($dbEnabled) {
            $stats['total_files']  = FileUpload::count();
            $stats['total_size']   = FileUpload::sum('size');
            $stats['used_files']   = FileUpload::used()->count();
            $stats['unused_files'] = FileUpload::unused()->count();
            $stats['images']       = FileUpload::images()->count();
            $stats['videos']       = FileUpload::videos()->count();
            $stats['documents']    = FileUpload::documents()->count();
            $stats['other']        = FileUpload::ofType('other')->count()
                + FileUpload::ofType('audio')->count();
        }

        $stats['active_sessions'] = UploadSession::where('status', 'pending')
            ->orWhere('status', 'assembling')
            ->count();

        $stats['failed_sessions'] = UploadSession::where('status', 'failed')->count();

        return view('advanced-file-upload::dashboard.index', compact('stats', 'config'));
    }

    /**
     * Media library with filtering.
     */
    public function media(Request $request): \Illuminate\View\View
    {
        $config    = config('file-upload');
        $dbEnabled = $config['database']['enabled'] ?? false;

        $filter      = $request->input('filter', 'all'); // all | used | unused | images | videos | documents | deleted
        $disk        = $request->input('disk', $config['storage']['disk'] ?? 'public');
        $search      = $request->input('search');
        $modelFilter = $request->input('model_type');

        $query = FileUpload::withTrashed();

        match ($filter) {
            'used'      => $query->used()->withoutTrashed(),
            'unused'    => $query->unused()->withoutTrashed(),
            'images'    => $query->images()->withoutTrashed(),
            'videos'    => $query->videos()->withoutTrashed(),
            'documents' => $query->documents()->withoutTrashed(),
            'deleted'   => $query->onlyTrashed(),
            default     => $query->withoutTrashed(),
        };

        if ($disk !== 'all') {
            $query->onDisk($disk);
        }

        if ($modelFilter) {
            $query->where('model_type', $modelFilter);
        }

        if ($search) {
            $query->where('original_name', 'like', "%{$search}%");
        }

        $files = $query->latest()->paginate(24)->withQueryString();

        // Attach existence-on-disk info to each file
        $files->through(function (FileUpload $file) {
            $file->disk_exists = $file->existsOnDisk();
            return $file;
        });

        $disks = array_keys(config('filesystems.disks', []));

        $models = [];
        $stats = [];
        if ($dbEnabled) {
            $models = FileUpload::select('model_type')
                ->distinct()
                ->whereNotNull('model_type')
                ->pluck('model_type');
                
            $totalBytes = (int) (clone $query)->sum('size');
            $sizeFormatted = $totalBytes < 1024 ? "{$totalBytes} B" :
                ($totalBytes < 1048576 ? round($totalBytes / 1024, 1) . ' KB' :
                ($totalBytes < 1073741824 ? round($totalBytes / 1048576, 1) . ' MB' : 
                round($totalBytes / 1073741824, 1) . ' GB'));

            $stats = [
                'total'  => (clone $query)->count(),
                'size'   => $sizeFormatted,
                'used'   => (clone $query)->used()->count(),
                'unused' => (clone $query)->unused()->count(),
            ];
        }

        return view('advanced-file-upload::dashboard.media', compact(
            'files', 'filter', 'disk', 'search', 'disks', 'dbEnabled', 'models', 'modelFilter', 'stats'
        ));
    }

    /**
     * Config editor page.
     */
    public function config(): \Illuminate\View\View
    {
        $config = config('file-upload');
        $configPath = config_path('file-upload.php');
        $isPublished = file_exists($configPath);

        return view('advanced-file-upload::dashboard.config', compact('config', 'configPath', 'isPublished'));
    }

    /**
     * Save config changes from UI.
     *
     * Only updates env-backed values (does not write to the PHP config file
     * directly — instead writes to .env to preserve structure).
     */
    public function saveConfig(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'FILE_UPLOAD_DISK'         => 'nullable|string',
            'FILE_UPLOAD_PATH'         => 'nullable|string',
            'FILE_UPLOAD_CDN_ENABLED'  => 'nullable|boolean',
            'FILE_UPLOAD_CDN_URL'      => 'nullable|url|nullable',
            'FILE_UPLOAD_DB_ENABLED'   => 'nullable|boolean',
            'FILE_UPLOAD_QUOTA_ENABLED'=> 'nullable|boolean',
            'FILE_UPLOAD_URL_TIMEOUT'  => 'nullable|integer|min:1|max:600',
            'FILE_UPLOAD_URL_MAX_SIZE' => 'nullable|integer|min:1048576',
            'FILE_UPLOAD_IMAGE_DRIVER' => 'nullable|in:gd,imagick',
            'FILE_UPLOAD_MAX_SIZE'     => 'nullable|integer|min:1048576',
            'FILE_UPLOAD_CHUNK_SIZE'   => 'nullable|integer|min:1048576',
            'FILE_UPLOAD_PRUNE_DAYS'   => 'nullable|integer|min:1',
        ]);

        $envPath = base_path('.env');

        if (!file_exists($envPath)) {
            return back()->with('error', '.env file not found.');
        }

        $env = file_get_contents($envPath);

        foreach ($validated as $key => $value) {
            if ($value === null) {
                continue;
            }

            $value = is_bool($value) ? ($value ? 'true' : 'false') : $value;

            if (preg_match("/^{$key}=.*/m", $env)) {
                $env = preg_replace("/^{$key}=.*/m", "{$key}={$value}", $env);
            } else {
                $env .= "\n{$key}={$value}";
            }
        }

        file_put_contents($envPath, $env);

        return back()->with('success', 'Configuration saved successfully. You may need to run php artisan config:clear.');
    }

    /**
     * Upload sessions management page.
     */
    public function sessions(Request $request): \Illuminate\View\View
    {
        $status   = $request->input('status', 'all');
        $query    = UploadSession::latest();

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $sessions = $query->paginate(20)->withQueryString();

        return view('advanced-file-upload::dashboard.sessions', compact('sessions', 'status'));
    }

    /**
     * Soft-delete a file record (keeps the physical file on disk).
     */
    public function destroy(int $id): JsonResponse
    {
        $file = FileUpload::findOrFail($id);
        $file->delete();

        return response()->json(['status' => true, 'message' => 'File record deleted successfully.']);
    }

    /**
     * Hard-delete a file: removes both the DB record and the physical file.
     */
    public function forceDestroy(int $id): JsonResponse
    {
        $file = FileUpload::withTrashed()->findOrFail($id);
        $this->hardDeleteFile($file);

        return response()->json(['status' => true, 'message' => 'File permanently deleted.']);
    }

    /**
     * Bulk hard-delete files.
     */
    public function bulkForceDestroy(Request $request): JsonResponse
    {
        $ids = $request->input('ids', []);
        if (empty($ids)) {
            return response()->json(['status' => false, 'message' => 'No files selected.'], 400);
        }

        $files = FileUpload::withTrashed()->whereIn('id', $ids)->get();
        $count = 0;

        foreach ($files as $file) {
            $this->hardDeleteFile($file);
            $count++;
        }

        return response()->json(['status' => true, 'message' => "{$count} files permanently deleted."]);
    }

    /**
     * Bulk soft-delete files.
     */
    public function bulkDestroy(Request $request): JsonResponse
    {
        $ids = $request->input('ids', []);
        if (empty($ids)) {
            return response()->json(['status' => false, 'message' => 'No files selected.'], 400);
        }

        $count = FileUpload::whereIn('id', $ids)->delete();

        return response()->json(['status' => true, 'message' => "{$count} files moved to trash."]);
    }

    /**
     * Performs actual physical and DB deletion.
     */
    private function hardDeleteFile(FileUpload $file): void
    {
        // Remove physical file
        if (Storage::disk($file->disk)->exists($file->path)) {
            Storage::disk($file->disk)->delete($file->path);
        }

        // Remove thumbnails if they exist in metadata
        $thumbnails = $file->metadata['thumbnails'] ?? [];
        foreach ($thumbnails as $url) {
            $relativePath = ltrim(parse_url($url, PHP_URL_PATH), '/');
            if (Storage::disk($file->disk)->exists($relativePath)) {
                Storage::disk($file->disk)->delete($relativePath);
            }
        }

        $file->forceDelete();
    }

    /**
     * Restore a soft-deleted file record.
     */
    public function restore(int $id): JsonResponse
    {
        $file = FileUpload::onlyTrashed()->findOrFail($id);
        $file->restore();

        return response()->json(['status' => true, 'message' => 'File restored successfully.']);
    }

    /**
     * Scan the database for orphaned records (DB record exists but file is gone from disk).
     */
    public function scan(): JsonResponse
    {
        $orphaned = [];
        $checked  = 0;

        FileUpload::chunk(100, function ($files) use (&$orphaned, &$checked) {
            foreach ($files as $file) {
                $checked++;
                if (!$file->existsOnDisk()) {
                    $orphaned[] = $file->id;
                }
            }
        });

        return response()->json([
            'status'        => true,
            'checked'       => $checked,
            'orphaned_count'=> count($orphaned),
            'orphaned_ids'  => $orphaned,
        ]);
    }

    /**
     * Mark a file as used/unused.
     */
    public function markUsed(Request $request, int $id): JsonResponse
    {
        $file = FileUpload::findOrFail($id);
        $file->update(['is_used' => $request->boolean('is_used')]);

        return response()->json(['status' => true]);
    }
}
