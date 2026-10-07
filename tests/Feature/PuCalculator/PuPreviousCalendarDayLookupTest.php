<?php

use App\Actions\Emissions\GeneratePuDailyCurve;
use App\Actions\Emissions\HomologatePuCurve;
use App\Domain\PuCalculator\Calculators\DailyFactorCalculator;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexRateLookupMode;
use App\Domain\PuCalculator\Enums\PuOfficialCurveFreshness;
use App\Domain\PuCalculator\Services\BusinessDayCalendarService;
use App\Domain\PuCalculator\Services\DecimalRounder;
use App\Domain\PuCalculator\Services\PuCurveExtensionService;
use App\Domain\PuCalculator\Services\PuCurveGenerationService;
use App\Domain\PuCalculator\Services\PuCurvePrerequisiteService;
use App\Domain\PuCalculator\Services\PuIndexCoverageService;
use App\Domain\PuCalculator\Services\PuIndexRateRequirementResolver;
use App\Domain\PuCalculator\Services\PuOfficialCurveFreshnessService;
use App\Models\BusinessCalendarDate;
use App\Models\Emission;
use App\Models\EmissionPuDailyCurve;
use App\Models\IndexRate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Fase 3.1 -- D-1 calendário (`PreviousCalendarDayExact`) coerente.
 *
 * O modo vem do Termo da TROUPE: o Fator DI de uma data usa o CDI do dia
 * CALENDÁRIO anterior, e o dia anterior sem divulgação (sábado, domingo,
 * feriado) não tem CDI -- a data seguinte a ele acumula Fator DI 1. Cada CDI
 * divulgado incide uma única vez, no dia seguinte ao da observação.
 *
 * Até aqui os pré-requisitos exigiam o CDI de sábado no domingo, o modo nunca
 * gerava curva e a engine, sozinha, preenchia com Fator DI 1 até um buraco real
 * no histórico. Agora uma regra só -- o resolvedor -- diz qual observação cada
 * data exige, e pré-requisito, cobertura, geração, extensão e atualidade
 * perguntam a ele.
 *
 * Semana do feriado de 07/09/2026 (segunda-feira), calendário B3 legado (dados
 * ANBIMA): sexta 04/09, sábado 05, domingo 06, feriado 07, terça 08, quarta 09.
 */
uses(RefreshDatabase::class);

const P31D_HOLIDAY = '2026-09-07';

function p31dSeedCalendar(string $from, string $to): void
{
    for ($date = CarbonImmutable::parse($from); $date->lte(CarbonImmutable::parse($to)); $date = $date->addDay()) {
        BusinessCalendarDate::query()->create([
            'calendar_code' => 'B3',
            'calendar_date' => $date->toDateString(),
            'is_business_day' => ! $date->isWeekend() && $date->toDateString() !== P31D_HOLIDAY,
            'description' => $date->toDateString() === P31D_HOLIDAY ? 'Independência do Brasil' : null,
        ]);
    }

    app(BusinessDayCalendarService::class)->flushCache();
}

/**
 * CDI divulgado em cada dia útil do intervalo (nunca no fim de semana nem no feriado).
 *
 * @param  list<string>  $except
 */
function p31dPublish(string $from, string $to, array $except = []): void
{
    for ($date = CarbonImmutable::parse($from); $date->lte(CarbonImmutable::parse($to)); $date = $date->addDay()) {
        if ($date->isWeekend() || $date->toDateString() === P31D_HOLIDAY || in_array($date->toDateString(), $except, true)) {
            continue;
        }

        IndexRate::factory()->create([
            'indexer' => PuIndexer::Cdi->value,
            'rate_date' => $date->toDateString(),
            'rate_value' => '14.90000000',
            'source' => 'bcb_sgs',
            'source_reference' => 'bcb_sgs:4389',
        ]);
    }
}

function p31dEmission(): Emission
{
    p31dSeedCalendar('2026-08-24', '2027-01-15');

    $emission = Emission::factory()->active()->create(['type' => 'CR']);
    $emission->integralizationHistories()->create([
        'date' => '2026-08-31',
        'quantity' => '100.0000',
        'unit_value' => '1000.00000000',
        'financial_value' => '100000.00',
        'investor_fund' => 'Head Invest',
    ]);
    $emission->puParameter()->create([
        'curve_start_date' => '2026-08-31',
        'curve_end_date' => '2026-12-31',
        'initial_unit_value' => '1000.0000000000000000',
        'spread_rate' => '6.50000000',
        'indexer' => PuIndexer::Cdi->value,
        'business_day_basis' => 252,
        'calendar_code' => 'B3',
        'index_rate_lookup_mode' => PuIndexRateLookupMode::PreviousCalendarDayExact->value,
        'index_rate_lag_business_days' => 1,
        'legacy_projection_enabled' => false,
    ]);

    return $emission->fresh();
}

function p31dAt(string $localDateTime): CarbonImmutable
{
    return CarbonImmutable::parse($localDateTime, 'America/Sao_Paulo');
}

/**
 * @return array<string, EmissionPuDailyCurve>
 */
function p31dOfficialRows(Emission $emission): array
{
    return EmissionPuDailyCurve::query()
        ->where('emission_id', $emission->id)
        ->whereHas('curveVersion', fn ($query) => $query->official())
        ->orderBy('curve_date')
        ->get()
        ->keyBy(fn (EmissionPuDailyCurve $row): string => CarbonImmutable::parse((string) $row->curve_date)->toDateString())
        ->all();
}

dataset('p31d_required_observation', [
    'segunda comum: domingo sem CDI' => ['2026-08-31', null],
    'terca comum: CDI de segunda' => ['2026-09-01', '2026-08-31'],
    'sabado: CDI de sexta' => ['2026-09-05', '2026-09-04'],
    'domingo: sabado sem CDI' => ['2026-09-06', null],
    'feriado: domingo sem CDI' => ['2026-09-07', null],
    'dia util logo apos o feriado: o feriado nao teve CDI' => ['2026-09-08', null],
    'segundo dia util apos o feriado: CDI da terca' => ['2026-09-09', '2026-09-08'],
]);

it('resolves one D-1 observation date per curve date, from the publication calendar', function (string $curveDate, ?string $requiredRateDate) {
    $emission = p31dEmission();

    $requirement = app(PuIndexRateRequirementResolver::class)->resolve(
        $emission->puParameter,
        CarbonImmutable::parse($curveDate),
    );

    expect($requirement->lookupDate?->toDateString())->toBe($requiredRateDate)
        ->and($requirement->requiredRateDate()?->toDateString())->toBe($requiredRateDate)
        ->and($requirement->isRequiredForCalculation())->toBe($requiredRateDate !== null)
        ->and($requirement->rateCalendarCode)->toBe('B3');
})->with('p31d_required_observation');

it('keeps prerequisites, generation, official extension and freshness on the same D-1 observation dates', function () {
    $emission = p31dEmission();
    p31dPublish('2026-08-24', '2026-09-03');

    // Quinta, 03/09: a sexta 04/09 exige o CDI de quinta (já divulgado); o sábado
    // exige o de sexta, ainda não divulgado -- a curva realizada termina na sexta.
    $this->travelTo(p31dAt('2026-09-04 10:00'));
    $prerequisite = app(PuCurvePrerequisiteService::class)->handle($emission);
    $coverage = app(PuIndexCoverageService::class)->report($emission);
    $generated = app(GeneratePuDailyCurve::class)->handle($emission);
    app(HomologatePuCurve::class)->handle($emission->fresh(), $generated->calculationVersion, User::factory()->create()->id, 'Conferida contra o Termo.');
    $friday = app(PuOfficialCurveFreshnessService::class)->status($emission->fresh(), p31dAt('2026-09-04 10:00'));

    expect($prerequisite->passes())->toBeTrue()
        ->and($coverage->missingIndexDates)->toBe([])
        ->and($coverage->pendingIndexDates)->toBe(['2026-09-04'])
        ->and(array_key_last(p31dOfficialRows($emission)))->toBe('2026-09-04')
        ->and($friday->freshness)->toBe(PuOfficialCurveFreshness::Current)
        ->and($friday->realizedThrough?->toDateString())->toBe('2026-09-04')
        ->and($friday->nextRequiredRateDate?->toDateString())->toBe('2026-09-04');

    // Terça, 08/09, 10:00: chegou só o CDI de sexta. Sábado o usa; domingo, o
    // feriado e a própria terça não exigem nada (sábado, domingo e o feriado não
    // tiveram CDI). Quarta exige o de terça. A extensão vai até terça, e a
    // atualidade espera, para terça às 10:00, só o CDI de sexta -- o feriado não
    // vira divulgação atrasada.
    p31dPublish('2026-09-04', '2026-09-04');
    $this->travelTo(p31dAt('2026-09-08 10:00'));
    $extension = app(PuCurveExtensionService::class)->extendOfficial($emission->fresh());
    $rows = p31dOfficialRows($emission);
    $tuesday = app(PuOfficialCurveFreshnessService::class)->status($emission->fresh(), p31dAt('2026-09-08 10:00'));
    // A matemática do fator não muda: o fator de sábado é o de um dia de CDI a 14,90% a.a.
    $expectedFactor = app(DecimalRounder::class)->round(
        app(DailyFactorCalculator::class)->factorDiForDay('14.90000000', true, 252, DecimalRounder::CALCULATION_SCALE),
        DecimalRounder::FACTOR_SCALE,
    );

    expect($extension->action)->toBe(PuCurveExtensionService::ACTION_EXTENDED)
        ->and(array_key_last($rows))->toBe('2026-09-08')
        ->and(CarbonImmutable::parse((string) $rows['2026-09-05']->index_rate_date)->toDateString())->toBe('2026-09-04')
        // Relido do banco: o SQLite guarda 15 dígitos significativos, daí 12 casas.
        ->and(bccomp((string) $rows['2026-09-05']->factor_di, $expectedFactor, 12))->toBe(0)
        ->and(bccomp((string) $rows['2026-09-05']->factor_di, '1', 16))->toBe(1)
        ->and($rows['2026-09-06']->index_rate_date)->toBeNull()
        ->and($rows['2026-09-07']->index_rate_date)->toBeNull()
        ->and($rows['2026-09-08']->index_rate_date)->toBeNull()
        ->and(bccomp((string) $rows['2026-09-06']->factor_di, '1', 16))->toBe(0)
        ->and(bccomp((string) $rows['2026-09-07']->factor_di, '1', 16))->toBe(0)
        ->and(bccomp((string) $rows['2026-09-08']->factor_di, '1', 16))->toBe(0)
        ->and($tuesday->freshness)->toBe(PuOfficialCurveFreshness::Current)
        ->and($tuesday->expectedLatestRateDate?->toDateString())->toBe('2026-09-04')
        ->and($tuesday->nextRequiredRateDate?->toDateString())->toBe('2026-09-08')
        ->and($tuesday->realizedThrough?->toDateString())->toBe('2026-09-08');

    // Quarta, 09/09, 10:00: o CDI de terça já devia estar no banco. Ele falta --
    // e o de quarta chegou: é buraco no histórico. Todos apontam a mesma data.
    p31dPublish('2026-09-09', '2026-09-09');
    $this->travelTo(p31dAt('2026-09-10 10:00'));
    $blocked = app(PuCurvePrerequisiteService::class)->handle($emission->fresh());
    $holeCoverage = app(PuIndexCoverageService::class)->report($emission->fresh());
    $blockedExtension = app(PuCurveExtensionService::class)->extendOfficial($emission->fresh());
    $hole = app(PuOfficialCurveFreshnessService::class)->status($emission->fresh(), p31dAt('2026-09-10 10:00'));
    $engineRows = app(PuCurveGenerationService::class)->handle($emission->fresh())->rows;

    expect($blocked->passes())->toBeFalse()
        ->and($blocked->blockingSummary())->toContain('Taxa DI ausente para 2026-09-08')
        ->and($blocked->blockingSummary())->toContain('Data da curva: 2026-09-09')
        ->and($holeCoverage->missingIndexDates)->toBe(['2026-09-08'])
        ->and($blockedExtension->action)->toBe(PuCurveExtensionService::ACTION_PREREQUISITES_BLOCKED)
        ->and($hole->freshness)->toBe(PuOfficialCurveFreshness::MissingIndex)
        ->and($hole->nextRequiredRateDate?->toDateString())->toBe('2026-09-08')
        // A engine sozinha também para: nada de Fator DI 1 no lugar de um CDI que falta.
        ->and(end($engineRows)->date->toDateString())->toBe('2026-09-08')
        ->and(array_key_last(p31dOfficialRows($emission)))->toBe('2026-09-08');

    // O CDI de terça chega: a extensão segue até quarta, que usa exatamente ele.
    p31dPublish('2026-09-08', '2026-09-08');
    $resumed = app(PuCurveExtensionService::class)->extendOfficial($emission->fresh());
    $rows = p31dOfficialRows($emission);

    expect($resumed->action)->toBe(PuCurveExtensionService::ACTION_EXTENDED)
        ->and(CarbonImmutable::parse((string) $rows['2026-09-09']->index_rate_date)->toDateString())->toBe('2026-09-08')
        ->and(CarbonImmutable::parse((string) $rows['2026-09-10']->index_rate_date)->toDateString())->toBe('2026-09-09')
        ->and(array_key_last($rows))->toBe('2026-09-10');
});

it('never completes the D-1 future with the last published CDI', function () {
    $emission = p31dEmission();
    p31dPublish('2026-08-24', '2026-09-04');
    $this->travelTo(p31dAt('2026-09-05 10:00'));

    $rows = app(PuCurveGenerationService::class)->handle($emission)->rows;
    $requirement = app(PuIndexRateRequirementResolver::class)->resolve($emission->puParameter, CarbonImmutable::parse('2026-09-09'));

    // Sábado usa a sexta; domingo, feriado e terça não exigem nada; quarta exige o
    // CDI de terça, que ainda não existe: futuro, nunca a última taxa repetida.
    expect(end($rows)->date->toDateString())->toBe('2026-09-08')
        ->and($requirement->isAwaitingPublication())->toBeTrue()
        ->and($requirement->isMissingHistoricalObservation())->toBeFalse()
        ->and($requirement->endsRealizedCurve())->toBeTrue()
        ->and($requirement->rate)->toBeNull();
});
