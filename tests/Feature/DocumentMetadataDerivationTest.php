<?php

use App\Models\Document;
use App\Services\DocumentStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * O `mime_type` persistido é reaproveitado como `Content-Type`. Enquanto ele
 * chegava por um campo `Hidden` do Filament, quem controlasse a requisição
 * Livewire podia declarar `text/html` para um arquivo que passa na validação de
 * MIME como imagem — e transformar o preview em execução de conteúdo.
 */
beforeEach(function (): void {
    Storage::fake(DocumentStorageService::privateDisk());
});

it('derives the mime type from the stored file, ignoring what the form sent', function () {
    $path = UploadedFile::fake()
        ->create('parecer.pdf', 16, 'application/pdf')
        ->store('documents', DocumentStorageService::privateDisk());

    $document = Document::factory()->create([
        'file_path' => $path,
        'file_name' => 'Parecer Jurídico.pdf',
        'mime_type' => 'text/html',
        'storage_disk' => DocumentStorageService::privateDisk(),
    ]);

    expect($document->fresh()->mime_type)->not->toBe('text/html');
});

it('derives the file size from the stored file', function () {
    $path = UploadedFile::fake()
        ->create('balanco.pdf', 24, 'application/pdf')
        ->store('documents', DocumentStorageService::privateDisk());

    $document = Document::factory()->create([
        'file_path' => $path,
        'mime_type' => 'application/pdf',
        'file_size' => 1,
        'storage_disk' => DocumentStorageService::privateDisk(),
    ]);

    $expectedSize = Storage::disk(DocumentStorageService::privateDisk())->size($path);

    expect($document->fresh()->file_size)->toBe($expectedSize);
});

/**
 * O nome exibido continua sendo o informado no upload: ele sai por
 * `HeaderUtils::makeDisposition()`, que sanitiza, e trocá-lo pelo nome de
 * armazenamento degradaria o download sem ganho de segurança.
 */
it('keeps the display name provided on upload', function () {
    $path = UploadedFile::fake()
        ->create('a1b2c3.pdf', 8, 'application/pdf')
        ->store('documents', DocumentStorageService::privateDisk());

    $document = Document::factory()->create([
        'file_path' => $path,
        'file_name' => 'Termo de Securitização.pdf',
        'storage_disk' => DocumentStorageService::privateDisk(),
    ]);

    expect($document->fresh()->file_name)->toBe('Termo de Securitização.pdf');
});

it('re-derives the metadata when the file is replaced by a new version', function () {
    $document = Document::factory()->create([
        'file_path' => UploadedFile::fake()
            ->create('v1.pdf', 8, 'application/pdf')
            ->store('documents', DocumentStorageService::privateDisk()),
        'storage_disk' => DocumentStorageService::privateDisk(),
    ]);

    $replacementPath = UploadedFile::fake()
        ->create('v2.pdf', 40, 'application/pdf')
        ->store('documents', DocumentStorageService::privateDisk());

    $document->update([
        'file_path' => $replacementPath,
        'mime_type' => 'text/html',
    ]);

    $expectedSize = Storage::disk(DocumentStorageService::privateDisk())->size($replacementPath);

    expect($document->fresh())
        ->mime_type->not->toBe('text/html')
        ->file_size->toBe($expectedSize);
});

it('falls back to a download-forcing mime type when the disk cannot be inspected', function () {
    $document = Document::factory()->create([
        'file_path' => 'documents/arquivo-que-nao-existe.pdf',
        'mime_type' => null,
        'storage_disk' => DocumentStorageService::privateDisk(),
    ]);

    expect($document->fresh()->mime_type)->toBe('application/octet-stream');
});

it('stops accepting file metadata from the Filament forms', function (string $file) {
    $contents = file_get_contents(base_path($file));

    expect($contents)
        ->not->toContain("Hidden::make('mime_type')")
        ->not->toContain("Hidden::make('file_size')");
})->with([
    'app/Filament/Resources/Documents/Schemas/DocumentForm.php',
    'app/Filament/Resources/Documents/Tables/DocumentsTable.php',
]);
