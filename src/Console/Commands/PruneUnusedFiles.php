<?php

declare(strict_types=1);

namespace MohamedSamy902\AdvancedFileUpload\Console\Commands;

use Illuminate\Console\Command;
use MohamedSamy902\AdvancedFileUpload\Models\FileUpload;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class PruneUnusedFiles extends Command
{
    protected $signature = 'file-upload:prune-unused {--days=30 : The number of days after which unused files are deleted} {--force : Force hard deletion without confirmation}';
    protected $description = 'Prune unused file uploads older than the specified number of days.';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $force = $this->option('force');
        $threshold = Carbon::now()->subDays($days);

        $query = FileUpload::unused()->where('created_at', '<', $threshold);
        $count = $query->count();

        if ($count === 0) {
            $this->info("No unused files older than {$days} days found.");
            return self::SUCCESS;
        }

        if (!$force && !$this->confirm("Found {$count} unused files older than {$days} days. Do you want to permanently delete them?")) {
            $this->info('Operation cancelled.');
            return self::SUCCESS;
        }

        $this->info("Deleting {$count} unused files...");
        
        $config = config('file-upload');
        $deleted = 0;

        $query->chunkById(100, function ($files) use (&$deleted, $config) {
            foreach ($files as $file) {
                // Delete physical file
                if (Storage::disk($file->disk)->exists($file->path)) {
                    Storage::disk($file->disk)->delete($file->path);
                }

                // Delete thumbnails if they exist in metadata
                $thumbnails = $file->metadata['thumbnails'] ?? [];
                foreach ($thumbnails as $url) {
                    $relativePath = ltrim(parse_url($url, PHP_URL_PATH), '/');
                    if (Storage::disk($file->disk)->exists($relativePath)) {
                        Storage::disk($file->disk)->delete($relativePath);
                    }
                }

                $file->forceDelete();
                $deleted++;
            }
        });

        $this->info("Successfully deleted {$deleted} files.");
        return self::SUCCESS;
    }
}
