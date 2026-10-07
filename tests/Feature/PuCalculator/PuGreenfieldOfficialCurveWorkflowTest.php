<?php

use App\Actions\Emissions\GeneratePuDailyCurve;
use App\Actions\Emissions\HomologatePuCurve;
use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexRateLookupMode;
use App\Domain\PuCalculator\Enums\PuOfficialCurveFreshness;
use App\Domain\PuCalculator\Services\BusinessDayCalendarService;
use App\Domain\PuCalculator\Services\EmissionPuReader;
use App\Domain\PuCalculator\Services\IndexRateSyncService;
use App\Domain\PuCalculator\Services\PuCurveVersionService;
use App\Models\BusinessCalendarDate;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\IndexRate;
use App\Models\User;
use App\Services\Guarantees\OutstandingBalanceResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

/**
 * Fase 3 -- o primeiro uso do PU em produção, do zero.
 *
 * Produção não tem curva de PU nenhuma: a primeira nasce depois desta fase.
 * O fluxo inteiro, com o CDI chegando dia a dia pela sincronização do Banco
 * Central (simulada): configurar → a rotina gera a candidata → revisar/validar →
 * homologar → a oficial avança sozinha com o CDI novo, inclusive com uma
 * candidata mais nova aberta ao lado. Em nenhum momento a rotina publica a
 * candidata, e em nenhum momento um CDI futuro é fabricado.
 */
uses(RefreshDatabase::class);

function p3gCalendar(): void
{
    for ($date = CarbonImmutable::parse('2026-07-27'); $date->lte(CarbonImmutable::parse('2027-08-13')); $date = $date->addDay()) {
        BusinessCalendarDate::query()->create([
            'calendar_code' => 'B3',
            'calendar_date' => $date->toDateString(),
            'is_business_day' => ! $date->isWeekend(),
            'description' => null,
        ]);
    }

    app(BusinessDayCalendarService::class)->flushCache();
}

/**
 * O Banco Central "divulga" o CDI de cada dia útil até a data informada.
 *
 * @return list<array{data: string, valor: string}>
 */
function p3gPublishedPayload(string $publishedThrough): array
{
    $rows = [];

    for ($date = CarbonImmutable::parse('2026-07-27'); $date->lte(CarbonImmutable::parse($publishedThrough)); $date = $date->addDay()) {
        if (! $date->isWeekend()) {
            $rows[] = ['data' => $date->format('d/m/Y'), 'valor' => '14.90'];
        }
    }

    return $rows;
}

/**
 * A manhã de um dia útil: a sincronização das 06:30 traz o CDI divulgado, e a
 * rotina das 07:15 estende as curvas.
 */
function p3gMorning(string $date, string $publishedThrough): void
{
    test()->travelTo(CarbonImmutable::parse($date.' 07:15', 'America/Sao_Paulo'));
    // O fake lê, a cada consulta, até onde o "Banco Central" já divulgou.
    config(['phase3_greenfield.published_through' => $publishedThrough]);
    Http::preventStrayRequests();
    Http::fake(['api.bcb.gov.br/dados/serie/bcdata.sgs.4389/*' => fn () => Http::response(
        p3gPublishedPayload((string) config('phase3_greenfield.published_through')),
        200,
    )]);

    app(IndexRateSyncService::class)->sync(PuIndexer::Cdi, CarbonImmutable::parse('2026-07-27'), CarbonImmutable::parse($date));
    test()->artisan('pu:curves:generate-realized')->assertSuccessful();
}

function p3gLastDate(EmissionPuCurveVersion $version): string
{
    return CarbonImmutable::parse((string) $version->fresh()->dailyCurves()->max('curve_date'))->toDateString();
}

it('runs the clean-slate production workflow without publishing candidates or fabricating future CDI', function () {
    // 1-2. Emissão nova e parâmetros de CDI (modo padrão do formulário).
    p3gCalendar();
    $emission = Emission::factory()->active()->create(['type' => 'CRI']);
    $emission->integralizationHistories()->create([
        'date' => '2026-08-03',
        'quantity' => '100.0000',
        'unit_value' => '1000.00000000',
        'financial_value' => '100000.00',
        'investor_fund' => 'Head Invest',
    ]);
    $emission->puParameter()->create([
        'curve_start_date' => '2026-08-03',
        'curve_end_date' => '2027-08-02',
        'initial_unit_value' => '1000.0000000000000000',
        'spread_rate' => '6.00000000',
        'indexer' => PuIndexer::Cdi->value,
        'business_day_basis' => 252,
        'calendar_code' => 'B3',
        'index_rate_lookup_mode' => PuIndexRateLookupMode::PreviousAvailableBusinessDay->value,
        'index_rate_lag_business_days' => 1,
        'legacy_projection_enabled' => false,
    ]);
    $reader = app(EmissionPuReader::class);

    // 3-4. CDI realizado até 24/08; a rotina gera a primeira versão sozinha.
    p3gMorning('2026-08-25', '2026-08-24');
    $candidate = $emission->fresh()->puCurveVersions()->sole();

    // 5. A candidata não é oficial, e nada a lê como oficial.
    expect($candidate->status)->toBe(PuCurveStatus::Generated)
        ->and($candidate->generated_by)->toBeNull()
        ->and(p3gLastDate($candidate))->toBe('2026-08-24')
        ->and($emission->fresh()->officialPuCurveVersion())->toBeNull()
        ->and($reader->readingOn($emission->fresh(), CarbonImmutable::parse('2026-08-24')))->toBeNull()
        ->and($reader->officialStatus($emission->fresh())->freshness)->toBe(PuOfficialCurveFreshness::NoOfficialCurve);

    // O CDI seguinte chega: a rotina estende a candidata, mas não a publica.
    p3gMorning('2026-08-26', '2026-08-25');

    expect($candidate->fresh()->status)->toBe(PuCurveStatus::Generated)
        ->and($candidate->fresh()->homologated_at)->toBeNull()
        ->and(p3gLastDate($candidate))->toBe('2026-08-25')
        ->and($emission->fresh()->officialPuCurveVersion())->toBeNull();

    // 6-7. Validação e homologação explícitas, por pessoas diferentes.
    app(PuCurveVersionService::class)->markValidated($candidate->fresh(), true, ['source' => 'conferência manual'], User::factory()->create()->id);
    $official = app(HomologatePuCurve::class)->handle(
        $emission->fresh(),
        $candidate->calculation_version,
        User::factory()->create()->id,
        'Conferida contra o sistema antigo; primeira curva oficial da emissão.',
    );

    // 8. PU oficial realizado.
    $atHomologation = $reader->officialReadingAt($emission->fresh(), CarbonImmutable::parse('2026-08-25'));

    expect($official->status)->toBe(PuCurveStatus::Homologated)
        ->and($atHomologation['reading']?->fromOfficialCurve())->toBeTrue()
        ->and($atHomologation['status']->freshness)->toBe(PuOfficialCurveFreshness::Current)
        ->and($atHomologation['status']->realizedThrough?->toDateString())->toBe('2026-08-25');

    // 9-11. CDI novo + rotina diária: a oficial avança.
    p3gMorning('2026-08-27', '2026-08-26');

    expect(p3gLastDate($official))->toBe('2026-08-26')
        ->and($reader->officialReadingAt($emission->fresh(), CarbonImmutable::parse('2026-08-26'))['reading']?->date->toDateString())
        ->toBe('2026-08-26');

    // 12. Uma candidata mais nova é gerada ao lado da oficial.
    $result = app(GeneratePuDailyCurve::class)->handle($emission->fresh());
    $newer = EmissionPuCurveVersion::query()->where('emission_id', $emission->id)->where('calculation_version', $result->calculationVersion)->sole();

    // 13-14. Outro CDI: a oficial continua avançando; a nova não vira oficial.
    p3gMorning('2026-08-28', '2026-08-27');

    expect($emission->fresh()->officialPuCurveVersion()?->id)->toBe($official->id)
        ->and($official->fresh()->status)->toBe(PuCurveStatus::Homologated)
        ->and(p3gLastDate($official))->toBe('2026-08-27')
        ->and($newer->fresh()->status)->toBe(PuCurveStatus::Generated)
        ->and(p3gLastDate($newer))->toBe('2026-08-27')
        ->and($reader->officialStatus($emission->fresh())->freshness)->toBe(PuOfficialCurveFreshness::Current);

    // 15. Nenhum CDI futuro fabricado: nem taxa, nem linha de curva além do divulgado.
    $maturityReading = $reader->readingOn($emission->fresh(), CarbonImmutable::parse('2027-08-02'));

    expect(CarbonImmutable::parse((string) IndexRate::query()->max('rate_date'))->toDateString())->toBe('2026-08-27')
        ->and(IndexRate::query()->where('is_projected', true)->exists())->toBeFalse()
        ->and($emission->fresh()->puDailyCurves()->whereDate('curve_date', '>', '2026-08-27')->exists())->toBeFalse()
        ->and($maturityReading?->date->toDateString())->toBe('2026-08-27')
        ->and($maturityReading?->isCarriedForward())->toBeTrue()
        ->and($reader->officialReadingAt($emission->fresh(), CarbonImmutable::parse('2026-09-15'))['reading'])->toBeNull();
});

/*
 * Fase 3.1 -- o mesmo primeiro uso, no modo D-1 calendário e atravessando o
 * feriado de 07/09/2026 (segunda). O feriado não tem CDI: a rotina não o espera,
 * a oficial atravessa o fim de semana prolongado só com o CDI de sexta, e a
 * posição de cada PU é a data a que ele pertence -- nunca a data pedida.
 */
const P31G_HOLIDAY = '2026-09-07';

function p31gCalendar(): void
{
    for ($date = CarbonImmutable::parse('2026-08-17'); $date->lte(CarbonImmutable::parse('2027-09-10')); $date = $date->addDay()) {
        BusinessCalendarDate::query()->create([
            'calendar_code' => 'B3',
            'calendar_date' => $date->toDateString(),
            'is_business_day' => ! $date->isWeekend() && $date->toDateString() !== P31G_HOLIDAY,
            'description' => $date->toDateString() === P31G_HOLIDAY ? 'Independência do Brasil' : null,
        ]);
    }

    app(BusinessDayCalendarService::class)->flushCache();
}

/**
 * @return list<array{data: string, valor: string}>
 */
function p31gPublishedPayload(string $publishedThrough): array
{
    $rows = [];

    for ($date = CarbonImmutable::parse('2026-08-17'); $date->lte(CarbonImmutable::parse($publishedThrough)); $date = $date->addDay()) {
        if (! $date->isWeekend() && $date->toDateString() !== P31G_HOLIDAY) {
            $rows[] = ['data' => $date->format('d/m/Y'), 'valor' => '14.90'];
        }
    }

    return $rows;
}

function p31gMorning(string $date, string $publishedThrough): void
{
    test()->travelTo(CarbonImmutable::parse($date.' 07:15', 'America/Sao_Paulo'));
    config(['phase31_greenfield.published_through' => $publishedThrough]);
    Http::preventStrayRequests();
    Http::fake(['api.bcb.gov.br/dados/serie/bcdata.sgs.4389/*' => fn () => Http::response(
        p31gPublishedPayload((string) config('phase31_greenfield.published_through')),
        200,
    )]);

    app(IndexRateSyncService::class)->sync(PuIndexer::Cdi, CarbonImmutable::parse('2026-08-17'), CarbonImmutable::parse($date));
    test()->artisan('pu:curves:generate-realized')->assertSuccessful();
}

it('runs the clean-slate workflow in D-1 mode across a national holiday, never presenting a stale PU as current', function () {
    p31gCalendar();
    $emission = Emission::factory()->active()->create([
        'type' => 'CR',
        'is_public' => true,
        'if_code' => 'CR31G'.random_int(100, 999),
        'integralized_quantity' => 100,
    ]);
    $emission->integralizationHistories()->create([
        'date' => '2026-08-31',
        'quantity' => '100.0000',
        'unit_value' => '1000.00000000',
        'financial_value' => '100000.00',
        'investor_fund' => 'Head Invest',
    ]);
    $emission->puParameter()->create([
        'curve_start_date' => '2026-08-31',
        'curve_end_date' => '2027-08-31',
        'initial_unit_value' => '1000.0000000000000000',
        'spread_rate' => '6.50000000',
        'indexer' => PuIndexer::Cdi->value,
        'business_day_basis' => 252,
        'calendar_code' => 'B3',
        'index_rate_lookup_mode' => PuIndexRateLookupMode::PreviousCalendarDayExact->value,
        'index_rate_lag_business_days' => 1,
        'legacy_projection_enabled' => false,
    ]);
    $reader = app(EmissionPuReader::class);
    // O leitor de PU memoriza a versão oficial por instância: cada consulta de saldo
    // resolve um resolvedor novo, como o ouvinte e o comando de garantias fazem.
    $balances = fn (): OutstandingBalanceResolver => app(OutstandingBalanceResolver::class);

    // Quarta, 02/09: CDI até terça. A rotina gera a candidata: quarta usa o CDI de
    // terça (D-1), e a curva realizada termina nela. Nada é oficial.
    p31gMorning('2026-09-02', '2026-09-01');
    $candidate = $emission->fresh()->puCurveVersions()->sole();

    expect($candidate->status)->toBe(PuCurveStatus::Generated)
        ->and(p3gLastDate($candidate))->toBe('2026-09-02')
        ->and($emission->fresh()->officialPuCurveVersion())->toBeNull()
        ->and($balances()->resolveOrNull($emission->fresh(), '2026-09-01'))->toBeNull();

    app(PuCurveVersionService::class)->markValidated($candidate->fresh(), true, ['source' => 'conferência manual'], User::factory()->create()->id);
    $official = app(HomologatePuCurve::class)->handle(
        $emission->fresh(),
        $candidate->calculation_version,
        User::factory()->create()->id,
        'Conferida contra o sistema antigo; primeira curva oficial da emissão.',
    );

    // Sexta, 04/09: CDI até quinta. A oficial vai até sexta.
    p31gMorning('2026-09-04', '2026-09-03');

    expect(p3gLastDate($official))->toBe('2026-09-04');

    // Terça, 08/09, depois do feriado: só o CDI de sexta é novo -- não há CDI de
    // sábado, domingo nem do feriado. A oficial atravessa até terça (sábado usa a
    // sexta; domingo, feriado e terça não exigem nada) e está em dia: o próximo
    // CDI exigido é o da própria terça.
    p31gMorning('2026-09-08', '2026-09-04');
    $tuesday = $reader->officialStatus($emission->fresh());
    $september = $balances()->reading($emission->fresh(), '2026-09-01');

    expect(p3gLastDate($official))->toBe('2026-09-08')
        ->and($tuesday->freshness)->toBe(PuOfficialCurveFreshness::Current)
        ->and($tuesday->expectedLatestRateDate?->toDateString())->toBe('2026-09-04')
        ->and($tuesday->nextRequiredRateDate?->toDateString())->toBe('2026-09-08')
        ->and(CarbonImmutable::parse((string) $official->fresh()->dailyCurves()->whereDate('curve_date', '2026-09-05')->value('index_rate_date'))->toDateString())->toBe('2026-09-04')
        ->and($official->fresh()->dailyCurves()->whereDate('curve_date', '>', '2026-09-05')->whereNotNull('index_rate_date')->exists())->toBeFalse()
        // Garantia de setembro: PU de 08/09, carregado para 30/09 com a curva em dia.
        ->and($september->date->toDateString())->toBe('2026-09-08')
        ->and($september->isCarriedForward())->toBeTrue()
        ->and($september->standsForRequestedDate())->toBeTrue()
        ->and($balances()->resolveOrNull($emission->fresh(), '2026-09-01'))->toBe(round((float) $september->unitValue * 100, 2));

    $this->get(route('site.emissions.show', $emission->if_code))
        ->assertOk()
        ->assertSee('R$ '.number_format((float) $september->unitValue, 6, ',', '.'))
        ->assertSee('08/09/2026');

    // Uma candidata mais nova ao lado; o CDI de terça chega na quarta: a oficial
    // continua avançando e a nova não vira oficial.
    $result = app(GeneratePuDailyCurve::class)->handle($emission->fresh());
    $newer = EmissionPuCurveVersion::query()->where('emission_id', $emission->id)->where('calculation_version', $result->calculationVersion)->sole();
    p31gMorning('2026-09-09', '2026-09-08');

    expect($emission->fresh()->officialPuCurveVersion()?->id)->toBe($official->id)
        ->and(p3gLastDate($official))->toBe('2026-09-09')
        ->and($newer->fresh()->status)->toBe(PuCurveStatus::Generated)
        ->and(p3gLastDate($newer))->toBe('2026-09-09');

    // Sexta, 11/09, 10:00: o CDI de quarta e de quinta deviam ter chegado e não
    // chegaram. A oficial para em quarta e fica com índice ausente: o PU de quarta
    // continua sendo o de quarta, mas não responde mais pelo mês na garantia nem
    // aparece como PU atual no site.
    $this->travelTo(CarbonImmutable::parse('2026-09-11 10:00', 'America/Sao_Paulo'));
    $stuck = $reader->officialStatus($emission->fresh());
    $septemberStuck = $balances()->reading($emission->fresh(), '2026-09-01');

    expect($stuck->freshness)->toBe(PuOfficialCurveFreshness::MissingIndex)
        ->and($stuck->nextRequiredRateDate?->toDateString())->toBe('2026-09-09')
        ->and($septemberStuck->date->toDateString())->toBe('2026-09-09')
        ->and($septemberStuck->standsForRequestedDate())->toBeFalse()
        ->and($balances()->resolveOrNull($emission->fresh(), '2026-09-01'))->toBeNull()
        ->and($reader->officialReadingAt($emission->fresh(), CarbonImmutable::parse('2026-09-09'))['reading']?->date->toDateString())->toBe('2026-09-09')
        ->and($reader->officialReadingAt($emission->fresh(), CarbonImmutable::parse('2026-09-11'))['reading'])->toBeNull();

    $this->get(route('site.emissions.show', $emission->if_code))
        ->assertOk()
        ->assertDontSee('R$ '.number_format((float) $septemberStuck->unitValue, 6, ',', '.'));

    // Nenhum CDI futuro fabricado, nenhuma taxa projetada, nenhuma linha além do divulgado.
    expect(CarbonImmutable::parse((string) IndexRate::query()->max('rate_date'))->toDateString())->toBe('2026-09-08')
        ->and(IndexRate::query()->where('is_projected', true)->exists())->toBeFalse()
        ->and(IndexRate::query()->whereDate('rate_date', P31G_HOLIDAY)->exists())->toBeFalse()
        ->and($emission->fresh()->puDailyCurves()->whereDate('curve_date', '>', '2026-09-09')->exists())->toBeFalse();
});
