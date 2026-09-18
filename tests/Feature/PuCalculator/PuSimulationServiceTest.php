<?php

use App\Domain\PuCalculator\DTOs\PuCurveGenerationResult;
use App\Domain\PuCalculator\DTOs\PuDailyCurveRowData;
use App\Domain\PuCalculator\DTOs\PuSimulationInput;
use App\Domain\PuCalculator\Enums\PuIndexRateLookupMode;
use App\Domain\PuCalculator\Enums\PuSimulationState;
use App\Domain\PuCalculator\Enums\PuSimulationValueOrigin;
use App\Domain\PuCalculator\Services\BusinessCalendarService;
use App\Domain\PuCalculator\Services\DecimalRounder;
use App\Domain\PuCalculator\Services\PuBaselineCandidateFactory;
use App\Domain\PuCalculator\Services\PuCurveGeneratorService;
use App\Domain\PuCalculator\Services\PuSimulationService;
use App\Models\Emission;
use App\Models\EmissionPuEvent;
use App\Models\EmissionPuParameter;
use App\Models\IndexRate;
use App\Models\IntegralizationHistory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Pu\PuSimulationFixture;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    // A simulação é determinística sobre dados locais: qualquer tentativa de
    // fetch externo durante `simulate()` estoura o teste.
    Http::preventStrayRequests();
});

function simulations(): PuSimulationService
{
    return app(PuSimulationService::class);
}

// ---------------------------------------------------------------------------
// Resolução de parâmetros
// ---------------------------------------------------------------------------

it('loads the confirmed contractual baseline without any persisted parameter', function () {
    $emission = PuSimulationFixture::contractualEmission();

    $resolution = simulations()->resolveParameters($emission, new PuSimulationInput);
    $values = $resolution['values'];
    $origins = $resolution['origins'];

    expect($values['indexer'])->toBe('CDI')
        ->and($values['spread_rate'])->not->toBeNull()
        ->and($values['business_day_basis'])->toBe(252)
        ->and($values['calendar_code'])->toBe(PuSimulationFixture::CALENDAR_CODE)
        ->and($values['index_rate_lookup_mode'])->toBe(PuIndexRateLookupMode::BusinessDayLagExact->value)
        ->and((int) $values['index_rate_lag_business_days'])->toBe(-5)
        ->and($origins['indexer'])->toBe(PuSimulationValueOrigin::Contractual->value)
        ->and($origins['calendar_code'])->toBe(PuSimulationValueOrigin::Contractual->value);
});

it('reuses the canonical contractual spread without changing the legal fraction', function () {
    $emission = PuSimulationFixture::contractualEmission();
    $spread = $emission->legalInstrumentFields()->where('field_key', 'spread')->firstOrFail();
    $rawSpread = (string) $spread->getRawOriginal('value_numeric');
    $candidate = app(PuBaselineCandidateFactory::class)->make($emission, null);

    $resolution = simulations()->resolveParameters($emission, new PuSimulationInput(
        firstIntegralizationDate: PuSimulationFixture::integralizationDate(),
    ));

    expect(bccomp($rawSpread, '0.06', DecimalRounder::RATE_SCALE))->toBe(0)
        ->and($candidate->configuration['spread_rate'])->toBe('6.00000000')
        ->and($candidate->configuration['index_percentage'])->toBe('100.00000000')
        ->and($resolution['values']['spread_rate'])->toBe('6.00000000')
        ->and($resolution['parameter']->spread_rate)->toBe('6.00000000')
        ->and($resolution['origins']['spread_rate'])->toBe(PuSimulationValueOrigin::Contractual->value)
        ->and(PuSimulationFixture::syntheticParameter()->spread_rate)->toBe('6.00000000')
        ->and((string) $spread->fresh()->getRawOriginal('value_numeric'))->toBe($rawSpread);
});

it('preserves persisted spread percentage points without double conversion', function (string $spreadRate) {
    $emission = PuSimulationFixture::contractualEmission();
    $persisted = PuSimulationFixture::persistParameter($emission, ['spread_rate' => $spreadRate]);

    $resolution = simulations()->resolveParameters($emission->fresh(), new PuSimulationInput);
    $spreadConflict = collect($resolution['conflicts'])->firstWhere('field', 'spread_rate');

    expect($resolution['values']['spread_rate'])->toBe($spreadRate)
        ->and($resolution['parameter']->spread_rate)->toBe($spreadRate)
        ->and($resolution['origins']['spread_rate'])->toBe(PuSimulationValueOrigin::Persisted->value)
        ->and($persisted->fresh()->spread_rate)->toBe($spreadRate);

    if ($spreadRate === '6.00000000') {
        expect($spreadConflict)->toBeNull();
    } else {
        expect($spreadConflict['contractual'])->toBe('6.00000000')
            ->and($spreadConflict['persisted'])->toBe($spreadRate);
    }
})->with([
    'six percent annually' => ['6.00000000'],
    'intentional fractional percentage point' => ['0.06000000'],
]);

it('interprets spread overrides as annual percentage points', function (string $override, string $expected) {
    $emission = PuSimulationFixture::contractualEmission();
    $persisted = PuSimulationFixture::persistParameter($emission);

    $resolution = simulations()->resolveParameters($emission->fresh(), new PuSimulationInput(
        overrides: ['spread_rate' => $override],
    ));

    expect($resolution['parameter']->spread_rate)->toBe($expected)
        ->and(bccomp($resolution['values']['spread_rate'], $expected, DecimalRounder::RATE_SCALE))->toBe(0)
        ->and($resolution['origins']['spread_rate'])->toBe(PuSimulationValueOrigin::SimulationOverride->value)
        ->and($persisted->fresh()->spread_rate)->toBe('6.00000000');
})->with([
    'decimal point' => ['7.5', '7.50000000'],
    'decimal comma' => ['7,5', '7.50000000'],
    'localized six percent' => ['6,00', '6.00000000'],
    'intentional fractional percentage point' => ['0.06', '0.06000000'],
]);

it('reports the first integralization date as undefined until the user informs it', function () {
    $emission = PuSimulationFixture::contractualEmission();

    $resolution = simulations()->resolveParameters($emission, new PuSimulationInput);

    expect($resolution['values']['curve_start_date'])->toBeNull()
        ->and($resolution['origins']['curve_start_date'])->toBe(PuSimulationValueOrigin::Undefined->value)
        ->and($resolution['missing'])->toContain('curve_start_date')
        ->and($resolution['parameter'])->toBeNull();
});

it('accepts an explicit simulation first integralization date as an override', function () {
    $emission = PuSimulationFixture::contractualEmission();

    $resolution = simulations()->resolveParameters($emission, new PuSimulationInput(
        firstIntegralizationDate: PuSimulationFixture::integralizationDate(),
    ));

    expect($resolution['values']['curve_start_date'])
        ->toBe(PuSimulationFixture::integralizationDate()->toDateString())
        ->and($resolution['origins']['curve_start_date'])
        ->toBe(PuSimulationValueOrigin::SimulationOverride->value)
        ->and($resolution['missing'])->not->toContain('curve_start_date')
        ->and($resolution['parameter'])->toBeInstanceOf(EmissionPuParameter::class);
});

it('never persists the simulation parameter it builds in memory', function () {
    $emission = PuSimulationFixture::contractualEmission();

    $resolution = simulations()->resolveParameters($emission, new PuSimulationInput(
        firstIntegralizationDate: PuSimulationFixture::integralizationDate(),
    ));

    /** @var EmissionPuParameter $parameter */
    $parameter = $resolution['parameter'];

    expect($parameter->exists)->toBeFalse()
        ->and($parameter->getKey())->toBeNull()
        ->and($parameter->emission_id)->toBeNull()
        ->and(EmissionPuParameter::query()->count())->toBe(0);
});

it('reports missing input when a mandatory field has no origin', function () {
    $emission = PuSimulationFixture::contractualEmission();

    $result = simulations()->simulate($emission, new PuSimulationInput);

    expect($result->state)->toBe(PuSimulationState::MissingInput)
        ->and($result->missingFields)->toContain('curve_start_date')
        ->and($result->rows)->toBe([]);
});

it('lets an explicit override win over the persisted parameter and marks the origin', function () {
    $emission = PuSimulationFixture::contractualEmission();
    PuSimulationFixture::persistParameter($emission);

    $resolution = simulations()->resolveParameters($emission->fresh(), new PuSimulationInput(
        overrides: ['spread_rate' => '0.09'],
    ));

    expect($resolution['values']['spread_rate'])->toBe('0.09')
        ->and($resolution['origins']['spread_rate'])->toBe(PuSimulationValueOrigin::SimulationOverride->value)
        ->and($resolution['origins']['calendar_code'])->toBe(PuSimulationValueOrigin::Persisted->value);
});

it('surfaces a conflict between the contractual reading and the persisted parameter', function () {
    $emission = PuSimulationFixture::contractualEmission();
    PuSimulationFixture::persistParameter($emission, ['spread_rate' => '0.0700']);

    $resolution = simulations()->resolveParameters($emission->fresh(), new PuSimulationInput);
    $spreadConflict = collect($resolution['conflicts'])->firstWhere('field', 'spread_rate');

    expect($spreadConflict)->not->toBeNull()
        ->and($spreadConflict['used'])->toBe($spreadConflict['persisted']);
});

// ---------------------------------------------------------------------------
// Equivalência com o motor oficial — requisito crítico da fase
// ---------------------------------------------------------------------------

it('passes the same contractual event set to both engine entrypoints before calculation', function () {
    $scenario = PuSimulationFixture::calculableScenario();
    $eventSets = [];
    $parameterSets = [];
    $timelineSets = [];
    $this->mock(PuCurveGeneratorService::class)
        ->shouldReceive('handle')
        ->twice()
        ->andReturnUsing(function (Emission $engineScenario) use (
            &$eventSets,
            &$parameterSets,
            &$timelineSets,
        ): PuCurveGenerationResult {
            $eventSets[] = $engineScenario->puEvents
                ->map(fn (EmissionPuEvent $event): array => PuSimulationFixture::eventSignature($event))
                ->values()
                ->all();
            $parameterSets[] = PuSimulationFixture::engineParameterSignature($engineScenario->puParameter);
            $timelineSets[] = $engineScenario->integralizationHistories->count();

            return new PuCurveGenerationResult([]);
        });

    $simulation = simulations()->simulate($scenario['emission'], $scenario['input']);
    PuSimulationFixture::officialEngineRows($scenario['emission'], $scenario);

    expect($simulation->state)->toBe(PuSimulationState::Calculated)
        ->and($eventSets)->toHaveCount(2)
        ->and($parameterSets)->toHaveCount(2)
        // Os TRÊS insumos da engine -- eventos, parâmetro e timeline -- são
        // idênticos nos dois entrypoints. Qualquer divergência de linha
        // financeira depois disto é da engine, nunca do adapter de simulação.
        ->and($eventSets[0])->toBe($eventSets[1])
        ->and($parameterSets[0])->toBe($parameterSets[1])
        ->and($timelineSets)->toBe([0, 0])
        // O recorte da janela é o parâmetro da engine; o vencimento contratual
        // de 2031 nunca chega até aqui.
        ->and($parameterSets[0]['curve_end_date'])->toBe('2026-06-30')
        ->and($parameterSets[0]['curve_start_date'])->toBe('2026-05-15')
        ->and($eventSets[0])->toBe([[
            'event_type' => 'interest_payment',
            'original_date' => '2026-06-08',
            'effective_date' => '2026-06-08',
            'amortization_type' => 'none',
            'amortization_value' => null,
            'sequence' => 1,
        ]]);
});

it('does not turn the simulation cutoff into principal redemption', function () {
    $scenario = PuSimulationFixture::calculableScenario();

    $result = simulations()->simulate($scenario['emission'], $scenario['input']);
    $last = $result->lastRow();

    expect($result->state)->toBe(PuSimulationState::Calculated)
        ->and($result->parameters['contractual_curve_end_date'])->toBe('2031-05-08')
        ->and($result->parameters['curve_end_date'])->toBe('2026-06-30')
        ->and($result->events)->toHaveCount(1)
        ->and($result->events[0]['event_type'])->toBe('interest_payment')
        ->and($last->date->toDateString())->toBe('2026-06-30')
        ->and($last->eventOriginalDate)->toBeNull()
        ->and($last->eventEffectiveDate)->toBeNull()
        ->and($last->amortizationRatio)->toBe('0.0000000000000000')
        ->and($last->amortizationUnitValue)->toBe('0.0000000000000000')
        ->and($last->paymentTotalUnitValue)->toBe('0.0000000000000000')
        ->and($last->residualUnitValue)->toBe($last->updatedUnitValue);
});

it('preserves the contractual bullet at maturity without generating a five year curve', function () {
    $emission = PuSimulationFixture::contractualEmission();
    $before = PuSimulationFixture::counts();

    $events = PuSimulationFixture::officialEvents($emission, CarbonImmutable::parse('2031-05-08'));
    $principal = $events->where('event_type', 'amortization')->values();
    $lastCoupon = $events->where('event_type', 'interest_payment')->last();

    expect($principal)->toHaveCount(1)
        ->and(PuSimulationFixture::eventSignature($principal->first()))->toBe([
            'event_type' => 'amortization',
            'original_date' => '2031-05-08',
            'effective_date' => '2031-05-08',
            'amortization_type' => 'residual',
            'amortization_value' => null,
            'sequence' => 1,
        ])
        ->and($lastCoupon->original_date->toDateString())->toBe('2031-05-08')
        ->and($lastCoupon->effective_date->toDateString())->toBe('2031-05-08')
        ->and(PuSimulationFixture::counts())->toBe($before);
});

it('produces exactly the same rows as the official engine for the same input', function () {
    $scenario = PuSimulationFixture::calculableScenario();
    $emission = $scenario['emission'];

    $simulation = simulations()->simulate($emission, $scenario['input']);

    expect($simulation->state)->toBe(PuSimulationState::Calculated)
        ->and($simulation->rows)->not->toBe([]);

    // Mesmo input, montado à mão e entregue diretamente à engine oficial.
    $official = PuSimulationFixture::officialEngineRows($emission, $scenario);

    expect(count($simulation->rows))->toBe(count($official));

    foreach ($simulation->rows as $index => $row) {
        expect(PuSimulationFixture::rowSignature($row))
            ->toBe(PuSimulationFixture::rowSignature($official[$index]));
    }
});

it('matches the official engine on the final PU, factors and rate dates', function () {
    $scenario = PuSimulationFixture::calculableScenario();
    $simulation = simulations()->simulate($scenario['emission'], $scenario['input']);
    $official = PuSimulationFixture::officialEngineRows($scenario['emission'], $scenario);

    /** @var PuDailyCurveRowData $simulationLast */
    $simulationLast = $simulation->rows[array_key_last($simulation->rows)];
    /** @var PuDailyCurveRowData $officialLast */
    $officialLast = $official[array_key_last($official)];

    expect($simulationLast->updatedUnitValue)->toBe($officialLast->updatedUnitValue)
        ->and($simulationLast->residualUnitValue)->toBe($officialLast->residualUnitValue)
        ->and($simulationLast->factorDi)->toBe($officialLast->factorDi)
        ->and($simulationLast->factorDiAccumulated)->toBe($officialLast->factorDiAccumulated)
        ->and($simulationLast->factorSpread)->toBe($officialLast->factorSpread)
        ->and($simulationLast->factorSpreadDi)->toBe($officialLast->factorSpreadDi)
        ->and($simulationLast->indexRateDate?->toDateString())->toBe($officialLast->indexRateDate?->toDateString())
        ->and($simulationLast->indexRateValue)->toBe($officialLast->indexRateValue);
});

// ---------------------------------------------------------------------------
// Resultado da simulação
// ---------------------------------------------------------------------------

it('returns the PU for the requested focus date', function () {
    $scenario = PuSimulationFixture::calculableScenario();
    $result = simulations()->simulate($scenario['emission'], $scenario['input']);
    $focus = $result->rows[1]->date;

    $focused = simulations()->simulate($scenario['emission'], new PuSimulationInput(
        firstIntegralizationDate: $scenario['input']->firstIntegralizationDate,
        simulationEndDate: $scenario['input']->simulationEndDate,
        focusDate: $focus,
    ));

    expect($focused->selectedRow?->date->toDateString())->toBe($focus->toDateString())
        ->and($focused->selectedUnitValue())->toBe($result->rows[1]->updatedUnitValue);
});

it('defaults the selected PU to the last calculated row of the window', function () {
    $scenario = PuSimulationFixture::calculableScenario();

    $result = simulations()->simulate($scenario['emission'], $scenario['input']);

    expect($result->selectedRow?->date->toDateString())
        ->toBe($result->lastRow()?->date->toDateString());
});

it('returns the calculation memory produced by the engine for every row', function () {
    $scenario = PuSimulationFixture::calculableScenario();

    $result = simulations()->simulate($scenario['emission'], $scenario['input']);
    $memory = $result->lastRow()?->calculationMemory ?? [];

    expect($memory)->toHaveKeys([
        'engine_version',
        'is_business_day',
        'calendar_code',
        'index_rate_lookup_mode',
        'factor_di_raw',
        'factor_di_accumulated_raw',
        'factor_spread_raw',
        'factor_spread_di_raw',
        'interest_real_unit_value_raw',
        'updated_unit_value_raw',
        'residual_unit_value_raw',
        'dup_interest',
        'dut_interest',
        'index_rate_date',
        'index_rate_value',
    ])->and($memory['calendar_code'])->toBe(PuSimulationFixture::CALENDAR_CODE);
});

it('reports the required rate dates resolved by the official resolver', function () {
    $scenario = PuSimulationFixture::calculableScenario();

    $result = simulations()->simulate($scenario['emission'], $scenario['input']);

    expect($result->requiredRateDates)->not->toBe([])
        ->and($result->missingRateDates)->toBe([])
        ->and($result->requiredRateDates)->toBe(collect($result->requiredRateDates)->sort()->values()->all());
});

// ---------------------------------------------------------------------------
// CDI: data exata, lag e ausência de fallback
// ---------------------------------------------------------------------------

it('uses the exact business-day lag date and never falls back to another rate', function () {
    $scenario = PuSimulationFixture::calculableScenario();
    $result = simulations()->simulate($scenario['emission'], $scenario['input']);
    /** @var PuDailyCurveRowData $accrualRow */
    $accrualRow = collect($result->rows)
        ->first(fn (PuDailyCurveRowData $row): bool => $row->isBusinessDay
            && $row->date->gt($scenario['input']->firstIntegralizationDate));

    $expectedRateDate = app(BusinessCalendarService::class)
        ->shiftBusinessDays($accrualRow->date, -5, PuSimulationFixture::CALENDAR_CODE);

    expect($accrualRow->indexRateDate?->toDateString())->toBe($expectedRateDate->toDateString())
        ->and($accrualRow->indexRateValue)->not->toBeNull();
});

it('reports the missing rate dates instead of substituting a nearby rate', function () {
    $scenario = PuSimulationFixture::calculableScenario();
    $requiredDates = simulations()->simulate($scenario['emission'], $scenario['input'])->requiredRateDates;
    $removed = $requiredDates[intdiv(count($requiredDates), 2)];
    IndexRate::query()->whereDate('rate_date', $removed)->delete();

    $result = simulations()->simulate($scenario['emission'], $scenario['input']);

    expect($result->state)->toBe(PuSimulationState::RatesMissing)
        ->and($result->missingRateDates)->toContain($removed)
        ->and($result->rows)->toBe([])
        ->and($result->reason)->toContain('Faltam');
});

it('honours the 252 business-day basis and the contractual spread', function () {
    $scenario = PuSimulationFixture::calculableScenario();

    $result = simulations()->simulate($scenario['emission'], $scenario['input']);
    /** @var PuDailyCurveRowData $accrualRow */
    $accrualRow = collect($result->rows)
        ->first(fn (PuDailyCurveRowData $row): bool => $row->isBusinessDay
            && $row->date->gt($scenario['input']->firstIntegralizationDate));

    expect($accrualRow->dutInterest)->toBe(252)
        ->and($accrualRow->dupInterest)->toBeGreaterThan(0)
        ->and($result->parameters['spread_rate'])->not->toBeNull()
        ->and($accrualRow->factorSpread)->not->toBe('0.0000000000000000');
});

// ---------------------------------------------------------------------------
// Prêmio do primeiro cupom, eventos e quantidade
// ---------------------------------------------------------------------------

it('exposes the first coupon premium configuration and its accrual dates', function () {
    $scenario = PuSimulationFixture::calculableScenario();

    $premium = simulations()->simulate($scenario['emission'], $scenario['input'])->premium;

    expect($premium['enabled'])->toBeTrue()
        ->and($premium['resolvable'])->toBeTrue()
        ->and($premium['business_days'])->toBe(2)
        ->and($premium['applies_index_factor'])->toBeTrue()
        ->and($premium['applies_spread_factor'])->toBeTrue()
        ->and($premium['accrual_dates'])->toHaveCount(2)
        ->and($premium['memory'])->toHaveKeys(['factor_di', 'factor_spread', 'factor']);
});

it('builds the contractual events through the official requirement resolver', function () {
    $scenario = PuSimulationFixture::calculableScenario();

    $result = simulations()->simulate($scenario['emission'], $scenario['input']);

    expect($result->events)->not->toBe([])
        ->and(collect($result->events)->pluck('event_type')->unique()->all())
        ->toContain('interest_payment')
        ->and(EmissionPuEvent::query()->count())->toBe(0);
});

it('reports the schedule diagnostics instead of silently simulating without coupons', function () {
    $scenario = PuSimulationFixture::calculableScenario();

    $resolved = simulations()->simulate($scenario['emission'], $scenario['input']);

    expect($resolved->scheduleDiagnostics['source'])->toBe('contractual_schedule')
        ->and($resolved->scheduleDiagnostics['resolvable'])->toBeTrue()
        ->and($resolved->scheduleDiagnostics['event_count'])->toBe(count($resolved->events));

    // Emissão sem cronograma contratual comprovado: a simulação segue possível,
    // mas declara explicitamente que não resolveu eventos.
    $bare = PuSimulationFixture::bareEmission();
    $bareResult = simulations()->simulate($bare, new PuSimulationInput(
        firstIntegralizationDate: PuSimulationFixture::integralizationDate(),
        simulationEndDate: PuSimulationFixture::windowEndDate(),
        overrides: PuSimulationFixture::manualOverrides(),
    ));

    expect($bareResult->scheduleDiagnostics['resolvable'])->toBeFalse()
        ->and($bareResult->scheduleDiagnostics['reason'])->not->toBe('')
        ->and($bareResult->events)->toBe([]);
});

it('keeps the unit PU independent from the simulated quantity', function () {
    $scenario = PuSimulationFixture::calculableScenario();
    $withoutQuantity = simulations()->simulate($scenario['emission'], $scenario['input']);

    $withQuantity = simulations()->simulate($scenario['emission'], new PuSimulationInput(
        firstIntegralizationDate: $scenario['input']->firstIntegralizationDate,
        simulationEndDate: $scenario['input']->simulationEndDate,
        quantity: '1500',
    ));

    expect($withQuantity->selectedUnitValue())->toBe($withoutQuantity->selectedUnitValue())
        ->and($withoutQuantity->selectedTotalValue())->toBeNull()
        ->and($withQuantity->selectedTotalValue())->not->toBeNull()
        ->and($withQuantity->selectedTotalValue())->not->toBe('0.0000000000000000')
        ->and(IntegralizationHistory::query()->count())->toBe(0);
});

it('keeps every unit curve row unchanged and derives the exact selected position', function (?string $quantity, ?string $expectedTotal) {
    $scenario = PuSimulationFixture::calculableScenario();
    $withoutQuantity = simulations()->simulate($scenario['emission'], $scenario['input']);
    $before = PuSimulationFixture::counts();

    $result = simulations()->simulate($scenario['emission'], new PuSimulationInput(
        firstIntegralizationDate: $scenario['input']->firstIntegralizationDate,
        simulationEndDate: $scenario['input']->simulationEndDate,
        quantity: $quantity,
    ));

    /**
     * O cenário tem um cupom dentro da janela. O PU esperado é um período de juros
     * depois do reset (1000 × 1,012596825). O valor anterior, 1025,352324, era o
     * quadrado desse fator: a curva sem quantidade tinha pagamento financeiro zero
     * no dia do cupom e nunca reiniciava o período -- defeito corrigido ao reiniciar
     * pelo pagamento unitário (`hasUnitPayment()`).
     */
    expect($result->state)->toBe(PuSimulationState::Calculated)
        ->and($result->selectedUnitValue())->toBe($withoutQuantity->selectedUnitValue())
        ->and($result->selectedUnitValue())->toBe('1012.5968250000000000')
        ->and($result->selectedTotalValue())->toBe($expectedTotal)
        ->and($result->rowCount())->toBe($withoutQuantity->rowCount())
        ->and($result->parameters)->toBe($withoutQuantity->parameters)
        ->and($result->selectedRow->quantity)->toBe('0.0000')
        ->and($result->selectedRow->totalValue)->toBe('0.0000000000000000')
        ->and(PuSimulationFixture::counts())->toBe($before)
        ->and(IntegralizationHistory::query()->count())->toBe(0);

    foreach ($result->rows as $index => $row) {
        expect(PuSimulationFixture::rowSignature($row))
            ->toBe(PuSimulationFixture::rowSignature($withoutQuantity->rows[$index]));
    }
})->with([
    'no quantity' => [null, null],
    'one unit' => ['1', '1012.5968250000000000'],
    '1500 units' => ['1500', '1518895.2375000000000000'],
    '4000 units' => ['4000', '4050387.3000000000000000'],
    'fractional quantity' => ['1.25', '1265.7460312500000000'],
    'round only the total to financial scale' => ['1.000000000000001', '1012.5968250000010126'],
    'zero quantity' => ['0', '0.0000000000000000'],
    'blank quantity' => ['', null],
]);

it('uses the selected updated PU for the position on a coupon date', function () {
    $scenario = PuSimulationFixture::calculableScenario();
    $withoutQuantity = simulations()->simulate($scenario['emission'], $scenario['input']);
    $coupon = collect($withoutQuantity->rows)->first(
        fn (PuDailyCurveRowData $row): bool => bccomp($row->interestPaymentUnitValue, '0', DecimalRounder::UNIT_SCALE) === 1,
    );

    expect($coupon)->toBeInstanceOf(PuDailyCurveRowData::class);

    $result = simulations()->simulate($scenario['emission'], new PuSimulationInput(
        firstIntegralizationDate: $scenario['input']->firstIntegralizationDate,
        simulationEndDate: $scenario['input']->simulationEndDate,
        quantity: '1500',
        focusDate: $coupon->date,
    ));

    expect($result->selectedUnitValue())->toBe($coupon->updatedUnitValue)
        ->and($result->selectedUnitValue())->not->toBe($result->selectedResidualUnitValue())
        ->and($result->selectedTotalValue())->toBe(bcmul($coupon->updatedUnitValue, '1500', DecimalRounder::TOTAL_SCALE))
        ->and($result->selectedTotalValue())->not->toBe(bcmul($coupon->residualUnitValue, '1500', DecimalRounder::TOTAL_SCALE));
});

// ---------------------------------------------------------------------------
// Janela, calendário e erros
// ---------------------------------------------------------------------------

it('never extends the simulated window beyond the contractual maturity', function () {
    $scenario = PuSimulationFixture::calculableScenario();

    $result = simulations()->simulate($scenario['emission'], new PuSimulationInput(
        firstIntegralizationDate: $scenario['input']->firstIntegralizationDate,
        simulationEndDate: CarbonImmutable::parse('2099-12-31'),
    ));

    expect($result->parameters['curve_end_date'])
        ->toBe($result->parameters['contractual_curve_end_date']);
});

it('rejects an end date earlier than the start date', function () {
    $scenario = PuSimulationFixture::calculableScenario();

    $result = simulations()->simulate($scenario['emission'], new PuSimulationInput(
        firstIntegralizationDate: $scenario['input']->firstIntegralizationDate,
        simulationEndDate: $scenario['input']->firstIntegralizationDate->subDay(),
    ));

    expect($result->state)->toBe(PuSimulationState::MissingInput)
        ->and($result->reason)->toContain('anterior')
        ->and($result->rows)->toBe([]);
});

it('reports an unresolvable calendar without leaking a stack trace', function () {
    $scenario = PuSimulationFixture::calculableScenario();
    PuSimulationFixture::breakCalendarCoverage();

    $result = simulations()->simulate($scenario['emission'], $scenario['input']);

    expect($result->state)->toBe(PuSimulationState::CalendarIncomplete)
        ->and($result->calendarDiagnostics['resolvable'])->toBeFalse()
        ->and($result->reason)->not->toContain('#0 ')
        ->and($result->reason)->not->toContain('.php:')
        ->and($result->rows)->toBe([]);
});

it('separates simulation readiness from operational readiness', function () {
    $scenario = PuSimulationFixture::calculableScenario();
    $emission = $scenario['emission'];

    $result = simulations()->simulate($emission, $scenario['input']);

    // Gate C segue bloqueado: nenhuma evidência de primeira integralização, nenhum
    // parâmetro persistido, nenhuma candidate e nenhuma curva operacional.
    expect($result->state)->toBe(PuSimulationState::Calculated)
        ->and($emission->puBaselineEvidences()
            ->where('evidence_type', 'first_integralization_date')
            ->exists())->toBeFalse()
        ->and(EmissionPuParameter::query()->count())->toBe(0)
        ->and($emission->fresh()->puCurveVersions()->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Zero efeito colateral
// ---------------------------------------------------------------------------

it('writes absolutely nothing when simulating', function () {
    $scenario = PuSimulationFixture::calculableScenario();
    $before = PuSimulationFixture::counts();

    $result = simulations()->simulate($scenario['emission'], $scenario['input']);

    expect($result->state)->toBe(PuSimulationState::Calculated)
        ->and(PuSimulationFixture::counts())->toBe($before);
});

it('writes nothing when the simulation is blocked by missing rates', function () {
    $scenario = PuSimulationFixture::calculableScenario();
    IndexRate::query()->delete();
    $before = PuSimulationFixture::counts();

    $result = simulations()->simulate($scenario['emission'], $scenario['input']);

    expect($result->state)->toBe(PuSimulationState::RatesMissing)
        ->and(PuSimulationFixture::counts())->toBe($before);
});

it('does not mutate the persisted parameter of the emission', function () {
    $emission = PuSimulationFixture::contractualEmission();
    $parameter = PuSimulationFixture::persistParameter($emission);
    $before = $parameter->fresh()->only([
        'curve_start_date', 'curve_end_date', 'spread_rate', 'calendar_code', 'initial_unit_value',
    ]);

    simulations()->simulate($emission->fresh(), new PuSimulationInput(
        firstIntegralizationDate: PuSimulationFixture::integralizationDate(),
        simulationEndDate: PuSimulationFixture::windowEndDate(),
        overrides: ['spread_rate' => '0.99'],
    ));

    expect($parameter->fresh()->only([
        'curve_start_date', 'curve_end_date', 'spread_rate', 'calendar_code', 'initial_unit_value',
    ]))->toEqual($before);
});

it('does not mutate the loaded relations of the emission instance it received', function () {
    $scenario = PuSimulationFixture::calculableScenario();
    $emission = $scenario['emission'];
    $emission->setRelation('puEvents', new EloquentCollection);
    $emission->setRelation('integralizationHistories', new EloquentCollection);

    simulations()->simulate($emission, $scenario['input']);

    expect($emission->getRelation('puEvents'))->toHaveCount(0)
        ->and($emission->getRelation('integralizationHistories'))->toHaveCount(0)
        ->and($emission->relationLoaded('puParameter'))->toBeFalse();
});

it('never reaches the official engine entrypoint with a persisted scenario', function () {
    $scenario = PuSimulationFixture::calculableScenario();

    simulations()->simulate($scenario['emission'], $scenario['input']);

    // A engine é a mesma classe usada pela geração operacional; a diferença é
    // exclusivamente o cenário em memória.
    expect(app(PuCurveGeneratorService::class))->toBeInstanceOf(PuCurveGeneratorService::class)
        ->and(Emission::query()->count())->toBe(1)
        ->and(EmissionPuParameter::query()->count())->toBe(0)
        ->and(EmissionPuEvent::query()->count())->toBe(0);
});
