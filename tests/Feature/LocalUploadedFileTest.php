<?php

use App\Support\Uploads\LocalUploadedFile;
use Illuminate\Support\Facades\File;

/**
 * O único caminho da aplicação de um arquivo enviado para o disco local.
 *
 * Com o envio temporário num disco remoto, `getRealPath()` devolve um caminho
 * relativo: quem lia o arquivo por ele ficava cego. {@see LocalUploadedFile}
 * entrega o caminho real quando ele existe e uma cópia por stream quando não.
 */

/**
 * As cópias do helper que existem no diretório onde ele as cria. O diretório é
 * descoberto pela própria cópia: o teste não depende de onde ela fica, e a
 * suíte não toca diretório temporário cru (guarda de StorageIsolationTest).
 *
 * @return list<string>
 */
function localUploadCopiesIn(string $directory): array
{
    return glob($directory.DIRECTORY_SEPARATOR.'nimbus-upload-*') ?: [];
}

it('uses the real path when the temporary file is local, without copying it', function () {
    $file = temporaryUploadWithContent('planilha.xlsx', 'conteúdo da planilha');

    $seen = LocalUploadedFile::using($file, fn (string $path): string => $path);

    // The callback got the upload itself: there was no copy to hand over.
    expect($seen)->toBe($file->getRealPath())
        ->and(is_file($seen))->toBeTrue();
});

it('copies by stream to a local file with the lowercase extension when the disk has no local path, and deletes it afterwards', function () {
    useRemoteLikeTemporaryUploadDisk();

    $file = temporaryUploadWithContent('Planilha.XLSX', "bytes\x00da\xffplanilha");

    expect(is_file($file->getRealPath()))->toBeFalse();

    [$path, $contents] = LocalUploadedFile::using($file, fn (string $path): array => [$path, file_get_contents($path)]);

    expect($path)->toStartWith(DIRECTORY_SEPARATOR)
        ->and(basename($path))->toStartWith('nimbus-upload-')
        ->and($path)->toEndWith('.xlsx')
        ->and($contents)->toBe("bytes\x00da\xffplanilha")
        ->and(file_exists($path))->toBeFalse();
});

it('deletes the local copy even when the callback throws', function () {
    useRemoteLikeTemporaryUploadDisk();

    $file = temporaryUploadWithContent('planilha.xlsx', 'conteúdo');
    $copy = null;

    try {
        LocalUploadedFile::using($file, function (string $path) use (&$copy): never {
            $copy = $path;

            throw new RuntimeException('falha durante a leitura');
        });
    } catch (RuntimeException) {
        // The failure is the point of the test.
    }

    expect($copy)->not->toBeNull()
        ->and(file_exists((string) $copy))->toBeFalse();
});

it('computes the same sha256 on both disks without copying', function () {
    $contents = str_repeat('linha da planilha;', 4096);

    $local = LocalUploadedFile::checksum(temporaryUploadWithContent('planilha.xlsx', $contents));

    useRemoteLikeTemporaryUploadDisk();

    $remoteUpload = temporaryUploadWithContent('planilha.xlsx', $contents);
    $copyDirectory = LocalUploadedFile::using($remoteUpload, fn (string $path): string => dirname($path));
    $copiesBefore = localUploadCopiesIn($copyDirectory);

    $remote = LocalUploadedFile::checksum($remoteUpload);

    expect($local)->toBe(hash('sha256', $contents))
        ->and($remote)->toBe($local)
        ->and(localUploadCopiesIn($copyDirectory))->toBe($copiesBefore);
});

/**
 * `getRealPath()` só é caminho de verdade num disco local. Qualquer leitura de
 * arquivo enviado passa pelo helper; um uso novo fora dele traria de volta a
 * conferência cega.
 */
it('keeps every getRealPath() call in app/ inside LocalUploadedFile', function () {
    $offenders = collect(File::allFiles(app_path()))
        ->filter(fn (SplFileInfo $file): bool => $file->getExtension() === 'php')
        ->reject(fn (SplFileInfo $file): bool => $file->getRealPath() === app_path('Support/Uploads/LocalUploadedFile.php'))
        ->filter(fn (SplFileInfo $file): bool => str_contains(File::get($file->getPathname()), '->getRealPath()'))
        ->map(fn (SplFileInfo $file): string => str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()))
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});
