<?php

use App\Domain\PuCalculator\Enums\PuAmortizationType;
use App\Domain\PuCalculator\Enums\PuEventType;
use App\Domain\PuCalculator\Enums\PuObligationComponent;
use App\Domain\PuCalculator\Enums\PuObligationType;
use App\Domain\PuCalculator\Exceptions\PuCurveInputsException;
use App\Domain\PuCalculator\Services\PuCurveGeneratorService;
use App\Domain\PuCalculator\Services\PuCurvePrerequisiteService;
use App\Domain\PuCalculator\Services\PuFinancialEffectSupport;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Pu\PuObligationFixture as Fx;

/**
 * Fase 7 (P0) -- efeito contratual sem regra não vira zero nem é ignorado.
 *
 * O teto da amortização extraordinária só existe quando declarado no evento
 * (`maximum_principal_fraction`, fração da mesma base do percentual): acima dele a
 * curva é recusada, nunca truncada, e sem ele o sistema não inventa teto nenhum
 * (o 98% do Alto Bellevue é exigência a conferir no Termo, não regra genérica). A
 * regra declarada é lida por uma lista fechada de chaves: uma chave que a engine
 * não aplica bloqueia, com o nome dela.
 */
uses(RefreshDatabase::class);

/**
 * Um título de R$ 1.000 com amortização extraordinária em 10/03, paga junto com
 * os juros do período.
 *
 * @param  array<string, mixed>  $effect  chaves a mais (ou substitutas) da regra
 */
function p7cEmission(string $type, string $value, array $effect = []): Emission
{
    return Fx::emission([
        [PuEventType::InterestPayment, '2026-03-10'],
        [PuEventType::ExtraordinaryAmortization, '2026-03-10', [
            'amortization_type' => $type,
            'amortization_value' => $value,
            'financial_effect' => $effect + [
                PuFinancialEffectSupport::KEY_PRINCIPAL_REDUCTION => PuFinancialEffectSupport::PRINCIPAL_REDUCTION_ON_EFFECTIVE_DATE,
                PuFinancialEffectSupport::KEY_ACCRUED_INTEREST => PuFinancialEffectSupport::ACCRUED_INTEREST_PAID_BY_INTEREST_PAYMENT,
            ],
        ]],
    ], quantity: '1.0000');
}

function p7cBlocked(Emission $emission, string $reason): void
{
    $prerequisites = app(PuCurvePrerequisiteService::class)->handle($emission->fresh());

    expect($prerequisites->passes())->toBeFalse()
        ->and($prerequisites->blockingSummary())->toContain($reason)
        ->and(fn () => Fx::generate($emission))->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(PuCurveGeneratorService::class)->handle($emission->fresh()))->toThrow(PuCurveInputsException::class)
        ->and(EmissionPuCurveVersion::query()->where('emission_id', $emission->id)->exists())->toBeFalse();
}

it('applies an explicit contractual cap: a percentage at or below it is calculated, above it the curve is refused instead of truncated', function () {
    $atCap = p7cEmission(PuAmortizationType::Percentage->value, '0.9800000000000000', [PuFinancialEffectSupport::KEY_MAXIMUM_PRINCIPAL_FRACTION => '0.98']);
    $official = Fx::official($atCap);

    expect((string) Fx::row($official, '2026-03-10')->residual_unit_value)->toBe('20.0000000000000000')
        ->and(Fx::obligation($atCap, PuObligationType::ExtraordinaryAmortization, '2026-03-10')->currentCalculation->componentAmount(PuObligationComponent::ExtraordinaryAmortization))->toBe('980.00');

    p7cBlocked(
        p7cEmission(PuAmortizationType::Percentage->value, '0.9900000000000000', [PuFinancialEffectSupport::KEY_MAXIMUM_PRINCIPAL_FRACTION => '0.98']),
        'excede o teto contratual declarado',
    );
});

it('applies the explicit cap to a unit-value amortization at the event date, refusing the curve above it', function () {
    $emission = p7cEmission(PuAmortizationType::UnitValue->value, '250.0000000000000000', [PuFinancialEffectSupport::KEY_MAXIMUM_PRINCIPAL_FRACTION => '0.20']);

    expect(fn () => app(PuCurveGeneratorService::class)->handle($emission->fresh()))
        ->toThrow(PuCurveInputsException::class, 'excede o teto contratual declarado')
        ->and(fn () => Fx::generate($emission))->toThrow(PuCurveInputsException::class, 'não é truncada');

    $withinCap = p7cEmission(PuAmortizationType::UnitValue->value, '250.0000000000000000', [PuFinancialEffectSupport::KEY_MAXIMUM_PRINCIPAL_FRACTION => '0.25']);

    expect((string) Fx::row(Fx::official($withinCap), '2026-03-10')->residual_unit_value)->toBe('750.0000000000000000');
});

it('refuses a malformed cap rather than guessing it', function (mixed $cap) {
    p7cBlocked(
        p7cEmission(PuAmortizationType::Percentage->value, '0.1000000000000000', [PuFinancialEffectSupport::KEY_MAXIMUM_PRINCIPAL_FRACTION => $cap]),
        'maximum_principal_fraction',
    );
})->with([
    'zero' => ['0'],
    'negative' => ['-0.10'],
    'above one' => ['1.5'],
    'percent written as 98' => ['98'],
    'text' => ['noventa e oito'],
    'boolean' => [true],
]);

it('invents no universal cap: without a declared cap, a contractual 99% amortization within the balance is calculated', function () {
    $emission = p7cEmission(PuAmortizationType::Percentage->value, '0.9900000000000000');

    expect((string) Fx::row(Fx::official($emission), '2026-03-10')->residual_unit_value)->toBe('10.0000000000000000');
});

it('blocks a rule key the engine does not apply instead of silently ignoring it', function () {
    p7cBlocked(
        p7cEmission(PuAmortizationType::Percentage->value, '0.9900000000000000', ['maximum_percentage' => '98']),
        'maximum_percentage',
    );

    $earlyMaturity = Fx::emission([
        [PuEventType::InterestPayment, '2026-03-09'],
        [PuEventType::EarlyMaturity, '2026-03-12', ['financial_effect' => [
            PuFinancialEffectSupport::KEY_ACCELERATED_PRINCIPAL => PuFinancialEffectSupport::ACCELERATED_PRINCIPAL_OUTSTANDING_BALANCE,
            PuFinancialEffectSupport::KEY_ACCRUED_INTEREST => PuFinancialEffectSupport::ACCRUED_INTEREST_ORDINARY_PRO_RATA,
            PuFinancialEffectSupport::KEY_ADDITIONAL_CHARGES => PuFinancialEffectSupport::NONE,
            'penalty_rate' => '2.00',
        ]]],
    ]);

    p7cBlocked($earlyMaturity, 'penalty_rate');
});

it('accepts purely descriptive keys next to the rule', function () {
    $emission = p7cEmission(PuAmortizationType::Percentage->value, '0.5000000000000000', [
        'description' => 'Amortização extraordinária da cláusula 4.10',
        'document_reference' => 'Termo de Securitização, cl. 4.10',
        'kind' => 'contractual',
    ]);

    expect(app(PuCurvePrerequisiteService::class)->handle($emission->fresh())->passes())->toBeTrue();
});
