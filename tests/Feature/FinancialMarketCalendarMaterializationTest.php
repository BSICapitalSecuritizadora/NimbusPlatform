<?php

use App\Domain\PuCalculator\Services\BusinessDayCalendarService;
use App\Domain\PuCalculator\Services\FebrabanHolidayImporter;
use App\Domain\PuCalculator\Services\FinancialMarketCalendarMaterializationService;
use App\Domain\PuCalculator\Support\BusinessCalendarRegistry;
use App\Models\BusinessCalendar;
use App\Models\BusinessCalendarDate;
use App\Models\BusinessCalendarYear;
use App\Models\BusinessHoliday;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuDailyCurve;
use App\Models\EmissionPuParameter;
use App\Models\IntegralizationHistory;
use App\Models\Obligation;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Calendars\FebrabanSourceFixture;

uses(RefreshDatabase::class);

/**
 * Conjunto de 2026 em que as duas fontes concordam integralmente — a pré-condição para materializar.
 * Nenhuma data é escrita à mão no calendário: as decisões saem da reconciliação das evidências.
 *
 * @param  list<array{0:string,1:string}>  $extra
 */
function reconciled2026(array $extra = []): void
{
    $holidays = [
        ['2026-01-01', 'Confraternização Universal'],
        ['2026-02-16', 'Carnaval'],
        ['2026-02-17', 'Carnaval'],
        ['2026-04-03', 'Sexta-Feira da Paixão'],
        ['2026-06-04', 'Corpus Christi'],
        ['2026-12-25', 'Natal'],
        ...$extra,
    ];

    foreach ($holidays as [$date, $name]) {
        FebrabanSourceFixture::anbimaFact($date, $name);
    }

    FebrabanSourceFixture::anbimaYearCoverage(2026);
    FebrabanSourceFixture::fakeYear(
        FebrabanSourceFixture::payload($holidays),
        FebrabanSourceFixture::payload([
            ['2026-02-18', 'Quarta-Feira de Cinzas'],
            ['2026-12-31', 'Último dia útil do ano (Não haverá expediente ao público)'],
        ]),
    );

    app(FebrabanHolidayImporter::class)->importFromSource([2026]);
}

function materializer(): FinancialMarketCalendarMaterializationService
{
    return app(FinancialMarketCalendarMaterializationService::class);
}

it('materializa o ano inteiro com uma decisão explícita para cada dia', function (): void {
    reconciled2026();

    $result = materializer()->materialize(2026);
    $expectedDays = CarbonImmutable::create(2026, 1, 1)->isLeapYear() ? 366 : 365;

    expect($result->blocked)->toBeFalse()
        ->and($result->totalDays)->toBe($expectedDays)
        ->and($result->rowsCreated)->toBe($expectedDays)
        ->and(BusinessCalendarDate::query()
            ->where('calendar_code', BusinessCalendarRegistry::BR_FINANCIAL_MARKET)
            ->count())->toBe($expectedDays)
        ->and($result->businessDays + $result->nonBusinessDays)->toBe($expectedDays);
});

it('declara dia útil comum sem recorrer a fallback implícito', function (): void {
    reconciled2026();
    materializer()->materialize(2026);

    // Esta é exatamente a data em que a calculadora falhava: terça-feira comum, que nenhuma fonte
    // lista como feriado e que a política explicit_official_decisions recusava inferir.
    $row = BusinessCalendarDate::query()
        ->where('calendar_code', BusinessCalendarRegistry::BR_FINANCIAL_MARKET)
        ->whereDate('calendar_date', '2026-05-05')
        ->first();

    expect($row)->not->toBeNull()
        ->and($row->is_business_day)->toBeTrue()
        ->and($row->data_origin)->toBe(FinancialMarketCalendarMaterializationService::ORIGIN_COVERED_YEAR_RULE)
        ->and(app(BusinessDayCalendarService::class)->isBusinessDay(
            CarbonImmutable::parse('2026-05-05'),
            BusinessCalendarRegistry::BR_FINANCIAL_MARKET,
        ))->toBeTrue();
});

it('materializa Corpus Christi como não útil a partir da evidência das duas fontes', function (): void {
    reconciled2026();
    materializer()->materialize(2026);

    $row = BusinessCalendarDate::query()
        ->where('calendar_code', BusinessCalendarRegistry::BR_FINANCIAL_MARKET)
        ->whereDate('calendar_date', '2026-06-04')
        ->first();

    expect($row->is_business_day)->toBeFalse()
        ->and($row->description)->toBe('Corpus Christi')
        ->and($row->data_origin)->toBe(FinancialMarketCalendarMaterializationService::ORIGIN_RECONCILED_HOLIDAY)
        ->and($row->source_document)->toContain('ANBIMA + FEBRABAN')
        ->and(app(BusinessDayCalendarService::class)->isBusinessDay(
            CarbonImmutable::parse('2026-06-04'),
            BusinessCalendarRegistry::BR_FINANCIAL_MARKET,
        ))->toBeFalse();
});

it('materializa sábado e domingo como não úteis por regra própria', function (): void {
    reconciled2026();
    materializer()->materialize(2026);

    $calendar = app(BusinessDayCalendarService::class);
    $saturday = CarbonImmutable::parse('2026-06-06');
    $sunday = CarbonImmutable::parse('2026-06-07');

    expect($saturday->isSaturday())->toBeTrue()
        ->and($sunday->isSunday())->toBeTrue()
        ->and($calendar->isBusinessDay($saturday, BusinessCalendarRegistry::BR_FINANCIAL_MARKET))->toBeFalse()
        ->and($calendar->isBusinessDay($sunday, BusinessCalendarRegistry::BR_FINANCIAL_MARKET))->toBeFalse()
        ->and(BusinessCalendarDate::query()
            ->where('calendar_code', BusinessCalendarRegistry::BR_FINANCIAL_MARKET)
            ->whereDate('calendar_date', $saturday->toDateString())
            ->value('data_origin'))->toBe(FinancialMarketCalendarMaterializationService::ORIGIN_WEEKEND_RULE);
});

it('não transforma expediente especial de agência em dia não útil', function (): void {
    reconciled2026();
    $result = materializer()->materialize(2026);

    $calendar = app(BusinessDayCalendarService::class);

    // Quarta-feira de cinzas e 31/12 estão na tabela de atendimento da FEBRABAN, que admite operações
    // entre instituições financeiras. São dias em que o mercado opera.
    foreach (['2026-02-18', '2026-12-31'] as $dateKey) {
        $row = BusinessCalendarDate::query()
            ->where('calendar_code', BusinessCalendarRegistry::BR_FINANCIAL_MARKET)
            ->whereDate('calendar_date', $dateKey)
            ->first();

        expect($row->is_business_day)->toBeTrue()
            ->and($row->data_origin)->toBe(FinancialMarketCalendarMaterializationService::ORIGIN_COVERED_YEAR_RULE)
            ->and($calendar->isBusinessDay(
                CarbonImmutable::parse($dateKey),
                BusinessCalendarRegistry::BR_FINANCIAL_MARKET,
            ))->toBeTrue();
    }

    // 24/12 não é publicado por fonte alguma: nenhum feriado é inventado.
    expect($calendar->isBusinessDay(
        CarbonImmutable::parse('2026-12-24'),
        BusinessCalendarRegistry::BR_FINANCIAL_MARKET,
    ))->toBeTrue()
        ->and($result->specialHoursObserved)->toBe(2)
        ->and($result->blocked)->toBeFalse();
});

it('bloqueia o ano inteiro quando as fontes divergem', function (): void {
    reconciled2026([['2026-11-20', 'Dia da Consciência Negra']]);

    // A FEBRABAN se retrata numa data que a ANBIMA continua afirmando.
    FebrabanSourceFixture::fakeYear(FebrabanSourceFixture::payload([
        ['2026-01-01', 'Confraternização Universal'],
        ['2026-02-16', 'Carnaval'],
        ['2026-02-17', 'Carnaval'],
        ['2026-04-03', 'Sexta-Feira da Paixão'],
        ['2026-06-04', 'Corpus Christi'],
        ['2026-12-25', 'Natal'],
    ]));
    app(FebrabanHolidayImporter::class)->importFromSource([2026]);

    $result = materializer()->materialize(2026);

    expect($result->blocked)->toBeTrue()
        ->and($result->conflicts)->toBe(1)
        ->and($result->blockingReasons[0])->toContain('divergência')
        ->and($result->blockedDates[0]['date'])->toBe('2026-11-20')
        ->and(BusinessCalendarDate::query()
            ->where('calendar_code', BusinessCalendarRegistry::BR_FINANCIAL_MARKET)
            ->count())->toBe(0);
});

it('bloqueia quando apenas uma das fontes afirma uma data', function (): void {
    reconciled2026();
    // A ANBIMA conhece uma data que a FEBRABAN não publica: não há política governada para decidir.
    FebrabanSourceFixture::anbimaFact('2026-07-09', 'Data publicada apenas pela ANBIMA');

    $result = materializer()->materialize(2026);

    expect($result->blocked)->toBeTrue()
        ->and($result->sourceOnly)->toBe(1)
        ->and($result->conflicts)->toBe(0)
        ->and($result->blockedDates[0]['date'])->toBe('2026-07-09')
        ->and(BusinessCalendarDate::query()
            ->where('calendar_code', BusinessCalendarRegistry::BR_FINANCIAL_MARKET)
            ->count())->toBe(0);
});

it('bloqueia quando a FEBRABAN nunca importou o ano com sucesso', function (): void {
    // Cobertura ANBIMA completa, mas nenhuma execução FEBRABAN: 2030 é o caso real, em que o importador
    // recusa a resposta incompleta da fonte e, por isso, nenhuma execução bem-sucedida chega a existir.
    FebrabanSourceFixture::anbimaYearCoverage(2030);

    $result = materializer()->materialize(2030);

    expect($result->blocked)->toBeTrue()
        // Causa única: o portão que falta é o da FEBRABAN.
        ->and($result->blockingReasons)->toBe([
            'Materialização bloqueada: não há importação FEBRABAN bem-sucedida de 2030. Execute pu:holidays:import-febraban --year=2030 antes de materializar.',
        ])
        ->and(BusinessCalendarDate::query()
            ->where('calendar_code', BusinessCalendarRegistry::BR_FINANCIAL_MARKET)
            ->count())->toBe(0);
});

it('bloqueia quando a cobertura ANBIMA do ano não está completa', function (): void {
    FebrabanSourceFixture::anbimaFact('2026-06-04', 'Corpus Christi');
    FebrabanSourceFixture::fakeYear(FebrabanSourceFixture::marketPayload2026());
    app(FebrabanHolidayImporter::class)->importFromSource([2026]);

    // Sem BusinessCalendarYear oficial/confirmado para a ANBIMA, o portão de cobertura não passa.
    $result = materializer()->materialize(2026);

    expect($result->blocked)->toBeTrue()
        ->and($result->blockingReasons)->toContain(
            'Materialização bloqueada: a cobertura ANBIMA de 2026 está em "none". Importe e confirme BR_BANKING_ANBIMA/2026 antes de materializar.',
        )
        ->and(BusinessCalendarDate::query()
            ->where('calendar_code', BusinessCalendarRegistry::BR_FINANCIAL_MARKET)
            ->count())->toBe(0);
});

it('é idempotente: materializar duas vezes não duplica nem altera decisões', function (): void {
    reconciled2026();
    $expectedDays = CarbonImmutable::create(2026, 1, 1)->isLeapYear() ? 366 : 365;

    $first = materializer()->materialize(2026);
    $revisionAfterFirst = BusinessCalendarYear::query()
        ->where('calendar_code', BusinessCalendarRegistry::BR_FINANCIAL_MARKET)
        ->where('year', 2026)
        ->value('revision');

    $second = materializer()->materialize(2026);

    expect($first->rowsCreated)->toBe($expectedDays)
        ->and($second->rowsCreated)->toBe(0)
        ->and($second->rowsUpdated)->toBe(0)
        ->and($second->rowsUnchanged)->toBe($expectedDays)
        ->and(BusinessCalendarDate::query()
            ->where('calendar_code', BusinessCalendarRegistry::BR_FINANCIAL_MARKET)
            ->count())->toBe($expectedDays)
        ->and(BusinessCalendarYear::query()
            ->where('calendar_code', BusinessCalendarRegistry::BR_FINANCIAL_MARKET)
            ->where('year', 2026)
            ->value('revision'))->toBe($revisionAfterFirst);
});

it('não persiste nada em dry-run', function (): void {
    reconciled2026();
    $expectedDays = CarbonImmutable::create(2026, 1, 1)->isLeapYear() ? 366 : 365;

    $result = materializer()->materialize(2026, dryRun: true);

    expect($result->dryRun)->toBeTrue()
        ->and($result->blocked)->toBeFalse()
        ->and($result->totalDays)->toBe($expectedDays)
        ->and($result->rowsCreated)->toBe($expectedDays)
        ->and(BusinessCalendarDate::query()
            ->where('calendar_code', BusinessCalendarRegistry::BR_FINANCIAL_MARKET)
            ->count())->toBe(0)
        ->and(BusinessCalendarYear::query()
            ->where('calendar_code', BusinessCalendarRegistry::BR_FINANCIAL_MARKET)
            ->count())->toBe(0);
});

it('cria o ano como provisional, sem autoconfirmar', function (): void {
    reconciled2026();
    $result = materializer()->materialize(2026);

    $calendarYear = BusinessCalendarYear::query()
        ->where('calendar_code', BusinessCalendarRegistry::BR_FINANCIAL_MARKET)
        ->where('year', 2026)
        ->first();

    expect($calendarYear->status)->toBe(BusinessCalendarYear::STATUS_PROVISIONAL)
        ->and($calendarYear->confirmed_at)->toBeNull()
        ->and($calendarYear->source_is_official)->toBeTrue()
        ->and($calendarYear->checksum)->toBe($result->checksum)
        ->and($result->yearStatus)->toBe(BusinessCalendarYear::STATUS_PROVISIONAL);
});

it('marca o ano como stale em vez de sobrescrever uma decisão confirmada em silêncio', function (): void {
    reconciled2026();
    materializer()->materialize(2026);

    BusinessCalendarYear::query()
        ->where('calendar_code', BusinessCalendarRegistry::BR_FINANCIAL_MARKET)
        ->where('year', 2026)
        ->update(['status' => BusinessCalendarYear::STATUS_CONFIRMED, 'confirmed_at' => now()]);

    // Uma nova evidência confirmada pelas duas fontes muda o manifesto do ano.
    FebrabanSourceFixture::anbimaFact('2026-07-09', 'Feriado superveniente');
    FebrabanSourceFixture::fakeYear(
        FebrabanSourceFixture::marketPayload2026([['2026-07-09', 'Feriado superveniente']]),
    );
    app(FebrabanHolidayImporter::class)->importFromSource([2026]);

    $result = materializer()->materialize(2026);
    $calendarYear = BusinessCalendarYear::query()
        ->where('calendar_code', BusinessCalendarRegistry::BR_FINANCIAL_MARKET)
        ->where('year', 2026)
        ->first();

    expect($result->blocked)->toBeFalse()
        ->and($calendarYear->status)->toBe(BusinessCalendarYear::STATUS_STALE)
        ->and($calendarYear->revision)->toBeGreaterThan(1)
        ->and($result->rowsUpdated)->toBe(1);
});

it('continua exigindo decisão explícita para anos não materializados', function (): void {
    reconciled2026();
    materializer()->materialize(2026);

    expect(fn () => app(BusinessDayCalendarService::class)->isBusinessDay(
        CarbonImmutable::parse('2027-05-05'),
        BusinessCalendarRegistry::BR_FINANCIAL_MARKET,
    ))->toThrow(RuntimeException::class, 'exige decisão explícita');
});

it('preserva a política e o escopo não operacional do calendário consolidado', function (): void {
    reconciled2026();
    materializer()->materialize(2026);

    $calendar = BusinessCalendar::query()
        ->where('code', BusinessCalendarRegistry::BR_FINANCIAL_MARKET)
        ->first();

    expect($calendar->materialization_policy)->toBe(BusinessCalendar::MATERIALIZATION_POLICY_EXPLICIT_OFFICIAL_DECISIONS)
        ->and($calendar->allowsImplicitWeekdayDecision())->toBeFalse()
        ->and($calendar->financial_use_allowed)->toBeFalse()
        ->and($calendar->available_for_new_configurations)->toBeFalse();
});

it('não escreve em nenhuma tabela financeira nem altera a evidência das fontes', function (): void {
    reconciled2026();

    $anbimaBefore = BusinessHoliday::query()->where('source', 'anbima')->get()->toArray();
    $febrabanBefore = BusinessHoliday::query()
        ->where('source', FebrabanHolidayImporter::SOURCE_MARKET)
        ->get()
        ->toArray();

    materializer()->materialize(2026);

    expect(EmissionPuParameter::query()->count())->toBe(0)
        ->and(EmissionPuCurveVersion::query()->count())->toBe(0)
        ->and(EmissionPuDailyCurve::query()->count())->toBe(0)
        ->and(Payment::query()->count())->toBe(0)
        ->and(IntegralizationHistory::query()->count())->toBe(0)
        ->and(Obligation::query()->count())->toBe(0)
        ->and(BusinessHoliday::query()->where('source', 'anbima')->get()->toArray())->toEqual($anbimaBefore)
        ->and(BusinessHoliday::query()
            ->where('source', FebrabanHolidayImporter::SOURCE_MARKET)
            ->get()
            ->toArray())->toEqual($febrabanBefore)
        // O calendário ANBIMA não recebe decisão alguma desta fase.
        ->and(BusinessCalendarDate::query()
            ->where('calendar_code', BusinessCalendarRegistry::BR_BANKING_ANBIMA)
            ->count())->toBe(0);
});
