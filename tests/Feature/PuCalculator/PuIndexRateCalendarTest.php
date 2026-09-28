<?php

use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexRateLookupMode;
use App\Domain\PuCalculator\Services\BusinessCalendarService;
use App\Domain\PuCalculator\Services\IndexRateService;
use App\Domain\PuCalculator\Services\NationalLegalHolidayMaterializationService;
use App\Domain\PuCalculator\Services\PuCurvePrerequisiteService;
use App\Domain\PuCalculator\Services\PuIndexRateRequirementResolver;
use App\Domain\PuCalculator\Services\PuNumericHomologationFingerprintService;
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

function nationalLagParameter(?string $indexRateCalendarCode): EmissionPuParameter
{
    return EmissionPuParameter::factory()->make([
        'indexer' => PuIndexer::Cdi->value,
        'calendar_code' => BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS,
        'index_rate_calendar_code' => $indexRateCalendarCode,
        'index_rate_lookup_mode' => PuIndexRateLookupMode::BusinessDayLagExact->value,
        'index_rate_lag_business_days' => -5,
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

it('counts the CDI lag on the saved publication calendar while accrual stays contractual', function () {
    coverBankingCalendar2026();
    $resolver = app(PuIndexRateRequirementResolver::class);
    $curveDate = CarbonImmutable::parse('2026-06-11');

    $contractual = $resolver->resolve(nationalLagParameter(null), $curveDate);
    $publication = $resolver->resolve(nationalLagParameter(BusinessCalendarRegistry::BR_BANKING_ANBIMA), $curveDate);
    $corpusChristi = $resolver->resolve(
        nationalLagParameter(BusinessCalendarRegistry::BR_BANKING_ANBIMA),
        CarbonImmutable::parse(PUBLICATION_CORPUS_CHRISTI_2026),
    );

    expect($contractual->lookupDate?->toDateString())->toBe(PUBLICATION_CORPUS_CHRISTI_2026)
        ->and($contractual->ruleDescription())->toContain(BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS)
        ->and($publication->lookupDate?->toDateString())->toBe('2026-06-03')
        ->and($publication->ruleDescription())->toContain(BusinessCalendarRegistry::BR_BANKING_ANBIMA)
        ->and($publication->calendarCode)->toBe(BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS)
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

it('stops demanding an unpublished CDI once the publication calendar is saved', function () {
    app(NationalLegalHolidayMaterializationService::class)->materialize(2026, 2026, User::factory()->create()->id);
    coverBankingCalendar2026();
    seedPublishedCdi('2026-05-20', '2026-06-15');

    $contractual = app(PuCurvePrerequisiteService::class)->handle(nationalCalendarEmission(null)->fresh());
    $publication = app(PuCurvePrerequisiteService::class)->handle(
        nationalCalendarEmission(BusinessCalendarRegistry::BR_BANKING_ANBIMA)->fresh(),
    );

    expect($contractual->passes())->toBeFalse()
        ->and($contractual->blockingSummary())->toContain('Taxa DI ausente para '.PUBLICATION_CORPUS_CHRISTI_2026)
        ->and($publication->passes())->toBeTrue();
});

it('requires official coverage of the publication calendar for the curve period', function () {
    app(NationalLegalHolidayMaterializationService::class)->materialize(2026, 2026, User::factory()->create()->id);
    seedPublishedCdi('2026-05-20', '2026-06-15');

    $result = app(PuCurvePrerequisiteService::class)->handle(
        nationalCalendarEmission(BusinessCalendarRegistry::BR_BANKING_ANBIMA)->fresh(),
    );
    $calendarIssue = collect($result->blockingIssues())->firstWhere('key', 'business_calendar_dates');

    expect($calendarIssue?->message)->toContain(BusinessCalendarRegistry::BR_BANKING_ANBIMA)
        ->and($calendarIssue?->message)->toContain('ano(s) 2026');
});

it('keeps numeric homologation fingerprints unchanged when no publication calendar is saved', function () {
    $fingerprints = app(PuNumericHomologationFingerprintService::class);

    expect($fingerprints->parameterSnapshot(nationalLagParameter(null)))->not->toHaveKey('index_rate_calendar_code')
        ->and($fingerprints->parameterSnapshot(nationalLagParameter(BusinessCalendarRegistry::BR_BANKING_ANBIMA)))
        ->toHaveKey('index_rate_calendar_code', BusinessCalendarRegistry::BR_BANKING_ANBIMA);
});
