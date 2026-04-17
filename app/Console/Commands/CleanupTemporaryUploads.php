<?php

namespace App\Console\Commands;

use App\Services\DocumentStorageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CleanupTemporaryUploads extends Command
{
    protected $signature = 'app:cleanup-temporary-uploads';

    protected $description = 'Delete staged upload files older than 24 hours from the temporary holding directory.';

    public function handle(): int
    {
        $disk = Storage::disk(DocumentStorageService::PRIVATE_DISK);
        $tmpDirectory = DocumentStorageService::PRIVATE_PREFIX.'/'.DocumentStorageService::TMP_DIRECTORY;
        $cutoff = now()->subHours(24)->timestamp;
        $deleted = 0;

        foreach ($disk->files($tmpDirectory) as $filePath) {
            if ($disk->lastModified($filePath) < $cutoff) {
                $disk->delete($filePath);
                $deleted++;
            }
        }

        $this->info("Deleted {$deleted} stale temporary upload(s) from {$tmpDirectory}.");

        return self::SUCCESS;
    }
}
