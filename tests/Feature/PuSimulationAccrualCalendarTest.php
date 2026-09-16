<?php

use App\Domain\PuCalculator\DTOs\PuDailyCurveRowData;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexRateLookupMode;
use App\Domain\PuCalculator\Services\FebrabanHolidayImporter;
use App\Domain\PuCalculator\Services\FinancialMarketCalendarMaterializationService;
use App\Domain\PuCalculator\Services\PuCurveGeneratorService;
use App\Domain\PuCalculator\Support\BusinessCalendarRegistry;
use App\Models\BusinessCalendarDate;
use App\Models\Emission;
use App\Models\EmissionPuEvent;
use App\Models\EmissionPuParameter;
use App\Models\IndexRate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Calendars\FebrabanSourceFixture;

uses(RefreshDatabase::class);

/**
 * Materializa BR_FINANCIAL_MARKET/2026 pelo caminho governado real — evidência das duas fontes,
 * reconciliação, materialização —, sem escrever nenhuma data à mão no calendário.
 */
function materializeFinancialMarket2026(): void
{
    $holidays = [
        ['2026-01-01', 'Confraternização Universal'],
        ['2026-02-16', 'Carnaval'],
        ['2026-02-17', 'Carnaval'],
        ['2026-04-03', 'Sexta-Feira da Paixão'],
        ['2026-06-04', 'Corpus Christi'],
        ['2026-12-25', 'Natal'],
    ];

    foreach ($holidays as [$date, $name]) {
        FebrabanSourceFixture::anbimaFact($date, $name);
    }

    FebrabanSourceFixture::anbimaYearCoverage(2026);
    FebrabanSourceFixture::fakeYear(FebrabanSourceFixture::payload($holidays));
    app(FebrabanHolidayImporter::class)->importFromSource([2026]);
    app(FinancialMarketCalendarMaterializationService::class)->materialize(2026);
}

/**
 * Calendário contratual da curva com decisão explícita para toda a janela, e SEM Corpus Christi —
 * é justamente essa diferença que o teste precisa medir.
 */
function materializeContractualWindow(CarbonImmutable $from, CarbonImmutable $to): void
{
    for ($date = $from; $date->lte($to); $date = $date->addDay()) {
        BusinessCalendarDate::query()->create([
            'calendar_code' => BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS,
            'calendar_date' => $date->toDateString(),
            'is_business_day' => ! $date->isWeekend(),
            'description' => $date->isWeekend() ? 'Fim de semana' : 'Dia útil legal',
            'data_origin' => 'legal_rule_projection',
            'source' => 'federal_legislation',
        ]);
    }
}

function seedRatesForWindow(CarbonImmutable $from, CarbonImmutable $to): void
{
    for ($date = $from; $date->lte($to); $date = $date->addDay()) {
        IndexRate::query()->firstOrCreate(
            ['indexer' => PuIndexer::Cdi->value, 'rate_date' => $date->startOfDay()],
            [
                'rate_value' => '14.90000000',
                'source' => 'bcb_sgs',
                'source_reference' => 'bcb_sgs:4389',
                'external_series_code' => '4389',
                'is_projected' => false,
            ],
        );
    }
}

/**
 * @param  EloquentCollection<int, EmissionPuEvent>|null  $events
 * @return list<PuDailyCurveRowData>
 */
function accrualCurveRows(
    ?string $accrualCalendarCode,
    ?string $indexRateCalendarCode = null,
    ?EloquentCollection $events = null,
): array {
    $emission = Emission::factory()->create();
    $parameter = new EmissionPuParameter;
    $parameter->exists = false;
    $parameter->forceFill([
        'indexer' => PuIndexer::Cdi->value,
        'spread_rate' => '6.00000000',
        'business_day_basis' => 252,
        // Calendário CONTRATUAL da curva. Não muda em nenhum cenário deste teste.
        'calendar_code' => BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS,
        'index_rate_lookup_mode' => PuIndexRateLookupMode::BusinessDayLagExact->value,
        'index_rate_lag_business_days' => -5,
        'initial_unit_value' => '1000.0000000000000000',
        'curve_start_date' => '2026-06-01',
        'curve_end_date' => '2026-06-10',
        'first_coupon_pre_integralization_premium_enabled' => false,
    ]);

    $scenario = clone $emission;
    $scenario->setRelation('puParameter', $parameter);
    $scenario->setRelation('puEvents', $events ?? new EloquentCollection);
    $scenario->setRelation('integralizationHistories', new EloquentCollection);

    return app(PuCurveGeneratorService::class)
        ->handle($scenario, $indexRateCalendarCode, $accrualCalendarCode)
        ->rows;
}

/** @param  list<PuDailyCurveRowData>  $rows */
function rowOn(array $rows, string $date): PuDailyCurveRowData
{
    foreach ($rows as $row) {
        if ($row->date->toDateString() === $date) {
            return $row;
        }
    }

    throw new RuntimeException(sprintf('Linha de curva ausente para %s.', $date));
}

beforeEach(function (): void {
    materializeContractualWindow(
        CarbonImmutable::parse('2026-01-01'),
        CarbonImmutable::parse('2026-12-31'),
    );
    seedRatesForWindow(
        CarbonImmutable::parse('2026-04-01'),
        CarbonImmutable::parse('2026-07-31'),
    );
    materializeFinancialMarket2026();
});

it('trata Corpus Christi como dia útil enquanto o accrual segue o calendário contratual', function (): void {
    // Estado de partida — e a causa do bug relatado: BR_NATIONAL_HOLIDAYS não conhece Corpus Christi.
    $rows = accrualCurveRows(null);

    expect(rowOn($rows, '2026-06-04')->isBusinessDay)->toBeTrue()
        ->and(rowOn($rows, '2026-06-04')->dupInterest)
        ->toBe(rowOn($rows, '2026-06-03')->dupInterest + 1);
});

it('usa o calendário de accrual informado para decidir o Dia Útil da curva', function (): void {
    $rows = accrualCurveRows(BusinessCalendarRegistry::BR_FINANCIAL_MARKET);

    expect(rowOn($rows, '2026-06-03')->isBusinessDay)->toBeTrue()
        ->and(rowOn($rows, '2026-06-04')->isBusinessDay)->toBeFalse()
        ->and(rowOn($rows, '2026-06-05')->isBusinessDay)->toBeTrue();
});

it('não incrementa DU nem aplica fator diário financeiro em Corpus Christi', function (): void {
    $rows = accrualCurveRows(BusinessCalendarRegistry::BR_FINANCIAL_MARKET);

    $before = rowOn($rows, '2026-06-03');
    $holiday = rowOn($rows, '2026-06-04');
    $after = rowOn($rows, '2026-06-05');

    expect($holiday->dupInterest)->toBe($before->dupInterest)
        ->and($after->dupInterest)->toBe($before->dupInterest + 1)
        // Fator diário neutro: o dia não remunera.
        ->and((float) $holiday->factorDi)->toBe(1.0)
        ->and($holiday->factorDiAccumulated)->toBe($before->factorDiAccumulated)
        ->and($holiday->factorSpread)->toBe($before->factorSpread)
        // E o dia seguinte volta a remunerar normalmente.
        ->and((float) $after->factorDi)->toBeGreaterThan(1.0);
});

it('mantém os três calendários explicitamente separados', function (): void {
    $rows = accrualCurveRows(
        accrualCalendarCode: BusinessCalendarRegistry::BR_FINANCIAL_MARKET,
        indexRateCalendarCode: BusinessCalendarRegistry::BR_FINANCIAL_MARKET,
    );

    $holiday = rowOn($rows, '2026-06-04');

    // O calendário CONTRATUAL do parâmetro permanece intocado...
    expect($holiday->calculationMemory['calendar_code'] ?? null)
        ->toBe(BusinessCalendarRegistry::BR_FINANCIAL_MARKET)
        ->and($holiday->isBusinessDay)->toBeFalse();

    $parameterCalendars = EmissionPuParameter::query()
        ->pluck('calendar_code')
        ->unique()
        ->all();

    // ...e nenhum EmissionPuParameter foi criado ou alterado pela simulação.
    expect($parameterCalendars)->toBe([]);
});

it('não desloca eventos: a convenção de pagamento continua no calendário contratual', function (): void {
    // Um pagamento marcado exatamente em Corpus Christi. No calendário CONTRATUAL a data é dia útil, e é
    // ele que a convenção Following consulta — a hipótese de accrual não pode empurrar o pagamento.
    $event = new EmissionPuEvent;
    $event->forceFill([
        'id' => 1,
        'emission_id' => 1,
        'event_type' => 'interest_payment',
        'original_date' => '2026-06-04',
        'effective_date' => '2026-06-04',
        'sequence' => 1,
    ]);

    $rows = accrualCurveRows(
        accrualCalendarCode: BusinessCalendarRegistry::BR_FINANCIAL_MARKET,
        events: new EloquentCollection([$event]),
    );

    $holiday = rowOn($rows, '2026-06-04');

    expect($holiday->eventEffectiveDate?->toDateString())->toBe('2026-06-04')
        // O dia não acumula juros pelo accrual, mas o pagamento continua nele.
        ->and($holiday->isBusinessDay)->toBeFalse()
        ->and(rowOn($rows, '2026-06-05')->eventEffectiveDate)->toBeNull();
});

it('preserva byte a byte o resultado quando nenhuma hipótese de accrual é informada', function (): void {
    $baseline = accrualCurveRows(null);
    $explicitContractual = accrualCurveRows(BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS);
    $emptyString = accrualCurveRows('');

    $signature = static fn (array $rows): array => array_map(
        static fn (PuDailyCurveRowData $row): array => [
            $row->date->toDateString(),
            $row->isBusinessDay,
            $row->factorDi,
            $row->factorSpreadDi,
            $row->updatedUnitValue,
            $row->dupInterest,
        ],
        $rows,
    );

    expect($signature($explicitContractual))->toBe($signature($baseline))
        ->and($signature($emptyString))->toBe($signature($baseline));
});

it('não altera a matemática dos dias úteis comuns sob a hipótese de accrual', function (): void {
    $baseline = accrualCurveRows(null);
    $overridden = accrualCurveRows(BusinessCalendarRegistry::BR_FINANCIAL_MARKET);

    // 01/06 e 02/06 são dias úteis nos dois calendários: a curva precisa ser idêntica até a véspera.
    foreach (['2026-06-01', '2026-06-02', '2026-06-03'] as $dateKey) {
        expect(rowOn($overridden, $dateKey)->factorDi)->toBe(rowOn($baseline, $dateKey)->factorDi)
            ->and(rowOn($overridden, $dateKey)->updatedUnitValue)
            ->toBe(rowOn($baseline, $dateKey)->updatedUnitValue)
            ->and(rowOn($overridden, $dateKey)->dupInterest)
            ->toBe(rowOn($baseline, $dateKey)->dupInterest);
    }
});
