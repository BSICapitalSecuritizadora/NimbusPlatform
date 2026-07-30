<?php

use App\Services\DocumentStorageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

it('keeps the private storage out of the deployment package', function () {
    $workflow = File::get(base_path('.github/workflows/main_bsicapital.yml'));

    expect($workflow)->toContain('-x "storage/app/*"')
        ->and($workflow)->toContain('Assert private storage is not packaged')
        ->and($workflow)->toContain('unzip -Z1 app.zip | grep -qE "^storage/app/.*[^/]$"')
        ->and(File::get(base_path('.github/workflows/azure-deploy.yml')))->toContain('-x "storage/app/*"');
});

it('provisions a persistent private storage root on container start', function () {
    $startupScript = File::get(base_path('startup.sh'));

    expect($startupScript)->toContain('LEGACY_PRIVATE_STORAGE_ROOT="/home/site/wwwroot/storage/app/private"')
        ->and($startupScript)->toContain('mkdir -p "$PRIVATE_STORAGE_TARGET"')
        ->and($startupScript)->toContain('cp -a -n "$LEGACY_PRIVATE_STORAGE_ROOT/." "$PRIVATE_STORAGE_TARGET/"');
});

it('refuses a non absolute private storage root on container start', function () {
    $startupScript = File::get(base_path('startup.sh'));

    expect($startupScript)->toContain('não é um caminho absoluto e será ignorado')
        ->and($startupScript)->toContain('PRIVATE_STORAGE_TARGET="${PRIVATE_STORAGE_ROOT%/}"');
});

it('documents the private storage variables for production', function () {
    $productionEnv = File::get(base_path('.env.example.production'));

    expect($productionEnv)->toContain('PRIVATE_STORAGE_ROOT=/home/data/private')
        ->and($productionEnv)->toContain('PRIVATE_FILESYSTEM_DISK=local')
        ->and($productionEnv)->toContain('AZURE_STORAGE_PRIVATE_CONTAINER=bsi-docs-privados');
});

it('roots the private disks outside the deploy folder when configured', function () {
    $originalRoot = $_SERVER['PRIVATE_STORAGE_ROOT'] ?? null;
    $_SERVER['PRIVATE_STORAGE_ROOT'] = '/home/data/private/';

    try {
        $filesystems = require config_path('filesystems.php');
    } finally {
        if ($originalRoot === null) {
            unset($_SERVER['PRIVATE_STORAGE_ROOT']);
        } else {
            $_SERVER['PRIVATE_STORAGE_ROOT'] = $originalRoot;
        }
    }

    expect($filesystems['disks']['local']['root'])->toBe('/home/data/private')
        ->and($filesystems['disks']['resumes']['root'])->toBe('/home/data/private/resumes');
});

it('falls back to the application storage path when no private root is configured', function () {
    expect(config('filesystems.disks.local.root'))->toBe(storage_path('app/private'))
        ->and(config('filesystems.disks.resumes.root'))->toBe(storage_path('app/private/resumes'));
});

it('ignores a private storage root that is not an absolute path', function (string $invalidRoot) {
    $originalRoot = $_SERVER['PRIVATE_STORAGE_ROOT'] ?? null;
    $_SERVER['PRIVATE_STORAGE_ROOT'] = $invalidRoot;

    try {
        $filesystems = require config_path('filesystems.php');
    } finally {
        if ($originalRoot === null) {
            unset($_SERVER['PRIVATE_STORAGE_ROOT']);
        } else {
            $_SERVER['PRIVATE_STORAGE_ROOT'] = $originalRoot;
        }
    }

    expect($filesystems['disks']['local']['root'])->toBe(storage_path('app/private'))
        ->and($filesystems['disks']['resumes']['root'])->toBe(storage_path('app/private/resumes'));
})->with([
    'nome de disco' => 'local',
    'caminho relativo' => 'storage/app/private',
    'vazio' => '',
]);

it('exposes an azure blob disk for the definitive private storage', function () {
    expect(config('filesystems.disks.private.driver'))->toBe('azure-storage-blob')
        ->and(config('filesystems.disks.private.container'))->toBe('bsi-docs-privados')
        ->and(config('filesystems.disks.private.visibility'))->toBe('private')
        ->and(config('filesystems.disks.private.throw'))->toBeTrue();
});

it('resolves the private disk from configuration', function () {
    expect(config('filesystems.private_disk'))->toBe(DocumentStorageService::DEFAULT_PRIVATE_DISK)
        ->and(DocumentStorageService::privateDisk())->toBe('local');

    config()->set('filesystems.private_disk', 'private');

    expect(DocumentStorageService::privateDisk())->toBe('private');
});

it('writes private documents to the configured disk', function () {
    Storage::fake('private');
    config()->set('filesystems.private_disk', 'private');

    $storedFile = app(DocumentStorageService::class)->storePrivateFile(
        UploadedFile::fake()->create('contrato.pdf', 16, 'application/pdf'),
        'submissions/7',
    );

    expect($storedFile['disk'])->toBe('private');
    Storage::disk('private')->assertExists($storedFile['path']);
    Storage::disk('local')->assertMissing($storedFile['path']);
});
