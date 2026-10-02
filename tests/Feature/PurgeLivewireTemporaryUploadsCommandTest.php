<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Filesystem\Filesystem as FilesystemContract;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;

/**
 * O expurgo dos envios temporários do Livewire abandonados.
 *
 * Um envio feito e nunca confirmado deixa no disco a planilha com CPF/CNPJ e o
 * `.json` com o nome original; o Livewire só limpa no envio seguinte. O comando
 * simula por padrão e só apaga com `--force`.
 */

/**
 * Grava um envio temporário (e, se pedido, o `.json` dele) com a idade dada.
 */
function abandonedUpload(FilesystemContract $disk, string $name, int $hoursOld, bool $withMetadata = true, bool $onlyMetadata = false): void
{
    $directory = FileUploadConfiguration::path();
    $timestamp = now()->subHours($hoursOld)->getTimestamp();

    if (! $onlyMetadata) {
        $disk->put($directory.'/'.$name, 'planilha com CPF');
        touch($disk->path($directory.'/'.$name), $timestamp);
    }

    if ($withMetadata || $onlyMetadata) {
        $disk->put($directory.'/'.$name.'.json', json_encode(['name' => 'carteira.xlsx']));
        touch($disk->path($directory.'/'.$name.'.json'), $timestamp);
    }
}

/**
 * Um disco que não é o temporário ativo, com raiz própria -- o resíduo antigo
 * no Blob, na produção.
 */
function residueDisk(): FilesystemAdapter
{
    $root = storage_path('framework/testing/disks/residuo-blob_test_'.ParallelTesting::token());

    if (! is_dir($root)) {
        mkdir($root, 0777, true);
    }

    $adapter = new LocalFilesystemAdapter($root);
    $disk = new FilesystemAdapter(new Filesystem($adapter), $adapter, ['root' => $root]);

    Storage::set('residuo-blob', $disk);

    return $disk;
}

it('only reports without --force and deletes nothing', function () {
    $disk = Storage::disk(FileUploadConfiguration::disk());

    abandonedUpload($disk, 'antigo.xlsx', hoursOld: 30);
    abandonedUpload($disk, 'recente.xlsx', hoursOld: 1);

    $this->artisan('uploads:purge-livewire-temporary')
        ->expectsOutputToContain('Encontrados: 2 envio(s), 2 metadado(s) .json (0 sem envio)')
        ->expectsOutputToContain('Seriam apagados: 1 envio(s), 1 metadado(s) .json (0 sem envio)')
        ->expectsOutputToContain('Simulação: nada foi apagado.')
        ->doesntExpectOutputToContain('antigo.xlsx')
        ->assertSuccessful();

    expect($disk->allFiles(FileUploadConfiguration::path()))->toHaveCount(4);
});

it('deletes the uploads and their metadata older than --hours and keeps the recent ones', function () {
    $disk = Storage::disk(FileUploadConfiguration::disk());

    abandonedUpload($disk, 'antigo.xlsx', hoursOld: 30);
    abandonedUpload($disk, 'recente.xlsx', hoursOld: 1);

    $this->artisan('uploads:purge-livewire-temporary', ['--force' => true])
        ->expectsOutputToContain('Apagados: 2 arquivo(s).')
        ->assertSuccessful();

    $directory = FileUploadConfiguration::path();

    expect($disk->allFiles($directory))->toEqualCanonicalizing([
        $directory.'/recente.xlsx',
        $directory.'/recente.xlsx.json',
    ]);
});

it('deletes an orphan metadata file too, and never one whose upload is still recent', function () {
    $disk = Storage::disk(FileUploadConfiguration::disk());
    $directory = FileUploadConfiguration::path();

    abandonedUpload($disk, 'orfao.xlsx', hoursOld: 30, onlyMetadata: true);
    abandonedUpload($disk, 'em-andamento.xlsx', hoursOld: 1);
    touch($disk->path($directory.'/em-andamento.xlsx.json'), now()->subHours(30)->getTimestamp());

    $this->artisan('uploads:purge-livewire-temporary', ['--force' => true])
        ->assertSuccessful();

    expect($disk->allFiles($directory))->toEqualCanonicalizing([
        $directory.'/em-andamento.xlsx',
        $directory.'/em-andamento.xlsx.json',
    ]);
});

it('refuses --hours=0 on the active temporary disk and accepts it on a disk that is not the active one', function () {
    $active = Storage::disk(FileUploadConfiguration::disk());
    abandonedUpload($active, 'em-uso.xlsx', hoursOld: 0);

    $this->artisan('uploads:purge-livewire-temporary', ['--hours' => 0, '--force' => true])
        ->expectsOutputToContain('--hours precisa ser de pelo menos 1 no disco temporário ativo')
        ->assertFailed();

    expect($active->allFiles(FileUploadConfiguration::path()))->toHaveCount(2);

    $residue = residueDisk();
    abandonedUpload($residue, 'residuo.xlsx', hoursOld: 0);

    $this->artisan('uploads:purge-livewire-temporary', ['--disk' => 'residuo-blob', '--hours' => 0, '--force' => true])
        ->assertSuccessful();

    expect($residue->allFiles(FileUploadConfiguration::path()))->toBe([])
        ->and($active->allFiles(FileUploadConfiguration::path()))->toHaveCount(2);
});

it('refuses a disk that does not exist', function () {
    $this->artisan('uploads:purge-livewire-temporary', ['--disk' => 'disco-inexistente'])
        ->expectsOutputToContain('O disco [disco-inexistente] não existe nesta configuração.')
        ->assertFailed();
});

it('schedules the purge every day on the active disk', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => $event->description === 'purge-livewire-temporary-uploads');

    expect($event)->not->toBeNull()
        ->and($event->command)->toContain('uploads:purge-livewire-temporary --force')
        ->and($event->expression)->toBe('30 2 * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});
