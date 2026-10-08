<?php

use App\Actions\Emissions\GeneratePuDailyCurve;
use App\Domain\PuCalculator\Enums\PuAmortizationType;
use App\Domain\PuCalculator\Enums\PuCalculationMethod;
use App\Domain\PuCalculator\Enums\PuCurveChangeImpact;
use App\Domain\PuCalculator\Enums\PuEventType;
use App\Domain\PuCalculator\Enums\PuFinancialEffectSupportLevel;
use App\Domain\PuCalculator\Enums\PuObligationCalculationState;
use App\Domain\PuCalculator\Enums\PuObligationComponent;
use App\Domain\PuCalculator\Enums\PuObligationLifecycle;
use App\Domain\PuCalculator\Enums\PuObligationType;
use App\Domain\PuCalculator\Enums\PuReconciliationStatus;
use App\Domain\PuCalculator\Enums\PuSettlementState;
use App\Domain\PuCalculator\Exceptions\PuCurveInputsException;
use App\Domain\PuCalculator\Services\DecimalRounder;
use App\Domain\PuCalculator\Services\PuCurveChangeImpactClassifier;
use App\Domain\PuCalculator\Services\PuCurveGeneratorService;
use App\Domain\PuCalculator\Services\PuCurveInputSnapshotService;
use App\Domain\PuCalculator\Services\PuCurvePrerequisiteService;
use App\Domain\PuCalculator\Services\PuFinancialEffectSupport;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuDailyCurve;
use App\Models\EmissionPuObligation;
use App\Models\EmissionPuObligationComponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Pu\PuObligationFixture as Fx;

/**
 * Fase 5 -- efeito financeiro contratual só com regra explícita.
 *
 * Amortização extraordinária é tipo próprio (nunca inferida de uma ordinária
 * grande) e reduz o principal pelo valor CONTRATUAL na data efetiva, nunca pelo
 * que foi liquidado. Vencimento antecipado acelera o saldo e supera as
 * obrigações ordinárias seguintes. Waiver só sem efeito no PU. Inadimplemento,
 * cura, juros extraordinários, prêmio e encargos sem regra de cálculo bloqueiam
 * a curva com o motivo -- nunca viram zero.
 */
uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $effect
 * @return array<string, mixed>
 */
function p5eExtraordinary(string $unitValue = '250.0000000000000000', array $effect = [], string $type = PuAmortizationType::UnitValue->value): array
{
    return [
        'amortization_type' => $type,
        'amortization_value' => $unitValue,
        'financial_effect' => $effect + [
            PuFinancialEffectSupport::KEY_PRINCIPAL_REDUCTION => PuFinancialEffectSupport::PRINCIPAL_REDUCTION_ON_EFFECTIVE_DATE,
            PuFinancialEffectSupport::KEY_ACCRUED_INTEREST => PuFinancialEffectSupport::ACCRUED_INTEREST_PAID_BY_INTEREST_PAYMENT,
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function p5eEarlyMaturityEffect(string $charges = PuFinancialEffectSupport::NONE): array
{
    return ['financial_effect' => [
        PuFinancialEffectSupport::KEY_ACCELERATED_PRINCIPAL => PuFinancialEffectSupport::ACCELERATED_PRINCIPAL_OUTSTANDING_BALANCE,
        PuFinancialEffectSupport::KEY_ACCRUED_INTEREST => PuFinancialEffectSupport::ACCRUED_INTEREST_ORDINARY_PRO_RATA,
        PuFinancialEffectSupport::KEY_ADDITIONAL_CHARGES => $charges,
    ]];
}

function p5eBlocked(Emission $emission, string $reason): void
{
    $prerequisites = app(PuCurvePrerequisiteService::class)->handle($emission->fresh());

    expect($prerequisites->passes())->toBeFalse()
        ->and($prerequisites->blockingSummary())->toContain($reason)
        ->and(fn () => app(GeneratePuDailyCurve::class)->handle($emission->fresh()))->toThrow(InvalidArgumentException::class)
        // Nem por fora dos pré-requisitos a engine o ignora ou o calcula como zero.
        ->and(fn () => app(PuCurveGeneratorService::class)->handle($emission->fresh()))->toThrow(PuCurveInputsException::class)
        ->and(EmissionPuCurveVersion::query()->where('emission_id', $emission->id)->whereNotNull('homologated_at')->exists())->toBeFalse()
        ->and(EmissionPuObligationComponent::query()->whereIn('component', [
            PuObligationComponent::DefaultInterest->value,
            PuObligationComponent::Penalty->value,
            PuObligationComponent::ExtraordinaryInterest->value,
            PuObligationComponent::OtherCharges->value,
        ])->exists())->toBeFalse();
}

it('reduces the principal by the contractual extraordinary amount, separately from the ordinary schedule', function () {
    // Um título de R$ 1.000: o principal de 1.000 vira 750 com a amortização
    // extraordinária de 250 em 10/03, paga junto com os juros do período.
    $emission = Fx::emission([
        [PuEventType::InterestPayment, '2026-03-10'],
        [PuEventType::ExtraordinaryAmortization, '2026-03-10', p5eExtraordinary()],
    ], quantity: '1.0000');
    $official = Fx::official($emission);
    $row = Fx::row($official, '2026-03-10');
    $extraordinary = Fx::obligation($emission, PuObligationType::ExtraordinaryAmortization, '2026-03-10');
    $scheduled = Fx::obligation($emission, PuObligationType::ScheduledPayment, '2026-03-10');

    expect((string) $row->residual_unit_value)->toBe('750.0000000000000000')
        ->and($row->calculation_memory['extraordinary_amortizations'])->toBe([['sequence' => 1, 'unit_value_raw' => $row->calculation_memory['extraordinary_amortizations'][0]['unit_value_raw']]])
        ->and($extraordinary->currentCalculation->componentAmount(PuObligationComponent::ExtraordinaryAmortization))->toBe('250.00')
        ->and($extraordinary->currentCalculation->components)->toHaveCount(1)
        // O cupom da data fica no pagamento do cronograma, sem amortização ordinária.
        ->and($scheduled->currentCalculation->componentAmount(PuObligationComponent::OrdinaryInterest))->toBe(app(DecimalRounder::class)->round((string) $row->interest_payment_value, 2))
        ->and($scheduled->currentCalculation->componentAmount(PuObligationComponent::OrdinaryAmortization))->toBeNull()
        ->and($scheduled->currentCalculation->componentAmount(PuObligationComponent::ExtraordinaryAmortization))->toBeNull();

    // Liquidação de 240 contra 250: fechada, divergência de -10, e o principal
    // continua o contratual (750), não 760.
    $fingerprint = app(PuCurveInputSnapshotService::class)->capture($emission->fresh())->fingerprint;
    Fx::settle($extraordinary, '240.00', reference: 'B3-AMEX');
    $extraordinary->refresh();

    expect($extraordinary->settlement_state)->toBe(PuSettlementState::Settled)
        ->and($extraordinary->reconciliation_status)->toBe(PuReconciliationStatus::Divergent)
        ->and((string) $extraordinary->latestReconciliation->difference)->toBe('-10.00')
        ->and((string) Fx::row($official, '2026-03-10')->residual_unit_value)->toBe('750.0000000000000000')
        ->and(EmissionPuObligation::query()->whereBelongsTo($emission)->count())->toBe(2)
        // O evento contratual continua dizendo 250: o valor pago não vira redução de principal.
        ->and((string) $emission->puEvents()->where('event_type', PuEventType::ExtraordinaryAmortization->value)->sole()->amortization_value)->toBe('250.0000000000000000')
        ->and(app(PuCurveInputSnapshotService::class)->capture($emission->fresh())->fingerprint)->toBe($fingerprint)
        ->and(app(PuCurveChangeImpactClassifier::class)->assessVersion($official->fresh())->impact)->toBe(PuCurveChangeImpact::NoCurveImpact);
});

it('capitalizes the accrued interest only when the extraordinary amortization declares it', function () {
    $emission = Fx::emission([
        [PuEventType::ExtraordinaryAmortization, '2026-03-10', p5eExtraordinary(effect: [
            PuFinancialEffectSupport::KEY_ACCRUED_INTEREST => PuFinancialEffectSupport::ACCRUED_INTEREST_CAPITALIZED,
        ])],
    ], quantity: '1.0000');
    $official = Fx::official($emission);
    $row = Fx::row($official, '2026-03-10');

    expect((string) $row->interest_payment_unit_value)->toBe('0.0000000000000000')
        ->and((string) $row->residual_unit_value)->toBe(bcsub((string) $row->updated_unit_value, '250', 16))
        ->and(EmissionPuObligation::query()->whereBelongsTo($emission)->sole()->obligation_type)->toBe(PuObligationType::ExtraordinaryAmortization);
});

it('splits ordinary and extraordinary amortization paid on the same date into distinct obligations', function () {
    $emission = Fx::emission([
        [PuEventType::InterestPayment, '2026-03-10'],
        [PuEventType::Amortization, '2026-03-10', ['amortization_type' => PuAmortizationType::UnitValue->value, 'amortization_value' => '100.0000000000000000', 'sequence' => 2]],
        [PuEventType::ExtraordinaryAmortization, '2026-03-10', p5eExtraordinary()],
    ]);
    $official = Fx::official($emission);
    $row = Fx::row($official, '2026-03-10');
    $scheduled = Fx::obligation($emission, PuObligationType::ScheduledPayment, '2026-03-10');
    $extraordinary = Fx::obligation($emission, PuObligationType::ExtraordinaryAmortization, '2026-03-10');

    expect((string) $row->amortization_value)->toBe('35000.0000000000000000')
        ->and($scheduled->currentCalculation->componentAmount(PuObligationComponent::OrdinaryAmortization))->toBe('10000.00')
        ->and($extraordinary->currentCalculation->componentAmount(PuObligationComponent::ExtraordinaryAmortization))->toBe('25000.00')
        ->and((string) $row->residual_unit_value)->toBe('650.0000000000000000');
});

it('refuses an extraordinary amortization without the explicit contractual rule', function (array $attributes, string $reason) {
    $emission = Fx::emission([
        [PuEventType::InterestPayment, '2026-03-10'],
        [PuEventType::ExtraordinaryAmortization, '2026-03-10', $attributes],
    ], quantity: '1.0000');

    p5eBlocked($emission, $reason);
})->with([
    'sem regra de redução' => [['amortization_type' => 'unit_value', 'amortization_value' => '250', 'financial_effect' => [PuFinancialEffectSupport::KEY_ACCRUED_INTEREST => PuFinancialEffectSupport::ACCRUED_INTEREST_PAID_BY_INTEREST_PAYMENT]], 'principal_reduction'],
    'redução na data da liquidação' => [p5eExtraordinary(effect: [PuFinancialEffectSupport::KEY_PRINCIPAL_REDUCTION => 'on_settlement_date']), 'data da liquidação'],
    'juros só sobre a parcela amortizada' => [p5eExtraordinary(effect: [PuFinancialEffectSupport::KEY_ACCRUED_INTEREST => 'pro_rata_on_amortized_portion']), 'parcela amortizada'],
    'amortização total' => [p5eExtraordinary(type: PuAmortizationType::Residual->value), 'liquidação antecipada'],
    'sem valor' => [p5eExtraordinary(unitValue: '0'), 'valor positivo'],
]);

it('refuses an extraordinary amortization that declares interest paid without an interest payment on the date', function () {
    $emission = Fx::emission([[PuEventType::ExtraordinaryAmortization, '2026-03-10', p5eExtraordinary()]], quantity: '1.0000');

    p5eBlocked($emission, 'não há pagamento de juros ativo na mesma data');
});

it('refuses an extraordinary amortization above the outstanding principal instead of capping it', function () {
    $emission = Fx::emission([
        [PuEventType::InterestPayment, '2026-03-10'],
        [PuEventType::ExtraordinaryAmortization, '2026-03-10', p5eExtraordinary('1500.0000000000000000')],
    ], quantity: '1.0000');

    expect(fn () => app(PuCurveGeneratorService::class)->handle($emission->fresh()))
        ->toThrow(PuCurveInputsException::class, 'maior que o saldo disponível');
});

it('refuses an extraordinary amortization in the fixed-rate engine, which does not calculate it', function () {
    $emission = Fx::emission([
        [PuEventType::InterestPayment, '2026-03-10'],
        [PuEventType::ExtraordinaryAmortization, '2026-03-10', p5eExtraordinary()],
    ], quantity: '1.0000');
    $emission->puParameter->update(['indexer' => 'PREFIXED', 'annual_rate' => '12.00000000', 'spread_rate' => null]);

    expect($emission->puParameter->fresh()->resolvedCalculationMethod())->toBe(PuCalculationMethod::FixedRate);

    p5eBlocked($emission, 'ainda não calcula');
});

it('accelerates the outstanding balance on early maturity and supersedes the later ordinary obligations', function () {
    $emission = Fx::emission([
        [PuEventType::InterestPayment, '2026-03-09'],
        [PuEventType::InterestPayment, '2026-04-09'],
        [PuEventType::InterestPayment, '2026-05-11'],
    ]);
    Fx::publish('2026-03-16', '2026-03-31');
    $v1 = Fx::official($emission);
    $later = Fx::obligation($emission, PuObligationType::ScheduledPayment, '2026-04-09');

    expect($later->isActive())->toBeTrue()
        ->and($later->calculation_state)->toBe(PuObligationCalculationState::AwaitingIndex);

    // Vencimento antecipado declarado em 20/03, aprovado numa versão nova.
    Fx::event($emission, PuEventType::EarlyMaturity, '2026-03-20', p5eEarlyMaturityEffect());
    $v2 = Fx::official($emission);
    $accelerated = Fx::obligation($emission, PuObligationType::EarlyMaturity, '2026-03-20');
    $row = Fx::row($v2, '2026-03-20');
    $later->refresh();
    $last = Fx::obligation($emission, PuObligationType::ScheduledPayment, '2026-05-11');

    expect(Fx::lastDate($v2))->toBe('2026-03-20')
        ->and((string) $row->residual_unit_value)->toBe('0.0000000000000000')
        ->and($row->calculation_memory['early_maturity']['interest_paid_by'])->toBe('early_maturity')
        ->and($accelerated->calculation_state)->toBe(PuObligationCalculationState::Calculated)
        ->and($accelerated->currentCalculation->componentAmount(PuObligationComponent::AcceleratedPrincipal))->toBe('100000.00')
        ->and($accelerated->currentCalculation->componentAmount(PuObligationComponent::OrdinaryInterest))->toBe(app(DecimalRounder::class)->round((string) $row->interest_payment_value, 2))
        ->and($accelerated->currentCalculation->componentAmount(PuObligationComponent::OrdinaryAmortization))->toBeNull()
        // As obrigações ordinárias seguintes não ficam ativas, mas continuam rastreáveis.
        ->and($later->lifecycle_status)->toBe(PuObligationLifecycle::Superseded)
        ->and($later->supersession_reason)->toBe('superseded_by_early_maturity')
        ->and($later->superseded_by_obligation_id)->toBe($accelerated->id)
        ->and($later->reconciliation_status)->toBe(PuReconciliationStatus::NotApplicable)
        ->and($last->lifecycle_status)->toBe(PuObligationLifecycle::Superseded)
        ->and($emission->puEvents()->whereDate('effective_date', '2026-04-09')->sole()->isActive())->toBeTrue()
        ->and(EmissionPuDailyCurve::query()->where('curve_version_id', $v1->id)->count())->toBeGreaterThan(0);

    // A liquidação fecha a obrigação acelerada; diferença é divergência.
    Fx::settle($accelerated, bcsub(Fx::expectedTotal($accelerated), '0.50', 2), reference: 'B3-VA');

    expect($accelerated->fresh()->settlement_state)->toBe(PuSettlementState::Settled)
        ->and($accelerated->fresh()->reconciliation_status)->toBe(PuReconciliationStatus::Divergent)
        ->and((string) $accelerated->fresh()->latestReconciliation->difference)->toBe('-0.50');
});

it('refuses early maturity without the explicit financial terms', function (array $attributes, string $reason) {
    $emission = Fx::emission([[PuEventType::InterestPayment, '2026-03-09']]);
    Fx::event($emission, PuEventType::EarlyMaturity, '2026-03-12', $attributes);

    p5eBlocked($emission, $reason);
})->with([
    'sem termos' => [['financial_effect' => ['description' => 'Assembleia declarou o vencimento antecipado.']], 'accelerated_principal'],
    'com prêmio' => [p5eEarlyMaturityEffect('premium_2_percent'), 'prêmio, multa'],
    'com multa e mora' => [p5eEarlyMaturityEffect('penalty_and_default_interest'), 'prêmio, multa'],
]);

it('supports a waiver only when it declares no effect on the PU, and refuses every ambiguous one', function () {
    $emission = Fx::emission([[PuEventType::InterestPayment, '2026-03-09']]);
    $v1 = Fx::generate($emission);
    Fx::event($emission, PuEventType::Waiver, '2026-03-05', [
        'effective_until' => '2026-03-31',
        'financial_effect' => [PuFinancialEffectSupport::KEY_PU_EFFECT => PuFinancialEffectSupport::NONE, 'description' => 'Dispensa de vencimento antecipado aprovada em assembleia.'],
    ]);
    $v2 = Fx::generate($emission);
    $values = fn (EmissionPuCurveVersion $version): array => EmissionPuDailyCurve::query()->where('curve_version_id', $version->id)->orderBy('curve_date')->pluck('residual_unit_value')->map(fn ($value): string => (string) $value)->all();

    expect($values($v2))->toBe($values($v1))
        ->and($v2->curve_inputs['payload']['events'])->toHaveCount(2);
});

it('refuses a waiver whose financial effect is missing or not modeled', function (array $effect, string $reason) {
    $emission = Fx::emission([[PuEventType::InterestPayment, '2026-03-09']]);
    Fx::event($emission, PuEventType::Waiver, '2026-03-05', ['effective_until' => '2026-03-31', 'financial_effect' => $effect]);

    p5eBlocked($emission, $reason);
})->with([
    'sem efeito declarado' => [['description' => 'Waiver aprovado.'], 'ambíguo'],
    'suspende juros' => [[PuFinancialEffectSupport::KEY_PU_EFFECT => 'suspend_ordinary_interest'], 'só pu_effect = none'],
    'taxa de waiver escondida' => [[PuFinancialEffectSupport::KEY_PU_EFFECT => 'none', 'waiver_fee_rate' => '0.5'], 'só pu_effect = none'],
]);

it('refuses default, cure and every other effect without a calculation rule instead of inventing it', function (PuEventType $type, array $effect) {
    $emission = Fx::emission([[PuEventType::InterestPayment, '2026-03-09']]);
    Fx::event($emission, $type, '2026-03-11', [
        'effective_until' => $type->supportsDuration() ? '2026-03-31' : null,
        'financial_effect' => $effect,
    ]);

    p5eBlocked($emission, 'ainda não calcula');
})->with([
    'mora com taxa declarada' => [PuEventType::Default, ['default_interest_rate' => '1.00', 'basis' => 'monthly', 'penalty_rate' => '2.00']],
    'cura' => [PuEventType::Cure, ['ends' => 'default']],
    'juros extraordinários' => [PuEventType::ExtraordinaryInterest, ['rate' => '3.00']],
    'prêmio' => [PuEventType::Premium, ['amount' => '1000.00']],
    'encargo contratual' => [PuEventType::ContractualCharge, ['amount' => '50.00']],
    'carência' => [PuEventType::GracePeriod, ['interest' => 'capitalized']],
    'diferimento' => [PuEventType::Deferral, ['payment_moves_to' => '2026-04-30']],
    'liquidação antecipada' => [PuEventType::EarlySettlement, ['premium' => '1.00']],
]);

it('keeps an explicit support matrix covering every contractual effect of the catalog', function () {
    $matrix = collect(app(PuFinancialEffectSupport::class)->matrix());
    $effects = $matrix->pluck('effect')->all();
    $levels = $matrix->pluck('level', 'effect');

    expect($effects)->toContain(
        'integralization', 'ordinary_interest', 'ordinary_amortization', 'extraordinary_amortization',
        'extraordinary_interest', 'waiver', 'grace_period', 'deferral', 'default', 'cure', 'early_maturity',
        'early_settlement', 'spread_amendment', 'indexer_change', 'maturity_change', 'premium', 'penalty_charge',
        'cancellation_reversal',
    )
        ->and($matrix->pluck('event_type')->filter()->unique()->sort()->values()->all())
        ->toBe(collect(PuEventType::cases())->map->value->sort()->values()->all())
        ->and($levels['ordinary_interest'])->toBe(PuFinancialEffectSupportLevel::FullyCalculated->value)
        ->and($levels['extraordinary_amortization'])->toBe(PuFinancialEffectSupportLevel::FullyCalculated->value)
        ->and($levels['early_maturity'])->toBe(PuFinancialEffectSupportLevel::FullyCalculated->value)
        ->and($levels['default'])->toBe(PuFinancialEffectSupportLevel::LifecycleOnly->value)
        ->and($levels['premium'])->toBe(PuFinancialEffectSupportLevel::InformedOnly->value)
        ->and($levels['integralization'])->toBe(PuFinancialEffectSupportLevel::NoFinancialObligation->value);
});
