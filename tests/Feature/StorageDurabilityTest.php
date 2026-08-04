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
        ->and($startupScript)->toContain('provision_storage_root "PRIVATE_STORAGE_ROOT" "${PRIVATE_STORAGE_ROOT:-}" "$EFFECTIVE_PRIVATE_STORAGE_ROOT" "$LEGACY_PRIVATE_STORAGE_ROOT"')
        ->and($startupScript)->toContain('provision_storage_root "PUBLIC_STORAGE_ROOT" "${PUBLIC_STORAGE_ROOT:-}" "$EFFECTIVE_PUBLIC_STORAGE_ROOT" "$LEGACY_PUBLIC_STORAGE_ROOT"')
        ->and($startupScript)->toContain('mkdir -p "$storage_target"')
        ->and($startupScript)->toContain('cp -a -n "$storage_legacy/." "$storage_target/"');
});

it('refuses a storage root that is not an absolute path on container start', function () {
    $startupScript = File::get(base_path('startup.sh'));

    expect($startupScript)->toContain('não é um caminho absoluto e será ignorado')
        ->and($startupScript)->toContain('/*) printf \'%s\n\' "$storage_configured" ;;');
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
        ->and($productionEnv)->toContain('AZURE_STORAGE_PRIVATE_CONTAINER=bsi-docs-privados')
        // Nome real do container no Azure — os logos das emissões são lidos
        // dele pela URL pública, então trocar o valor quebra o site.
        ->and($productionEnv)->toMatch('/^AZURE_STORAGE_CONTAINER=public$/m');
});

/**
 * O template já apontou `FILESYSTEM_DISK=s3` com todo o bloco `AWS_*`
 * comentado. Um disco padrão sem credencial não falha só no upload: o
 * /healthcheck escreve nele a cada probe, então a instância inteira é marcada
 * como não íntegra. Este teste amarra o disco escolhido às suas credenciais.
 */
it('never defaults the production filesystem to a disk without credentials', function () {
    $productionEnv = File::get(base_path('.env.example.production'));

    preg_match('/^FILESYSTEM_DISK=(.*)$/m', $productionEnv, $matches);
    $disk = trim($matches[1] ?? '');

    expect($disk)->toBeIn(['local', 'public', 'azure', 's3']);

    $requiredVariables = match ($disk) {
        'azure' => ['AZURE_STORAGE_CONNECTION_STRING', 'AZURE_STORAGE_CONTAINER'],
        's3' => ['AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_DEFAULT_REGION', 'AWS_BUCKET'],
        default => [],
    };

    // Uma variável comentada não chega ao processo: só a linha ativa conta.
    foreach ($requiredVariables as $variable) {
        expect($productionEnv)->toMatch('/^'.preg_quote($variable, '/').'=/m');
    }
});

it('keeps the production mailer aligned with the transport it configures', function () {
    $productionEnv = File::get(base_path('.env.example.production'));

    preg_match('/^MAIL_MAILER=(.*)$/m', $productionEnv, $matches);
    $mailer = trim($matches[1] ?? '');

    expect($mailer)->toBe('graph')
        ->and(config("mail.mailers.{$mailer}"))->not->toBeNull();

    foreach (['OUTLOOK_TENANT_ID', 'OUTLOOK_CLIENT_ID', 'OUTLOOK_CLIENT_SECRET', 'OUTLOOK_MAILBOX'] as $variable) {
        expect($productionEnv)->toMatch('/^'.preg_quote($variable, '/').'=/m');
    }
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

/**
 * O symlink `public/storage` aponta para a raiz pública e é servido como
 * arquivo estático. Uma raiz pública sobreposta à privada publicaria todos os
 * documentos, então ela é descartada em favor do padrão da aplicação.
 */
it('refuses a public storage root that overlaps the private one', function (string $publicRoot) {
    $filesystems = reloadFilesystemsConfigWithEnv([
        'PRIVATE_STORAGE_ROOT' => '/home/data/private',
        'PUBLIC_STORAGE_ROOT' => $publicRoot,
    ]);

    expect($filesystems['disks']['local']['root'])->toBe('/home/data/private')
        ->and($filesystems['disks']['public']['root'])->toBe(storage_path('app/public'))
        ->and($filesystems['links'][public_path('storage')])->toBe(storage_path('app/public'));
})->with([
    'mesma raiz' => '/home/data/private',
    'mesma raiz com barra final' => '/home/data/private/',
    'dentro da raiz privada' => '/home/data/private/publico',
    'contendo a raiz privada' => '/home/data',
]);

it('accepts a public storage root that only shares a name prefix', function () {
    $filesystems = reloadFilesystemsConfigWithEnv([
        'PRIVATE_STORAGE_ROOT' => '/home/data/private',
        'PUBLIC_STORAGE_ROOT' => '/home/data/private-publico',
    ]);

    expect($filesystems['disks']['public']['root'])->toBe('/home/data/private-publico');
});

it('serves the public storage location from the public root instead of the symlink', function () {
    $startupScript = File::get(base_path('startup.sh'));

    expect($startupScript)
        // "^~" impede que a location regex de PHP execute um upload .php.
        ->toContain('location ^~ /storage/ {')
        ->toContain('alias ${EFFECTIVE_PUBLIC_STORAGE_ROOT}/;')
        ->toContain('try_files \$uri =404;');
});

it('discards an overlapping public storage root on container start', function () {
    $startupScript = File::get(base_path('startup.sh'));

    expect($startupScript)
        ->toContain('storage_roots_overlap "$EFFECTIVE_PUBLIC_STORAGE_ROOT" "$EFFECTIVE_PRIVATE_STORAGE_ROOT"')
        ->toContain('se sobrepõe a PRIVATE_STORAGE_ROOT')
        ->toContain('EFFECTIVE_PUBLIC_STORAGE_ROOT="$LEGACY_PUBLIC_STORAGE_ROOT"');
});

/**
 * Uma raiz vazia viraria `alias /;` e publicaria o sistema de arquivos inteiro
 * do container em /storage/.
 */
it('aborts the container start when a resolved storage root is unusable', function () {
    $startupScript = File::get(base_path('startup.sh'));

    expect($startupScript)
        ->toContain('for storage_variable in EFFECTIVE_PRIVATE_STORAGE_ROOT EFFECTIVE_PUBLIC_STORAGE_ROOT; do')
        ->toContain('/?*) ;;')
        ->toContain('não é utilizável.');
});

it('documents that the public storage root must be disjoint from the private one', function () {
    expect(File::get(base_path('.env.example.production')))
        ->toContain('precisa ser DIFERENTE de PRIVATE_STORAGE_ROOT');
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
