<?php

use App\Services\DocumentStorageService;
use Illuminate\Support\Facades\Storage;

$tmpDir = DocumentStorageService::PRIVATE_PREFIX.'/'.DocumentStorageService::TMP_DIRECTORY;

// Ensure the staging directory starts clean and is removed after each test,
// mirroring the pattern used by NimbusSubmissionStoreTest.
beforeEach(function () use ($tmpDir) {
    Storage::disk(DocumentStorageService::PRIVATE_DISK)->deleteDirectory($tmpDir);
});

afterEach(function () use ($tmpDir) {
    Storage::disk(DocumentStorageService::PRIVATE_DISK)->deleteDirectory($tmpDir);
});

it('deletes staged files older than 24 hours and preserves recent ones', function () use ($tmpDir) {
    $disk = Storage::disk(DocumentStorageService::PRIVATE_DISK);

    $staleFile = $tmpDir.'/stale-upload.pdf';
    $recentFile = $tmpDir.'/recent-upload.pdf';

    $disk->put($staleFile, 'old content');
    $disk->put($recentFile, 'fresh content');

    // Backdate the stale file to 25 hours ago so it falls outside the 24-hour grace period.
    touch($disk->path($staleFile), now()->subHours(25)->timestamp);

    $this->artisan('app:cleanup-temporary-uploads')->assertSuccessful();

    expect($disk->exists($staleFile))->toBeFalse()
        ->and($disk->exists($recentFile))->toBeTrue();
});

it('does nothing when the tmp directory is empty', function () {
    $this->artisan('app:cleanup-temporary-uploads')
        ->assertSuccessful()
        ->expectsOutputToContain('Deleted 0 stale temporary upload(s)');
});

it('reports the correct number of deleted files', function () use ($tmpDir) {
    $disk = Storage::disk(DocumentStorageService::PRIVATE_DISK);

    foreach (['a.pdf', 'b.pdf', 'c.pdf'] as $name) {
        $path = $tmpDir.'/'.$name;
        $disk->put($path, 'content');
        touch($disk->path($path), now()->subHours(25)->timestamp);
    }

    $this->artisan('app:cleanup-temporary-uploads')
        ->assertSuccessful()
        ->expectsOutputToContain('Deleted 3 stale temporary upload(s)');
});
