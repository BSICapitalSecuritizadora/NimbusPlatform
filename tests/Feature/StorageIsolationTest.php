<?php

use App\Services\DocumentStorageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Spatie\SimpleExcel\SimpleExcelReader;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Symfony\Component\Finder\Finder;

/**
 * A suíte já gravou de verdade em `storage/app`: planilhas de importação,
 * documentos enviados, e um teste de limpeza que apagava o diretório de uploads
 * temporários da aplicação. O isolamento vive em `Tests\TestCase`; estes testes
 * existem para que ele não volte a ser desfeito em silêncio -- nem por
 * configuração, nem por um teste novo que monte o próprio disco.
 */

/**
 * Censo do diretório real de arquivos da aplicação: caminho, tamanho e
 * permissão de cada arquivo. Permissão entra no censo porque o estrago
 * histórico não foi só de conteúdo -- diretórios criados pelo disco privado
 * nascem 0700 e o php-fpm do container deixa de conseguir lê-los.
 *
 * @return array<string, string>
 */
function realStorageCensus(): array
{
    $root = storage_path('app');

    if (! is_dir($root)) {
        return [];
    }

    $census = [];

    foreach (Finder::create()->in($root)->ignoreDotFiles(false) as $entry) {
        $census[$entry->getRelativePathname()] = sprintf(
            '%s:%o:%d',
            $entry->isDir() ? 'dir' : 'file',
            $entry->getPerms() & 0o777,
            $entry->isDir() ? 0 : $entry->getSize(),
        );
    }

    ksort($census);

    return $census;
}

it('roots every isolated disk outside the application storage', function (string $disk) {
    $root = rtrim(Storage::disk($disk)->path(''), '/\\');

    expect($root)->toStartWith(storage_path('framework/testing/disks'))
        ->and($root)->not->toStartWith(storage_path('app'));
})->with(['local', 'public', 'resumes', 'tmp-for-tests']);

it('resolves the disks the application actually asks for to isolated roots', function () {
    $disks = [
        DocumentStorageService::privateDisk(),
        FileUploadConfiguration::disk(),
        config('filesystems.default'),
    ];

    foreach ($disks as $disk) {
        expect(rtrim(Storage::disk($disk)->path(''), '/\\'))
            ->not->toStartWith(storage_path('app'));
    }
});

/**
 * O isolamento não pode ter sido obtido reescrevendo a configuração: é ela que
 * descreve produção, e um teste que a altera globalmente esconderia uma
 * regressão de deploy em vez de prevenir uma de suíte.
 */
it('leaves the production filesystem configuration untouched', function () {
    expect(config('filesystems.disks.local.root'))->toBe(storage_path('app/private'))
        ->and(config('filesystems.disks.public.root'))->toBe(storage_path('app/public'))
        ->and(config('filesystems.default'))->toBe('local');
});

it('writes an uploaded document to the isolated disk and leaves the real one untouched', function () {
    $before = realStorageCensus();

    $storedFile = app(DocumentStorageService::class)->storePrivateFile(
        UploadedFile::fake()->create('contrato-social.pdf', 32, 'application/pdf'),
        'submissions/1',
    );

    Storage::disk(DocumentStorageService::privateDisk())->assertExists($storedFile['path']);

    expect($storedFile['path'])->toStartWith(DocumentStorageService::PRIVATE_PREFIX.'/')
        ->and(realStorageCensus())->toBe($before);
});

it('round-trips a spreadsheet through the isolated disk without touching the real one', function () {
    $before = realStorageCensus();

    $path = temporaryTestFilePath('storage-isolation');

    SimpleExcelWriter::create($path)
        ->addHeader(['coluna'])
        ->addRow(['coluna' => 'valor'])
        ->close();

    // O arquivo tem de existir de verdade no disco: é assim que o parser o lê
    // durante uma importação, e é o que um fake superficial deixaria de cobrir.
    expect($path)->toBeFile()
        ->and(SimpleExcelReader::create($path)->getRows()->pluck('coluna')->all())->toBe(['valor']);

    $stored = 'imports/contracts/'.basename($path);
    Storage::disk('local')->put($stored, (string) file_get_contents($path));

    Storage::disk('local')->assertExists($stored);

    expect(realStorageCensus())->toBe($before)
        ->and(storage_path('app/private/'.$stored))->not->toBeFile();
});

/**
 * Checksum sobre o arquivo físico continua sendo checksum de conteúdo: dois
 * arquivos com as mesmas linhas têm de bater, e uma linha diferente tem de
 * mudar o hash. É o que sustenta a deduplicação de `ImportRun`.
 */
it('keeps file checksums determined by content, not by the temporary path', function () {
    $rows = [['coluna' => 'valor']];

    $first = temporaryTestFilePath('checksum-a');
    $second = temporaryTestFilePath('checksum-b');
    $different = temporaryTestFilePath('checksum-c');

    foreach ([[$first, $rows], [$second, $rows], [$different, [['coluna' => 'outro']]]] as [$path, $content]) {
        $writer = SimpleExcelWriter::create($path)->addHeader(['coluna']);

        foreach ($content as $row) {
            $writer->addRow($row);
        }

        $writer->close();
    }

    expect(sheetContentChecksum($first))->toBe(sheetContentChecksum($second))
        ->and(sheetContentChecksum($first))->not->toBe(sheetContentChecksum($different));
});

it('cleans up temporary files created through the helper', function () {
    $path = temporaryTestFilePath('discarded');
    file_put_contents($path, 'conteudo');

    expect($path)->toBeFile()
        ->and($path)->toStartWith(storage_path('framework/testing/disks'));
});

/**
 * Um teste que monta o próprio disco escapa do isolamento e da limpeza: foi
 * exatamente esse padrão que encheu `storage/framework/testing/disks` de mais
 * de doze mil diretórios órfãos. `tempnam()` tem o problema irmão -- grava num
 * diretório compartilhado por toda a máquina e não é removido.
 */
it('keeps tests from opting out of the isolated disks', function () {
    $forbidden = [
        'Storage::createLocalDriver(' => 'use os discos que o TestCase já isola',
        'Storage::persistentFake(' => 'a raiz persistente não é limpa entre execuções',
        'tempnam(' => 'use temporaryTestFilePath()',
    ];

    $offenders = [];

    foreach (testFilesUnderGuard() as $file) {
        $contents = (string) file_get_contents($file->getRealPath());

        foreach ($forbidden as $needle => $reason) {
            if (str_contains($contents, $needle)) {
                $offenders[] = $file->getRelativePathname().' → '.$needle.' ('.$reason.')';
            }
        }
    }

    expect($offenders)->toBe([]);
});

/**
 * `sys_get_temp_dir()` continua legítimo em um lugar só: o comando
 * `storage:split-public-root` move diretórios de verdade e recusa uma raiz
 * pública dentro da pasta de deploy, então o teste dele precisa de raízes reais
 * fora de `base_path()`. Ele monta e descarta as próprias.
 */
it('confines raw temporary directories to the command that needs them', function () {
    $allowed = ['SplitPublicStorageRootCommandTest.php'];

    $offenders = [];

    foreach (testFilesUnderGuard() as $file) {
        if (in_array($file->getFilename(), $allowed, true)) {
            continue;
        }

        if (str_contains((string) file_get_contents($file->getRealPath()), 'sys_get_temp_dir(')) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([]);
});

/**
 * Os testes varridos pelos guardas -- todos menos este arquivo, que cita os
 * padrões proibidos justamente para procurá-los.
 *
 * @return iterable<SplFileInfo>
 */
function testFilesUnderGuard(): iterable
{
    return Finder::create()
        ->in(base_path('tests'))
        ->name('*Test.php')
        ->notName(basename(__FILE__))
        ->files();
}

function sheetContentChecksum(string $path): string
{
    return hash('sha256', SimpleExcelReader::create($path)->getRows()->toJson());
}
