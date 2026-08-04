<?php

use App\Models\Bank;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

/**
 * Monta duas raízes isoladas simulando produção, com o conteúdo público ainda
 * dentro da raiz privada. Ficam fora de base_path() porque o comando recusa
 * uma raiz pública dentro da pasta de deploy.
 *
 * @return array{private: string, public: string}
 */
function configureSplitStorageRoots(): array
{
    $base = sys_get_temp_dir().'/split-public-root-'.uniqid();
    $privateRoot = $base.'/private';
    $publicRoot = $base.'/public';

    File::ensureDirectoryExists($privateRoot);
    File::ensureDirectoryExists($publicRoot);

    config([
        'filesystems.disks.local.root' => $privateRoot,
        'filesystems.disks.public.root' => $publicRoot,
    ]);

    test()->beforeApplicationDestroyed(fn () => File::deleteDirectory($base));

    return ['private' => $privateRoot, 'public' => $publicRoot];
}

it('moves only the public directories out of the private root', function () {
    ['private' => $privateRoot, 'public' => $publicRoot] = configureSplitStorageRoots();

    File::ensureDirectoryExists($privateRoot.'/banks/logos');
    File::ensureDirectoryExists($privateRoot.'/measurements/receipts');
    File::ensureDirectoryExists($privateRoot.'/resumes');
    File::put($privateRoot.'/banks/logos/itau.png', 'logo');
    File::put($privateRoot.'/measurements/receipts/comprovante.pdf', 'recibo');
    File::put($privateRoot.'/resumes/curriculo.pdf', 'sigiloso');

    $this->artisan('storage:split-public-root')->assertSuccessful();

    expect(File::exists($publicRoot.'/banks/logos/itau.png'))->toBeTrue()
        ->and(File::exists($publicRoot.'/measurements/receipts/comprovante.pdf'))->toBeTrue()
        ->and(File::exists($privateRoot.'/banks/logos/itau.png'))->toBeFalse()
        // O documento privado não pode ser publicado pela migração.
        ->and(File::exists($privateRoot.'/resumes/curriculo.pdf'))->toBeTrue()
        ->and(File::exists($publicRoot.'/resumes/curriculo.pdf'))->toBeFalse();
});

it('leaves every file in place during a dry run', function () {
    ['private' => $privateRoot, 'public' => $publicRoot] = configureSplitStorageRoots();

    File::ensureDirectoryExists($privateRoot.'/banks/logos');
    File::put($privateRoot.'/banks/logos/itau.png', 'logo');

    $this->artisan('storage:split-public-root', ['--dry-run' => true])
        ->expectsOutputToContain('1 arquivo(s) seriam movidos.')
        ->assertSuccessful();

    expect(File::exists($privateRoot.'/banks/logos/itau.png'))->toBeTrue()
        ->and(File::exists($publicRoot.'/banks/logos/itau.png'))->toBeFalse();
});

it('never overwrites a file that already exists at the destination', function () {
    ['private' => $privateRoot, 'public' => $publicRoot] = configureSplitStorageRoots();

    File::ensureDirectoryExists($privateRoot.'/banks/logos');
    File::ensureDirectoryExists($publicRoot.'/banks/logos');
    File::put($privateRoot.'/banks/logos/itau.png', 'origem');
    File::put($publicRoot.'/banks/logos/itau.png', 'destino');

    $this->artisan('storage:split-public-root')->assertSuccessful();

    expect(File::get($publicRoot.'/banks/logos/itau.png'))->toBe('destino')
        ->and(File::get($privateRoot.'/banks/logos/itau.png'))->toBe('origem');
});

it('does not touch directories that exist in both trees', function () {
    ['private' => $privateRoot, 'public' => $publicRoot] = configureSplitStorageRoots();

    File::ensureDirectoryExists($privateRoot.'/documents');
    File::put($privateRoot.'/documents/aditivo.pdf', 'sigiloso');

    $this->artisan('storage:split-public-root')
        ->expectsOutputToContain('Conferência manual')
        ->assertSuccessful();

    expect(File::exists($privateRoot.'/documents/aditivo.pdf'))->toBeTrue()
        ->and(File::exists($publicRoot.'/documents/aditivo.pdf'))->toBeFalse();
});

it('reports the database paths that did not reach the public root', function () {
    ['private' => $privateRoot] = configureSplitStorageRoots();

    Bank::factory()->create(['logo_path' => 'banks/logos/ausente.png']);

    File::ensureDirectoryExists($privateRoot.'/banks/logos');
    File::put($privateRoot.'/banks/logos/itau.png', 'logo');

    $this->artisan('storage:split-public-root')
        ->expectsOutputToContain('banks/logos/ausente.png')
        ->assertSuccessful();
});

it('confirms every database path once the files are in the public root', function () {
    ['private' => $privateRoot] = configureSplitStorageRoots();

    Bank::factory()->create(['logo_path' => 'banks/logos/itau.png']);

    File::ensureDirectoryExists($privateRoot.'/banks/logos');
    File::put($privateRoot.'/banks/logos/itau.png', 'logo');

    $this->artisan('storage:split-public-root')
        ->expectsOutputToContain('Todos os caminhos registrados no banco existem na raiz pública.')
        ->assertSuccessful();
});

it('refuses to run while both roots point at the same directory', function () {
    $root = sys_get_temp_dir().'/split-public-root-same';
    File::ensureDirectoryExists($root);

    config([
        'filesystems.disks.local.root' => $root,
        'filesystems.disks.public.root' => $root,
    ]);

    $this->artisan('storage:split-public-root')
        ->expectsOutputToContain('Corrija PUBLIC_STORAGE_ROOT antes de rodar este comando.')
        ->assertFailed();

    File::deleteDirectory($root);
});

/**
 * Rodar o comando antes de corrigir a App Setting levaria os arquivos para o
 * diretório padrão, dentro do wwwroot, que o deploy seguinte substitui.
 */
it('refuses a public root inside the deploy folder', function () {
    config([
        'filesystems.disks.local.root' => sys_get_temp_dir().'/split-public-root-private',
        'filesystems.disks.public.root' => storage_path('app/public'),
    ]);

    $this->artisan('storage:split-public-root')
        ->expectsOutputToContain('seria apagada no próximo deploy')
        ->assertFailed();
});
