<?php

use App\Services\DocumentStorageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * Recarrega o arquivo de configuração de discos com variáveis de ambiente
 * controladas, sem afetar a configuração já resolvida da aplicação.
 *
 * @param  array<string, string>  $variables
 * @return array<string, mixed>
 */
function reloadFilesystemsConfigWithEnv(array $variables): array
{
    $originals = [];

    foreach ($variables as $name => $value) {
        $originals[$name] = $_SERVER[$name] ?? null;
        $_SERVER[$name] = $value;
    }

    try {
        return require config_path('filesystems.php');
    } finally {
        foreach ($originals as $name => $original) {
            if ($original === null) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $original;
            }
        }
    }
}

it('keeps the uploaded files out of the deployment package', function () {
    $workflow = File::get(base_path('.github/workflows/main_bsicapital.yml'));

    expect($workflow)->toContain('-x "storage/app/*"')
        ->and($workflow)->toContain('Assert private storage is not packaged')
        ->and($workflow)->toContain('unzip -Z1 app.zip | grep -qE "^storage/app/.*[^/]$"')
        ->and(File::get(base_path('.github/workflows/azure-deploy.yml')))->toContain('-x "storage/app/*"');
});

it('keeps repository-only files out of deployment packages', function () {
    $primaryWorkflow = File::get(base_path('.github/workflows/main_bsicapital.yml'));
    $legacyWorkflow = File::get(base_path('.github/workflows/azure-deploy.yml'));

    foreach ([$primaryWorkflow, $legacyWorkflow] as $workflow) {
        expect($workflow)
            ->toContain('-x ".env*"')
            ->toContain('-x "*/.env*"')
            ->toContain('-x "App_Data/*"')
            ->toContain('-x "compose.yaml"')
            ->toContain('-x "docs/*"')
            ->toContain('-x "fix_*.php"')
            ->toContain('-x "fix_*.py"')
            ->toContain('-x "infra/*"')
            ->toContain('-x "update_*.php"')
            ->toContain('-x "NUL"')
            ->toContain('Assert repository-only files are not packaged')
            ->toContain("grep -E '(^|/)\\.env[^/]*$|^(docs|infra|App_Data)/|")
            ->toContain('^update_[^/]*\\.php$');
    }
});

it('does not keep operational spreadsheets or loose maintenance scripts', function () {
    $spreadsheetFiles = collect(File::allFiles(base_path('docs')))
        ->filter(fn (SplFileInfo $file): bool => $file->getExtension() === 'xlsx');
    $maintenanceScripts = collect([
        ...File::glob(base_path('fix_*.php')),
        ...File::glob(base_path('fix_*.py')),
        ...File::glob(base_path('update_*.php')),
    ]);
    $gitignore = File::get(base_path('.gitignore'));

    expect($spreadsheetFiles)->toBeEmpty()
        ->and($maintenanceScripts)->toBeEmpty()
        ->and(File::exists(base_path('NUL')))->toBeFalse()
        ->and($gitignore)->toContain('/docs/**/*.xlsx')
        ->and($gitignore)->toContain('/fix_*.php')
        ->and($gitignore)->toContain('/fix_*.py')
        ->and($gitignore)->toContain('/update_*.php')
        ->and($gitignore)->toContain('/NUL');
});

it('provisions both persistent storage roots on container start', function () {
    $startupScript = File::get(base_path('startup.sh'));

    expect($startupScript)->toContain('LEGACY_PRIVATE_STORAGE_ROOT="/home/site/wwwroot/storage/app/private"')
        ->and($startupScript)->toContain('LEGACY_PUBLIC_STORAGE_ROOT="/home/site/wwwroot/storage/app/public"')
        ->and($startupScript)->toContain('provision_storage_root "PRIVATE_STORAGE_ROOT" "${PRIVATE_STORAGE_ROOT:-}" "$LEGACY_PRIVATE_STORAGE_ROOT"')
        ->and($startupScript)->toContain('provision_storage_root "PUBLIC_STORAGE_ROOT" "${PUBLIC_STORAGE_ROOT:-}" "$LEGACY_PUBLIC_STORAGE_ROOT"')
        ->and($startupScript)->toContain('mkdir -p "$storage_target"')
        ->and($startupScript)->toContain('cp -a -n "$storage_legacy/." "$storage_target/"');
});

it('refuses a storage root that is not an absolute path on container start', function () {
    $startupScript = File::get(base_path('startup.sh'));

    expect($startupScript)->toContain('não é um caminho absoluto e será ignorado')
        ->and($startupScript)->toContain('storage_target="${storage_configured%/}"');
});

it('recreates the public storage symlink on every container start', function () {
    $startupScript = File::get(base_path('startup.sh'));

    expect($startupScript)->toContain('php artisan storage:link --force --no-interaction');
});

it('documents the storage variables for production', function () {
    $productionEnv = File::get(base_path('.env.example.production'));

    expect($productionEnv)->toContain('PRIVATE_STORAGE_ROOT=/home/data/private')
        ->and($productionEnv)->toContain('PUBLIC_STORAGE_ROOT=/home/data/public')
        ->and($productionEnv)->toContain('PRIVATE_FILESYSTEM_DISK=local')
        ->and($productionEnv)->toContain('AZURE_STORAGE_PRIVATE_CONTAINER=bsi-docs-privados');
});

it('roots the upload disks outside the deploy folder when configured', function () {
    $filesystems = reloadFilesystemsConfigWithEnv([
        'PRIVATE_STORAGE_ROOT' => '/home/data/private/',
        'PUBLIC_STORAGE_ROOT' => '/home/data/public/',
    ]);

    expect($filesystems['disks']['local']['root'])->toBe('/home/data/private')
        ->and($filesystems['disks']['resumes']['root'])->toBe('/home/data/private/resumes')
        ->and($filesystems['disks']['public']['root'])->toBe('/home/data/public')
        ->and($filesystems['links'][public_path('storage')])->toBe('/home/data/public');
});

it('falls back to the application storage path when no root is configured', function () {
    expect(config('filesystems.disks.local.root'))->toBe(storage_path('app/private'))
        ->and(config('filesystems.disks.resumes.root'))->toBe(storage_path('app/private/resumes'))
        ->and(config('filesystems.disks.public.root'))->toBe(storage_path('app/public'))
        ->and(config('filesystems.links')[public_path('storage')])->toBe(storage_path('app/public'));
});

it('ignores storage roots that are not absolute paths', function (string $invalidRoot) {
    $filesystems = reloadFilesystemsConfigWithEnv([
        'PRIVATE_STORAGE_ROOT' => $invalidRoot,
        'PUBLIC_STORAGE_ROOT' => $invalidRoot,
    ]);

    expect($filesystems['disks']['local']['root'])->toBe(storage_path('app/private'))
        ->and($filesystems['disks']['resumes']['root'])->toBe(storage_path('app/private/resumes'))
        ->and($filesystems['disks']['public']['root'])->toBe(storage_path('app/public'))
        ->and($filesystems['links'][public_path('storage')])->toBe(storage_path('app/public'));
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
