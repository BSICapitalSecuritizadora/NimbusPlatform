<?php

use App\Actions\Emissions\GeneratePuDailyCurve;
use App\Actions\Emissions\HomologatePuCurve;
use App\Actions\Emissions\InvalidatePuCurve;
use App\Domain\PuCalculator\DTOs\PuReading;
use App\Domain\PuCalculator\Enums\PuAmortizationType;
use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Domain\PuCalculator\Enums\PuEventType;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexRateLookupMode;
use App\Domain\PuCalculator\Services\EmissionPuReader;
use App\Domain\PuCalculator\Services\IndexRateLookupService;
use App\Domain\PuCalculator\Services\IndexRateService;
use App\Domain\PuCalculator\Services\PuCurveExtensionService;
use App\Domain\PuCalculator\Services\PuCurveVersionService;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuDailyCurve;
use App\Models\EmissionPuEvent;
use App\Models\IndexRate;
use App\Models\Payment;
use App\Models\PuHistory;
use App\Models\User;
use App\Services\Guarantees\OutstandingBalanceResolver;
use App\Services\Reports\EmissionMonthlyReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Fase 2 -- isolamento da fonte oficial de PU.
 *
 * Calculado não é oficial, validado não é oficial, erro nunca é oficial: só a
 * homologação leva uma curva governada ao leitor, ao relatório, às garantias, ao
 * site, ao Cronograma de Pagamentos e ao `current_pu`. O Histórico de PU continua
 * sendo a fonte das emissões legadas e não serve de atalho para as governadas.
 */
uses(RefreshDatabase::class);

const ISOLATION_LEGACY_PU = '1111.111111';

function isolationPublishCdi(string $from, string $to): void
{
    for ($date = CarbonImmutable::parse($from); $date->lte(CarbonImmutable::parse($to)); $date = $date->addDay()) {
        if ($date->isWeekend()) {
            continue;
        }

        IndexRate::query()->updateOrCreate(
            ['indexer' => PuIndexer::Cdi->value, 'rate_date' => $date->toDateString()],
            ['rate_value' => '14.90000000', 'source' => 'testing', 'source_reference' => 'isolation'],
        );
    }

    app(IndexRateService::class)->flushCache();
    app(IndexRateLookupService::class)->flushCache();
}

/**
 * Emissão pública CDI com a flag legada LIGADA (o default antigo) e `current_pu`
 * de partida conhecido: se qualquer caminho publicasse a curva por ela, apareceria.
 */
function isolationEmission(): Emission
{
    $emission = Emission::factory()->active()->create([
        'type' => 'CRI',
        'is_public' => true,
        'if_code' => 'CRI26ISO'.random_int(10, 99),
        'current_pu' => ISOLATION_LEGACY_PU,
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
    isolationPublishCdi('2026-02-27', '2026-03-13');

    return $emission->fresh();
}

function isolationGenerate(Emission $emission): EmissionPuCurveVersion
{
    $result = app(GeneratePuDailyCurve::class)->handle($emission->fresh());

    return EmissionPuCurveVersion::query()
        ->where('emission_id', $emission->id)
        ->where('calculation_version', $result->calculationVersion)
        ->sole();
}

/**
 * O `current_pu` continua o de partida: comparação numérica, porque o tipo cru
 * devolvido pela coluna decimal depende do driver.
 */
function isolationCurrentPuUntouched(Emission $emission): bool
{
    return bccomp((string) Emission::query()->whereKey($emission->id)->value('current_pu'), ISOLATION_LEGACY_PU, 6) === 0;
}

function isolationReader(): EmissionPuReader
{
    return app(EmissionPuReader::class);
}

/**
 * O que cada consumidor oficial enxerga da emissão na data-base de março de 2026.
 *
 * @return array{reading: ?PuReading, report_pu: string, balance: mixed, has_balance: bool}
 */
function isolationConsumers(Emission $emission): array
{
    $emission = $emission->fresh();
    $report = app(EmissionMonthlyReportService::class)->build($emission, CarbonImmutable::parse('2026-03-01'));
    [$balance, $hasBalance] = app(OutstandingBalanceResolver::class)->resolve($emission, '2026-03-01');

    return [
        'reading' => isolationReader()->readingOn($emission, CarbonImmutable::parse('2026-03-31')),
        'report_pu' => (string) $report['header']['current_pu'],
        'balance' => $balance,
        'has_balance' => $hasBalance,
    ];
}

/**
 * PU da curva na data-base de março (último dia gravado até 31/03), a mesma que
 * o relatório e o saldo devedor das garantias leem.
 */
function isolationLastCurveValue(EmissionPuCurveVersion $version): string
{
    return (string) $version->dailyCurves()
        ->whereDate('curve_date', '<=', '2026-03-31')
        ->orderByDesc('curve_date')
        ->value('residual_unit_value');
}

it('keeps a curve that is not homologated away from every official consumer', function (PuCurveStatus $status) {
    $emission = isolationEmission();
    $version = isolationGenerate($emission);

    if ($status !== PuCurveStatus::Generated) {
        $version->forceFill(['status' => $status])->save();
    }

    $curveValue = isolationLastCurveValue($version);
    $consumers = isolationConsumers($emission);

    expect($version->dailyCurves()->count())->toBeGreaterThan(0)
        ->and(isolationReader()->officialVersion($emission->fresh()))->toBeNull()
        ->and($consumers['reading'])->toBeNull()
        ->and($consumers['report_pu'])->not->toContain(number_format((float) $curveValue, 8, ',', '.'))
        ->and($consumers['has_balance'])->toBeFalse()
        ->and(PuHistory::query()->whereBelongsTo($emission)->count())->toBe(0)
        ->and(Payment::query()->whereBelongsTo($emission)->count())->toBe(0)
        ->and(isolationCurrentPuUntouched($emission))->toBeTrue();

    $this->get(route('site.emissions.show', $emission->if_code))
        ->assertOk()
        ->assertDontSee('R$ '.number_format((float) $curveValue, 6, ',', '.'))
        ->assertDontSee('R$ '.number_format((float) ISOLATION_LEGACY_PU, 6, ',', '.'));
})->with([
    'gerada' => [PuCurveStatus::Generated],
    'validada' => [PuCurveStatus::Validated],
    'divergente' => [PuCurveStatus::Divergent],
    'com erro (linhas gravadas antes da falha)' => [PuCurveStatus::Error],
]);

it('never projects an unhomologated curve into the legacy tables through the daily extension', function () {
    $emission = isolationEmission();
    $version = isolationGenerate($emission);
    isolationPublishCdi('2026-03-16', '2026-03-20');

    $result = app(PuCurveExtensionService::class)->extend($emission->fresh());

    expect($result->action)->toBe(PuCurveExtensionService::ACTION_EXTENDED)
        ->and($result->versionId)->toBe($version->id)
        ->and(PuHistory::query()->whereBelongsTo($emission)->count())->toBe(0)
        ->and(Payment::query()->whereBelongsTo($emission)->count())->toBe(0)
        ->and(isolationCurrentPuUntouched($emission))->toBeTrue()
        ->and(isolationReader()->readingOn($emission->fresh(), CarbonImmutable::parse('2026-03-20')))->toBeNull();
});

it('makes the curve official exactly when it is homologated', function () {
    // Com a curva oficial em dia (16/03, CDI até 13/03): o PU carregado de 16/03
    // responde pelo mês no saldo devedor e pelo dia no site.
    $this->travelTo(CarbonImmutable::parse('2026-03-16 10:00', 'America/Sao_Paulo'));
    $emission = isolationEmission();
    $version = isolationGenerate($emission);
    $curveValue = isolationLastCurveValue($version);

    expect(isolationConsumers($emission)['reading'])->toBeNull();

    app(HomologatePuCurve::class)->handle($emission, 'v1', User::factory()->create()->id, 'Conferida.');
    $consumers = isolationConsumers($emission);

    expect($consumers['reading']->source)->toBe(PuReading::SOURCE_OFFICIAL_CURVE)
        ->and($consumers['reading']->unitValue)->toBe($curveValue)
        ->and($consumers['reading']->calculationVersion)->toBe('v1')
        ->and($consumers['report_pu'])->toContain(number_format((float) $curveValue, 8, ',', '.'))
        ->and($consumers['has_balance'])->toBeTrue()
        ->and($consumers['balance'])->toBe(round((float) $curveValue * 100, 2))
        // O PU oficial é lido da curva; o motor não grava o legado nem o `current_pu`.
        ->and(PuHistory::query()->whereBelongsTo($emission)->count())->toBe(0)
        ->and(isolationCurrentPuUntouched($emission))->toBeTrue();

    $this->get(route('site.emissions.show', $emission->if_code))
        ->assertOk()
        ->assertSee('R$ '.number_format((float) $curveValue, 6, ',', '.'));
});

it('does not let a newer unhomologated version replace the official one', function () {
    $emission = isolationEmission();
    $official = isolationGenerate($emission);
    app(HomologatePuCurve::class)->handle($emission, 'v1', User::factory()->create()->id, 'Conferida.');
    $newer = isolationGenerate($emission);
    app(PuCurveVersionService::class)->markValidated($newer, true, ['status' => 'approved'], null);

    expect($emission->fresh()->currentPuCurveVersion()?->id)->toBe($newer->id)
        ->and(isolationReader()->officialVersion($emission->fresh())?->id)->toBe($official->id)
        ->and(isolationConsumers($emission)['reading']->calculationVersion)->toBe('v1');
});

it('falls back to the previous homologated curve, and never to an unhomologated one, when the official is invalidated', function () {
    $emission = isolationEmission();
    $first = isolationGenerate($emission);
    app(HomologatePuCurve::class)->handle($emission, 'v1', User::factory()->create()->id, 'Conferida.');
    $second = isolationGenerate($emission);
    app(HomologatePuCurve::class)->handle($emission, 'v2', User::factory()->create()->id, 'Reprocessada.');
    $unhomologated = isolationGenerate($emission);

    app(InvalidatePuCurve::class)->handle($emission, 'v2', User::factory()->create()->id, 'Evento errado.');

    expect($second->fresh()->status)->toBe(PuCurveStatus::Obsolete)
        ->and($unhomologated->fresh()->status)->toBe(PuCurveStatus::Generated)
        ->and(isolationReader()->officialVersion($emission->fresh())?->id)->toBe($first->id)
        ->and(isolationConsumers($emission)['reading']->calculationVersion)->toBe('v1');

    app(InvalidatePuCurve::class)->handle($emission, 'v1', User::factory()->create()->id, 'Também errada.');

    expect(isolationReader()->officialVersion($emission->fresh()))->toBeNull()
        ->and(isolationConsumers($emission)['reading'])->toBeNull();
});

// ---------------------------------------------------------------------------
// Emissão legada × emissão governada
// ---------------------------------------------------------------------------

it('serves a legacy emission from its whole PU history and its registered current PU', function () {
    $emission = Emission::factory()->active()->create(['type' => 'CRI', 'current_pu' => ISOLATION_LEGACY_PU]);
    PuHistory::query()->create(['emission_id' => $emission->id, 'date' => '2026-03-10', 'unit_value' => '1005.123456']);
    $withoutHistory = Emission::factory()->active()->create(['type' => 'CRI', 'current_pu' => ISOLATION_LEGACY_PU]);

    $reading = isolationReader()->readingOn($emission, CarbonImmutable::parse('2026-03-13'));

    expect(isolationReader()->isGoverned($emission))->toBeFalse()
        ->and($reading->source)->toBe(PuReading::SOURCE_PU_HISTORY)
        ->and(bccomp($reading->unitValue, '1005.123456', 6))->toBe(0)
        ->and(isolationReader()->legacyCurrentUnitValue($withoutHistory))->toBe(ISOLATION_LEGACY_PU);
});

it('does not let the PU history publish what the engine wrote for a governed emission', function () {
    $emission = isolationEmission();
    $governedSince = CarbonImmutable::parse('2026-10-01 12:00:00');
    $this->travelTo($governedSince->subDays(10));
    $preGovernance = PuHistory::query()->create(['emission_id' => $emission->id, 'date' => '2026-03-05', 'unit_value' => '1001.000000']);
    $this->travelTo($governedSince);
    isolationGenerate($emission);
    $this->travelTo($governedSince->addHour());
    // Linha sem origem gravada depois da primeira geração: é o que a projeção
    // legada escrevia até a Fase 2 -- a curva não homologada, vista como histórico.
    PuHistory::query()->create(['emission_id' => $emission->id, 'date' => '2026-03-12', 'unit_value' => '1009.999999']);

    $reading = isolationReader()->readingOn($emission->fresh(), CarbonImmutable::parse('2026-03-13'));

    expect(isolationReader()->isGoverned($emission->fresh()))->toBeTrue()
        ->and($reading->source)->toBe(PuReading::SOURCE_PU_HISTORY)
        ->and($reading->date->toDateString())->toBe('2026-03-05')
        ->and(bccomp($reading->unitValue, (string) $preGovernance->unit_value, 6))->toBe(0)
        // Nem o `current_pu` responde por emissão governada.
        ->and(isolationReader()->legacyCurrentUnitValue($emission->fresh()))->toBeNull();
});

it('keeps imported and manually entered PU history valid for a governed emission awaiting homologation', function (string $source) {
    $emission = isolationEmission();
    isolationGenerate($emission);
    $this->travelTo(now()->addHour());
    PuHistory::query()->create(['emission_id' => $emission->id, 'date' => '2026-03-12', 'unit_value' => '1009.999999', 'source' => $source]);

    $reading = isolationReader()->readingOn($emission->fresh(), CarbonImmutable::parse('2026-03-13'));

    expect($reading->source)->toBe(PuReading::SOURCE_PU_HISTORY)
        ->and($reading->date->toDateString())->toBe('2026-03-12');
})->with([
    'planilha' => [PuHistory::SOURCE_IMPORT],
    'lançamento manual' => [PuHistory::SOURCE_MANUAL],
]);

it('reads the official curve, never the PU history, for the dates the official curve covers', function () {
    $emission = isolationEmission();
    $version = isolationGenerate($emission);
    PuHistory::query()->create(['emission_id' => $emission->id, 'date' => '2026-03-12', 'unit_value' => '1.000000', 'source' => PuHistory::SOURCE_IMPORT]);
    app(HomologatePuCurve::class)->handle($emission, 'v1', User::factory()->create()->id, 'Conferida.');

    $reading = isolationReader()->readingOn($emission->fresh(), CarbonImmutable::parse('2026-03-12'));
    $row = EmissionPuDailyCurve::query()->where('curve_version_id', $version->id)->whereDate('curve_date', '2026-03-12')->sole();

    expect($reading->source)->toBe(PuReading::SOURCE_OFFICIAL_CURVE)
        ->and($reading->unitValue)->toBe((string) $row->residual_unit_value);
});
