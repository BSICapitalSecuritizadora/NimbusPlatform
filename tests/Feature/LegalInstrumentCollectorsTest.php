<?php

use App\Enums\GuaranteeDetectionStatus;
use App\Enums\GuaranteeLegalStatus;
use App\Enums\GuaranteeType;
use App\Enums\LegalInstrumentDocumentRole;
use App\Enums\LegalInstrumentDocumentStatus;
use App\Enums\LegalInstrumentFieldKey;
use App\Enums\LegalInstrumentFieldStatus;
use App\Enums\LegalInstrumentType;
use App\Enums\MalwareScanStatus;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\LegalInstrumentsRelationManager;
use App\Filament\Resources\Emissions\Pages\EditEmission;
use App\Jobs\ProcessLegalInstrumentDocumentJob;
use App\Models\Document;
use App\Models\Emission;
use App\Models\ExtractedGuarantee;
use App\Models\ExtractedObligation;
use App\Models\Guarantee;
use App\Models\LegalInstrument;
use App\Models\LegalInstrumentDocument;
use App\Models\LegalInstrumentField;
use App\Services\GeminiService;
use App\Services\Guarantees\GuaranteeSuggestionReviewService;
use App\Services\LegalInstruments\ExistingDocumentScanner;
use App\Services\LegalInstruments\InstrumentDocumentExtractor;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

function ccbDocument(?Emission $emission = null): LegalInstrumentDocument
{
    $emission ??= Emission::factory()->create();

    $instrument = LegalInstrument::factory()
        ->ofType(LegalInstrumentType::Ccb, '001/2026')
        ->create(['emission_id' => $emission->id]);

    return LegalInstrumentDocument::factory()
        ->original('2024-01-10')
        ->create(['legal_instrument_id' => $instrument->id]);
}

it('turns detected guarantees into pending candidates linked to the instrument', function (): void {
    $instrumentDocument = ccbDocument();

    $this->mock(GeminiService::class)
        ->shouldReceive('extractFromDocumentWithPrompt')
        ->once()
        ->andReturn([
            'guarantees' => [[
                'type' => 'af_imovel',
                'event' => 'constitution',
                'name' => 'AF Imóvel — Matrícula 18.900',
                'identification' => ['registration_number' => '18.900'],
                'clause' => '3.1',
                'page' => 4,
                'excerpt' => 'Fica constituída a alienação fiduciária do imóvel matriculado sob o nº 18.900.',
                'confidence_score' => 0.9,
            ]],
        ]);

    (new ProcessLegalInstrumentDocumentJob($instrumentDocument->id))->handle(app(InstrumentDocumentExtractor::class));

    $candidate = ExtractedGuarantee::query()->sole();

    expect($candidate->status)->toBe(GuaranteeDetectionStatus::Suggested)
        ->and($candidate->legal_instrument_id)->toBe($instrumentDocument->legal_instrument_id)
        ->and($candidate->type)->toBe(GuaranteeType::RealEstateFiduciaryAlienation)
        ->and($candidate->source_page)->toBe(4)
        ->and(Guarantee::query()->count())->toBe(0);
});

it('links the confirmed guarantee to the instrument that revealed it', function (): void {
    $instrumentDocument = ccbDocument();
    $actor = makeAdminUser();

    $candidate = ExtractedGuarantee::factory()->create([
        'emission_id' => $instrumentDocument->instrument->emission_id,
        'legal_instrument_id' => $instrumentDocument->legal_instrument_id,
        'legal_instrument_document_id' => $instrumentDocument->id,
    ]);

    $guarantee = app(GuaranteeSuggestionReviewService::class)->approve($candidate, $actor);

    expect($guarantee->legal_instrument_id)->toBe($instrumentDocument->legal_instrument_id)
        ->and($instrumentDocument->instrument->fresh()->guarantees)->toHaveCount(1);
});

it('flags a guarantee already registered as a possible duplicate', function (): void {
    $emission = Emission::factory()->create();
    $instrumentDocument = ccbDocument($emission);

    Guarantee::factory()
        ->effectiveBetween()
        ->ofType(GuaranteeType::RealEstateFiduciaryAlienation)
        ->create([
            'emission_id' => $emission->id,
            'legal_status' => GuaranteeLegalStatus::Active,
            'identification' => ['registration_number' => '18.900'],
        ]);

    $this->mock(GeminiService::class)
        ->shouldReceive('extractFromDocumentWithPrompt')
        ->once()
        ->andReturn([
            'guarantees' => [[
                'type' => 'af_imovel',
                'event' => 'constitution',
                'name' => 'AF Imóvel',
                'identification' => ['registration_number' => '18.900'],
                'excerpt' => 'alienação fiduciária do imóvel matriculado sob o nº 18.900',
            ]],
        ]);

    (new ProcessLegalInstrumentDocumentJob($instrumentDocument->id))->handle(app(InstrumentDocumentExtractor::class));

    $candidate = ExtractedGuarantee::query()->sole();

    expect($candidate->has_conflict)->toBeTrue()
        ->and($candidate->conflict_reason)->toContain('Possível mesma garantia')
        ->and($candidate->related_guarantee_id)->not->toBeNull();
});

it('suggests obligations found in the instrument without creating them', function (): void {
    $instrumentDocument = ccbDocument();

    $this->mock(GeminiService::class)
        ->shouldReceive('extractFromDocumentWithPrompt')
        ->once()
        ->andReturn([
            'obligations' => [[
                'title' => 'Reavaliar o imóvel anualmente',
                'recurrence' => 'Anual',
                'clause' => '6.4',
                'page' => 9,
                'excerpt' => 'A Emitente deverá apresentar laudo de reavaliação anual do imóvel.',
                'confidence_score' => 0.88,
            ]],
        ]);

    (new ProcessLegalInstrumentDocumentJob($instrumentDocument->id))->handle(app(InstrumentDocumentExtractor::class));

    $obligation = ExtractedObligation::query()->sole();

    expect($obligation->status)->toBe(ExtractedObligation::STATUS_SUGGESTED)
        ->and($obligation->title)->toBe('Reavaliar o imóvel anualmente')
        ->and($obligation->obligation_category)->toBe('Garantias')
        ->and($obligation->source_page)->toBe(9)
        ->and($obligation->document_id)->toBe($instrumentDocument->document_id);
});

it('does not duplicate guarantees or obligations on reprocessing', function (): void {
    $instrumentDocument = ccbDocument();

    $payload = [
        'guarantees' => [[
            'type' => 'af_imovel',
            'name' => 'AF Imóvel',
            'identification' => ['registration_number' => '18.900'],
            'excerpt' => 'alienação fiduciária do imóvel 18.900',
        ]],
        'obligations' => [[
            'title' => 'Reavaliar o imóvel anualmente',
            'excerpt' => 'laudo de reavaliação anual',
        ]],
    ];

    $this->mock(GeminiService::class)
        ->shouldReceive('extractFromDocumentWithPrompt')
        ->twice()
        ->andReturn($payload);

    $job = new ProcessLegalInstrumentDocumentJob($instrumentDocument->id);
    $job->handle(app(InstrumentDocumentExtractor::class));
    $job->handle(app(InstrumentDocumentExtractor::class));

    expect(ExtractedGuarantee::query()->count())->toBe(1)
        ->and(ExtractedObligation::query()->count())->toBe(1);
});

it('does not repropose the same change arriving from a second document', function (): void {
    $instrumentDocument = ccbDocument();
    $instrument = $instrumentDocument->instrument;

    $amendment = LegalInstrumentDocument::factory()
        ->amendment(1, '2025-01-10')
        ->create(['legal_instrument_id' => $instrument->id]);

    $accessory = LegalInstrumentDocument::factory()
        ->withRole(LegalInstrumentDocumentRole::Accessory, '2025-01-10')
        ->create(['legal_instrument_id' => $instrument->id]);

    $payload = [
        'fields' => [[
            'field_key' => 'minimum_coverage',
            'value' => '1.3',
            'excerpt' => 'cobertura mínima de 130%',
        ]],
    ];

    $this->mock(GeminiService::class)
        ->shouldReceive('extractFromDocumentWithPrompt')
        ->twice()
        ->andReturn($payload);

    (new ProcessLegalInstrumentDocumentJob($amendment->id))->handle(app(InstrumentDocumentExtractor::class));
    (new ProcessLegalInstrumentDocumentJob($accessory->id))->handle(app(InstrumentDocumentExtractor::class));

    // A mesma alteração chegou por dois documentos: o revisor decide uma vez.
    expect(LegalInstrumentField::query()->pendingReview()->count())->toBe(1);
});

it('recognises existing operation documents as an instrument dossier', function (): void {
    $emission = Emission::factory()->create();

    foreach ([
        'CRI XYZ_CCB.pdf',
        'CRI XYZ_1º Aditamento CCB.pdf',
        'CRI XYZ_2º Aditamento CCB.pdf',
        'CRI XYZ_AFI.pdf',
        'Apresentacao institucional.pdf',
    ] as $title) {
        $document = Document::factory()->create(['title' => $title, 'category' => 'documentos_operacao']);
        $emission->documents()->attach($document->id);
    }

    $suggestions = app(ExistingDocumentScanner::class)->scan($emission);

    expect($suggestions->keys()->all())->toContain(LegalInstrumentType::Ccb->value)
        ->and($suggestions->keys()->all())->toContain(LegalInstrumentType::RealEstateFiduciaryAlienation->value);

    $ccb = $suggestions->get(LegalInstrumentType::Ccb->value);

    expect($ccb['documents'])->toHaveCount(3);

    $roles = $ccb['documents']->map(fn (array $entry): string => $entry['role']->value)->all();

    expect($roles)->toContain(LegalInstrumentDocumentRole::Original->value)
        ->and($roles)->toContain(LegalInstrumentDocumentRole::Amendment->value);

    $sequences = $ccb['documents']
        ->filter(fn (array $entry): bool => $entry['role'] === LegalInstrumentDocumentRole::Amendment)
        ->map(fn (array $entry): ?int => $entry['sequence'])
        ->values()
        ->all();

    expect($sequences)->toBe([1, 2]);
});

it('does not suggest documents already attached to a dossier', function (): void {
    $emission = Emission::factory()->create();
    $instrument = LegalInstrument::factory()->create(['emission_id' => $emission->id]);

    $document = Document::factory()->create(['title' => 'CRI XYZ_CCB.pdf', 'category' => 'documentos_operacao']);
    $emission->documents()->attach($document->id);

    LegalInstrumentDocument::factory()->original('2024-01-10')->create([
        'legal_instrument_id' => $instrument->id,
        'document_id' => $document->id,
    ]);

    expect(app(ExistingDocumentScanner::class)->scan($emission))->toBeEmpty();
});

it('builds a clickable source url pointing at the cited page', function (): void {
    $instrumentDocument = ccbDocument();

    $field = LegalInstrumentField::factory()->create([
        'legal_instrument_id' => $instrumentDocument->legal_instrument_id,
        'legal_instrument_document_id' => $instrumentDocument->id,
        'document_id' => $instrumentDocument->document_id,
        'field_key' => LegalInstrumentFieldKey::MinimumCoverage,
        'value_type' => LegalInstrumentFieldKey::MinimumCoverage->valueType(),
        'value' => '130%',
        'value_numeric' => 1.3,
        'page' => 7,
    ]);

    expect($field->source_url)
        ->toBe(route('admin.documents.preview', $instrumentDocument->document_id).'#page=7');
});

it('serves the document inline so the page anchor works', function (): void {
    $this->actingAs(makeAdminUser());

    $disk = Document::defaultStorageDisk();
    Storage::fake($disk);

    $document = Document::factory()->create([
        'title' => 'CCB nº 001/2026',
        'mime_type' => 'application/pdf',
        'category' => 'documentos_operacao',
        'storage_disk' => $disk,
    ]);

    Storage::disk($disk)->put($document->file_path, '%PDF-1.4 conteudo');

    $document->forceFill(['scan_status' => MalwareScanStatus::Clean])->saveQuietly();

    $response = $this->get(route('admin.documents.preview', $document));

    $response->assertSuccessful();

    expect($response->headers->get('Content-Disposition'))->toStartWith('inline')
        ->and($response->headers->get('Content-Type'))->toBe('application/pdf');
});

it('requeues a failed document without discarding confirmed information', function (): void {
    $this->actingAs(makeAdminUser());

    Queue::fake();

    $instrumentDocument = ccbDocument();
    $instrument = $instrumentDocument->instrument;

    $instrumentDocument->forceFill([
        'processing_status' => LegalInstrumentDocumentStatus::Failed,
        'error_message' => 'A File API devolveu 503.',
        'extraction_attempts' => 1,
    ])->save();

    $confirmed = LegalInstrumentField::factory()->create([
        'legal_instrument_id' => $instrument->id,
        'field_key' => LegalInstrumentFieldKey::MinimumCoverage,
        'value_type' => LegalInstrumentFieldKey::MinimumCoverage->valueType(),
        'value' => '120%',
        'value_numeric' => 1.2,
        'status' => LegalInstrumentFieldStatus::Confirmed,
    ]);

    Livewire::test(LegalInstrumentsRelationManager::class, [
        'ownerRecord' => $instrument->emission,
        'pageClass' => EditEmission::class,
    ])
        ->callTableAction('reprocess_document', $instrument, data: [
            'legal_instrument_document_id' => $instrumentDocument->id,
        ])
        ->assertHasNoTableActionErrors();

    $instrumentDocument->refresh();

    expect($instrumentDocument->processing_status)->toBe(LegalInstrumentDocumentStatus::Pending)
        ->and($instrumentDocument->error_message)->toBeNull()
        ->and($confirmed->refresh()->status)->toBe(LegalInstrumentFieldStatus::Confirmed);

    Queue::assertPushed(ProcessLegalInstrumentDocumentJob::class);
});
