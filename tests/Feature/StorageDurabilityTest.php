<?php

use App\Services\DocumentStorageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * Recarrega o arquivo de configuração de discos com variáveis de ambiente
 * controladas, sem afetar a configuração já resolvida da aplicação.
 *
 * Com `$basePath`, a aplicação responde por aquela pasta durante a carga: é
 * como o teste reproduz o layout do App Service (/home/site/wwwroot) em
 * qualquer máquina -- no host a pasta real fica em /home, no Sail em
 * /var/www/html, e a regra do App Service depende de onde ela está. A pasta
 * real volta no fim, mesmo quando a carga lança.
 *
 * @param  array<string, string>  $variables
 * @return array<string, mixed>
 */
function reloadFilesystemsConfigWithEnv(array $variables, ?string $basePath = null): array
{
    $configFile = config_path('filesystems.php');
    $realBasePath = app()->basePath();
    $originals = [];

    foreach ($variables as $name => $value) {
        $originals[$name] = $_SERVER[$name] ?? null;
        $_SERVER[$name] = $value;
    }

    if ($basePath !== null) {
        app()->setBasePath($basePath);
    }

    try {
        return require $configFile;
    } finally {
        if ($basePath !== null) {
            app()->setBasePath($realBasePath);
        }

        foreach ($originals as $name => $original) {
            if ($original === null) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $original;
            }
        }
    }
}

/**
 * Recarrega a configuração de discos como o build do CI a carrega: sem `.env` e
 * sem `APP_ENV` no ambiente -- quando o `config/app.php` assume 'production'
 * por padrão. O `package:discover` do `composer install` roda exatamente assim.
 *
 * @param  array<string, string>  $variables
 * @return array<string, mixed>
 */
function reloadFilesystemsConfigWithoutAppEnv(array $variables): array
{
    $server = $_SERVER['APP_ENV'] ?? null;
    $environment = $_ENV['APP_ENV'] ?? null;
    $process = getenv('APP_ENV');

    unset($_SERVER['APP_ENV'], $_ENV['APP_ENV']);
    putenv('APP_ENV');

    try {
        return reloadFilesystemsConfigWithEnv($variables);
    } finally {
        if ($server !== null) {
            $_SERVER['APP_ENV'] = $server;
        }

        if ($environment !== null) {
            $_ENV['APP_ENV'] = $environment;
        }

        if ($process !== false) {
            putenv("APP_ENV={$process}");
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
            ->toContain('Assert repository-only and conflicting files are not packaged')
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

/**
 * O `optimize` de um boot anterior deixa a configuração em cache em
 * bootstrap/cache/config.php. Se ela sobra -- reinício sem deploy, ou um deploy
 * que não apaga o que o pacote não traz --, o `migrate` lê o cache e não avalia
 * config/filesystems.php: a guarda da raiz privada só falharia no `optimize`,
 * com as migrations da versão nova já aplicadas. Limpando o cache logo antes, a
 * guarda vale antes de qualquer migração. A linha não leva `|| true`: quando a
 * guarda recusa, é o próprio `config:clear` que falha e para o startup.
 */
it('clears a configuration cached by a previous boot right before migrating', function () {
    $startupLines = array_map(fn (string $line): string => trim($line), preg_split('/\R/', File::get(base_path('startup.sh'))));
    $artisanCommands = array_values(array_filter($startupLines, fn (string $line): bool => str_starts_with($line, 'php artisan ')));
    $changeDirectory = array_search('cd /home/site/wwwroot', $startupLines, true);
    $clearCachedConfiguration = array_search('php artisan config:clear', $startupLines, true);

    expect(array_slice($artisanCommands, 0, 3))->toBe([
        'php artisan config:clear',
        'php artisan migrate --force --isolated --no-interaction',
        'php artisan optimize',
    ])
        ->and($changeDirectory)->toBeInt()
        ->and($clearCachedConfiguration)->toBeInt()->toBeGreaterThan($changeDirectory);
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

/**
 * O disco dos envios temporários do Livewire guarda planilhas com CPF/CNPJ e é
 * lido pelas conferências das importações: na produção ele é `local` (raiz
 * privada, fora do wwwroot), nunca um disco remoto. No `.env.example` a linha fica
 * comentada -- a esteira copia esse arquivo, e um valor ativo esconderia se o
 * padrão do `config/livewire.php` ainda vale.
 */
it('pins the livewire temporary upload disk to local in production and leaves it commented in the example', function () {
    $productionEnv = File::get(base_path('.env.example.production'));
    $exampleEnv = File::get(base_path('.env.example'));

    preg_match_all('/^LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK=(.*)$/m', $productionEnv, $productionValues);

    expect($productionValues[1])->toBe(['local'])
        ->and($exampleEnv)->toMatch('/^# LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK=local$/m')
        ->and($exampleEnv)->not->toMatch('/^LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK=/m');
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

/**
 * Em produção, sem raiz persistente os documentos privados iriam para dentro da
 * pasta de deploy e seriam apagados no deploy seguinte -- em silêncio, porque o
 * startup.sh só avisava. A configuração passa a falhar na carga (e, com ela, o
 * `config:clear` e o `migrate` do startup.sh, antes de qualquer migração),
 * dizendo o que configurar.
 *
 * Os casos do App Service rodam com a pasta de deploy dele (/home/site/wwwroot)
 * e com as variáveis que a plataforma injeta: lá só /home persiste entre
 * reinícios, e /home não diferencia maiúsculas de minúsculas. Os demais rodam
 * sem essas variáveis, para que a máquina do teste não decida o resultado.
 */
it('refuses to boot production without a persistent private storage root', function (string $case) {
    [$root, $message, $appService] = match ($case) {
        'empty' => ['', 'PRIVATE_STORAGE_ROOT não está definida', []],
        'relative path' => ['storage/app/private', "PRIVATE_STORAGE_ROOT='storage/app/private' não é um caminho absoluto", []],
        'inside the deploy folder' => [base_path('storage/app/private'), 'se sobrepõe à pasta de deploy', []],
        'the deploy folder itself' => [base_path(), 'se sobrepõe à pasta de deploy', []],
        'containing the deploy folder' => [dirname(base_path()), 'se sobrepõe à pasta de deploy', []],
        'space at the end' => ['/home/data/private ', "PRIVATE_STORAGE_ROOT='/home/data/private ' começa ou termina com espaço", []],
        'space at the start' => [' /home/data/private', "PRIVATE_STORAGE_ROOT=' /home/data/private' começa ou termina com espaço", []],
        'parent folder segment' => ['/home/data/../private', "PRIVATE_STORAGE_ROOT='/home/data/../private' não é um caminho direto", []],
        'doubled slash' => ['/home//data/private', "PRIVATE_STORAGE_ROOT='/home//data/private' não é um caminho direto", []],
        'outside /home on App Service' => ['/data/private', "PRIVATE_STORAGE_ROOT='/data/private' fica fora de /home", ['WEBSITE_SITE_NAME' => 'bsicapital']],
        'temporary folder on App Service' => ['/tmp/private', "PRIVATE_STORAGE_ROOT='/tmp/private' fica fora de /home", ['WEBSITE_INSTANCE_ID' => '0123456789abcdef']],
        'sibling of /home on App Service' => ['/homedata/private', "PRIVATE_STORAGE_ROOT='/homedata/private' fica fora de /home", ['WEBSITE_SITE_NAME' => 'bsicapital']],
        'deploy folder in another case on App Service' => ['/home/site/WWWROOT/storage/app/private', 'se sobrepõe à pasta de deploy', ['WEBSITE_SITE_NAME' => 'bsicapital']],
        'deploy folder parent in another case on App Service' => ['/home/Site', 'se sobrepõe à pasta de deploy', ['WEBSITE_INSTANCE_ID' => '0123456789abcdef']],
    };

    $environment = [
        'APP_ENV' => 'production',
        'PRIVATE_STORAGE_ROOT' => $root,
        'WEBSITE_INSTANCE_ID' => '',
        'WEBSITE_SITE_NAME' => '',
        ...$appService,
    ];

    expect(fn () => reloadFilesystemsConfigWithEnv($environment, $appService === [] ? null : '/home/site/wwwroot'))
        ->toThrow(RuntimeException::class, $message);
})->with([
    'empty',
    'relative path',
    'inside the deploy folder',
    'the deploy folder itself',
    'containing the deploy folder',
    'space at the end',
    'space at the start',
    'parent folder segment',
    'doubled slash',
    'outside /home on App Service',
    'temporary folder on App Service',
    'sibling of /home on App Service',
    'deploy folder in another case on App Service',
    'deploy folder parent in another case on App Service',
]);

it('boots production with a persistent private storage root outside the deploy folder', function () {
    $filesystems = reloadFilesystemsConfigWithEnv([
        'APP_ENV' => 'production',
        'PRIVATE_STORAGE_ROOT' => '/home/data/private/',
        'PUBLIC_STORAGE_ROOT' => '/home/data/public',
    ]);

    expect($filesystems['disks']['local']['root'])->toBe('/home/data/private')
        ->and($filesystems['disks']['resumes']['root'])->toBe('/home/data/private/resumes')
        ->and($filesystems['disks']['public']['root'])->toBe('/home/data/public');
});

/**
 * As regras do App Service não podem recusar o que é persistente lá: um
 * diretório em /home fora da pasta de deploy, inclusive um vizinho que só
 * compartilha o começo do nome dela.
 */
it('boots production on App Service with a private root under /home outside the deploy folder', function (string $root, string $expectedRoot) {
    $filesystems = reloadFilesystemsConfigWithEnv([
        'APP_ENV' => 'production',
        'PRIVATE_STORAGE_ROOT' => $root,
        'PUBLIC_STORAGE_ROOT' => '/home/data/public',
        'WEBSITE_INSTANCE_ID' => '0123456789abcdef',
        'WEBSITE_SITE_NAME' => 'bsicapital',
    ], '/home/site/wwwroot');

    expect($filesystems['disks']['local']['root'])->toBe($expectedRoot)
        ->and($filesystems['disks']['resumes']['root'])->toBe($expectedRoot.'/resumes')
        ->and($filesystems['disks']['public']['root'])->toBe('/home/data/public');
})->with([
    'data folder' => ['/home/data/private', '/home/data/private'],
    'with a trailing slash' => ['/home/data/private/', '/home/data/private'],
    'sibling that only shares the name prefix' => ['/home/site/wwwroot-dados/private', '/home/site/wwwroot-dados/private'],
]);

/**
 * A exigência de /home é do App Service: fora dele -- sem as variáveis que a
 * plataforma injeta -- a produção continua aceitando outro diretório
 * persistente.
 */
it('keeps accepting a private root outside /home when production does not run on App Service', function () {
    $filesystems = reloadFilesystemsConfigWithEnv([
        'APP_ENV' => 'production',
        'PRIVATE_STORAGE_ROOT' => '/srv/nimbus/private',
        'WEBSITE_INSTANCE_ID' => '',
        'WEBSITE_SITE_NAME' => '',
    ]);

    expect($filesystems['disks']['local']['root'])->toBe('/srv/nimbus/private');
});

it('keeps the application fallback outside production', function (string $environment) {
    $filesystems = reloadFilesystemsConfigWithEnv([
        'APP_ENV' => $environment,
        'PRIVATE_STORAGE_ROOT' => '',
    ]);

    expect($filesystems['disks']['local']['root'])->toBe(storage_path('app/private'));
})->with(['local', 'testing', 'staging']);

it('keeps the application fallback while the CI build boots without an environment', function () {
    $environmentBefore = [$_SERVER['APP_ENV'] ?? null, $_ENV['APP_ENV'] ?? null, getenv('APP_ENV')];

    $filesystems = reloadFilesystemsConfigWithoutAppEnv(['PRIVATE_STORAGE_ROOT' => '']);

    expect($filesystems['disks']['local']['root'])->toBe(storage_path('app/private'))
        ->and([$_SERVER['APP_ENV'] ?? null, $_ENV['APP_ENV'] ?? null, getenv('APP_ENV')])->toBe($environmentBefore);
});
