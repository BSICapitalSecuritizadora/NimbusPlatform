<?php

use App\Domain\PuCalculator\DTOs\PuSimulationInput;
use App\Domain\PuCalculator\DTOs\PuSimulationResult;
use App\Domain\PuCalculator\Enums\PuSimulationState;
use App\Domain\PuCalculator\Services\BusinessCalendarService;
use App\Domain\PuCalculator\Services\PuSimulationService;
use App\Domain\PuCalculator\Support\BusinessCalendarRegistry;
use App\Filament\Resources\Emissions\Pages\PuCalculatorSimulator;
use App\Models\BusinessCalendarDate;
use App\Models\Emission;
use App\Models\IndexRate;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Pu\PuSimulationFixture;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    // Nenhum teste desta suíte pode consultar o Banco Central.
    Http::preventStrayRequests();
});

/**
 * 04/06/2026 é Corpus Christi.
 *
 * `BR_NATIONAL_HOLIDAYS` materializa somente feriados FIXOS de lei federal e
 * exclui Corpus Christi por decisão registrada, então esse dia é dia útil ali.
 * O calendário bancário é outro: não há expediente e o Banco Central não divulga
 * CDI. Como a planilha oficial da ANBIMA ainda não foi importada neste projeto,
 * a linha abaixo é a HIPÓTESE DE TESTE que representa essa divergência --
 * gravada pelo mesmo mecanismo de exceção que o importador oficial usaria, e
 * suficiente porque `BR_BANKING_ANBIMA` decide por regra-base de dia de semana
 * complementada por exceções.
 */
const RATE_CALENDAR_CORPUS_CHRISTI_2026 = '2026-06-04';

function assumeBankHolidayOnCorpusChristi(): void
{
    BusinessCalendarDate::query()->create([
        'calendar_code' => BusinessCalendarRegistry::BR_BANKING_ANBIMA,
        'calendar_date' => RATE_CALENDAR_CORPUS_CHRISTI_2026,
        'is_business_day' => false,
        'description' => 'Corpus Christi (hipótese de teste: feriado bancário sem divulgação de CDI).',
        'data_origin' => 'imported',
        'source' => 'ANBIMA',
        'source_is_official' => false,
    ]);

    app(BusinessCalendarService::class)->flushCache();
}

/**
 * Garante as taxas que a hipótese exige e devolve a simulação já resolvida.
 *
 * O calendário de observação desloca o conjunto de datas requeridas, então o
 * seed do fixture (resolvido sobre o calendário contratual) não o cobre por
 * inteiro. As taxas que faltam são criadas pelo mesmo helper sintético do
 * fixture -- nenhuma consulta ao Banco Central, nenhum valor inventado para uma
 * data já existente.
 */
function simulateWithSeededRates(Emission $emission, PuSimulationInput $input): PuSimulationResult
{
    $service = app(PuSimulationService::class);
    $result = $service->simulate($emission, $input);

    if ($result->missingRateDates === []) {
        return $result;
    }

    foreach ($result->missingRateDates as $date) {
        PuSimulationFixture::ensureRate(CarbonImmutable::parse($date));
    }

    return $service->simulate($emission, $input);
}

/** Hipótese bancária sobre o cenário calculável do fixture. */
function bankingHypothesis(): PuSimulationInput
{
    return new PuSimulationInput(
        firstIntegralizationDate: PuSimulationFixture::integralizationDate(),
        simulationEndDate: PuSimulationFixture::windowEndDate(),
        indexRateCalendarCode: BusinessCalendarRegistry::BR_BANKING_ANBIMA,
    );
}

// ---------------------------------------------------------------------------
// Os dois calendários são semanticamente distintos
// ---------------------------------------------------------------------------

it('treats corpus christi as a business day in the contractual calendar only', function () {
    PuSimulationFixture::contractualEmission();
    assumeBankHolidayOnCorpusChristi();
    $calendar = app(BusinessCalendarService::class);
    $date = CarbonImmutable::parse(RATE_CALENDAR_CORPUS_CHRISTI_2026);

    expect($calendar->isBusinessDay($date, BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS))->toBeTrue()
        ->and($calendar->isBusinessDay($date, BusinessCalendarRegistry::BR_BANKING_ANBIMA))->toBeFalse();
});

it('shifts the observation lag differently in each calendar', function () {
    PuSimulationFixture::contractualEmission();
    assumeBankHolidayOnCorpusChristi();
    $calendar = app(BusinessCalendarService::class);
    $corpusChristi = CarbonImmutable::parse(RATE_CALENDAR_CORPUS_CHRISTI_2026);

    // A data da curva é DERIVADA, não escolhida: é aquela cujo lag -5 cai
    // exatamente em Corpus Christi pelo calendário contratual. Nenhuma das duas
    // pontas é escrita à mão.
    $curveDate = $calendar->shiftBusinessDays($corpusChristi, 5, BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS);

    // Avançar a partir de Corpus Christi não distingue nada: `shiftBusinessDays`
    // só passa a contar no dia seguinte ao de partida, então a classificação do
    // próprio 04/06 fica de fora e os dois calendários chegam à mesma data. É
    // justamente por isso que a prova precisa vir do sentido inverso.
    expect($calendar->shiftBusinessDays($corpusChristi, 5, BusinessCalendarRegistry::BR_BANKING_ANBIMA)
        ->toDateString())->toBe($curveDate->toDateString());

    $contractual = $calendar->shiftBusinessDays($curveDate, -5, BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS);
    $banking = $calendar->shiftBusinessDays($curveDate, -5, BusinessCalendarRegistry::BR_BANKING_ANBIMA);

    // Voltando, 04/06 entra na contagem de um calendário e não do outro: o
    // contratual observa o CDI num dia em que não houve divulgação, e o bancário
    // recua um Dia Útil. Qual é esse dia quem diz é o serviço.
    expect($contractual->toDateString())->toBe(RATE_CALENDAR_CORPUS_CHRISTI_2026)
        ->and($banking->toDateString())->not->toBe($contractual->toDateString())
        ->and($banking->lessThan($contractual))->toBeTrue()
        // E o recuo é resolução de calendário, não fallback para o dia anterior
        // mais próximo: a data bancária é Dia Útil bancário, e 04/06 não é.
        ->and($calendar->isBusinessDay($banking, BusinessCalendarRegistry::BR_BANKING_ANBIMA))->toBeTrue()
        ->and($calendar->isBusinessDay($contractual, BusinessCalendarRegistry::BR_BANKING_ANBIMA))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Default preservado: sem hipótese, nada muda
// ---------------------------------------------------------------------------

it('keeps the contractual calendar for rate observation when no hypothesis is given', function () {
    $scenario = PuSimulationFixture::calculableScenario();
    assumeBankHolidayOnCorpusChristi();

    $withoutHypothesis = app(PuSimulationService::class)->simulate($scenario['emission'], $scenario['input']);
    $explicitlyContractual = app(PuSimulationService::class)->simulate($scenario['emission'], new PuSimulationInput(
        firstIntegralizationDate: PuSimulationFixture::integralizationDate(),
        simulationEndDate: PuSimulationFixture::windowEndDate(),
        indexRateCalendarCode: BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS,
    ));

    // Informar o próprio calendário da curva é indistinguível de não informar.
    expect($withoutHypothesis->requiredRateDates)->toContain(RATE_CALENDAR_CORPUS_CHRISTI_2026)
        ->and($withoutHypothesis->requiredRateDates)->toBe($explicitlyContractual->requiredRateDates)
        ->and($withoutHypothesis->state)->toBe($explicitlyContractual->state);
});

// ---------------------------------------------------------------------------
// Com hipótese: só as datas de taxa mudam
// ---------------------------------------------------------------------------

it('moves the required rate dates without touching curve, events or business day flags', function () {
    $scenario = PuSimulationFixture::calculableScenario();
    assumeBankHolidayOnCorpusChristi();
    $service = app(PuSimulationService::class);

    $contractual = $service->simulate($scenario['emission'], $scenario['input']);
    $banking = $service->simulate($scenario['emission'], bankingHypothesis());

    // A data de observação sai do feriado bancário...
    expect($contractual->requiredRateDates)->toContain(RATE_CALENDAR_CORPUS_CHRISTI_2026)
        ->and($banking->requiredRateDates)->not->toContain(RATE_CALENDAR_CORPUS_CHRISTI_2026);

    // ...e nada da semântica contratual se move.
    expect($banking->events)->toBe($contractual->events)
        ->and($banking->parameters['calendar_code'])->toBe(BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS)
        ->and($banking->parameters)->toBe($contractual->parameters)
        ->and($banking->startDate->toDateString())->toBe($contractual->startDate->toDateString())
        ->and($banking->endDate->toDateString())->toBe($contractual->endDate->toDateString());
});

it('keeps every curve row date, business day flag and DUP/DUT identical under the hypothesis', function () {
    $scenario = PuSimulationFixture::calculableScenario();
    assumeBankHolidayOnCorpusChristi();
    $service = app(PuSimulationService::class);

    $contractual = $service->simulate($scenario['emission'], $scenario['input']);
    $banking = simulateWithSeededRates($scenario['emission'], bankingHypothesis());

    expect($contractual->state)->toBe(PuSimulationState::Calculated)
        ->and($banking->state)->toBe(PuSimulationState::Calculated)
        ->and(count($banking->rows))->toBe(count($contractual->rows));

    foreach ($contractual->rows as $index => $row) {
        $other = $banking->rows[$index];

        // Calendário da curva: intocado.
        expect($other->date->toDateString())->toBe($row->date->toDateString())
            ->and($other->isBusinessDay)->toBe($row->isBusinessDay)
            ->and($other->dupInterest)->toBe($row->dupInterest)
            ->and($other->dutInterest)->toBe($row->dutInterest)
            ->and($other->eventOriginalDate?->toDateString())->toBe($row->eventOriginalDate?->toDateString())
            ->and($other->eventEffectiveDate?->toDateString())->toBe($row->eventEffectiveDate?->toDateString())
            ->and($other->amortizationUnitValue)->toBe($row->amortizationUnitValue);
    }
});

it('uses the shifted observation date for the engine lookup, never corpus christi', function () {
    $scenario = PuSimulationFixture::calculableScenario();
    assumeBankHolidayOnCorpusChristi();

    $banking = simulateWithSeededRates($scenario['emission'], bankingHypothesis());

    expect($banking->state)->toBe(PuSimulationState::Calculated);

    $observationDates = collect($banking->rows)
        ->map(fn ($row): ?string => $row->indexRateDate?->toDateString())
        ->filter()
        ->unique()
        ->values();

    expect($observationDates)->not->toBeEmpty()
        ->and($observationDates->all())->not->toContain(RATE_CALENDAR_CORPUS_CHRISTI_2026)
        // Toda data observada é dia útil bancário: a resolução é do calendário,
        // não de um fallback para o dia anterior mais próximo.
        ->and($observationDates->every(fn (string $date): bool => app(BusinessCalendarService::class)
            ->isBusinessDay(CarbonImmutable::parse($date), BusinessCalendarRegistry::BR_BANKING_ANBIMA)))->toBeTrue();
});

// ---------------------------------------------------------------------------
// A hipótese não escreve nada
// ---------------------------------------------------------------------------

it('persists nothing when the observation calendar hypothesis is used', function () {
    $scenario = PuSimulationFixture::calculableScenario();
    assumeBankHolidayOnCorpusChristi();
    // As taxas da hipótese são semeadas ANTES do retrato: o que está sob prova
    // é a simulação, que não pode escrever nada por conta própria.
    $calculated = simulateWithSeededRates($scenario['emission'], bankingHypothesis());
    $before = PuSimulationFixture::counts();

    app(PuSimulationService::class)->simulate($scenario['emission'], bankingHypothesis());

    expect($calculated->state)->toBe(PuSimulationState::Calculated)
        ->and(PuSimulationFixture::counts())->toBe($before);
});

// ---------------------------------------------------------------------------
// Tela
// ---------------------------------------------------------------------------

it('offers the observation calendar as an explicit simulation hypothesis on the page', function () {
    $this->actingAs(makeAdminUser());
    $scenario = PuSimulationFixture::calculableScenario();
    assumeBankHolidayOnCorpusChristi();

    $component = Livewire::test(PuCalculatorSimulator::class, ['record' => $scenario['emission']->getRouteKey()])
        ->assertOk()
        ->set('firstIntegralizationDate', PuSimulationFixture::integralizationDate()->toDateString())
        ->set('simulationEndDate', PuSimulationFixture::windowEndDate()->toDateString());

    // Sem hipótese: a tela declara que o CDI segue o calendário da curva.
    expect($component->instance()->indexRateCalendarOverride())->toBeNull()
        ->and($component->instance()->curveCalendarCode())->toBe(BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS)
        ->and($component->instance()->indexRateCalendarOptions())
        ->toHaveKey(BusinessCalendarRegistry::BR_BANKING_ANBIMA);

    $component->set('indexRateCalendarCode', BusinessCalendarRegistry::BR_BANKING_ANBIMA)
        ->call('calculate')
        ->assertSee('Calendário de observação do CDI')
        ->assertSee('Hipótese de simulação');

    expect($component->instance()->indexRateCalendarOverride())
        ->toBe(BusinessCalendarRegistry::BR_BANKING_ANBIMA)
        ->and($component->instance()->result()->requiredRateDates)->not->toContain(RATE_CALENDAR_CORPUS_CHRISTI_2026);
});

it('forgets the hypothesis on a fresh component', function () {
    $this->actingAs(makeAdminUser());
    $scenario = PuSimulationFixture::calculableScenario();
    assumeBankHolidayOnCorpusChristi();

    Livewire::test(PuCalculatorSimulator::class, ['record' => $scenario['emission']->getRouteKey()])
        ->set('indexRateCalendarCode', BusinessCalendarRegistry::BR_BANKING_ANBIMA);

    // A hipótese vive só na sessão de Livewire: nada a recupera.
    $fresh = Livewire::test(PuCalculatorSimulator::class, ['record' => $scenario['emission']->getRouteKey()]);

    expect($fresh->instance()->indexRateCalendarCode)->toBeNull()
        ->and($fresh->instance()->indexRateCalendarOverride())->toBeNull();

    expect(IndexRate::query()->count())->toBeGreaterThan(0);
});
