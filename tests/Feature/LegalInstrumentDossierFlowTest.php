<?php

use App\Enums\LegalInstrumentDocumentRole;
use App\Enums\LegalInstrumentDocumentStatus;
use App\Enums\LegalInstrumentFieldKey;
use App\Enums\LegalInstrumentFieldStatus;
use App\Enums\LegalInstrumentType;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\InstrumentChangesRelationManager;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\LegalInstrumentsRelationManager;
use App\Filament\Resources\Emissions\Pages\EditEmission;
use App\Jobs\ProcessLegalInstrumentDocumentJob;
use App\Models\Document;
use App\Models\Emission;
use App\Models\LegalInstrument;
use App\Models\LegalInstrumentDocument;
use App\Models\LegalInstrumentField;
use App\Services\GeminiService;
use App\Services\LegalInstruments\InstrumentChangeReviewService;
use App\Services\LegalInstruments\InstrumentDocumentExtractor;
use App\Services\LegalInstruments\InstrumentPositionResolver;
use App\Services\Reports\EmissionMonthlyReportService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * Fluxo completo do escopo: CCB → upload → extração → posição inicial →
 * aditamento → mudanças detectadas → confirmação → nova posição → histórico.
 */
it('runs the full dossier flow from the original document to the amended position', function (): void {
    $emission = Emission::factory()->create();
    $actor = makeAdminUser();

    $instrument = LegalInstrument::factory()
        ->ofType(LegalInstrumentType::Ccb, '001/2026')
        ->create(['emission_id' => $emission->id]);

    // --- 1. Documento original entra no dossiê e é lido.
    $originalDocument = LegalInstrumentDocument::factory()
        ->original('2024-01-10')
        ->create(['legal_instrument_id' => $instrument->id]);

    $gemini = $this->mock(GeminiService::class);

    $gemini->shouldReceive('extractFromDocumentWithPrompt')
        ->once()
        ->andReturn([
            'fields' => [
                ['field_key' => 'number', 'value' => '001/2026', 'clause' => '1.1', 'page' => 1, 'excerpt' => 'CCB nº 001/2026'],
                ['field_key' => 'issuer', 'value' => 'SPE Exemplo Ltda.', 'clause' => '1.2', 'page' => 1, 'excerpt' => 'Emitente: SPE Exemplo Ltda.'],
                ['field_key' => 'original_amount', 'value' => '30000000', 'clause' => '2.1', 'page' => 3, 'excerpt' => 'Valor de R$ 30.000.000,00'],
                ['field_key' => 'principal_amount', 'value' => '30000000', 'clause' => '2.1', 'page' => 3, 'excerpt' => 'Valor de R$ 30.000.000,00'],
                ['field_key' => 'minimum_coverage', 'value' => '1.2', 'clause' => '4.2', 'page' => 6, 'excerpt' => 'cobertura mínima de 120% do saldo devedor'],
            ],
            'effect_summary' => 'Constituição da CCB',
        ]);

    (new ProcessLegalInstrumentDocumentJob($originalDocument->id))->handle(app(InstrumentDocumentExtractor::class));

    // Nada foi consolidado ainda: tudo aguarda revisão.
    $resolver = app(InstrumentPositionResolver::class);
    expect($resolver->resolve($instrument)->fields)->toBeEmpty()
        ->and($instrument->fields()->pendingReview()->count())->toBe(5);

    // --- 2. Revisão confirma a posição inicial.
    $review = app(InstrumentChangeReviewService::class);
    $review->confirmMany($instrument->fields()->pendingReview()->get(), $actor);

    $initial = $resolver->resolve($instrument->fresh(), '2024-03-01');

    expect($initial->numeric(LegalInstrumentFieldKey::PrincipalAmount))->toBe(30_000_000.0)
        ->and($initial->numeric(LegalInstrumentFieldKey::MinimumCoverage))->toBe(1.2)
        ->and($initial->value(LegalInstrumentFieldKey::Issuer))->toBe('SPE Exemplo Ltda.');

    // --- 3. Aditamento eleva a cobertura.
    $amendment = LegalInstrumentDocument::factory()
        ->amendment(3, '2026-05-15')
        ->create(['legal_instrument_id' => $instrument->id]);

    $gemini->shouldReceive('extractFromDocumentWithPrompt')
        ->once()
        ->andReturn([
            'fields' => [[
                'field_key' => 'minimum_coverage',
                'value' => '1.3',
                'effective_date' => '2026-05-15',
                'clause' => '4.2',
                'page' => 7,
                'excerpt' => 'A cobertura mínima passa de 120% para 130% do saldo devedor.',
            ]],
            'effect_summary' => 'Alteração de cobertura',
        ]);

    (new ProcessLegalInstrumentDocumentJob($amendment->id))->handle(app(InstrumentDocumentExtractor::class));

    $proposal = $instrument->fields()->pendingReview()->sole();

    // A posição só muda depois da confirmação.
    expect($resolver->resolve($instrument->fresh())->numeric(LegalInstrumentFieldKey::MinimumCoverage))->toBe(1.2);

    $review->confirm($proposal, $actor);

    // --- 4. Nova posição vigente, histórico preservado.
    expect($resolver->resolve($instrument->fresh())->numeric(LegalInstrumentFieldKey::MinimumCoverage))->toBe(1.3)
        ->and($resolver->resolve($instrument->fresh(), '2026-01-01')->numeric(LegalInstrumentFieldKey::MinimumCoverage))->toBe(1.2)
        ->and($resolver->resolve($instrument->fresh())->numeric(LegalInstrumentFieldKey::OriginalAmount))->toBe(30_000_000.0);

    // --- 5. O evento subiu para a linha do tempo da emissão.
    $timelineEntry = Activity::query()
        ->where('event', 'legal_instrument_change')
        ->where('subject_type', Emission::class)
        ->sole();

    expect($timelineEntry->properties['attributes']['De'])->toBe('120%')
        ->and($timelineEntry->properties['attributes']['Para'])->toBe('130%')
        ->and($timelineEntry->properties['attributes']['Documento'])->toBe('3º Aditamento');
});

it('feeds the monthly report with the position as it stood in that month', function (): void {
    $emission = Emission::factory()->create();
    $actor = makeAdminUser();

    $instrument = LegalInstrument::factory()
        ->ofType(LegalInstrumentType::Ccb, '001/2026')
        ->create(['emission_id' => $emission->id]);

    $original = LegalInstrumentDocument::factory()
        ->original('2024-01-10')
        ->create(['legal_instrument_id' => $instrument->id]);

    $amendment = LegalInstrumentDocument::factory()
        ->amendment(1, '2026-05-15')
        ->create(['legal_instrument_id' => $instrument->id]);

    foreach ([LegalInstrumentFieldKey::OriginalAmount, LegalInstrumentFieldKey::PrincipalAmount] as $key) {
        LegalInstrumentField::factory()->create([
            'legal_instrument_id' => $instrument->id,
            'field_key' => $key,
            'value_type' => $key->valueType(),
            'value' => '30000000',
            'value_numeric' => 30_000_000,
            'effective_date' => '2024-01-10',
            'status' => LegalInstrumentFieldStatus::Confirmed,
            'legal_instrument_document_id' => $original->id,
        ]);
    }

    $raise = LegalInstrumentField::factory()->pending()->create([
        'legal_instrument_id' => $instrument->id,
        'field_key' => LegalInstrumentFieldKey::PrincipalAmount,
        'value_type' => LegalInstrumentFieldKey::PrincipalAmount->valueType(),
        'value' => '35000000',
        'value_numeric' => 35_000_000,
        'effective_date' => '2026-05-15',
        'legal_instrument_document_id' => $amendment->id,
    ]);

    app(InstrumentChangeReviewService::class)->confirm($raise, $actor);

    $service = app(EmissionMonthlyReportService::class);

    $april = $service->build($emission->fresh(), Carbon::parse('2026-04-15'));
    $june = $service->build($emission->fresh(), Carbon::parse('2026-06-15'));

    expect($april['legal_instruments'][0]['current_amount'])->toBe('R$ 30.000.000,00')
        ->and($june['legal_instruments'][0]['current_amount'])->toBe('R$ 35.000.000,00')
        ->and($june['legal_instruments'][0]['original_amount'])->toBe('R$ 30.000.000,00')
        ->and($june['legal_instruments'][0]['last_change'])->toBe('1º Aditamento — 15/05/2026');
});

it('reports a field the documents never stated as not located', function (): void {
    $emission = Emission::factory()->create();

    LegalInstrument::factory()
        ->ofType(LegalInstrumentType::Ccb, '001/2026')
        ->create(['emission_id' => $emission->id]);

    $report = app(EmissionMonthlyReportService::class)->build($emission, Carbon::parse('2026-06-15'));

    expect($report['legal_instruments'][0]['maturity_date'])->toBe('Valor não localizado no documento.')
        ->and($report['legal_instruments'][0]['current_amount'])->not->toBe('R$ 0,00');
});

it('lists instruments and their dossier in the relation manager', function (): void {
    $this->actingAs(makeAdminUser());

    $emission = Emission::factory()->create();

    $instrument = LegalInstrument::factory()
        ->ofType(LegalInstrumentType::Ccb, '001/2026')
        ->create(['emission_id' => $emission->id]);

    LegalInstrumentDocument::factory()->original('2024-01-10')->create(['legal_instrument_id' => $instrument->id]);
    LegalInstrumentDocument::factory()->amendment(1, '2024-06-18')->create(['legal_instrument_id' => $instrument->id]);

    Livewire::test(LegalInstrumentsRelationManager::class, [
        'ownerRecord' => $emission,
        'pageClass' => EditEmission::class,
    ])
        ->assertCanSeeTableRecords([$instrument])
        ->assertSee('CCB nº 001/2026')
        ->assertSee('2 documento(s) · 1 aditamento(s)');
});

it('attaches a document to the dossier and queues its processing', function (): void {
    Queue::fake();

    $this->actingAs(makeAdminUser());

    $emission = Emission::factory()->create();
    $instrument = LegalInstrument::factory()->create(['emission_id' => $emission->id]);

    $document = Document::factory()->create([
        'title' => '1º Aditamento à CCB',
        'category' => 'documentos_operacao',
    ]);
    $emission->documents()->attach($document->id);

    Livewire::test(LegalInstrumentsRelationManager::class, [
        'ownerRecord' => $emission,
        'pageClass' => EditEmission::class,
    ])
        ->callTableAction('attach_document', $instrument, data: [
            'document_id' => $document->id,
            'role' => LegalInstrumentDocumentRole::Amendment->value,
            'sequence' => 1,
            'document_date' => '2024-06-18',
        ])
        ->assertHasNoTableActionErrors();

    $entry = LegalInstrumentDocument::query()->sole();

    expect($entry->document_id)->toBe($document->id)
        ->and($entry->role)->toBe(LegalInstrumentDocumentRole::Amendment)
        ->and($entry->sequence)->toBe(1)
        ->and($entry->processing_status)->toBe(LegalInstrumentDocumentStatus::Pending);

    Queue::assertPushed(ProcessLegalInstrumentDocumentJob::class);

    expect(Activity::query()->where('event', 'document_attached')->exists())->toBeTrue();
});

it('confirms a detected change from the review queue', function (): void {
    $this->actingAs(makeAdminUser());

    $emission = Emission::factory()->create();
    $instrument = LegalInstrument::factory()->create(['emission_id' => $emission->id]);

    LegalInstrumentField::factory()->create([
        'legal_instrument_id' => $instrument->id,
        'field_key' => LegalInstrumentFieldKey::MinimumCoverage,
        'value_type' => LegalInstrumentFieldKey::MinimumCoverage->valueType(),
        'value' => '120%',
        'value_numeric' => 1.2,
        'effective_date' => '2024-01-10',
        'status' => LegalInstrumentFieldStatus::Confirmed,
    ]);

    $proposal = LegalInstrumentField::factory()->pending()->create([
        'legal_instrument_id' => $instrument->id,
        'field_key' => LegalInstrumentFieldKey::MinimumCoverage,
        'value_type' => LegalInstrumentFieldKey::MinimumCoverage->valueType(),
        'value' => '130%',
        'value_numeric' => 1.3,
        'effective_date' => '2026-05-15',
    ]);

    Livewire::test(InstrumentChangesRelationManager::class, [
        'ownerRecord' => $emission,
        'pageClass' => EditEmission::class,
    ])
        ->assertCanSeeTableRecords([$proposal])
        ->callTableAction('confirm', $proposal, data: ['review_notes' => 'Conferido contra a cláusula 4.2.'])
        ->assertHasNoTableActionErrors();

    expect($proposal->refresh()->status)->toBe(LegalInstrumentFieldStatus::Confirmed)
        ->and(app(InstrumentPositionResolver::class)->resolve($instrument->fresh())->numeric(LegalInstrumentFieldKey::MinimumCoverage))
        ->toBe(1.3);
});
