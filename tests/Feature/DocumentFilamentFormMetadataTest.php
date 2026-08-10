<?php

use App\Filament\Resources\Documents\Pages\CreateDocument;
use App\Filament\Resources\Documents\Pages\EditDocument;
use App\Models\Document;
use App\Services\DocumentStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * O formulário deixou de enviar `mime_type` e `file_size` — eles passaram a ser
 * derivados do arquivo em disco. As páginas de criação e edição continuavam
 * lendo essas chaves do payload, e em produção o acesso à chave inexistente
 * virava `ErrorException` no `mutateFormDataBeforeCreate`: 500 na requisição
 * Livewire, que o Filament exibia como "Erro ao carregar a página".
 */
beforeEach(function (): void {
    Storage::fake(DocumentStorageService::privateDisk());
});

/**
 * @param  array<string, mixed>  $data
 * @return array<string, mixed>
 */
function mutateDocumentFormData(object $page, string $method, array $data): array
{
    $mutate = (new ReflectionClass($page))->getMethod($method);
    $mutate->setAccessible(true);

    return $mutate->invoke($page, $data);
}

it('creates a document when the form omits the derived metadata', function () {
    $path = UploadedFile::fake()
        ->create('anuncio-de-inicio.pdf', 32, 'application/pdf')
        ->store('documents', DocumentStorageService::privateDisk());

    $data = mutateDocumentFormData(new CreateDocument, 'mutateFormDataBeforeCreate', [
        'title' => 'Anúncio de Início – 2ª Série',
        'category' => 'anuncios',
        'file_path' => $path,
        'file_name' => 'Anúncio de Início 2ª Série.pdf',
        'storage_disk' => DocumentStorageService::privateDisk(),
        'is_published' => true,
    ]);

    $document = Document::query()->create($data)->fresh();

    expect($document->file_name)->toBe('Anúncio de Início 2ª Série.pdf')
        ->and($document->mime_type)->not->toBeNull()
        ->and($document->file_size)->toBe(
            Storage::disk(DocumentStorageService::privateDisk())->size($path),
        );
});

it('falls back to the stored file name when the upload did not provide one', function () {
    $path = UploadedFile::fake()
        ->create('fato-relevante.pdf', 8, 'application/pdf')
        ->store('documents', DocumentStorageService::privateDisk());

    $data = mutateDocumentFormData(new CreateDocument, 'mutateFormDataBeforeCreate', [
        'title' => 'Fato Relevante',
        'category' => 'fatos_relevantes',
        'file_path' => $path,
        'storage_disk' => DocumentStorageService::privateDisk(),
    ]);

    expect($data['file_name'])->toBe(basename($path));
});

it('updates a document when the form omits the derived metadata', function () {
    $document = Document::factory()->create([
        'file_path' => UploadedFile::fake()
            ->create('v1.pdf', 8, 'application/pdf')
            ->store('documents', DocumentStorageService::privateDisk()),
        'file_name' => 'Versão 1.pdf',
        'storage_disk' => DocumentStorageService::privateDisk(),
    ]);

    $replacementPath = UploadedFile::fake()
        ->create('v2.pdf', 48, 'application/pdf')
        ->store('documents', DocumentStorageService::privateDisk());

    $page = new EditDocument;
    $page->record = $document;

    $data = mutateDocumentFormData($page, 'mutateFormDataBeforeSave', [
        'title' => $document->title,
        'category' => $document->category,
        'file_path' => $replacementPath,
        'file_name' => 'Versão 2.pdf',
        'storage_disk' => DocumentStorageService::privateDisk(),
    ]);

    $document->update($data);

    expect($document->fresh()->file_size)->toBe(
        Storage::disk(DocumentStorageService::privateDisk())->size($replacementPath),
    );
});

/**
 * A `FileUpload` do Filament devolve o caminho dentro de um array; sem achatar,
 * o valor chegaria ao banco como array.
 */
it('flattens the file path returned by the upload component', function () {
    $path = UploadedFile::fake()
        ->create('parecer.pdf', 8, 'application/pdf')
        ->store('documents', DocumentStorageService::privateDisk());

    $data = mutateDocumentFormData(new CreateDocument, 'mutateFormDataBeforeCreate', [
        'title' => 'Parecer',
        'category' => 'governanca',
        'file_path' => ['abc123' => $path],
        'storage_disk' => DocumentStorageService::privateDisk(),
    ]);

    expect($data['file_path'])->toBe($path);
});

it('stops reading the derived metadata from the form payload', function (string $file) {
    $contents = file_get_contents(base_path($file));

    expect($contents)
        ->not->toContain("\$data['mime_type']")
        ->not->toContain("\$data['file_size']");
})->with([
    'app/Filament/Resources/Documents/Pages/CreateDocument.php',
    'app/Filament/Resources/Documents/Pages/EditDocument.php',
]);
