<?php

use App\Actions\Emissions\GeneratePuDailyCurve;
use App\Actions\Emissions\HomologatePuCurve;
use App\Actions\Emissions\ImportPaymentsFromSpreadsheet;
use App\Actions\Emissions\InvalidatePuCurve;
use App\Domain\PuCalculator\DTOs\PuReading;
use App\Domain\PuCalculator\Enums\PuAmortizationType;
use App\Domain\PuCalculator\Enums\PuEventType;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexRateLookupMode;
use App\Domain\PuCalculator\Services\DecimalRounder;
use App\Domain\PuCalculator\Services\EmissionPuReader;
use App\Domain\PuCalculator\Services\IndexRateLookupService;
use App\Domain\PuCalculator\Services\IndexRateService;
use App\Domain\PuCalculator\Services\PuCurveExtensionService;
use App\Domain\PuCalculator\Services\PuPaymentScheduleService;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\PuHistoriesRelationManager;
use App\Filament\Resources\Emissions\Pages\EditEmission;
use App\Models\Emission;
use App\Models\EmissionPuDailyCurve;
use App\Models\EmissionPuEvent;
use App\Models\IndexRate;
use App\Models\Payment;
use App\Models\PuHistory;
use App\Models\User;
use App\Services\Guarantees\OutstandingBalanceResolver;
use App\Services\Reports\EmissionMonthlyReportService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

function publishOfficialCurveCdi(string $from, string $to): void
{
    for ($date = CarbonImmutable::parse($from); $date->lte(CarbonImmutable::parse($to)); $date = $date->addDay()) {
        if ($date->isWeekend()) {
            continue;
        }

        IndexRate::factory()->create([
            'indexer' => PuIndexer::Cdi->value,
            'rate_date' => $date->toDateString(),
            'rate_value' => '14.90000000',
            'source' => 'testing',
            'source_reference' => 'official-curve',
        ]);
    }

    app(IndexRateService::class)->flushCache();
    app(IndexRateLookupService::class)->flushCache();
}

function officialCurveInterestEvent(Emission $emission, string $originalDate, string $effectiveDate): EmissionPuEvent
{
    return EmissionPuEvent::query()->create([
        'emission_id' => $emission->id,
        'event_type' => PuEventType::InterestPayment->value,
        'original_date' => $originalDate,
        'effective_date' => $effectiveDate,
        'amortization_type' => PuAmortizationType::None->value,
        'amortization_value' => null,
        'sequence' => 1,
    ]);
}

/**
 * Emissão pública com 100 títulos integralizados em 02/03/2026, cupom de juros
 * do dia 08/03 (domingo) pago na segunda, 09/03, e curva gerada até 13/03 pela
 * engine oficial. A curva nasce gerada, não homologada.
 */
function officialCurveEmission(bool $legacyProjection = false): Emission
{
    $emission = Emission::factory()->active()->create([
        'type' => 'CRI',
        'is_public' => true,
        'if_code' => 'CRI26OFC'.random_int(10, 99),
    ]);
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
        'legacy_projection_enabled' => $legacyProjection,
    ]);
    officialCurveInterestEvent($emission, '2026-03-08', '2026-03-09');
    publishOfficialCurveCdi('2026-02-27', '2026-03-13');
    app(GeneratePuDailyCurve::class)->handle($emission->fresh());

    return $emission->fresh();
}

/**
 * A curva do cenário nasce sem maker identificado (gerada direto pela ação),
 * então a homologação registra justificativa; e sempre nomeia a versão.
 */
function homologateOfficialCurve(Emission $emission, string $calculationVersion = 'v1'): void
{
    app(HomologatePuCurve::class)->handle($emission->fresh(), $calculationVersion, User::factory()->create()->id, 'Conferida contra o sistema antigo.');
}

function officialCurveRow(Emission $emission, string $date): EmissionPuDailyCurve
{
    return $emission->currentPuCurveVersion()->dailyCurves()->whereDate('curve_date', $date)->sole();
}

function forecastPayment(Emission $emission, string $date, string $interest): Payment
{
    return Payment::query()->create([
        'emission_id' => $emission->id,
        'payment_date' => $date,
        'premium_value' => '0.00',
        'interest_value' => $interest,
        'amortization_value' => '0.00',
        'extra_amortization_value' => '0.00',
    ]);
}

function puReaderOn(Emission $emission, string $date): ?PuReading
{
    return app(EmissionPuReader::class)->readingOn($emission->fresh(), CarbonImmutable::parse($date));
}

it('keeps a generated curve away from the other areas until it is homologated', function () {
    $emission = officialCurveEmission();
    // PU legado importado de planilha: é o que vale enquanto não há curva homologada.
    PuHistory::query()->create(['emission_id' => $emission->id, 'date' => '2026-03-10', 'unit_value' => '999.00000000', 'source' => PuHistory::SOURCE_IMPORT]);

    $beforeHomologation = puReaderOn($emission, '2026-03-10');

    homologateOfficialCurve($emission);
    $official = puReaderOn($emission, '2026-03-10');
    $curveValue = (string) officialCurveRow($emission, '2026-03-10')->residual_unit_value;

    app(InvalidatePuCurve::class)->handle($emission->fresh(), 'v1', User::factory()->create()->id);
    $afterInvalidation = puReaderOn($emission, '2026-03-10');

    expect($beforeHomologation->source)->toBe(PuReading::SOURCE_PU_HISTORY)
        ->and(bccomp($beforeHomologation->unitValue, '999', 8))->toBe(0)
        ->and($official->source)->toBe(PuReading::SOURCE_OFFICIAL_CURVE)
        ->and($official->unitValue)->toBe($curveValue)
        ->and($official->calculationVersion)->toBe('v1')
        ->and($afterInvalidation->source)->toBe(PuReading::SOURCE_PU_HISTORY);
});

it('feeds the monthly report, the guarantee balance and the public site from the official curve', function () {
    // Segunda, 16/03, 10:00 em Brasília: a curva realizada chega a 16/03 com o CDI
    // até 13/03 e está em dia. O PU de 16/03 responde pelo dia no site e, carregado,
    // pelo mês de março no saldo devedor, cujo PU de fim de mês ainda não existe.
    $this->travelTo(CarbonImmutable::parse('2026-03-16 10:00', 'America/Sao_Paulo'));
    $emission = officialCurveEmission();
    homologateOfficialCurve($emission);
    $lastRow = $emission->currentPuCurveVersion()->dailyCurves()->orderByDesc('curve_date')->first();
    $unitValue = (string) $lastRow->residual_unit_value;

    $report = app(EmissionMonthlyReportService::class)->build($emission->fresh(), CarbonImmutable::parse('2026-03-01'));
    [$balance, $hasData] = app(OutstandingBalanceResolver::class)->resolve($emission->fresh(), '2026-03-01');

    expect($report['header']['current_pu'])->toContain(number_format((float) $unitValue, 8, ',', '.'))
        ->and($hasData)->toBeTrue()
        ->and($balance)->toBe(round((float) $unitValue * 100, 2));

    $this->get(route('site.emissions.show', $emission->if_code))
        ->assertOk()
        ->assertSee('R$ '.number_format((float) $unitValue, 6, ',', '.'))
        ->assertSee(CarbonImmutable::parse((string) $lastRow->curve_date)->format('d/m/Y'));
});

it('takes the next event of the report from the contractual schedule', function () {
    $emission = officialCurveEmission();
    officialCurveInterestEvent($emission, '2031-05-08', '2031-05-08');
    forecastPayment($emission, '2026-04-20', '10.00');

    $report = app(EmissionMonthlyReportService::class)->build($emission->fresh(), CarbonImmutable::parse('2026-04-01'));

    expect($report['header']['next_event'])->toBe('08/05/2031');
});

it('replaces the forecast of every payment the official curve calculated and keeps it as expected', function () {
    $emission = officialCurveEmission();
    $movedForecast = forecastPayment($emission, '2026-03-08', '999.00');
    $strayForecast = forecastPayment($emission, '2026-03-05', '50.00');
    $futureForecast = forecastPayment($emission, '2026-04-09', '777.00');

    homologateOfficialCurve($emission);

    $payment = $movedForecast->fresh();
    $row = officialCurveRow($emission, '2026-03-09');
    $activity = Activity::query()->where('description', 'pu_payments_reconciled')->sole();

    expect($payment->payment_date->toDateString())->toBe('2026-03-09')
        ->and($payment->isCalculatedByOfficialCurve())->toBeTrue()
        ->and((string) $payment->interest_value)->toBe(app(DecimalRounder::class)->round((string) $row->interest_payment_value, 2))
        ->and(bccomp((string) $payment->interest_value, '0', 2))->toBe(1)
        ->and((string) $payment->expected_interest_value)->toBe('999.00')
        ->and($payment->pu_curve_version_id)->toBe($emission->currentPuCurveVersion()->id)
        ->and($strayForecast->fresh()->value_source)->toBeNull()
        ->and((string) $strayForecast->fresh()->interest_value)->toBe('50.00')
        ->and((string) $futureForecast->fresh()->interest_value)->toBe('777.00')
        ->and(Payment::query()->whereBelongsTo($emission)->count())->toBe(3)
        ->and($activity->properties['moved'])->toBe(1)
        ->and($activity->properties['unmatched_forecast_dates'])->toBe(['2026-03-05']);

    app(InvalidatePuCurve::class)->handle($emission->fresh(), 'v1', User::factory()->create()->id);

    expect($payment->fresh()->value_source)->toBeNull()
        ->and((string) $payment->fresh()->interest_value)->toBe('999.00')
        ->and($payment->fresh()->expected_interest_value)->toBeNull();
});

it('creates the calculated payment when there was no forecast and does nothing on a second run', function () {
    $emission = officialCurveEmission();
    homologateOfficialCurve($emission);

    $payment = Payment::query()->whereBelongsTo($emission)->sole();
    $again = app(PuPaymentScheduleService::class)->reconcile($emission->fresh());

    expect($payment->payment_date->toDateString())->toBe('2026-03-09')
        ->and($payment->isCalculatedByOfficialCurve())->toBeTrue()
        ->and($payment->expectedTotal())->toBeNull()
        ->and([$again['updated'], $again['created'], $again['moved'], $again['reverted']])->toBe([0, 0, 0, 0])
        ->and(Activity::query()->where('description', 'pu_payments_reconciled')->count())->toBe(1);
});

it('lets the spreadsheet update only the expected value of a calculated payment', function () {
    Storage::fake('local');
    $emission = officialCurveEmission();
    homologateOfficialCurve($emission);
    $payment = Payment::query()->whereBelongsTo($emission)->sole();
    $calculated = (string) $payment->interest_value;
    Storage::disk('local')->put('imports/official-curve.csv', "Data,Juros\n08/03/2026,\"1234,56\"\n");

    app(ImportPaymentsFromSpreadsheet::class)->handle(Storage::disk('local')->path('imports/official-curve.csv'), $emission->fresh());

    expect(Payment::query()->whereBelongsTo($emission)->count())->toBe(1)
        ->and((string) $payment->fresh()->interest_value)->toBe($calculated)
        ->and((string) $payment->fresh()->expected_interest_value)->toBe('1234.56');
});

it('brings the new payments of a homologated curve in with the daily extension', function () {
    // Fase 4: o cupom de 20/03 só chega pela extensão porque a versão homologada já
    // o aprovou no retrato de insumos. Cadastrado DEPOIS da homologação, ele não
    // entraria na versão (ver PuContractualLifecycleTest).
    $emission = officialCurveEmission();
    officialCurveInterestEvent($emission, '2026-03-20', '2026-03-20');
    app(GeneratePuDailyCurve::class)->handle($emission->fresh());
    homologateOfficialCurve($emission, 'v2');
    publishOfficialCurveCdi('2026-03-16', '2026-03-25');

    $result = app(PuCurveExtensionService::class)->extend($emission->fresh());

    expect($result->action)->toBe(PuCurveExtensionService::ACTION_EXTENDED)
        ->and(Payment::query()->whereBelongsTo($emission)->whereDate('payment_date', '2026-03-20')->sole()->isCalculatedByOfficialCurve())
        ->toBeTrue();
});

it('reconciles emissions with the old legacy projection flag through the official writer only', function () {
    $emission = officialCurveEmission(legacyProjection: true);

    $beforeHomologation = app(PuPaymentScheduleService::class)->reconcile($emission);

    expect($beforeHomologation['action'])->toBe(PuPaymentScheduleService::ACTION_RECONCILED)
        ->and($beforeHomologation['version'])->toBeNull()
        ->and(Payment::query()->whereBelongsTo($emission)->count())->toBe(0)
        ->and(PuHistory::query()->whereBelongsTo($emission)->count())->toBe(0);

    homologateOfficialCurve($emission);

    expect(Payment::query()->whereBelongsTo($emission)->sole()->isCalculatedByOfficialCurve())->toBeTrue()
        ->and(PuHistory::query()->whereBelongsTo($emission)->count())->toBe(0);
});

it('shows on the emission tabs that the official curve feeds the PU and the payments', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $emission = officialCurveEmission();
    homologateOfficialCurve($emission);
    $payment = Payment::query()->whereBelongsTo($emission)->sole();
    $this->actingAs(makeAdminUser());

    Livewire::test(PuHistoriesRelationManager::class, ['ownerRecord' => $emission->fresh(), 'pageClass' => EditEmission::class])
        ->assertSee('Esta emissão tem curva oficial homologada (v1)');

    Livewire::test(PaymentsRelationManager::class, ['ownerRecord' => $emission->fresh(), 'pageClass' => EditEmission::class])
        ->assertSee('Curva oficial')
        ->assertActionVisible(TestAction::make('reconcileWithOfficialCurve')->table())
        ->assertActionHidden(TestAction::make('edit')->table($payment));
});
