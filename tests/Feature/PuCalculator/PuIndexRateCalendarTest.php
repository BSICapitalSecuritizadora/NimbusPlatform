<?php

use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexRateLookupMode;
use App\Domain\PuCalculator\Services\BusinessCalendarService;
use App\Domain\PuCalculator\Services\IndexRateService;
use App\Domain\PuCalculator\Services\NationalLegalHolidayMaterializationService;
use App\Domain\PuCalculator\Services\PuCurvePrerequisiteService;
use App\Domain\PuCalculator\Services\PuIndexRateRequirementResolver;
use App\Domain\PuCalculator\Services\PuNumericHomologationFingerprintService;
use App\Domain\PuCalculator\Services\PuOfficialCurveFreshnessService;
use App\Domain\PuCalculator\Support\BusinessCalendarRegistry;
use App\Models\BusinessCalendarDate;
use App\Models\BusinessCalendarImportRun;
use App\Models\BusinessCalendarYear;
use App\Models\Emission;
use App\Models\EmissionPuParameter;
use App\Models\IndexRate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * 04/06/2026 é Corpus Christi: dia útil em `BR_NATIONAL_HOLIDAYS` (só feriados de
 * lei federal), sem expediente bancário e sem divulgação de CDI.
 */
const PUBLICATION_CORPUS_CHRISTI_2026 = '2026-06-04';

/**
 * Calendário bancário de 2026 com cobertura oficial completa e a exceção de
 * Corpus Christi, pelo mesmo mecanismo que o importador ANBIMA usa: ano com
 * checksum e execução aplicada com o mesmo checksum.
 */
function coverBankingCalendar2026(): void
{
    $checksum = hash('sha256', 'anbima-2026');
    $year = BusinessCalendarYear::factory()->create([
        'calendar_code' => BusinessCalendarRegistry::BR_BANKING_ANBIMA,
        'year' => 2026,
        'status' => BusinessCalendarYear::STATUS_PROVISIONAL,
        'source' => 'anbima',
        'source_is_official' => true,
        'source_document' => 'feriados_nacionais.xls',
        'checksum' => $checksum,
    ]);
    BusinessCalendarImportRun::factory()->create([
        'business_calendar_year_id' => $year->id,
        'calendar_code' => BusinessCalendarRegistry::BR_BANKING_ANBIMA,
        'year' => 2026,
        'checksum' => $checksum,
        'dry_run' => false,
        'result' => BusinessCalendarImportRun::RESULT_SUCCEEDED,
    ]);
    BusinessCalendarDate::query()->create([
        'calendar_code' => BusinessCalendarRegistry::BR_BANKING_ANBIMA,
        'calendar_date' => PUBLICATION_CORPUS_CHRISTI_2026,
        'is_business_day' => false,
        'description' => 'Corpus Christi',
        'data_origin' => 'imported',
        'source' => 'ANBIMA',
        'source_is_official' => true,
    ]);

    app(BusinessCalendarService::class)->flushCache();
}

function nationalLagParameter(
    ?string $indexRateCalendarCode,
    PuIndexRateLookupMode $lookupMode = PuIndexRateLookupMode::BusinessDayLagExact,
    int $lag = -5,
    string $calendarCode = BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS,
): EmissionPuParameter {
    return EmissionPuParameter::factory()->make([
        'indexer' => PuIndexer::Cdi->value,
        'calendar_code' => $calendarCode,
        'index_rate_calendar_code' => $indexRateCalendarCode,
        'index_rate_lookup_mode' => $lookupMode->value,
        'index_rate_lag_business_days' => $lag,
    ]);
}

/**
 * CDI publicado em todo dia de semana do período, exceto Corpus Christi.
 */
function seedPublishedCdi(string $from, string $to): void
{
    for ($date = CarbonImmutable::parse($from); $date->lte(CarbonImmutable::parse($to)); $date = $date->addDay()) {
        if ($date->isWeekend() || $date->toDateString() === PUBLICATION_CORPUS_CHRISTI_2026) {
            continue;
        }

        IndexRate::factory()->create([
            'indexer' => PuIndexer::Cdi->value,
            'rate_date' => $date->toDateString(),
            'rate_value' => '14.90000000',
            'source' => 'testing',
            'source_reference' => 'publication-calendar',
        ]);
    }

    app(IndexRateService::class)->flushCache();
}

function nationalCalendarEmission(?string $indexRateCalendarCode): Emission
{
    $emission = Emission::factory()->active()->create(['type' => 'CRI']);
    $emission->integralizationHistories()->create([
        'date' => '2026-06-01',
        'quantity' => '10.0000',
        'unit_value' => '1000.00000000',
        'financial_value' => '10000.00',
        'investor_fund' => 'Head Invest',
    ]);
    $emission->puParameter()->create([
        'curve_start_date' => '2026-06-01',
        'curve_end_date' => '2026-06-15',
        'initial_unit_value' => '1000.0000000000000000',
        'spread_rate' => '6.00000000',
        'indexer' => PuIndexer::Cdi->value,
        'business_day_basis' => 252,
        'calendar_code' => BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS,
        'index_rate_calendar_code' => $indexRateCalendarCode,
        'index_rate_lookup_mode' => PuIndexRateLookupMode::BusinessDayLagExact->value,
        'index_rate_lag_business_days' => -5,
        'legacy_projection_enabled' => false,
    ]);

    return $emission;
}

it('counts the CDI lag on the CDI publication calendar while accrual stays contractual', function () {
    coverBankingCalendar2026();
    $resolver = app(PuIndexRateRequirementResolver::class);
    $curveDate = CarbonImmutable::parse('2026-06-11');

    // Fase 3.1: sem calendário de divulgação salvo, um contrato em feriados
    // nacionais não leva a defasagem para Corpus Christi -- o padrão é o
    // calendário de divulgação do CDI, não o do contrato.
    $default = $resolver->resolve(nationalLagParameter(null), $curveDate);
    $publication = $resolver->resolve(nationalLagParameter(BusinessCalendarRegistry::BR_BANKING_ANBIMA), $curveDate);
    // A escolha explícita é preservada, mesmo a de um calendário sem divulgação.
    $explicitNational = $resolver->resolve(nationalLagParameter(BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS), $curveDate);
    $corpusChristi = $resolver->resolve(
        nationalLagParameter(BusinessCalendarRegistry::BR_BANKING_ANBIMA),
        CarbonImmutable::parse(PUBLICATION_CORPUS_CHRISTI_2026),
    );

    expect($default->lookupDate?->toDateString())->toBe('2026-06-03')
        ->and($default->rateCalendarCode)->toBe(BusinessCalendarRegistry::CDI_PUBLICATION_CALENDAR)
        ->and($default->ruleDescription())->toContain(BusinessCalendarRegistry::BR_BANKING_ANBIMA)
        ->and($default->calendarCode)->toBe(BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS)
        ->and($publication->lookupDate?->toDateString())->toBe('2026-06-03')
        ->and($publication->ruleDescription())->toContain(BusinessCalendarRegistry::BR_BANKING_ANBIMA)
        ->and($publication->calendarCode)->toBe(BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS)
        ->and($explicitNational->lookupDate?->toDateString())->toBe(PUBLICATION_CORPUS_CHRISTI_2026)
        ->and($explicitNational->ruleDescription())->toContain(BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS)
        ->and($corpusChristi->isBusinessDay)->toBeTrue();
});

it('lets an explicit simulation hypothesis override the saved publication calendar', function () {
    coverBankingCalendar2026();

    $requirement = app(PuIndexRateRequirementResolver::class)->resolve(
        nationalLagParameter(BusinessCalendarRegistry::BR_BANKING_ANBIMA),
        CarbonImmutable::parse('2026-06-11'),
        BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS,
    );

    expect($requirement->lookupDate?->toDateString())->toBe(PUBLICATION_CORPUS_CHRISTI_2026);
});

it('never demands an unpublished CDI on a bank holiday the contract calendar does not know', function () {
    app(NationalLegalHolidayMaterializationService::class)->materialize(2026, 2026, User::factory()->create()->id);
    coverBankingCalendar2026();
    seedPublishedCdi('2026-05-20', '2026-06-15');

    $default = app(PuCurvePrerequisiteService::class)->handle(nationalCalendarEmission(null)->fresh());
    $publication = app(PuCurvePrerequisiteService::class)->handle(
        nationalCalendarEmission(BusinessCalendarRegistry::BR_BANKING_ANBIMA)->fresh(),
    );
    // Escolha explícita de um calendário sem divulgação: preservada, e a falta
    // do CDI de Corpus Christi aparece como falta -- nunca preenchida.
    $explicitNational = app(PuCurvePrerequisiteService::class)->handle(
        nationalCalendarEmission(BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS)->fresh(),
    );

    expect($default->passes())->toBeTrue()
        ->and($publication->passes())->toBeTrue()
        ->and($explicitNational->passes())->toBeFalse()
        ->and($explicitNational->blockingSummary())->toContain('Taxa DI ausente para '.PUBLICATION_CORPUS_CHRISTI_2026);
});

it('requires official coverage of the publication calendar for the curve period', function (?string $indexRateCalendarCode) {
    app(NationalLegalHolidayMaterializationService::class)->materialize(2026, 2026, User::factory()->create()->id);
    seedPublishedCdi('2026-05-20', '2026-06-15');

    // Sem o ano oficial do calendário bancário, a geração é bloqueada com o nome
    // do calendário -- explícito ou padrão --, nunca segue de segunda a sexta.
    $result = app(PuCurvePrerequisiteService::class)->handle(
        nationalCalendarEmission($indexRateCalendarCode)->fresh(),
    );
    $calendarIssue = collect($result->blockingIssues())->firstWhere('key', 'business_calendar_dates');

    expect($result->passes())->toBeFalse()
        ->and($calendarIssue?->message)->toContain(BusinessCalendarRegistry::BR_BANKING_ANBIMA)
        ->and($calendarIssue?->message)->toContain('ano(s) 2026');
})->with([
    'calendario de divulgacao salvo' => [BusinessCalendarRegistry::BR_BANKING_ANBIMA],
    'calendario de divulgacao padrao' => [null],
]);

it('keeps numeric homologation fingerprints unchanged when no publication calendar is saved', function () {
    $fingerprints = app(PuNumericHomologationFingerprintService::class);

    expect($fingerprints->parameterSnapshot(nationalLagParameter(null)))->not->toHaveKey('index_rate_calendar_code')
        ->and($fingerprints->parameterSnapshot(nationalLagParameter(BusinessCalendarRegistry::BR_BANKING_ANBIMA)))
        ->toHaveKey('index_rate_calendar_code', BusinessCalendarRegistry::BR_BANKING_ANBIMA);
});

/*
 * Fase 3.1 -- calendário de divulgação do CDI por padrão.
 *
 * Contrato em feriados nacionais legais (Corpus Christi é dia útil nele), sem
 * calendário de divulgação salvo: a data de observação de cada modo sai do
 * calendário bancário. Semana de 01 a 09/06/2026: quinta 04/06 é Corpus Christi.
 */
dataset('publication_calendar_default_cases', [
    'ultimo disponivel: dia util comum' => [PuIndexRateLookupMode::PreviousAvailableBusinessDay, 0, '2026-06-03', '2026-06-03'],
    'ultimo disponivel: sabado nao exige' => [PuIndexRateLookupMode::PreviousAvailableBusinessDay, 0, '2026-06-06', null],
    'ultimo disponivel: Corpus Christi usa a quarta' => [PuIndexRateLookupMode::PreviousAvailableBusinessDay, 0, PUBLICATION_CORPUS_CHRISTI_2026, '2026-06-03'],
    'ultimo disponivel: primeiro dia de divulgacao apos o feriado' => [PuIndexRateLookupMode::PreviousAvailableBusinessDay, 0, '2026-06-05', '2026-06-05'],
    'D-1: quinta usa a quarta' => [PuIndexRateLookupMode::PreviousCalendarDayExact, 1, PUBLICATION_CORPUS_CHRISTI_2026, '2026-06-03'],
    'D-1: sexta apos Corpus Christi nao exige' => [PuIndexRateLookupMode::PreviousCalendarDayExact, 1, '2026-06-05', null],
    'D-1: segunda nao exige o domingo' => [PuIndexRateLookupMode::PreviousCalendarDayExact, 1, '2026-06-08', null],
    'D-1: terca usa a segunda' => [PuIndexRateLookupMode::PreviousCalendarDayExact, 1, '2026-06-09', '2026-06-08'],
    'defasagem: sexta pula Corpus Christi' => [PuIndexRateLookupMode::BusinessDayLagExact, -1, '2026-06-05', '2026-06-03'],
    'defasagem: segunda usa a sexta' => [PuIndexRateLookupMode::BusinessDayLagExact, -1, '2026-06-08', '2026-06-05'],
]);

it('resolves the CDI observation date on the publication calendar by default', function (
    PuIndexRateLookupMode $mode,
    int $lag,
    string $curveDate,
    ?string $requiredRateDate,
) {
    coverBankingCalendar2026();

    $requirement = app(PuIndexRateRequirementResolver::class)->resolve(
        nationalLagParameter(null, $mode, $lag),
        CarbonImmutable::parse($curveDate),
    );

    expect($requirement->rateCalendarCode)->toBe(BusinessCalendarRegistry::CDI_PUBLICATION_CALENDAR)
        ->and($requirement->calendarCode)->toBe(BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS)
        ->and($requirement->isRequiredForCalculation() ? $requirement->requiredRateDate()?->toDateString() : null)
        ->toBe($requiredRateDate);
})->with('publication_calendar_default_cases');

it('keeps a contract already on a CDI publication calendar on its own calendar', function (string $calendarCode) {
    $parameter = nationalLagParameter(null, calendarCode: $calendarCode);
    $resolver = app(PuIndexRateRequirementResolver::class);

    expect($resolver->observationCalendarCode($parameter))->toBe($calendarCode)
        ->and(BusinessCalendarRegistry::isCdiPublicationCalendar($calendarCode))->toBeTrue();
})->with([
    'bancario ANBIMA' => [BusinessCalendarRegistry::BR_BANKING_ANBIMA],
    'legado com os dados da ANBIMA' => [BusinessCalendarRegistry::LEGACY_B3],
]);

it('falls back to the CDI publication calendar for every contract calendar that does not publish the CDI', function (string $calendarCode) {
    $resolver = app(PuIndexRateRequirementResolver::class);

    expect(BusinessCalendarRegistry::isCdiPublicationCalendar($calendarCode))->toBeFalse()
        ->and($resolver->observationCalendarCode(nationalLagParameter(null, calendarCode: $calendarCode)))
        ->toBe(BusinessCalendarRegistry::CDI_PUBLICATION_CALENDAR)
        // A hipótese de simulação e a escolha salva continuam valendo acima do padrão.
        ->and($resolver->observationCalendarCode(nationalLagParameter($calendarCode, calendarCode: $calendarCode)))
        ->toBe($calendarCode)
        ->and($resolver->observationCalendarCode(nationalLagParameter(null, calendarCode: $calendarCode), 'HML_PU_SIMULACAO'))
        ->toBe('HML_PU_SIMULACAO');
})->with([
    'feriados nacionais legais' => [BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS],
    'sessoes da B3' => [BusinessCalendarRegistry::B3_LISTED_TRADING],
    'consolidado do mercado' => [BusinessCalendarRegistry::BR_FINANCIAL_MARKET],
    'calendario de homologacao' => ['HML_PU_CONTRATO'],
]);

it('expects the next CDI publication on the publication calendar, so a bank holiday is never overdue', function () {
    coverBankingCalendar2026();
    $freshness = app(PuOfficialCurveFreshnessService::class);
    // Sexta, 05/06, 10:00 em Brasília: o último CDI esperado é o de quarta, 03/06 --
    // Corpus Christi não teve divulgação. Contado no calendário do contrato, o
    // esperado seria o de 04/06, e a curva oficial apareceria com índice ausente.
    $friday = CarbonImmutable::parse('2026-06-05 10:00', 'America/Sao_Paulo');

    expect($freshness->expectedLatestRateDate(nationalLagParameter(null), $friday)?->toDateString())->toBe('2026-06-03')
        ->and($freshness->expectedLatestRateDate(nationalLagParameter(BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS), $friday)?->toDateString())
        ->toBe(PUBLICATION_CORPUS_CHRISTI_2026);
});

it('fails explicitly instead of presuming weekdays when the publication calendar cannot decide a date', function () {
    // Calendário de divulgação sem catálogo e sem dados: o resolvedor recusa decidir,
    // e a falta aparece como erro de configuração no pré-requisito e na cobertura.
    $emission = nationalCalendarEmission('HML_PU_SEM_DADOS');
    app(NationalLegalHolidayMaterializationService::class)->materialize(2026, 2026, User::factory()->create()->id);
    seedPublishedCdi('2026-05-20', '2026-06-15');

    expect(fn () => app(PuIndexRateRequirementResolver::class)->resolve($emission->puParameter, CarbonImmutable::parse('2026-06-03')))
        ->toThrow(RuntimeException::class, 'exige decisão explícita');

    $result = app(PuCurvePrerequisiteService::class)->handle($emission->fresh());

    expect($result->passes())->toBeFalse()
        ->and($result->blockingSummary())->toContain('HML_PU_SEM_DADOS');
});
