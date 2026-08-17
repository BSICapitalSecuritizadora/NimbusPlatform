<?php

use App\Enums\LegalInstrumentDocumentRole;
use App\Enums\LegalInstrumentDocumentStatus;
use App\Enums\LegalInstrumentFieldKey;
use App\Enums\LegalInstrumentFieldStatus;
use App\Enums\LegalInstrumentType;
use App\Jobs\ProcessLegalInstrumentDocumentJob;
use App\Models\Document;
use App\Models\Emission;
use App\Models\LegalInstrument;
use App\Models\LegalInstrumentDocument;
use App\Models\LegalInstrumentEvent;
use App\Models\LegalInstrumentField;
use App\Models\User;
use App\Services\GeminiService;
use App\Services\LegalInstruments\InstrumentChangeReviewService;
use App\Services\LegalInstruments\InstrumentDocumentExtractor;
use App\Services\LegalInstruments\InstrumentPositionResolver;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

function ccbWithConfirmedPosition(): LegalInstrument
{
    $emission = Emission::factory()->create();

    $instrument = LegalInstrument::factory()
        ->ofType(LegalInstrumentType::Ccb, '001/2026')
        ->create(['emission_id' => $emission->id]);

    $original = LegalInstrumentDocument::factory()
        ->original('2024-01-10')
        ->processed()
        ->create(['legal_instrument_id' => $instrument->id]);

    LegalInstrumentField::factory()->create([
        'legal_instrument_id' => $instrument->id,
        'field_key' => LegalInstrumentFieldKey::PrincipalAmount,
        'value_type' => LegalInstrumentFieldKey::PrincipalAmount->valueType(),
        'value' => '30000000',
        'value_numeric' => 30_000_000,
        'effective_date' => '2024-01-10',
        'status' => LegalInstrumentFieldStatus::Confirmed,
        'legal_instrument_document_id' => $original->id,
    ]);

    LegalInstrumentField::factory()->create([
        'legal_instrument_id' => $instrument->id,
        'field_key' => LegalInstrumentFieldKey::MinimumCoverage,
        'value_type' => LegalInstrumentFieldKey::MinimumCoverage->valueType(),
        'value' => '120%',
        'value_numeric' => 1.2,
        'effective_date' => '2024-01-10',
        'status' => LegalInstrumentFieldStatus::Confirmed,
        'legal_instrument_document_id' => $original->id,
    ]);

    return $instrument->fresh();
}

it('turns an amendment into pending proposals without touching the current position', function (): void {
    $instrument = ccbWithConfirmedPosition();

    $amendment = LegalInstrumentDocument::factory()
        ->amendment(3, '2026-05-15')
        ->create(['legal_instrument_id' => $instrument->id]);

    $this->mock(GeminiService::class)
        ->shouldReceive('extractFromDocumentWithPrompt')
        ->once()
        ->andReturn([
            'fields' => [[
                'field_key' => 'minimum_coverage',
                'value' => '1.3',
                'effective_date' => '2026-05-15',
                'evidence_level' => 'explicit',
                'confidence_score' => 0.93,
                'clause' => '4.2',
                'page' => 7,
                'excerpt' => 'A cobertura mínima passa de 120% para 130% do saldo devedor.',
            ]],
            'effect_summary' => 'Alteração de cobertura',
        ]);

    (new ProcessLegalInstrumentDocumentJob($amendment->id))->handle(app(InstrumentDocumentExtractor::class));

    $amendment->refresh();

    expect($amendment->processing_status)->toBe(LegalInstrumentDocumentStatus::NeedsReview)
        ->and($amendment->effect_summary)->toBe('Alteração de cobertura');

    // A posição vigente ainda é a antiga: proposta não altera nada (§20).
    $position = app(InstrumentPositionResolver::class)->resolve($instrument, '2026-07-31');

    expect($position->numeric(LegalInstrumentFieldKey::MinimumCoverage))->toBe(1.2);

    $proposal = $instrument->fields()->pendingReview()->sole();

    expect($proposal->value_numeric)->toBe(1.3)
        ->and($proposal->clause)->toBe('4.2')
        ->and($proposal->page)->toBe(7);
});

it('confirms a proposal, supersedes the previous version and records the event', function (): void {
    $instrument = ccbWithConfirmedPosition();
    $actor = makeAdminUser();

    $amendment = LegalInstrumentDocument::factory()
        ->amendment(3, '2026-05-15')
        ->create(['legal_instrument_id' => $instrument->id]);

    $proposal = LegalInstrumentField::factory()->pending()->create([
        'legal_instrument_id' => $instrument->id,
        'field_key' => LegalInstrumentFieldKey::MinimumCoverage,
        'value_type' => LegalInstrumentFieldKey::MinimumCoverage->valueType(),
        'value' => '130%',
        'value_numeric' => 1.3,
        'effective_date' => '2026-05-15',
        'legal_instrument_document_id' => $amendment->id,
        'clause' => '4.2',
        'page' => 7,
    ]);

    $previous = $instrument->fields()
        ->where('field_key', LegalInstrumentFieldKey::MinimumCoverage->value)
        ->confirmed()
        ->sole();

    app(InstrumentChangeReviewService::class)->confirm($proposal, $actor);

    expect($proposal->refresh()->status)->toBe(LegalInstrumentFieldStatus::Confirmed)
        ->and($proposal->supersedes_id)->toBe($previous->id)
        ->and($proposal->reviewed_by)->toBe($actor->id)
        ->and($previous->refresh()->status)->toBe(LegalInstrumentFieldStatus::Superseded);

    $position = app(InstrumentPositionResolver::class)->resolve($instrument->fresh(), '2026-07-31');

    expect($position->numeric(LegalInstrumentFieldKey::MinimumCoverage))->toBe(1.3);

    // O histórico anterior continua consultável (§11 e §12).
    $before = app(InstrumentPositionResolver::class)->resolve($instrument->fresh(), '2026-01-01');

    expect($before->numeric(LegalInstrumentFieldKey::MinimumCoverage))->toBe(1.2);

    $event = LegalInstrumentEvent::query()->sole();

    expect($event->event_type->value)->toBe('coverage_change')
        ->and($event->effective_date?->toDateString())->toBe('2026-05-15')
        ->and($event->change_list[0]['from'])->toBe('120%')
        ->and($event->change_list[0]['to'])->toBe('130%');
});

it('rejects a proposal without changing the position and requires a reason', function (): void {
    $instrument = ccbWithConfirmedPosition();
    $actor = makeAdminUser();

    $proposal = LegalInstrumentField::factory()->pending()->create([
        'legal_instrument_id' => $instrument->id,
        'field_key' => LegalInstrumentFieldKey::MinimumCoverage,
        'value_type' => LegalInstrumentFieldKey::MinimumCoverage->valueType(),
        'value' => '150%',
        'value_numeric' => 1.5,
    ]);

    $service = app(InstrumentChangeReviewService::class);

    expect(fn () => $service->reject($proposal, $actor, '  '))->toThrow(ValidationException::class);

    $service->reject($proposal, $actor, 'Cláusula não altera a cobertura.');

    expect($proposal->refresh()->status)->toBe(LegalInstrumentFieldStatus::Rejected)
        ->and(app(InstrumentPositionResolver::class)->resolve($instrument->fresh())->numeric(LegalInstrumentFieldKey::MinimumCoverage))
        ->toBe(1.2);
});

it('does not repropose values identical to the confirmed position', function (): void {
    $instrument = ccbWithConfirmedPosition();

    $amendment = LegalInstrumentDocument::factory()
        ->amendment(1, '2025-01-10')
        ->create(['legal_instrument_id' => $instrument->id]);

    $this->mock(GeminiService::class)
        ->shouldReceive('extractFromDocumentWithPrompt')
        ->once()
        ->andReturn([
            'fields' => [
                // Repete o valor vigente — não deve virar proposta.
                ['field_key' => 'minimum_coverage', 'value' => '1.2', 'excerpt' => 'cobertura de 120%'],
                // Este muda de verdade.
                ['field_key' => 'principal_amount', 'value' => '35000000', 'excerpt' => 'valor passa a R$ 35.000.000'],
            ],
        ]);

    (new ProcessLegalInstrumentDocumentJob($amendment->id))->handle(app(InstrumentDocumentExtractor::class));

    $pending = $instrument->fields()->pendingReview()->get();

    expect($pending)->toHaveCount(1)
        ->and($pending->first()->field_key)->toBe(LegalInstrumentFieldKey::PrincipalAmount);
});

it('flags a conflict when a non-amending document proposes a contractual change', function (): void {
    $instrument = ccbWithConfirmedPosition();

    $registration = LegalInstrumentDocument::factory()
        ->withRole(LegalInstrumentDocumentRole::Registration, '2026-03-01')
        ->create(['legal_instrument_id' => $instrument->id]);

    $this->mock(GeminiService::class)
        ->shouldReceive('extractFromDocumentWithPrompt')
        ->once()
        ->andReturn([
            'fields' => [[
                'field_key' => 'minimum_coverage',
                'value' => '1.4',
                'excerpt' => 'cobertura de 140%',
            ]],
        ]);

    (new ProcessLegalInstrumentDocumentJob($registration->id))->handle(app(InstrumentDocumentExtractor::class));

    $proposal = $instrument->fields()->pendingReview()->sole();

    expect($proposal->has_conflict)->toBeTrue()
        ->and($proposal->conflict_reason)->toContain('não altera a regra contratual');
});

it('marks a conflicting extraction for attention', function (): void {
    $instrument = ccbWithConfirmedPosition();

    $amendment = LegalInstrumentDocument::factory()
        ->amendment(1, '2025-01-10')
        ->create(['legal_instrument_id' => $instrument->id]);

    $this->mock(GeminiService::class)
        ->shouldReceive('extractFromDocumentWithPrompt')
        ->once()
        ->andReturn([
            'fields' => [[
                'field_key' => 'principal_amount',
                'value' => '35000000',
                'evidence_level' => 'conflicting',
                'excerpt' => 'o documento cita R$ 35 mi na cláusula 2.1 e R$ 34 mi no anexo',
            ]],
        ]);

    (new ProcessLegalInstrumentDocumentJob($amendment->id))->handle(app(InstrumentDocumentExtractor::class));

    $proposal = $instrument->fields()->pendingReview()->sole();

    expect($proposal->has_conflict)->toBeTrue()
        ->and($proposal->evidence_level->requiresAttention())->toBeTrue();
});

it('records the failure cause and allows retry', function (): void {
    $instrument = ccbWithConfirmedPosition();

    $amendment = LegalInstrumentDocument::factory()
        ->amendment(1, '2025-01-10')
        ->create(['legal_instrument_id' => $instrument->id]);

    $this->mock(GeminiService::class)
        ->shouldReceive('extractFromDocumentWithPrompt')
        ->once()
        ->andThrow(new RuntimeException('A File API devolveu 503.'));

    expect(fn () => (new ProcessLegalInstrumentDocumentJob($amendment->id))->handle(app(InstrumentDocumentExtractor::class)))
        ->toThrow(RuntimeException::class);

    $amendment->refresh();

    expect($amendment->processing_status)->toBe(LegalInstrumentDocumentStatus::Failed)
        ->and($amendment->error_message)->toContain('503')
        ->and($amendment->canRetry())->toBeTrue()
        ->and($amendment->extraction_attempts)->toBe(1);
});

it('does not stack duplicate proposals when a document is reprocessed', function (): void {
    $instrument = ccbWithConfirmedPosition();

    $amendment = LegalInstrumentDocument::factory()
        ->amendment(1, '2025-01-10')
        ->create(['legal_instrument_id' => $instrument->id]);

    $this->mock(GeminiService::class)
        ->shouldReceive('extractFromDocumentWithPrompt')
        ->twice()
        ->andReturn([
            'fields' => [[
                'field_key' => 'principal_amount',
                'value' => '35000000',
                'excerpt' => 'valor passa a R$ 35.000.000',
            ]],
        ]);

    $job = new ProcessLegalInstrumentDocumentJob($amendment->id);

    $job->handle(app(InstrumentDocumentExtractor::class));
    $job->handle(app(InstrumentDocumentExtractor::class));

    expect($instrument->fields()->pendingReview()->count())->toBe(1);
});

it('refuses confirmation from a user without permission', function (): void {
    $instrument = ccbWithConfirmedPosition();

    $proposal = LegalInstrumentField::factory()->pending()->create([
        'legal_instrument_id' => $instrument->id,
        'field_key' => LegalInstrumentFieldKey::MinimumCoverage,
        'value_type' => LegalInstrumentFieldKey::MinimumCoverage->valueType(),
        'value' => '130%',
        'value_numeric' => 1.3,
    ]);

    expect(fn () => app(InstrumentChangeReviewService::class)->confirm($proposal, User::factory()->create()))
        ->toThrow(AuthorizationException::class);

    expect($proposal->refresh()->status)->toBe(LegalInstrumentFieldStatus::PendingReview);
});

it('audits confirmation with the previous and the new value', function (): void {
    $instrument = ccbWithConfirmedPosition();
    $actor = makeAdminUser();

    $proposal = LegalInstrumentField::factory()->pending()->create([
        'legal_instrument_id' => $instrument->id,
        'field_key' => LegalInstrumentFieldKey::MinimumCoverage,
        'value_type' => LegalInstrumentFieldKey::MinimumCoverage->valueType(),
        'value' => '130%',
        'value_numeric' => 1.3,
    ]);

    app(InstrumentChangeReviewService::class)->confirm($proposal, $actor);

    $activity = Activity::query()
        ->where('event', InstrumentChangeReviewService::EVENT_CHANGE_CONFIRMED)
        ->sole();

    expect($activity->properties['previous_value'])->toBe('120%')
        ->and($activity->properties['new_value'])->toBe('130%')
        ->and($activity->properties['field_label'])->toBe('Cobertura mínima')
        ->and($activity->causer_id)->toBe($actor->id);
});

/**
 * Guarda de contrato entre o extrator e o GeminiService.
 *
 * Os demais testes desta suíte mockam `extractFromDocumentWithPrompt`, e o
 * Mockery aceita `shouldReceive` de um método que não existe na classe real —
 * ou seja, eles continuariam verdes se o método fosse removido ou renomeado.
 * Este teste olha a classe concreta, que é o que o worker executa.
 */
it('keeps the gemini method the instrument extractor depends on', function (): void {
    expect(method_exists(GeminiService::class, 'extractFromDocumentWithPrompt'))->toBeTrue();

    $method = new ReflectionMethod(GeminiService::class, 'extractFromDocumentWithPrompt');
    $parameters = $method->getParameters();

    expect($method->isPublic())->toBeTrue()
        ->and((string) $method->getReturnType())->toBe('array')
        ->and($parameters)->toHaveCount(2)
        ->and((string) $parameters[0]->getType())->toBe(Document::class)
        ->and((string) $parameters[1]->getType())->toBe('string');
});
