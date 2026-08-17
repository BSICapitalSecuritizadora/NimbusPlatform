<?php

use App\Enums\GuaranteeDetectionStatus;
use App\Enums\GuaranteeEventType;
use App\Enums\GuaranteeLegalStatus;
use App\Enums\GuaranteeRequirementBase;
use App\Enums\GuaranteeRequirementBasis;
use App\Enums\GuaranteeType;
use App\Enums\LegalDocumentType;
use App\Jobs\GenerateEmissionGuaranteesJob;
use App\Models\Document;
use App\Models\Emission;
use App\Models\ExtractedGuarantee;
use App\Models\Guarantee;
use App\Models\GuaranteeGenerationRun;
use App\Models\User;
use App\Services\GeminiService;
use App\Services\Guarantees\GuaranteeConflictDetector;
use App\Services\Guarantees\GuaranteeSuggestionReviewService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

function attachLegalDocument(
    Emission $emission,
    LegalDocumentType $type,
    string $documentDate,
    string $title = 'Termo de Securitização',
): Document {
    $document = Document::factory()->create(['title' => $title]);

    $emission->documents()->attach($document->id, [
        'legal_document_type' => $type->value,
        'document_date' => $documentDate,
        'is_guarantee_source' => true,
    ]);

    return $document;
}

it('records extracted guarantees as pending candidates rather than official guarantees', function (): void {
    $emission = Emission::factory()->create();
    $document = attachLegalDocument($emission, LegalDocumentType::SecuritizationTerm, '2024-01-10');

    $this->mock(GeminiService::class)
        ->shouldReceive('extractGuarantees')
        ->once()
        ->andReturn([[
            'event_type' => GuaranteeEventType::Constitution->value,
            'type' => GuaranteeType::RealEstateFiduciaryAlienation->value,
            'name' => 'AF Imóvel — Matrícula 45.721',
            'identification' => ['registration_number' => '45.721'],
            'contracted_value' => 23_500_000.0,
            'source_clause' => '8.3.1',
            'source_page' => 42,
            'source_excerpt' => 'Fica constituída a alienação fiduciária do imóvel matriculado sob o nº 45.721.',
            'confidence_score' => 0.94,
        ]]);

    $run = GuaranteeGenerationRun::factory()->create([
        'emission_id' => $emission->id,
        'document_id' => $document->id,
    ]);

    (new GenerateEmissionGuaranteesJob($emission->id, $document->id, $run->id))
        ->handle(app(GeminiService::class), app(GuaranteeConflictDetector::class));

    expect(Guarantee::query()->count())->toBe(0)
        ->and(ExtractedGuarantee::query()->count())->toBe(1);

    $candidate = ExtractedGuarantee::query()->sole();

    expect($candidate->status)->toBe(GuaranteeDetectionStatus::Suggested)
        ->and($candidate->document_type)->toBe(LegalDocumentType::SecuritizationTerm)
        ->and($candidate->document_date?->toDateString())->toBe('2024-01-10')
        ->and($candidate->source_page)->toBe(42)
        ->and($candidate->has_conflict)->toBeFalse();

    expect($run->refresh()->status)->toBe(GuaranteeGenerationRun::STATUS_COMPLETED)
        ->and($run->detected_count)->toBe(1);
});

it('confirms a candidate into an official guarantee with documental traceability', function (): void {
    $emission = Emission::factory()->create();
    $document = attachLegalDocument($emission, LegalDocumentType::SecuritizationTerm, '2024-01-10');
    $actor = makeAdminUser();

    $candidate = ExtractedGuarantee::factory()->create([
        'emission_id' => $emission->id,
        'document_id' => $document->id,
    ]);

    $guarantee = app(GuaranteeSuggestionReviewService::class)->approve($candidate, $actor);

    expect($guarantee->type)->toBe(GuaranteeType::RealEstateFiduciaryAlienation)
        ->and($guarantee->legal_status)->toBe(GuaranteeLegalStatus::Active)
        ->and((float) $guarantee->contracted_value)->toBe(23_500_000.0)
        ->and($candidate->refresh()->status)->toBe(GuaranteeDetectionStatus::Approved)
        ->and($candidate->guarantee_id)->toBe($guarantee->id);

    $reference = $guarantee->documentReferences()->sole();

    expect($reference->clause)->toBe('8.3.1')
        ->and($reference->page)->toBe(42)
        ->and($reference->document_id)->toBe($document->id)
        ->and($reference->confirmed_by)->toBe($actor->id)
        ->and($reference->location_label)->toBe('Cláusula 8.3.1 · Página 42');

    $event = $guarantee->events()->sole();

    expect($event->event_type)->toBe(GuaranteeEventType::Constitution)
        ->and($event->source)->toBe('document');
});

it('scenario 4: an amendment raises the minimum coverage and keeps the previous figure in history', function (): void {
    $emission = Emission::factory()->create();
    $actor = makeAdminUser();

    $guarantee = Guarantee::factory()
        ->effectiveBetween()
        ->ofType(GuaranteeType::ReceivablesFiduciaryAssignment)
        ->requiringPercentage(1.2)
        ->create(['emission_id' => $emission->id, 'legal_status' => GuaranteeLegalStatus::Active]);

    $amendment = attachLegalDocument($emission, LegalDocumentType::TermAmendment, '2025-03-15', '3º Aditamento');

    $candidate = ExtractedGuarantee::factory()
        ->receivablesCoverage(1.3)
        ->amending($guarantee)
        ->create([
            'emission_id' => $emission->id,
            'document_id' => $amendment->id,
            'document_date' => '2025-03-15',
            'effective_date' => '2025-03-15',
        ]);

    app(GuaranteeSuggestionReviewService::class)->approve($candidate, $actor);

    $guarantee->refresh();

    expect((float) $guarantee->requirement_percentage)->toBe(1.3)
        ->and(Guarantee::query()->count())->toBe(1);

    $event = $guarantee->events()->where('event_type', GuaranteeEventType::Amendment->value)->sole();

    expect($event->effective_date?->toDateString())->toBe('2025-03-15')
        ->and($event->previous_values['requirement_percentage'])->toBe(1.2)
        ->and($event->new_values['requirement_percentage'])->toBe(1.3);

    $change = collect($event->change_summary)->firstWhere('field', 'requirement_percentage');

    expect($change['from'])->toBe(1.2)->and($change['to'])->toBe(1.3);
});

it('scenario 6: a substitution closes the previous guarantee from its effective date', function (): void {
    $emission = Emission::factory()->create();
    $actor = makeAdminUser();

    $guarantee = Guarantee::factory()
        ->effectiveBetween()
        ->ofType(GuaranteeType::RealEstateFiduciaryAlienation)
        ->create([
            'emission_id' => $emission->id,
            'legal_status' => GuaranteeLegalStatus::Active,
            'identification' => ['registration_number' => '12.345'],
        ]);

    $instrument = attachLegalDocument($emission, LegalDocumentType::GuaranteeSubstitution, '2026-02-10', '5º Aditamento');

    $candidate = ExtractedGuarantee::factory()
        ->amending($guarantee, GuaranteeEventType::Substitution)
        ->create([
            'emission_id' => $emission->id,
            'document_id' => $instrument->id,
            'identification' => ['registration_number' => '12.345'],
            'document_date' => '2026-02-10',
            'effective_date' => '2026-02-10',
        ]);

    app(GuaranteeSuggestionReviewService::class)->approve($candidate, $actor);

    $guarantee->refresh();

    expect($guarantee->legal_status)->toBe(GuaranteeLegalStatus::Substituted)
        ->and($guarantee->released_at?->toDateString())->toBe('2026-02-10')
        ->and($guarantee->contributesToCoverageOn(now()->parse('2026-01-31')))->toBeTrue()
        ->and($guarantee->contributesToCoverageOn(now()->parse('2026-02-28')))->toBeFalse();
});

it('flags a conflict when an amendment targets no known guarantee', function (): void {
    $emission = Emission::factory()->create();
    $document = attachLegalDocument($emission, LegalDocumentType::TermAmendment, '2025-03-15', '3º Aditamento');

    $this->mock(GeminiService::class)
        ->shouldReceive('extractGuarantees')
        ->once()
        ->andReturn([[
            'event_type' => GuaranteeEventType::Release->value,
            'type' => GuaranteeType::RealEstateFiduciaryAlienation->value,
            'name' => 'Liberação da matrícula 99.999',
            'identification' => ['registration_number' => '99.999'],
            'source_excerpt' => 'A garantia prevista na cláusula 8.3 será liberada.',
            'confidence_score' => 0.7,
        ]]);

    (new GenerateEmissionGuaranteesJob($emission->id, $document->id))
        ->handle(app(GeminiService::class), app(GuaranteeConflictDetector::class));

    $candidate = ExtractedGuarantee::query()->sole();

    expect($candidate->has_conflict)->toBeTrue()
        ->and($candidate->conflict_reason)->toContain('nenhuma garantia confirmada corresponde');
});

it('flags a conflict when an amendment document proposes a brand new constitution', function (): void {
    $emission = Emission::factory()->create();
    $document = attachLegalDocument($emission, LegalDocumentType::TermAmendment, '2025-03-15', '1º Aditamento');

    $this->mock(GeminiService::class)
        ->shouldReceive('extractGuarantees')
        ->once()
        ->andReturn([[
            'event_type' => GuaranteeEventType::Constitution->value,
            'type' => GuaranteeType::Mortgage->value,
            'name' => 'Hipoteca',
            'source_excerpt' => 'Fica constituída hipoteca sobre o imóvel.',
            'confidence_score' => 0.65,
        ]]);

    (new GenerateEmissionGuaranteesJob($emission->id, $document->id))
        ->handle(app(GeminiService::class), app(GuaranteeConflictDetector::class));

    expect(ExtractedGuarantee::query()->sole()->conflict_reason)
        ->toContain('não constitui garantias por si só');
});

it('supersedes pending candidates on reprocessing but never touches reviewed ones', function (): void {
    $emission = Emission::factory()->create();
    $document = attachLegalDocument($emission, LegalDocumentType::SecuritizationTerm, '2024-01-10');
    $actor = makeAdminUser();

    $pending = ExtractedGuarantee::factory()->create([
        'emission_id' => $emission->id,
        'document_id' => $document->id,
    ]);

    $confirmed = ExtractedGuarantee::factory()->approved()->create([
        'emission_id' => $emission->id,
        'document_id' => $document->id,
    ]);

    $rejected = ExtractedGuarantee::factory()->rejected()->create([
        'emission_id' => $emission->id,
        'document_id' => $document->id,
    ]);

    $this->mock(GeminiService::class)
        ->shouldReceive('extractGuarantees')
        ->once()
        ->andReturn([[
            'type' => GuaranteeType::RealEstateFiduciaryAlienation->value,
            'name' => 'AF Imóvel revisada',
            'source_excerpt' => 'Fica constituída a alienação fiduciária.',
            'confidence_score' => 0.9,
        ]]);

    (new GenerateEmissionGuaranteesJob($emission->id, $document->id))
        ->handle(app(GeminiService::class), app(GuaranteeConflictDetector::class));

    expect($pending->refresh()->status)->toBe(GuaranteeDetectionStatus::Superseded)
        ->and($confirmed->refresh()->status)->toBe(GuaranteeDetectionStatus::Approved)
        ->and($rejected->refresh()->status)->toBe(GuaranteeDetectionStatus::Rejected)
        ->and(ExtractedGuarantee::query()->pending()->count())->toBe(1);
});

it('rejects a candidate only with a reason and records the review', function (): void {
    $emission = Emission::factory()->create();
    $actor = makeAdminUser();

    $candidate = ExtractedGuarantee::factory()->create(['emission_id' => $emission->id]);

    expect(fn () => app(GuaranteeSuggestionReviewService::class)->reject($candidate, $actor, '   '))
        ->toThrow(ValidationException::class);

    app(GuaranteeSuggestionReviewService::class)->reject($candidate, $actor, 'Cláusula é definição, não garantia.');

    expect($candidate->refresh()->status)->toBe(GuaranteeDetectionStatus::Rejected)
        ->and($candidate->review_notes)->toBe('Cláusula é definição, não garantia.')
        ->and($candidate->reviewed_by)->toBe($actor->id)
        ->and(Guarantee::query()->count())->toBe(0);
});

it('refuses confirmation from a user without the review permission', function (): void {
    $emission = Emission::factory()->create();
    $candidate = ExtractedGuarantee::factory()->create(['emission_id' => $emission->id]);

    $user = User::factory()->create();

    expect(fn () => app(GuaranteeSuggestionReviewService::class)->approve($candidate, $user))
        ->toThrow(AuthorizationException::class);

    expect($candidate->refresh()->status)->toBe(GuaranteeDetectionStatus::Suggested)
        ->and(Guarantee::query()->count())->toBe(0);
});

it('applies reviewer corrections before confirming', function (): void {
    $emission = Emission::factory()->create();
    $actor = makeAdminUser();

    $candidate = ExtractedGuarantee::factory()->create([
        'emission_id' => $emission->id,
        'contracted_value' => 23_500_000,
    ]);

    $guarantee = app(GuaranteeSuggestionReviewService::class)->approve($candidate, $actor, [
        'contracted_value' => 21_000_000,
        'requirement_basis' => GuaranteeRequirementBasis::Percentage,
        'requirement_percentage' => 1.25,
        'requirement_base' => GuaranteeRequirementBase::OutstandingBalance,
    ], 'Valor ajustado conforme laudo anexo.');

    expect((float) $guarantee->contracted_value)->toBe(21_000_000.0)
        ->and($guarantee->requirement_basis)->toBe(GuaranteeRequirementBasis::Percentage)
        ->and((float) $guarantee->requirement_percentage)->toBe(1.25)
        ->and($candidate->refresh()->review_notes)->toBe('Valor ajustado conforme laudo anexo.');
});
