<?php

use App\DTOs\Documents\DocumentBatchDefaults;
use App\DTOs\Documents\DocumentBatchItem;
use App\Enums\DocumentBatchItemStatus;
use App\Exceptions\UploadScanUnavailableException;
use App\Filament\Resources\Documents\DocumentResource;
use App\Filament\Resources\Documents\Pages\BatchCreateDocuments;
use App\Filament\Resources\Documents\Pages\CreateDocument;
use App\Filament\Resources\Documents\Pages\ListDocuments;
use App\Models\Document;
use App\Models\Emission;
use App\Models\User;
use App\Services\Documents\DocumentBatchCreator;
use App\Services\Documents\DocumentBatchLimits;
use App\Services\DocumentStorageService;
use App\Services\LegalInstruments\ExistingDocumentScanner;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Action;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Mime\MimeTypes;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake(FileUploadConfiguration::disk());
    Storage::fake(Document::defaultStorageDisk());

    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * Arquivo temporário do Livewire, no mesmo formato que o `FileUpload` do
 * Filament entrega ao componente quando `storeFiles(false)` está ativo.
 */
function documentBatchUpload(string $filename, string $contents = '%PDF-1.4 conteudo'): TemporaryUploadedFile
{
    $storedPath = FileUploadConfiguration::storeTemporaryFile(
        UploadedFile::fake()->createWithContent($filename, $contents),
        FileUploadConfiguration::disk(),
    );

    return TemporaryUploadedFile::createFromLivewire(basename($storedPath));
}

/**
 * @param  array<string, TemporaryUploadedFile>  $files
 * @return array<int, DocumentBatchItem>
 */
function documentBatchItems(array $files, array $titles = []): array
{
    $items = [];

    foreach ($files as $key => $file) {
        $items[] = new DocumentBatchItem(
            key: (string) $key,
            file: $file,
            title: $titles[$key] ?? DocumentBatchItem::titleFromFileName($file->getClientOriginalName()),
        );
    }

    return $items;
}

function documentBatchUserWithPermissions(string ...$permissions): User
{
    $user = User::factory()->withTwoFactor()->create();

    foreach ($permissions as $permission) {
        $user->givePermissionTo($permission);
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->fresh();
}

it('offers the batch registration action on the documents list', function () {
    $this->actingAs(makeAdminUser());

    $headerActions = new ReflectionMethod(ListDocuments::class, 'getHeaderActions');
    $headerActions->setAccessible(true);

    $batchAction = collect($headerActions->invoke(new ListDocuments))
        ->first(fn (mixed $action): bool => $action instanceof Action && $action->getName() === 'batch_create');

    expect($batchAction)->not->toBeNull()
        ->and($batchAction->getLabel())->toBe('Cadastrar documentos em lote')
        ->and($batchAction->getUrl())->toBe(DocumentResource::getUrl('batch'));
});

it('gates the batch page behind the same permission used to create a single document', function () {
    $reader = documentBatchUserWithPermissions('documents.view');

    $this->actingAs($reader);

    expect(DocumentResource::canCreate())->toBeFalse()
        ->and(BatchCreateDocuments::canAccess())->toBeFalse();

    $author = documentBatchUserWithPermissions('documents.view', 'documents.create');

    $this->actingAs($author);

    expect(DocumentResource::canCreate())->toBeTrue()
        ->and(BatchCreateDocuments::canAccess())->toBeTrue();
});

it('denies the batch page over http to a user without the creation permission', function () {
    $this->actingAs(documentBatchUserWithPermissions('documents.view'))
        ->get(DocumentResource::getUrl('batch'))
        ->assertForbidden();
});

it('opens the batch page for a user allowed to create documents', function () {
    $this->actingAs(documentBatchUserWithPermissions('documents.view', 'documents.create'))
        ->get(DocumentResource::getUrl('batch'))
        ->assertSuccessful()
        ->assertSee('Cadastrar documentos em lote');
});

it('creates every file of the batch linked to the shared category and emissions', function () {
    $this->actingAs(makeAdminUser());

    $emissions = Emission::factory()->count(2)->create();

    $files = [
        'a' => documentBatchUpload('Termo de Securitização.pdf', '%PDF-1.4 termo'),
        'b' => documentBatchUpload('1º Aditamento CCB.pdf', '%PDF-1.4 aditamento'),
        'c' => documentBatchUpload('Laudo de Avaliação.pdf', '%PDF-1.4 laudo'),
    ];

    $result = app(DocumentBatchCreator::class)->create(
        documentBatchItems($files),
        new DocumentBatchDefaults('documentos_operacao', $emissions->pluck('id')->all()),
    );

    $documents = Document::query()->orderBy('id')->get();

    expect($result->createdCount())->toBe(3)
        ->and($result->isPartialSuccess())->toBeFalse()
        ->and($documents)->toHaveCount(3)
        ->and($documents->pluck('title')->all())->toBe([
            'Termo de Securitização',
            '1º Aditamento CCB',
            'Laudo de Avaliação',
        ])
        ->and($documents->pluck('file_name')->all())->toBe([
            'Termo de Securitização.pdf',
            '1º Aditamento CCB.pdf',
            'Laudo de Avaliação.pdf',
        ])
        ->and($documents->pluck('category')->unique()->all())->toBe(['documentos_operacao']);

    foreach ($documents as $document) {
        expect($document->emissions->pluck('id')->all())->toBe($emissions->pluck('id')->all())
            ->and($document->storage_disk)->toBe(Document::defaultStorageDisk())
            ->and($document->mime_type)->not->toBeNull()
            ->and($document->file_size)->toBeGreaterThan(0)
            ->and(basename($document->file_path))->not->toBe($document->file_name);

        Storage::disk(Document::defaultStorageDisk())->assertExists($document->file_path);
    }
});

it('creates every batched document as a draft without publishing it anywhere', function () {
    $this->actingAs(makeAdminUser());

    app(DocumentBatchCreator::class)->create(
        documentBatchItems(['a' => documentBatchUpload('Fato Relevante.pdf')]),
        new DocumentBatchDefaults('fatos_relevantes'),
    );

    $document = Document::query()->sole();

    expect($document->is_published)->toBeFalse()
        ->and($document->is_public)->toBeFalse()
        ->and($document->published_at)->toBeNull()
        ->and($document->published_by)->toBeNull()
        ->and($document->workflow_status_label)->toBe('Rascunho')
        ->and($document->version)->toBe(1)
        ->and($document->parent_document_id)->toBeNull()
        ->and(Document::query()->published()->count())->toBe(0)
        ->and(Document::query()->visibleOnPublicSite()->count())->toBe(0);
});

it('keeps the derived title editable before the batch is confirmed', function () {
    $this->actingAs(makeAdminUser());

    $files = ['a' => documentBatchUpload('contrato-bruto.pdf')];

    app(DocumentBatchCreator::class)->create(
        documentBatchItems($files, ['a' => 'Contrato de Cessão – 3ª Série']),
        new DocumentBatchDefaults('documentos_operacao'),
    );

    expect(Document::query()->sole()->title)->toBe('Contrato de Cessão – 3ª Série')
        ->and(DocumentBatchItem::titleFromFileName('contrato-bruto.pdf'))->toBe('contrato-bruto');
});

it('rejects files whose format is not accepted, without touching the valid ones', function () {
    $this->actingAs(makeAdminUser());

    $result = app(DocumentBatchCreator::class)->create(
        documentBatchItems([
            'a' => documentBatchUpload('valido.pdf', '%PDF-1.4 ok'),
            'b' => documentBatchUpload('payload.html', '<!doctype html><body>unsafe</body>'),
        ]),
        new DocumentBatchDefaults('governanca'),
    );

    $rejected = $result->withStatus(DocumentBatchItemStatus::Rejected);

    expect($result->createdCount())->toBe(1)
        ->and($result->isPartialSuccess())->toBeTrue()
        ->and($rejected)->toHaveCount(1)
        ->and($rejected[0]->originalName)->toBe('payload.html')
        ->and($rejected[0]->reason)->toContain('Formato não aceito')
        ->and(Document::query()->pluck('file_name')->all())->toBe(['valido.pdf'])
        ->and(Storage::disk(Document::defaultStorageDisk())->allFiles(DocumentBatchCreator::DIRECTORY))->toHaveCount(1);
});

it('rejects files above the per file size limit of the batch', function () {
    $this->actingAs(makeAdminUser());

    $limits = app(DocumentBatchLimits::class);

    $oversizedPath = FileUploadConfiguration::storeTemporaryFile(
        UploadedFile::fake()->create('gigante.pdf', $limits->maxFileKilobytes() + 1, 'application/pdf'),
        FileUploadConfiguration::disk(),
    );

    $result = app(DocumentBatchCreator::class)->create(
        documentBatchItems(['a' => TemporaryUploadedFile::createFromLivewire(basename($oversizedPath))]),
        new DocumentBatchDefaults('governanca'),
    );

    expect($result->createdCount())->toBe(0)
        ->and($result->withStatus(DocumentBatchItemStatus::Rejected)[0]->reason)->toContain('acima do limite')
        ->and(Document::query()->count())->toBe(0)
        ->and(Storage::disk(Document::defaultStorageDisk())->allFiles(DocumentBatchCreator::DIRECTORY))->toBeEmpty();
});

it('refuses a batch above the declared count and total size limits', function () {
    $limits = app(DocumentBatchLimits::class);

    config([
        'uploads.document_batch.max_files' => 2,
        'uploads.document_batch.total_max_kb' => 1,
    ]);

    expect($limits->batchRejectionReason([]))
        ->toContain('Selecione ao menos um arquivo')
        ->and($limits->batchRejectionReason([10, 20, 30]))
        ->toContain('no máximo 2 arquivos')
        ->and($limits->batchRejectionReason([2048]))
        ->toContain('acima do limite');

    config(['uploads.document_batch.total_max_kb' => 81920]);

    expect($limits->batchRejectionReason([1024, 2048]))->toBeNull();
});

it('does not register the same file twice within one batch', function () {
    $this->actingAs(makeAdminUser());

    $result = app(DocumentBatchCreator::class)->create(
        documentBatchItems([
            'a' => documentBatchUpload('assembleia.pdf', '%PDF-1.4 mesmo conteudo'),
            'b' => documentBatchUpload('assembleia-copia.pdf', '%PDF-1.4 mesmo conteudo'),
            'c' => documentBatchUpload('outro.pdf', '%PDF-1.4 conteudo distinto'),
        ]),
        new DocumentBatchDefaults('assembleias'),
    );

    $duplicated = $result->withStatus(DocumentBatchItemStatus::Duplicated);

    expect($result->createdCount())->toBe(2)
        ->and($duplicated)->toHaveCount(1)
        ->and($duplicated[0]->originalName)->toBe('assembleia-copia.pdf')
        ->and($duplicated[0]->reason)->toContain('assembleia.pdf')
        ->and(Document::query()->count())->toBe(2)
        ->and(Storage::disk(Document::defaultStorageDisk())->allFiles(DocumentBatchCreator::DIRECTORY))->toHaveCount(2);
});

it('stores the checksum of every document it creates', function () {
    $this->actingAs(makeAdminUser());

    $contents = '%PDF-1.4 conteudo com hash';

    app(DocumentBatchCreator::class)->create(
        documentBatchItems(['a' => documentBatchUpload('com-hash.pdf', $contents)]),
        new DocumentBatchDefaults('governanca'),
    );

    expect(Document::query()->sole()->checksum)->toBe(hash('sha256', $contents));
});

it('identifies an already registered document by checksum, not by name', function () {
    $this->actingAs(makeAdminUser());

    $contents = '%PDF-1.4 mesmo arquivo';

    app(DocumentBatchCreator::class)->create(
        documentBatchItems(['first' => documentBatchUpload('ata-2025.pdf', $contents)]),
        new DocumentBatchDefaults('assembleias'),
    );

    $existing = Document::query()->sole();
    $existing->update(['title' => 'Ata da AGE de 2025']);

    // Mesmo conteúdo com outro nome: a advertência tem de aparecer assim mesmo.
    $result = app(DocumentBatchCreator::class)->create(
        documentBatchItems(['second' => documentBatchUpload('copia-renomeada.pdf', $contents)]),
        new DocumentBatchDefaults('assembleias'),
    );

    $created = $result->withStatus(DocumentBatchItemStatus::Created);

    expect($created)->toHaveCount(1)
        ->and($created[0]->duplicateWarning)->toContain('Documento idêntico já cadastrado')
        ->and($created[0]->duplicateWarning)->toContain('Ata da AGE de 2025')
        // Advertência, nunca bloqueio: o documento é cadastrado do mesmo jeito.
        ->and(Document::query()->count())->toBe(2);
});

it('does not warn about a homonym whose content is different', function () {
    $this->actingAs(makeAdminUser());

    app(DocumentBatchCreator::class)->create(
        documentBatchItems(['first' => documentBatchUpload('ata.pdf', '%PDF-1.4 primeiro conteudo')]),
        new DocumentBatchDefaults('assembleias'),
    );

    $result = app(DocumentBatchCreator::class)->create(
        documentBatchItems(['second' => documentBatchUpload('ata.pdf', '%PDF-1.4 outro conteudo')]),
        new DocumentBatchDefaults('assembleias'),
    );

    expect($result->withStatus(DocumentBatchItemStatus::Created)[0]->duplicateWarning)->toBeNull();
});

it('falls back to the name heuristic for documents registered before the checksum column', function () {
    $this->actingAs(makeAdminUser());

    $legacy = Document::factory()->create([
        'title' => 'Ata antiga sem hash',
        'file_name' => 'ata.pdf',
        'file_size' => 17,
        'checksum' => null,
    ]);

    $result = app(DocumentBatchCreator::class)->create(
        documentBatchItems(['a' => documentBatchUpload('ata.pdf', '%PDF-1.4 ata')]),
        new DocumentBatchDefaults('assembleias'),
    );

    expect($result->withStatus(DocumentBatchItemStatus::Created)[0]->duplicateWarning)
        ->toContain($legacy->title)
        ->and(Document::query()->count())->toBe(2);
});

it('backfills the checksum of documents registered before the column existed', function () {
    $this->actingAs(makeAdminUser());

    $contents = '%PDF-1.4 documento antigo';

    app(DocumentBatchCreator::class)->create(
        documentBatchItems(['a' => documentBatchUpload('antigo.pdf', $contents)]),
        new DocumentBatchDefaults('governanca'),
    );

    $document = Document::query()->sole();

    // Simula o acervo anterior à coluna.
    Document::query()->whereKey($document->id)->update(['checksum' => null]);

    $this->artisan('documents:backfill-checksums')->assertSuccessful();

    expect($document->fresh()->checksum)->toBe(hash('sha256', $contents));
});

it('keeps the valid documents when another file fails after being stored', function () {
    $this->actingAs(makeAdminUser());

    $emission = Emission::factory()->create();

    $creator = app(DocumentBatchCreator::class);

    $creator->create(
        documentBatchItems(['ok' => documentBatchUpload('valido.pdf', '%PDF-1.4 valido')]),
        new DocumentBatchDefaults('governanca', [$emission->id]),
    );

    // Série inexistente: a gravação do arquivo passa e a transação do documento
    // falha, que é o caminho em que pode sobrar arquivo órfão.
    $result = $creator->create(
        documentBatchItems(['falha' => documentBatchUpload('quebrado.pdf', '%PDF-1.4 quebrado')]),
        new DocumentBatchDefaults('governanca', [$emission->id + 9999]),
    );

    $failed = $result->withStatus(DocumentBatchItemStatus::Failed);

    expect($failed)->toHaveCount(1)
        ->and($failed[0]->originalName)->toBe('quebrado.pdf')
        ->and($failed[0]->documentId)->toBeNull()
        ->and(Document::query()->pluck('file_name')->all())->toBe(['valido.pdf'])
        ->and(Storage::disk(Document::defaultStorageDisk())->allFiles(DocumentBatchCreator::DIRECTORY))
        ->toHaveCount(1);
});

it('stops the batch and removes the orphan file when the antivirus is unavailable', function () {
    $this->actingAs(makeAdminUser());

    config([
        'uploads.clamav.enabled' => true,
        'uploads.clamav.socket' => null,
        'uploads.clamav.host' => '127.0.0.1',
        'uploads.clamav.port' => 1,
        'uploads.clamav.timeout' => 1,
    ]);

    $result = app(DocumentBatchCreator::class)->create(
        documentBatchItems([
            'a' => documentBatchUpload('primeiro.pdf', '%PDF-1.4 um'),
            'b' => documentBatchUpload('segundo.pdf', '%PDF-1.4 dois'),
            'c' => documentBatchUpload('terceiro.pdf', '%PDF-1.4 tres'),
        ]),
        new DocumentBatchDefaults('governanca'),
    );

    $failed = $result->withStatus(DocumentBatchItemStatus::Failed);
    $notProcessed = $result->withStatus(DocumentBatchItemStatus::NotProcessed);

    expect($result->createdCount())->toBe(0)
        ->and($failed)->toHaveCount(1)
        ->and($failed[0]->originalName)->toBe('primeiro.pdf')
        ->and($failed[0]->reason)->toContain('antivírus está indisponível')
        ->and($failed[0]->status->isRetryable())->toBeTrue()
        // Os demais nem chegam ao clamd: repetir o timeout arquivo a arquivo
        // estouraria o tempo da requisição sem mudar o desfecho.
        ->and($notProcessed)->toHaveCount(2)
        ->and($notProcessed[0]->reason)->toContain('antivírus está indisponível')
        ->and(Document::query()->count())->toBe(0)
        ->and(Storage::disk(Document::defaultStorageDisk())->allFiles(DocumentBatchCreator::DIRECTORY))->toBeEmpty();
});

it('still rejects an infected file individually instead of stopping the batch', function () {
    $this->actingAs(makeAdminUser());

    // A varredura desligada devolve CLEAN, então o caminho de reprovação por
    // malware é exercitado pelo tipo da exceção que o trait lança.
    $document = new Document(['file_path' => 'documents/x.pdf']);
    $reject = new ReflectionMethod($document, 'rejectUploadedFile');
    $reject->setAccessible(true);

    expect(fn () => $reject->invoke($document, 'malware_detectado', 'documents/x.pdf'))
        ->toThrow(ValidationException::class)
        ->and(fn () => $reject->invoke($document, 'malware_detectado', 'documents/x.pdf'))
        ->not->toThrow(UploadScanUnavailableException::class)
        ->and(fn () => $reject->invoke($document, 'antivirus_indisponivel', 'documents/x.pdf'))
        ->toThrow(UploadScanUnavailableException::class);
});

it('reports the files left out by the batch time budget as retryable', function () {
    $this->actingAs(makeAdminUser());

    config(['uploads.document_batch.time_budget_seconds' => 0]);

    $files = [
        'a' => documentBatchUpload('primeiro.pdf', '%PDF-1.4 um'),
        'b' => documentBatchUpload('segundo.pdf', '%PDF-1.4 dois'),
        'c' => documentBatchUpload('terceiro.pdf', '%PDF-1.4 tres'),
    ];

    $result = app(DocumentBatchCreator::class)->create(
        documentBatchItems($files),
        new DocumentBatchDefaults('governanca'),
    );

    $notProcessed = $result->withStatus(DocumentBatchItemStatus::NotProcessed);

    expect($result->createdCount())->toBe(1)
        ->and($notProcessed)->toHaveCount(2)
        ->and($notProcessed[0]->reason)->toContain('tempo limite')
        ->and($notProcessed[0]->status->isRetryable())->toBeTrue()
        ->and(Document::query()->pluck('file_name')->all())->toBe(['primeiro.pdf']);

    $retry = app(DocumentBatchCreator::class)->create(
        documentBatchItems(['b' => $files['b'], 'c' => $files['c']]),
        new DocumentBatchDefaults('governanca'),
    );

    expect($retry->createdCount())->toBe(1)
        ->and(Document::query()->count())->toBe(2);
});

it('refuses to process a batch above the total size limit from the page', function () {
    $this->actingAs(makeAdminUser());

    config(['uploads.document_batch.total_max_kb' => 1]);

    Livewire::test(BatchCreateDocuments::class)
        ->fillForm([
            'category' => 'anuncios',
            'upload' => [
                UploadedFile::fake()->createWithContent('a.pdf', '%PDF-1.4 '.str_repeat('a', 2048)),
                UploadedFile::fake()->createWithContent('b.pdf', '%PDF-1.4 '.str_repeat('b', 2048)),
            ],
        ])
        ->call('syncReviewItems')
        ->call('create');

    expect(Document::query()->count())->toBe(0)
        ->and(Storage::disk(Document::defaultStorageDisk())->allFiles(DocumentBatchCreator::DIRECTORY))->toBeEmpty();
});

it('retries only the files that failed, never the ones already registered', function () {
    $this->actingAs(makeAdminUser());

    config(['uploads.document_batch.time_budget_seconds' => 0]);

    $component = Livewire::test(BatchCreateDocuments::class)
        ->fillForm([
            'category' => 'governanca',
            'upload' => [
                UploadedFile::fake()->createWithContent('um.pdf', '%PDF-1.4 um'),
                UploadedFile::fake()->createWithContent('dois.pdf', '%PDF-1.4 dois'),
            ],
        ])
        ->call('syncReviewItems')
        ->call('create');

    expect(Document::query()->count())->toBe(1)
        ->and($component->get('createdFileKeys'))->toHaveCount(1);

    config(['uploads.document_batch.time_budget_seconds' => 300]);

    $component->call('create');

    $documents = Document::query()->orderBy('id')->get();

    expect($documents)->toHaveCount(2)
        ->and($documents->pluck('file_name')->all())->toBe(['um.pdf', 'dois.pdf'])
        ->and($component->get('outcomes'))->toHaveCount(2);
});

it('records the same activity log entries produced by the individual registration', function () {
    $this->actingAs(makeAdminUser());

    app(DocumentBatchCreator::class)->create(
        documentBatchItems(['a' => documentBatchUpload('relatorio-anual.pdf')]),
        new DocumentBatchDefaults('relatorios_anuais'),
    );

    $document = Document::query()->sole();

    $activity = Activity::query()
        ->where('subject_type', Document::class)
        ->where('subject_id', $document->id)
        ->where('description', 'created')
        ->sole();

    expect($activity->properties['attributes']['title'] ?? null)->toBe($document->title)
        ->and($activity->properties['attributes']['category'] ?? null)->toBe('relatorios_anuais')
        ->and($activity->properties['attributes']['is_published'] ?? null)->toBeFalse();
});

it('makes batched documents visible to the legal instrument scanner like individual ones', function () {
    $this->actingAs(makeAdminUser());

    $emission = Emission::factory()->create();

    app(DocumentBatchCreator::class)->create(
        documentBatchItems(['a' => documentBatchUpload('CRI XYZ_1º Aditamento CCB.pdf')]),
        new DocumentBatchDefaults(Emission::GUARANTEE_SOURCE_DOCUMENT_CATEGORY, [$emission->id]),
    );

    $suggestions = app(ExistingDocumentScanner::class)->scan($emission->fresh());

    expect($suggestions)->toHaveCount(1)
        ->and($suggestions->first()['documents']->first()['document']->title)
        ->toBe('CRI XYZ_1º Aditamento CCB');
});

it('registers a batch through the filament page and reports the outcome', function () {
    $this->actingAs(makeAdminUser());

    $emission = Emission::factory()->create();

    Livewire::test(BatchCreateDocuments::class)
        ->fillForm([
            'category' => 'anuncios',
            'emissions' => [$emission->id],
            'upload' => [
                UploadedFile::fake()->createWithContent('Anúncio de Início.pdf', '%PDF-1.4 anuncio'),
                UploadedFile::fake()->createWithContent('Comunicado ao Mercado.pdf', '%PDF-1.4 comunicado'),
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $documents = Document::query()->orderBy('id')->get();

    expect($documents)->toHaveCount(2)
        ->and($documents->pluck('title')->all())->toBe([
            'Anúncio de Início',
            'Comunicado ao Mercado',
        ])
        ->and($documents->pluck('is_published')->unique()->all())->toBe([false])
        ->and($documents->first()->emissions->pluck('id')->all())->toBe([$emission->id]);
});

it('builds the review step from the uploaded files with editable derived titles', function () {
    $this->actingAs(makeAdminUser());

    Document::factory()->create([
        'title' => 'Ata anterior',
        'file_name' => 'ata.pdf',
    ]);

    $component = Livewire::test(BatchCreateDocuments::class)
        ->fillForm([
            'category' => 'assembleias',
            'upload' => [
                UploadedFile::fake()->createWithContent('Ata da AGE.pdf', '%PDF-1.4 ata'),
                UploadedFile::fake()->createWithContent('copia.pdf', '%PDF-1.4 ata'),
                UploadedFile::fake()->createWithContent('ata.pdf', '%PDF-1.4 homonimo'),
            ],
        ])
        ->call('syncReviewItems');

    $rows = collect($component->get('data.files'))->keyBy('original_name');

    expect($rows)->toHaveCount(3)
        ->and($rows['Ata da AGE.pdf']['title'])->toBe('Ata da AGE')
        ->and($rows['Ata da AGE.pdf']['extension'])->toBe('pdf')
        ->and($rows['Ata da AGE.pdf']['size'])->toBeGreaterThan(0)
        ->and($rows['Ata da AGE.pdf']['error'])->toBeNull()
        ->and($rows['copia.pdf']['warning'])->toContain('idêntico a outro')
        ->and($rows['ata.pdf']['warning'])->toContain('Ata anterior');
});

it('shows the validation error next to the offending file in the review step', function () {
    $this->actingAs(makeAdminUser());

    config(['uploads.document_batch.max_kb' => 1]);

    $component = Livewire::test(BatchCreateDocuments::class)
        ->fillForm([
            'category' => 'anuncios',
            'upload' => [
                UploadedFile::fake()->createWithContent('pequeno.pdf', '%PDF-1.4 ok'),
                UploadedFile::fake()->createWithContent('grande.pdf', '%PDF-1.4 '.str_repeat('x', 4096)),
            ],
        ])
        ->call('syncReviewItems');

    $rows = collect($component->get('data.files'))->keyBy('original_name');

    expect($rows['pequeno.pdf']['error'])->toBeNull()
        ->and($rows['grande.pdf']['error'])->toContain('acima do limite');

    $component->call('create');

    expect(Document::query()->pluck('file_name')->all())->toBe(['pequeno.pdf']);
});

it('preserves the reviewed titles when the review step is rebuilt', function () {
    $this->actingAs(makeAdminUser());

    $component = Livewire::test(BatchCreateDocuments::class)
        ->fillForm([
            'category' => 'anuncios',
            'upload' => [UploadedFile::fake()->createWithContent('bruto.pdf', '%PDF-1.4 bruto')],
        ])
        ->call('syncReviewItems');

    $rows = $component->get('data.files');
    $rows[array_key_first($rows)]['title'] = 'Título revisado pelo usuário';

    $component
        ->set('data.files', $rows)
        ->call('syncReviewItems')
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Document::query()->sole()->title)->toBe('Título revisado pelo usuário');
});

it('renders the review step and the final summary on the page', function () {
    $this->actingAs(makeAdminUser());

    $component = Livewire::test(BatchCreateDocuments::class)
        ->fillForm([
            'category' => 'anuncios',
            'upload' => [
                UploadedFile::fake()->createWithContent('Fato Relevante 01.pdf', '%PDF-1.4 um'),
                UploadedFile::fake()->createWithContent('Fato Relevante 02.pdf', '%PDF-1.4 dois'),
            ],
        ])
        ->call('syncReviewItems')
        ->assertSee('Dados do lote')
        ->assertSee('Conferência')
        ->assertSee('Fato Relevante 01')
        ->assertSee('Fato Relevante 02')
        ->assertSee('Cadastrar documentos');

    $component
        ->call('create')
        ->assertSee('Resumo do cadastro em lote')
        ->assertSee('Cadastrado')
        ->assertSee('Ir para a listagem de documentos');

    expect(Document::query()->count())->toBe(2);
});

it('does not reprocess files that already became documents', function () {
    $this->actingAs(makeAdminUser());

    $component = Livewire::test(BatchCreateDocuments::class)
        ->fillForm([
            'category' => 'anuncios',
            'upload' => [UploadedFile::fake()->createWithContent('unico.pdf', '%PDF-1.4 unico')],
        ])
        ->call('create')
        ->call('create');

    expect(Document::query()->count())->toBe(1)
        ->and($component->get('createdFileKeys'))->toHaveCount(1);
});

it('never carries the previous checksum over to a new file', function () {
    $this->actingAs(makeAdminUser());

    app(DocumentBatchCreator::class)->create(
        documentBatchItems(['a' => documentBatchUpload('v1.pdf', '%PDF-1.4 versao um')]),
        new DocumentBatchDefaults('governanca'),
    );

    $document = Document::query()->sole();
    $firstChecksum = $document->checksum;

    // Troca do arquivo por um caminho que não existe no disco: o hash anterior
    // pertence ao arquivo anterior, então precisa sumir em vez de ficar
    // apontando para um conteúdo que o documento não tem mais.
    $document->update(['file_path' => 'documents/inexistente.pdf']);

    expect($firstChecksum)->toBe(hash('sha256', '%PDF-1.4 versao um'))
        ->and($document->fresh()->checksum)->toBeNull();
});

it('keeps the batch limits within the php limits shipped for production', function () {
    // Os limites do lote só degradam com elegância se forem os primeiros a
    // estourar. Quem estoura antes mata a requisição sem resumo e sem reenvio.
    $userIni = collect(preg_split('/\R/', File::get(public_path('.user.ini'))))
        ->mapWithKeys(function (string $line): array {
            if (! str_contains($line, '=')) {
                return [];
            }

            [$key, $value] = explode('=', $line, 2);

            return [trim($key) => trim($value)];
        });

    $toBytes = fn (string $value): int => (int) $value * match (strtoupper(substr($value, -1))) {
        'G' => 1024 ** 3,
        'M' => 1024 ** 2,
        'K' => 1024,
        default => 1,
    };

    $limits = app(DocumentBatchLimits::class);

    expect($limits->maxTotalBytes())
        ->toBeLessThan($toBytes($userIni['post_max_size']), 'O lote inteiro precisa caber no post_max_size.')
        ->and($limits->maxFileBytes())
        ->toBeLessThanOrEqual($toBytes($userIni['upload_max_filesize']), 'Cada arquivo precisa caber no upload_max_filesize.')
        ->and($limits->timeBudgetSeconds())
        ->toBeLessThan((int) $userIni['max_execution_time'], 'O orçamento de tempo precisa esgotar antes do max_execution_time.')
        // `max_file_uploads` é PHP_INI_SYSTEM: não há como elevá-lo pelo
        // .user.ini, então 20 é o teto real de arquivos por requisição.
        ->and($limits->maxFiles())->toBeLessThanOrEqual(20);
});

it('keeps the batch formats within what livewire accepts for temporary uploads', function () {
    // Primeira barreira: as regras de upload temporário do Livewire recusam a
    // extensão antes da conferência. A lista é global e escrita à mão, então um
    // formato novo em `uploads.document.allowed_mimes` seria aceito pelo lote e
    // recusado no envio — silenciosamente, e só em produção.
    $livewireRule = collect((array) config('livewire.temporary_file_upload.rules'))
        ->first(fn (mixed $rule): bool => is_string($rule) && str_starts_with($rule, 'mimes:'));

    expect($livewireRule)->not->toBeNull();

    $acceptedExtensions = explode(',', Str::after($livewireRule, 'mimes:'));
    $mimeTypes = MimeTypes::getDefault();

    foreach (app(DocumentBatchLimits::class)->allowedMimeTypes() as $mimeType) {
        expect(array_intersect($mimeTypes->getExtensions($mimeType), $acceptedExtensions))
            ->not->toBeEmpty("O tipo {$mimeType} é aceito no lote mas recusado no upload temporário do Livewire.");
    }
});

it('drops a disallowed format at upload time, before it reaches the review step', function () {
    $this->actingAs(makeAdminUser());

    $component = Livewire::test(BatchCreateDocuments::class)
        ->fillForm([
            'category' => 'governanca',
            'upload' => [
                UploadedFile::fake()->createWithContent('valido.pdf', '%PDF-1.4 ok'),
                UploadedFile::fake()->createWithContent('payload.html', '<!doctype html><body>unsafe</body>'),
            ],
        ])
        ->call('syncReviewItems');

    expect(collect($component->get('data.files'))->pluck('original_name')->all())
        ->toBe(['valido.pdf']);
});

it('keeps the individual registration flow untouched', function () {
    Storage::fake(DocumentStorageService::privateDisk());

    $this->actingAs(makeAdminUser());

    Livewire::test(CreateDocument::class)
        ->fillForm([
            'title' => 'Documento individual',
            'category' => 'governanca',
            'file_path' => UploadedFile::fake()->createWithContent('individual.pdf', '%PDF-1.4 individual'),
            'is_published' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $document = Document::query()->sole();

    expect($document->title)->toBe('Documento individual')
        ->and($document->is_published)->toBeTrue()
        ->and($document->published_at)->not->toBeNull()
        ->and($document->file_name)->toBe('individual.pdf')
        // O checksum é derivado no model, então lote e cadastro individual
        // alimentam a mesma detecção de duplicidade.
        ->and($document->checksum)->toBe(hash('sha256', '%PDF-1.4 individual'));
});
