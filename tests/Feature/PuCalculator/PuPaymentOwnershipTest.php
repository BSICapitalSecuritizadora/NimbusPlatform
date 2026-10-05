<?php

use App\Actions\Emissions\GeneratePuDailyCurve;
use App\Actions\Emissions\HomologatePuCurve;
use App\Actions\Emissions\ImportPaymentsFromSpreadsheet;
use App\Actions\Emissions\InvalidatePuCurve;
use App\Domain\PuCalculator\Enums\PuAmortizationType;
use App\Domain\PuCalculator\Enums\PuEventType;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexRateLookupMode;
use App\Domain\PuCalculator\Services\DecimalRounder;
use App\Domain\PuCalculator\Services\IndexRateLookupService;
use App\Domain\PuCalculator\Services\IndexRateService;
use App\Domain\PuCalculator\Services\PuPaymentScheduleService;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuDailyCurve;
use App\Models\EmissionPuEvent;
use App\Models\IndexRate;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

/**
 * Fase 2 -- propriedade dos componentes do pagamento.
 *
 * A curva oficial é dona só dos juros ordinários e da amortização ordinária.
 * Prêmio e amortização extraordinária nunca são zerados nem reescritos por uma
 * homologação, re-homologação ou invalidação; e um pagamento liquidado sob a
 * curva oficial da época não é reescrito por uma curva homologada depois.
 */
uses(RefreshDatabase::class);

function ownershipPublishCdi(string $from, string $to, string $rate = '14.90000000'): void
{
    for ($date = CarbonImmutable::parse($from); $date->lte(CarbonImmutable::parse($to)); $date = $date->addDay()) {
        if ($date->isWeekend()) {
            continue;
        }

        $existing = IndexRate::query()
            ->where('indexer', PuIndexer::Cdi->value)
            ->whereDate('rate_date', $date->toDateString())
            ->first();

        if ($existing instanceof IndexRate) {
            $existing->forceFill(['rate_value' => $rate])->save();

            continue;
        }

        IndexRate::query()->create([
            'indexer' => PuIndexer::Cdi->value,
            'rate_date' => $date->toDateString(),
            'rate_value' => $rate,
            'source' => 'testing',
            'source_reference' => 'ownership',
        ]);
    }

    app(IndexRateService::class)->flushCache();
    app(IndexRateLookupService::class)->flushCache();
}

/**
 * Cupom de juros de 08/03/2026 pago em 09/03 sobre 100 títulos.
 */
function ownershipEmission(): Emission
{
    $emission = Emission::factory()->active()->create(['type' => 'CRI']);
    $emission->integralizationHistories()->create([
        'date' => '2026-03-02',
        'quantity' => '100.0000',
        'unit_value' => '1000.00000000',
        'financial_value' => '100000.00',
        'investor_fund' => 'Head Invest',
    ]);
    $emission->puParameter()->create([
        'curve_start_date' => '2026-03-02',
        'curve_end_date' => '2026-12-31',
        'initial_unit_value' => '1000.0000000000000000',
        'spread_rate' => '6.00000000',
        'indexer' => PuIndexer::Cdi->value,
        'business_day_basis' => 252,
        'calendar_code' => 'B3',
        'index_rate_lookup_mode' => PuIndexRateLookupMode::BusinessDayLagExact->value,
        'index_rate_lag_business_days' => -1,
        'legacy_projection_enabled' => true,
    ]);
    EmissionPuEvent::query()->create([
        'emission_id' => $emission->id,
        'event_type' => PuEventType::InterestPayment->value,
        'original_date' => '2026-03-08',
        'effective_date' => '2026-03-09',
        'amortization_type' => PuAmortizationType::None->value,
        'amortization_value' => null,
        'sequence' => 1,
    ]);
    ownershipPublishCdi('2026-02-27', '2026-03-13');

    return $emission->fresh();
}

function ownershipGenerate(Emission $emission): EmissionPuCurveVersion
{
    $result = app(GeneratePuDailyCurve::class)->handle($emission->fresh());

    return EmissionPuCurveVersion::query()
        ->where('emission_id', $emission->id)
        ->where('calculation_version', $result->calculationVersion)
        ->sole();
}

function ownershipHomologate(Emission $emission, string $calculationVersion): void
{
    app(HomologatePuCurve::class)->handle($emission->fresh(), $calculationVersion, User::factory()->create()->id, 'Conferida.');
}

function ownershipCurveInterest(EmissionPuCurveVersion $version, string $date = '2026-03-09'): string
{
    $row = EmissionPuDailyCurve::query()->where('curve_version_id', $version->id)->whereDate('curve_date', $date)->sole();

    return app(DecimalRounder::class)->round((string) $row->interest_payment_value, 2);
}

/**
 * Previsto da planilha com todos os componentes preenchidos.
 */
function ownershipForecast(Emission $emission, string $date = '2026-03-09'): Payment
{
    return Payment::query()->create([
        'emission_id' => $emission->id,
        'payment_date' => $date,
        'premium_value' => '50.00',
        'interest_value' => '999.00',
        'amortization_value' => '0.00',
        'extra_amortization_value' => '25.00',
    ]);
}

it('keeps premium and extraordinary amortization when the official curve takes a payment over', function () {
    $emission = ownershipEmission();
    $version = ownershipGenerate($emission);
    $payment = ownershipForecast($emission);

    ownershipHomologate($emission, 'v1');
    $payment->refresh();

    expect($payment->isCalculatedByOfficialCurve())->toBeTrue()
        ->and((string) $payment->interest_value)->toBe(ownershipCurveInterest($version))
        ->and((string) $payment->expected_interest_value)->toBe('999.00')
        ->and((string) $payment->expected_amortization_value)->toBe('0.00')
        ->and((string) $payment->premium_value)->toBe('50.00')
        ->and((string) $payment->extra_amortization_value)->toBe('25.00')
        ->and($payment->expected_premium_value)->toBeNull()
        ->and($payment->expected_extra_amortization_value)->toBeNull();
});

it('keeps premium and extraordinary amortization across a re-homologation and an invalidation', function () {
    $emission = ownershipEmission();
    ownershipGenerate($emission);
    $payment = ownershipForecast($emission);
    ownershipHomologate($emission, 'v1');
    $second = ownershipGenerate($emission);

    ownershipHomologate($emission, 'v2');
    $payment->refresh();

    expect($payment->pu_curve_version_id)->toBe($second->id)
        ->and((string) $payment->premium_value)->toBe('50.00')
        ->and((string) $payment->extra_amortization_value)->toBe('25.00');

    app(InvalidatePuCurve::class)->handle($emission->fresh(), 'v2', User::factory()->create()->id);
    app(InvalidatePuCurve::class)->handle($emission->fresh(), 'v1', User::factory()->create()->id);
    $payment->refresh();

    // Sem curva oficial, só os componentes da curva voltam ao previsto.
    expect($payment->value_source)->toBeNull()
        ->and((string) $payment->interest_value)->toBe('999.00')
        ->and((string) $payment->amortization_value)->toBe('0.00')
        ->and((string) $payment->premium_value)->toBe('50.00')
        ->and((string) $payment->extra_amortization_value)->toBe('25.00')
        ->and($payment->expected_interest_value)->toBeNull();
});

it('restores the components the previous reconciliation had zeroed', function () {
    $emission = ownershipEmission();
    $version = ownershipGenerate($emission);
    ownershipHomologate($emission, 'v1');
    $payment = Payment::query()->whereBelongsTo($emission)->sole();
    // Estado deixado pela conciliação anterior à Fase 2: prêmio e amortização
    // extraordinária zerados na linha, o valor da planilha guardado como previsto.
    $payment->forceFill([
        'premium_value' => '0.00',
        'extra_amortization_value' => '0.00',
        'expected_premium_value' => '50.00',
        'expected_interest_value' => '999.00',
        'expected_amortization_value' => '0.00',
        'expected_extra_amortization_value' => '25.00',
    ])->save();

    $result = app(PuPaymentScheduleService::class)->reconcile($emission->fresh());
    $payment->refresh();

    expect($result['restored_components'])->toBe(2)
        ->and((string) $payment->premium_value)->toBe('50.00')
        ->and((string) $payment->extra_amortization_value)->toBe('25.00')
        ->and($payment->expected_premium_value)->toBeNull()
        ->and($payment->expected_extra_amortization_value)->toBeNull()
        ->and((string) $payment->interest_value)->toBe(ownershipCurveInterest($version))
        ->and((string) $payment->expected_interest_value)->toBe('999.00')
        ->and(Activity::query()->where('description', 'pu_payments_reconciled')->latest('id')->first()->properties['restored_components'])->toBe(2);

    expect(app(PuPaymentScheduleService::class)->reconcile($emission->fresh())['restored_components'])->toBe(0);
});

it('never rewrites a payment settled under the curve that was official on its date', function () {
    $emission = ownershipEmission();
    $this->travelTo(CarbonImmutable::parse('2026-03-02 15:00:00'));
    $first = ownershipGenerate($emission);
    ownershipHomologate($emission, 'v1');
    $payment = Payment::query()->whereBelongsTo($emission)->sole();
    $settledInterest = (string) $payment->interest_value;

    // Depois do pagamento, o CDI de 04/03 é corrigido e uma nova curva é homologada.
    $this->travelTo(CarbonImmutable::parse('2026-03-20 15:00:00'));
    ownershipPublishCdi('2026-03-04', '2026-03-04', '15.50000000');
    $second = ownershipGenerate($emission);
    ownershipHomologate($emission, 'v2');
    $payment->refresh();
    $reconciliation = Activity::query()->where('description', 'pu_payments_reconciled')->latest('id')->first();

    expect(ownershipCurveInterest($second))->not->toBe($settledInterest)
        ->and((string) $payment->interest_value)->toBe($settledInterest)
        ->and($payment->pu_curve_version_id)->toBe($first->id)
        ->and($reconciliation->properties['settled_divergent_dates'])->toBe(['2026-03-09']);

    // Invalidar as curvas também não devolve o pagamento liquidado ao previsto.
    app(InvalidatePuCurve::class)->handle($emission->fresh(), 'v2', User::factory()->create()->id);
    app(InvalidatePuCurve::class)->handle($emission->fresh(), 'v1', User::factory()->create()->id);
    $payment->refresh();

    expect($payment->isCalculatedByOfficialCurve())->toBeTrue()
        ->and((string) $payment->interest_value)->toBe($settledInterest)
        ->and($payment->pu_curve_version_id)->toBe($first->id);
});

it('still recalculates a past payment that only a retroactive homologation calculated', function () {
    $emission = ownershipEmission();
    ownershipGenerate($emission);
    $payment = ownershipForecast($emission);
    // Homologada hoje, depois de 09/03: a curva não valia no dia do pagamento.
    ownershipHomologate($emission, 'v1');
    ownershipPublishCdi('2026-03-04', '2026-03-04', '15.50000000');
    $second = ownershipGenerate($emission);

    ownershipHomologate($emission, 'v2');
    $payment->refresh();

    expect(app(PuPaymentScheduleService::class)->isSettled($payment))->toBeFalse()
        ->and($payment->pu_curve_version_id)->toBe($second->id)
        ->and((string) $payment->interest_value)->toBe(ownershipCurveInterest($second))
        ->and((string) $payment->expected_interest_value)->toBe('999.00')
        ->and((string) $payment->premium_value)->toBe('50.00');
});

it('lets the spreadsheet update premium and extraordinary amortization of a calculated payment', function () {
    Storage::fake('local');
    $emission = ownershipEmission();
    $version = ownershipGenerate($emission);
    ownershipHomologate($emission, 'v1');
    $payment = Payment::query()->whereBelongsTo($emission)->sole();
    Storage::disk('local')->put('imports/ownership.csv', "Data,Premio,Juros,Amortizacao Extraordinaria\n09/03/2026,\"12,34\",\"1234,56\",\"7,89\"\n");

    app(ImportPaymentsFromSpreadsheet::class)->handle(Storage::disk('local')->path('imports/ownership.csv'), $emission->fresh());
    $payment->refresh();

    expect((string) $payment->interest_value)->toBe(ownershipCurveInterest($version))
        ->and((string) $payment->expected_interest_value)->toBe('1234.56')
        ->and((string) $payment->premium_value)->toBe('12.34')
        ->and((string) $payment->extra_amortization_value)->toBe('7.89')
        ->and($payment->expected_premium_value)->toBeNull();

    app(PuPaymentScheduleService::class)->reconcile($emission->fresh());

    expect((string) $payment->fresh()->premium_value)->toBe('12.34')
        ->and((string) $payment->fresh()->extra_amortization_value)->toBe('7.89');
});
