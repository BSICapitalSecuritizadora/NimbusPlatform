<?php

use App\Enums\GuaranteeLegalStatus;
use App\Enums\GuaranteeType;
use App\Enums\LegalInstrumentDocumentRole;
use App\Enums\LegalInstrumentFieldKey;
use App\Enums\LegalInstrumentFieldStatus;
use App\Enums\LegalInstrumentType;
use App\Models\Emission;
use App\Models\Guarantee;
use App\Models\LegalInstrument;
use App\Models\LegalInstrumentDocument;
use App\Models\LegalInstrumentField;
use App\Services\LegalInstruments\InstrumentPositionResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * Monta o cenário obrigatório do escopo (§44): CCB nº 001/2026 com documento
 * original e três aditamentos, cada um alterando uma coisa diferente.
 *
 * @return array{instrument: LegalInstrument, guarantee: Guarantee}
 */
function ccbScenario(): array
{
    $emission = Emission::factory()->create();

    $instrument = LegalInstrument::factory()
        ->ofType(LegalInstrumentType::Ccb, '001/2026')
        ->create(['emission_id' => $emission->id]);

    $original = LegalInstrumentDocument::factory()
        ->original('2024-01-10')
        ->create(['legal_instrument_id' => $instrument->id]);

    $first = LegalInstrumentDocument::factory()
        ->amendment(1, '2024-06-18')
        ->create(['legal_instrument_id' => $instrument->id]);

    $second = LegalInstrumentDocument::factory()
        ->amendment(2, '2025-02-02')
        ->create(['legal_instrument_id' => $instrument->id]);

    $third = LegalInstrumentDocument::factory()
        ->amendment(3, '2026-05-15')
        ->create(['legal_instrument_id' => $instrument->id]);

    $guarantee = Guarantee::factory()
        ->effectiveBetween('2024-01-10')
        ->ofType(GuaranteeType::RealEstateFiduciaryAlienation)
        ->create([
            'emission_id' => $emission->id,
            'legal_instrument_id' => $instrument->id,
            'legal_status' => GuaranteeLegalStatus::Active,
        ]);

    $confirmed = fn (array $attributes) => LegalInstrumentField::factory()->create(array_merge([
        'legal_instrument_id' => $instrument->id,
        'status' => LegalInstrumentFieldStatus::Confirmed,
    ], $attributes));

    // --- CCB original: valor 30 mi, AFI 10.500, cessão 100%, cobertura 120%.
    $confirmed([
        'field_key' => LegalInstrumentFieldKey::OriginalAmount,
        'value_type' => LegalInstrumentFieldKey::OriginalAmount->valueType(),
        'value' => '30000000',
        'value_numeric' => 30_000_000,
        'effective_date' => '2024-01-10',
        'legal_instrument_document_id' => $original->id,
        'clause' => '2.1',
        'page' => 3,
    ]);

    $confirmed([
        'field_key' => LegalInstrumentFieldKey::PrincipalAmount,
        'value_type' => LegalInstrumentFieldKey::PrincipalAmount->valueType(),
        'value' => '30000000',
        'value_numeric' => 30_000_000,
        'effective_date' => '2024-01-10',
        'legal_instrument_document_id' => $original->id,
        'clause' => '2.1',
        'page' => 3,
    ]);

    $confirmed([
        'field_key' => LegalInstrumentFieldKey::MinimumCoverage,
        'value_type' => LegalInstrumentFieldKey::MinimumCoverage->valueType(),
        'value' => '120%',
        'value_numeric' => 1.2,
        'effective_date' => '2024-01-10',
        'legal_instrument_document_id' => $original->id,
        'clause' => '4.2',
        'page' => 6,
    ]);

    $confirmed([
        'field_key' => LegalInstrumentFieldKey::AssignedPercentage,
        'value_type' => LegalInstrumentFieldKey::AssignedPercentage->valueType(),
        'value' => '100%',
        'value_numeric' => 1.0,
        'effective_date' => '2024-01-10',
        'legal_instrument_document_id' => $original->id,
    ]);

    $originalRegistration = $confirmed([
        'field_key' => LegalInstrumentFieldKey::PropertyRegistration,
        'value_type' => LegalInstrumentFieldKey::PropertyRegistration->valueType(),
        'value' => '10.500',
        'value_numeric' => null,
        'guarantee_id' => $guarantee->id,
        'effective_date' => '2024-01-10',
        'legal_instrument_document_id' => $original->id,
        'clause' => '3.1',
        'page' => 4,
    ]);

    // --- 1º Aditamento: valor passa para 35 mi. O valor original permanece.
    $confirmed([
        'field_key' => LegalInstrumentFieldKey::PrincipalAmount,
        'value_type' => LegalInstrumentFieldKey::PrincipalAmount->valueType(),
        'value' => '35000000',
        'value_numeric' => 35_000_000,
        'effective_date' => '2024-06-18',
        'legal_instrument_document_id' => $first->id,
        'clause' => '2.1',
        'page' => 3,
    ]);

    // --- 2º Aditamento: matrícula 10.500 liberada, 18.900 incluída.
    $confirmed([
        'field_key' => LegalInstrumentFieldKey::PropertyRegistration,
        'value_type' => LegalInstrumentFieldKey::PropertyRegistration->valueType(),
        'value' => '18.900',
        'guarantee_id' => $guarantee->id,
        'effective_date' => '2025-02-02',
        'legal_instrument_document_id' => $second->id,
        'supersedes_id' => $originalRegistration->id,
        'clause' => '3.1',
        'page' => 4,
    ]);

    // --- 3º Aditamento: cobertura mínima passa para 130%.
    $confirmed([
        'field_key' => LegalInstrumentFieldKey::MinimumCoverage,
        'value_type' => LegalInstrumentFieldKey::MinimumCoverage->valueType(),
        'value' => '130%',
        'value_numeric' => 1.3,
        'effective_date' => '2026-05-15',
        'legal_instrument_document_id' => $third->id,
        'clause' => '4.2',
        'page' => 7,
    ]);

    return ['instrument' => $instrument->fresh(), 'guarantee' => $guarantee->fresh()];
}

it('consolidates the ccb position after every amendment', function (): void {
    ['instrument' => $instrument, 'guarantee' => $guarantee] = ccbScenario();

    $resolver = app(InstrumentPositionResolver::class);
    $position = $resolver->resolve($instrument, '2026-07-31');

    expect($position->numeric(LegalInstrumentFieldKey::OriginalAmount))->toBe(30_000_000.0)
        ->and($position->numeric(LegalInstrumentFieldKey::PrincipalAmount))->toBe(35_000_000.0)
        ->and($position->numeric(LegalInstrumentFieldKey::MinimumCoverage))->toBe(1.3)
        ->and($position->numeric(LegalInstrumentFieldKey::AssignedPercentage))->toBe(1.0);

    // O valor vigente não apaga o valor original (§9).
    expect($position->value(LegalInstrumentFieldKey::OriginalAmount))->toBe('R$ 30.000.000,00')
        ->and($position->value(LegalInstrumentFieldKey::PrincipalAmount))->toBe('R$ 35.000.000,00')
        ->and($position->value(LegalInstrumentFieldKey::MinimumCoverage))->toBe('130%');

    $registration = $resolver->resolveGuaranteeFields($guarantee, '2026-07-31')
        ->get(LegalInstrumentFieldKey::PropertyRegistration->value);

    expect($registration->formattedValue())->toBe('18.900')
        ->and($registration->previousFormattedValue())->toBe('10.500')
        ->and($registration->hasChanged())->toBeTrue();
});

it('reconstructs the position as it stood on a past date', function (): void {
    ['instrument' => $instrument, 'guarantee' => $guarantee] = ccbScenario();

    $resolver = app(InstrumentPositionResolver::class);

    // 31/12/2024: o 1º aditamento já vale, o 2º e o 3º ainda não.
    $endOf2024 = $resolver->resolve($instrument, '2024-12-31');

    expect($endOf2024->numeric(LegalInstrumentFieldKey::PrincipalAmount))->toBe(35_000_000.0)
        ->and($endOf2024->numeric(LegalInstrumentFieldKey::MinimumCoverage))->toBe(1.2);

    expect(
        $resolver->resolveGuaranteeFields($guarantee, '2024-12-31')
            ->get(LegalInstrumentFieldKey::PropertyRegistration->value)
            ->formattedValue()
    )->toBe('10.500');

    // 31/01/2024: só a constituição vale.
    $justAfterIssue = $resolver->resolve($instrument, '2024-01-31');

    expect($justAfterIssue->numeric(LegalInstrumentFieldKey::PrincipalAmount))->toBe(30_000_000.0)
        ->and($justAfterIssue->numeric(LegalInstrumentFieldKey::MinimumCoverage))->toBe(1.2);
});

it('keeps every superseded version instead of overwriting it', function (): void {
    ['instrument' => $instrument] = ccbScenario();

    $coverageVersions = $instrument->fields()
        ->where('field_key', LegalInstrumentFieldKey::MinimumCoverage->value)
        ->orderBy('effective_date')
        ->get();

    expect($coverageVersions)->toHaveCount(2)
        ->and($coverageVersions->first()->value_numeric)->toBe(1.2)
        ->and($coverageVersions->last()->value_numeric)->toBe(1.3);

    $principalVersions = $instrument->fields()
        ->where('field_key', LegalInstrumentFieldKey::PrincipalAmount->value)
        ->count();

    expect($principalVersions)->toBe(2);
});

it('points every consolidated field to the document, clause and page that support it', function (): void {
    ['instrument' => $instrument] = ccbScenario();

    $position = app(InstrumentPositionResolver::class)->resolve($instrument, '2026-07-31');

    $coverage = $position->field(LegalInstrumentFieldKey::MinimumCoverage);

    expect($coverage->sourceLocation())->toBe('Cláusula 4.2 · Página 7')
        ->and($coverage->effectiveSince())->toBe('15/05/2026')
        ->and($coverage->previousFormattedValue())->toBe('120%');

    $principal = $position->field(LegalInstrumentFieldKey::PrincipalAmount);

    expect($principal->sourceLocation())->toBe('Cláusula 2.1 · Página 3')
        ->and($principal->effectiveSince())->toBe('18/06/2024')
        ->and($principal->previousFormattedValue())->toBe('R$ 30.000.000,00');
});

it('ignores pending versions when building the current position', function (): void {
    ['instrument' => $instrument] = ccbScenario();

    LegalInstrumentField::factory()->pending()->create([
        'legal_instrument_id' => $instrument->id,
        'field_key' => LegalInstrumentFieldKey::MinimumCoverage,
        'value_type' => LegalInstrumentFieldKey::MinimumCoverage->valueType(),
        'value' => '150%',
        'value_numeric' => 1.5,
        'effective_date' => '2026-06-01',
    ]);

    $position = app(InstrumentPositionResolver::class)->resolve($instrument, '2026-07-31');

    expect($position->numeric(LegalInstrumentFieldKey::MinimumCoverage))->toBe(1.3)
        ->and($instrument->fresh()->hasPendingChanges())->toBeTrue();
});

it('reports a missing field as not located instead of zero', function (): void {
    ['instrument' => $instrument] = ccbScenario();

    $position = app(InstrumentPositionResolver::class)->resolve($instrument, '2026-07-31');

    expect($position->value(LegalInstrumentFieldKey::MaturityDate))->toBeNull()
        ->and($position->valueOrNotFound(LegalInstrumentFieldKey::MaturityDate))
        ->toBe('Valor não localizado no documento.');
});

it('orders the dossier by document date regardless of insertion order', function (): void {
    ['instrument' => $instrument] = ccbScenario();

    expect($instrument->documents->pluck('role_label')->all())->toBe([
        'Documento original',
        '1º Aditamento',
        '2º Aditamento',
        '3º Aditamento',
    ]);

    expect($instrument->latestAmendment()->sequence)->toBe(3)
        ->and($instrument->baseDocument()->role)->toBe(LegalInstrumentDocumentRole::Original);
});

/**
 * Regressão: campos de data.
 *
 * A aplicação usa `Date::use(CarbonImmutable::class)`, então `value_date` chega
 * como `CarbonImmutable`. Os demais testes só exercitavam campos de dinheiro,
 * percentual e texto — um tipo declarado como `Illuminate\Support\Carbon`
 * passava verde aqui e estourava na tela ao renderizar um vencimento.
 */
it('formats a date field coming from the database', function (): void {
    ['instrument' => $instrument] = ccbScenario();

    $field = LegalInstrumentField::factory()->create([
        'legal_instrument_id' => $instrument->id,
        'field_key' => LegalInstrumentFieldKey::MaturityDate,
        'value_type' => LegalInstrumentFieldKey::MaturityDate->valueType(),
        'value' => '2030-12-15',
        'value_numeric' => null,
        'value_date' => '2030-12-15',
        'effective_date' => '2024-01-10',
        'status' => LegalInstrumentFieldStatus::Confirmed,
    ]);

    // Recarrega do banco para que o cast produza CarbonImmutable de verdade.
    $fresh = LegalInstrumentField::query()->findOrFail($field->id);

    expect($fresh->value_date)->toBeInstanceOf(CarbonImmutable::class)
        ->and($fresh->formatted_value)->toBe('15/12/2030');

    $position = app(InstrumentPositionResolver::class)->resolve($instrument, '2026-07-31');

    expect($position->value(LegalInstrumentFieldKey::MaturityDate))->toBe('15/12/2030');
});

it('accepts an immutable date when reconstructing a past position', function (): void {
    ['instrument' => $instrument] = ccbScenario();

    $asOf = CarbonImmutable::parse('2024-12-31');

    $position = app(InstrumentPositionResolver::class)->resolve($instrument, $asOf);

    expect($position->numeric(LegalInstrumentFieldKey::MinimumCoverage))->toBe(1.2);
});
