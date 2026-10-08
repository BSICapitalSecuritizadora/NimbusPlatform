<?php

use App\Actions\Emissions\GeneratePuDailyCurve;
use App\Actions\Emissions\HomologatePuCurve;
use App\Domain\PuCalculator\DTOs\PuCurveInputSnapshot;
use App\Domain\PuCalculator\Enums\PuAmortizationType;
use App\Domain\PuCalculator\Enums\PuEventDateChangeReason;
use App\Domain\PuCalculator\Enums\PuEventType;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexRateLookupMode;
use App\Domain\PuCalculator\Enums\PuOfficialCurveFreshness;
use App\Domain\PuCalculator\Services\BusinessDayCalendarService;
use App\Domain\PuCalculator\Services\PuCurveInputSnapshotService;
use App\Domain\PuCalculator\Services\PuCurvePrerequisiteService;
use App\Domain\PuCalculator\Services\PuOfficialCurveFreshnessService;
use App\Models\BusinessCalendarDate;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuEvent;
use App\Models\IndexRate;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Fase 4 (P1-05) -- o horizonte canônico da curva.
 *
 * Vencimento num fim de semana: o Termo manda pagar no dia útil seguinte, os juros
 * correm até lá, e a curva precisa ir até esse dia. Antes, a engine parava no
 * vencimento cru e o último pagamento sumia em silêncio. Agora o horizonte é o
 * vencimento ou a data efetiva do pagamento do vencimento deslocado, e o que não
 * se explica assim é recusado com motivo.
 *
 * CDI com defasagem de 1 dia útil: com CDI até sexta, a curva é realizada até a
 * segunda seguinte.
 */
uses(RefreshDatabase::class);

function p4hEmission(string $maturity): Emission
{
    $emission = Emission::factory()->active()->create(['type' => 'CRI', 'issued_quantity' => 1000]);
    $emission->integralizationHistories()->create([
        'date' => '2026-03-02',
        'quantity' => '100.0000',
        'unit_value' => '1000.00000000',
        'financial_value' => '100000.00',
        'investor_fund' => 'Head Invest',
    ]);
    $emission->puParameter()->create([
        'curve_start_date' => '2026-03-02',
        'curve_end_date' => $maturity,
        'initial_unit_value' => '1000.0000000000000000',
        'spread_rate' => '6.00000000',
        'indexer' => PuIndexer::Cdi->value,
        'business_day_basis' => 252,
        'calendar_code' => 'B3',
        'index_rate_lookup_mode' => PuIndexRateLookupMode::BusinessDayLagExact->value,
        'index_rate_lag_business_days' => -1,
        'legacy_projection_enabled' => false,
    ]);

    for ($date = CarbonImmutable::parse('2026-02-23'); $date->lte(CarbonImmutable::parse('2026-04-30')); $date = $date->addDay()) {
        BusinessCalendarDate::query()->create([
            'calendar_code' => 'B3',
            'calendar_date' => $date->toDateString(),
            'is_business_day' => ! $date->isWeekend(),
            'description' => null,
        ]);
    }

    app(BusinessDayCalendarService::class)->flushCache();

    for ($date = CarbonImmutable::parse('2026-02-27'); $date->lte(CarbonImmutable::parse('2026-03-20')); $date = $date->addDay()) {
        if (! $date->isWeekend()) {
            IndexRate::factory()->create([
                'indexer' => PuIndexer::Cdi->value,
                'rate_date' => $date->toDateString(),
                'rate_value' => '14.90000000',
                'source' => 'bcb_sgs',
                'source_reference' => 'bcb_sgs:4389',
            ]);
        }
    }

    return $emission->fresh();
}

/**
 * Pagamento final: juros e amortização residual, da data contratual para a efetiva.
 */
function p4hFinalPayment(Emission $emission, string $original, string $effective, ?string $amortizationEffective = null): void
{
    $shifted = $original !== $effective;
    $justification = $shifted ? [
        'effective_date_reason' => PuEventDateChangeReason::Weekend,
        'effective_date_justification' => 'Vencimento em fim de semana: pagamento no Dia Útil seguinte.',
    ] : [];

    EmissionPuEvent::query()->create([
        'emission_id' => $emission->id,
        'event_type' => PuEventType::InterestPayment->value,
        'original_date' => $original,
        'effective_date' => $effective,
        'amortization_type' => PuAmortizationType::None->value,
        'sequence' => 1,
        ...$justification,
    ]);
    EmissionPuEvent::query()->create([
        'emission_id' => $emission->id,
        'event_type' => PuEventType::Amortization->value,
        'original_date' => $original,
        'effective_date' => $amortizationEffective ?? $effective,
        'amortization_type' => PuAmortizationType::Residual->value,
        'sequence' => 1,
        ...($original !== ($amortizationEffective ?? $effective) ? [
            'effective_date_reason' => PuEventDateChangeReason::IssuerDecision,
            'effective_date_justification' => 'Resgate na data deliberada pela assembleia.',
        ] : []),
    ]);
}

function p4hGenerate(Emission $emission): EmissionPuCurveVersion
{
    $result = app(GeneratePuDailyCurve::class)->handle($emission->fresh());

    return EmissionPuCurveVersion::query()->where('emission_id', $emission->id)->where('calculation_version', $result->calculationVersion)->sole();
}

it('P1-05: extends the curve to the business day the maturity payment moved to', function (string $maturity, string $paymentDate) {
    $emission = p4hEmission($maturity);
    p4hFinalPayment($emission, $maturity, $paymentDate);

    $version = p4hGenerate($emission);
    $inputs = PuCurveInputSnapshot::fromStored($version->curve_inputs);
    $last = $version->dailyCurves()->orderByDesc('curve_date')->first();

    expect($inputs->horizon())->toBe([
        'contractual_maturity_date' => $maturity,
        'curve_end_date' => $paymentDate,
        'curve_start_date' => '2026-03-02',
    ])
        ->and($inputs->provenance['horizon_contributors'][1]['source'])->toBe('adjusted_payment')
        ->and($inputs->provenance['horizon_contributors'][1]['effective_date'])->toBe($paymentDate)
        // A curva vai até a segunda e o pagamento acontece: nada some.
        ->and(CarbonImmutable::parse((string) $last->curve_date)->toDateString())->toBe($paymentDate)
        ->and($last->calculation_memory['event_types'])->toBe(['interest_payment', 'amortization'])
        ->and(bccomp((string) $last->interest_payment_unit_value, '0', 8))->toBe(1)
        ->and(bccomp((string) $last->amortization_unit_value, '999', 8))->toBe(1)
        ->and(bccomp((string) $last->residual_unit_value, '0', 8))->toBe(0);

    app(HomologatePuCurve::class)->handle($emission->fresh(), $version->calculation_version, User::factory()->create()->id, 'Conferida.');
    $status = app(PuOfficialCurveFreshnessService::class)->status($emission->fresh(), CarbonImmutable::parse('2026-03-20 10:00', 'America/Sao_Paulo'));

    expect($status->freshness)->toBe(PuOfficialCurveFreshness::Complete)
        ->and($status->curveEndDate?->toDateString())->toBe($paymentDate)
        ->and(Payment::query()->whereBelongsTo($emission)->whereDate('payment_date', $paymentDate)->sole()->isCalculatedByOfficialCurve())->toBeTrue();
})->with([
    'vencimento no sábado' => ['2026-03-14', '2026-03-16'],
    'vencimento no domingo' => ['2026-03-15', '2026-03-16'],
]);

it('ends on a business-day maturity exactly when the payment stays on it', function () {
    $emission = p4hEmission('2026-03-13');
    p4hFinalPayment($emission, '2026-03-13', '2026-03-13');

    $version = p4hGenerate($emission);
    $last = $version->dailyCurves()->orderByDesc('curve_date')->first();

    expect(PuCurveInputSnapshot::fromStored($version->curve_inputs)->horizonEndDate()->toDateString())->toBe('2026-03-13')
        ->and(CarbonImmutable::parse((string) $last->curve_date)->toDateString())->toBe('2026-03-13')
        ->and(bccomp((string) $last->residual_unit_value, '0', 8))->toBe(0);
});

it('covers a maturity payment settled after the adjusted maturity date', function () {
    // Juros na segunda (convenção) e o resgate na terça, por decisão registrada.
    $emission = p4hEmission('2026-03-14');
    p4hFinalPayment($emission, '2026-03-14', '2026-03-16', '2026-03-17');

    $version = p4hGenerate($emission);
    $monday = $version->dailyCurves()->whereDate('curve_date', '2026-03-16')->sole();
    $tuesday = $version->dailyCurves()->whereDate('curve_date', '2026-03-17')->sole();

    expect(PuCurveInputSnapshot::fromStored($version->curve_inputs)->horizonEndDate()->toDateString())->toBe('2026-03-17')
        ->and($monday->calculation_memory['event_types'])->toBe(['interest_payment'])
        ->and($tuesday->calculation_memory['event_types'])->toBe(['amortization'])
        ->and(bccomp((string) $tuesday->residual_unit_value, '0', 8))->toBe(0)
        ->and($version->dailyCurves()->whereDate('curve_date', '>', '2026-03-17')->exists())->toBeFalse();
});

it('rejects with a reason every event the horizon cannot explain, instead of ignoring it', function (Closure $configure, string $message) {
    $emission = p4hEmission('2026-03-14');
    p4hFinalPayment($emission, '2026-03-14', '2026-03-16');
    $configure($emission);

    $check = app(PuCurvePrerequisiteService::class)->handle($emission->fresh());

    expect($check->passes())->toBeFalse()
        ->and($check->blockingSummary())->toContain($message)
        ->and(fn () => app(GeneratePuDailyCurve::class)->handle($emission->fresh()))->toThrow(InvalidArgumentException::class, $message)
        ->and(EmissionPuCurveVersion::query()->where('emission_id', $emission->id)->count())->toBe(0);
})->with([
    'pagamento depois do vencimento sem data contratual no prazo' => [
        fn (Emission $emission) => EmissionPuEvent::query()->create(['emission_id' => $emission->id, 'event_type' => PuEventType::InterestPayment->value, 'original_date' => '2026-03-20', 'effective_date' => '2026-03-20', 'amortization_type' => 'none', 'sequence' => 1]),
        'sem data contratual dentro do prazo',
    ],
    'pagamento depois do vencimento sem data original' => [
        fn (Emission $emission) => EmissionPuEvent::query()->create(['emission_id' => $emission->id, 'event_type' => PuEventType::Amortization->value, 'original_date' => null, 'effective_date' => '2026-03-18', 'amortization_type' => 'residual', 'sequence' => 2]),
        'sem data contratual dentro do prazo',
    ],
    'evento antes do início da curva' => [
        fn (Emission $emission) => EmissionPuEvent::query()->create(['emission_id' => $emission->id, 'event_type' => PuEventType::InterestPayment->value, 'original_date' => '2026-02-27', 'effective_date' => '2026-02-27', 'amortization_type' => 'none', 'sequence' => 1]),
        'anterior ao início da curva',
    ],
    'integralização depois do vencimento' => [
        fn (Emission $emission) => $emission->integralizationHistories()->create(['date' => '2026-03-18', 'quantity' => '10.0000', 'unit_value' => '1000.00000000', 'financial_value' => '10000.00', 'investor_fund' => 'Fundo']),
        'depois do vencimento',
    ],
    'alteração de spread depois do fim da curva' => [
        fn (Emission $emission) => EmissionPuEvent::query()->create(['emission_id' => $emission->id, 'event_type' => PuEventType::SpreadAmendment->value, 'effective_date' => '2026-03-31', 'financial_effect' => ['spread_rate' => '7'], 'sequence' => 1]),
        'posterior ao fim da curva',
    ],
]);

it('derives the horizon in one place for generation, extension and freshness', function () {
    $emission = p4hEmission('2026-03-14');
    p4hFinalPayment($emission, '2026-03-14', '2026-03-16');
    $live = app(PuCurveInputSnapshotService::class)->capture($emission);
    $version = p4hGenerate($emission);

    // O vencimento vivo muda depois da geração; a versão continua com o horizonte dela.
    $emission->puParameter->update(['curve_end_date' => '2026-03-21']);

    expect($live->horizonEndDate()->toDateString())->toBe('2026-03-16')
        ->and(PuCurveInputSnapshot::fromStored($version->fresh()->curve_inputs)->horizonEndDate()->toDateString())->toBe('2026-03-16')
        ->and(app(PuCurveInputSnapshotService::class)->capture($emission->fresh())->horizonEndDate()->toDateString())->toBe('2026-03-21');
});
