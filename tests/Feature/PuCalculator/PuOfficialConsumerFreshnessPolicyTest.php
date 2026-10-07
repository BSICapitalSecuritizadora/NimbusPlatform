<?php

use App\Actions\Emissions\GeneratePuDailyCurve;
use App\Actions\Emissions\HomologatePuCurve;
use App\Domain\PuCalculator\DTOs\PuReading;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexRateLookupMode;
use App\Domain\PuCalculator\Enums\PuOfficialCurveFreshness;
use App\Domain\PuCalculator\Services\BusinessDayCalendarService;
use App\Domain\PuCalculator\Services\EmissionPuReader;
use App\Domain\PuCalculator\Services\IndexRateCorrectionService;
use App\Models\BusinessCalendarDate;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuDailyCurve;
use App\Models\IndexRate;
use App\Models\PuHistory;
use App\Models\User;
use App\Services\Guarantees\OutstandingBalanceResolver;
use App\Services\Reports\EmissionMonthlyReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Fase 3.1 -- o PU carregado nunca passa por PU realizado da data pedida.
 *
 * A curva oficial termina no último dia sustentado por CDI divulgado; quem pede o
 * PU de uma data posterior recebe o último conhecido, CARREGADO. Isso é legítimo
 * quando a curva está em dia (o PU da data pedida ainda não podia existir) e não
 * é quando ela está atrasada, sem índice, com a extensão falhando ou à espera de
 * reprocessamento.
 *
 * Política dos consumidores oficiais:
 *
 * | Situação              | PU da própria data | PU carregado (garantias, "PU atual" do site) | Relatório (posição exposta) |
 * |-----------------------|--------------------|----------------------------------------------|-----------------------------|
 * | atual / completa      | vale               | vale                                         | vale, com a data do PU      |
 * | atrasada              | vale               | indisponível                                 | vale, com a data do PU      |
 * | índice ausente        | vale               | indisponível                                 | vale, com a data do PU      |
 * | extensão falhou       | vale               | indisponível                                 | vale, com a data do PU      |
 * | reprocessamento       | só antes da 1ª data divergente | indisponível                     | só antes da 1ª data divergente |
 * | sem curva oficial     | Histórico de PU legítimo (Fase 2), com a data dele                                              |
 *
 * Curva: CDI com defasagem de 1 dia útil, divulgado de 27/02 a 13/03/2026; a
 * oficial realiza até segunda, 16/03. Horários em America/Sao_Paulo.
 */
uses(RefreshDatabase::class);

function p31cPublish(string $from, string $to): void
{
    for ($date = CarbonImmutable::parse($from); $date->lte(CarbonImmutable::parse($to)); $date = $date->addDay()) {
        if ($date->isWeekend()) {
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

function p31cEmission(string $curveEndDate = '2026-12-31'): Emission
{
    $emission = Emission::factory()->active()->create([
        'type' => 'CRI',
        'is_public' => true,
        'if_code' => 'CRI31C'.random_int(100, 999),
        'integralized_quantity' => 100,
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
        'curve_end_date' => $curveEndDate,
        'initial_unit_value' => '1000.0000000000000000',
        'spread_rate' => '6.00000000',
        'indexer' => PuIndexer::Cdi->value,
        'business_day_basis' => 252,
        'calendar_code' => 'B3',
        'index_rate_lookup_mode' => PuIndexRateLookupMode::BusinessDayLagExact->value,
        'index_rate_lag_business_days' => -1,
        'legacy_projection_enabled' => false,
    ]);

    for ($date = CarbonImmutable::parse('2026-02-23'); $date->lte(CarbonImmutable::parse('2027-01-08')); $date = $date->addDay()) {
        BusinessCalendarDate::query()->create([
            'calendar_code' => 'B3',
            'calendar_date' => $date->toDateString(),
            'is_business_day' => ! $date->isWeekend(),
            'description' => null,
        ]);
    }

    app(BusinessDayCalendarService::class)->flushCache();

    return $emission->fresh();
}

function p31cOfficialEmission(string $curveEndDate = '2026-12-31'): Emission
{
    $emission = p31cEmission($curveEndDate);
    p31cPublish('2026-02-27', '2026-03-13');
    $result = app(GeneratePuDailyCurve::class)->handle($emission);
    app(HomologatePuCurve::class)->handle($emission->fresh(), $result->calculationVersion, User::factory()->create()->id, 'Conferida.');

    return $emission->fresh();
}

function p31cAt(string $localDateTime): CarbonImmutable
{
    return CarbonImmutable::parse($localDateTime, 'America/Sao_Paulo');
}

function p31cRowValue(Emission $emission, string $date): string
{
    return (string) EmissionPuDailyCurve::query()
        ->whereHas('curveVersion', fn ($query) => $query->official())
        ->where('emission_id', $emission->id)
        ->whereDate('curve_date', $date)
        ->value('residual_unit_value');
}

function p31cSitePu(string $unitValue): string
{
    return 'R$ '.number_format((float) $unitValue, 6, ',', '.');
}

/**
 * @return array{balance: array{0: float, 1: bool}, reading: ?PuReading, report: array<string, mixed>, report_debt_position: ?string}
 */
function p31cConsumers(Emission $emission): array
{
    $emission = $emission->fresh();
    $report = app(EmissionMonthlyReportService::class)->build($emission, CarbonImmutable::parse('2026-03-01'));

    return [
        'balance' => app(OutstandingBalanceResolver::class)->resolve($emission, '2026-03-01'),
        'reading' => app(OutstandingBalanceResolver::class)->reading($emission, '2026-03-01'),
        'report' => $report['header'],
        // Seção "Saldo Devedor" do relatório: a mesma posição do cabeçalho.
        'report_debt_position' => collect($report['debt_balance'])->firstWhere('label', 'Posição em')['value'] ?? null,
    ];
}

it('lets every consumer carry the last realized PU while the official curve is current, with its own position date', function () {
    $emission = p31cOfficialEmission();
    $this->travelTo(p31cAt('2026-03-16 10:00'));
    $pu = p31cRowValue($emission, '2026-03-16');

    $consumers = p31cConsumers($emission);

    expect($consumers['reading']->date->toDateString())->toBe('2026-03-16')
        ->and($consumers['reading']->requestedDate?->toDateString())->toBe('2026-03-31')
        ->and($consumers['reading']->isCarriedForward())->toBeTrue()
        ->and($consumers['reading']->freshness())->toBe(PuOfficialCurveFreshness::Current)
        ->and($consumers['reading']->standsForRequestedDate())->toBeTrue()
        ->and($consumers['balance'])->toBe([round((float) $pu * 100, 2), true])
        ->and($consumers['report']['debt_position'])->toBe('16/03/2026')
        ->and($consumers['report_debt_position'])->toBe('16/03/2026')
        ->and($consumers['report']['current_pu'])->toContain(number_format((float) $pu, 8, ',', '.'));

    $this->get(route('site.emissions.show', $emission->if_code))
        ->assertOk()
        ->assertSee(p31cSitePu($pu))
        ->assertSee('16/03/2026');
});

/**
 * Leva a curva oficial a uma situação em que o PU carregado não responde pela data
 * pedida, e devolve o instante (Brasília) em que ela vale.
 */
function p31cArrangeUnsafe(string $case, Emission $emission): string
{
    return match ($case) {
        // O CDI de 16 a 18/03 chegou e a curva oficial não foi estendida.
        'stale' => (function (): string {
            p31cPublish('2026-03-16', '2026-03-18');

            return '2026-03-19 10:00';
        })(),
        // Quarta, 18/03: o CDI de segunda já devia ter sido divulgado.
        'missing_index' => '2026-03-18 10:00',
        // O índice chegou, mas a extensão da curva oficial falhou.
        'extension_failed' => (function () use ($emission): string {
            p31cPublish('2026-03-16', '2026-03-18');
            EmissionPuCurveVersion::query()->where('emission_id', $emission->id)->official()->firstOrFail()->forceFill([
                'extension_failed_at' => now(),
                'extension_failure' => ['action' => 'error', 'reason' => 'Falha simulada da extensão.'],
            ])->save();

            return '2026-03-19 10:00';
        })(),
    };
}

dataset('p31c_unsafe_freshness', [
    'atrasada: CDI chegou e a curva oficial nao foi estendida' => ['stale', PuOfficialCurveFreshness::Stale],
    'indice ausente: a divulgacao esperada nao chegou' => ['missing_index', PuOfficialCurveFreshness::MissingIndex],
    'extensao falhou' => ['extension_failed', PuOfficialCurveFreshness::ExtensionFailed],
]);

it('never lets a guarantee or the public site take a carried PU from an official curve that is behind', function (
    string $case,
    PuOfficialCurveFreshness $expected,
) {
    $emission = p31cOfficialEmission();
    $this->travelTo(p31cAt(p31cArrangeUnsafe($case, $emission)));
    $pu = p31cRowValue($emission, '2026-03-16');

    $consumers = p31cConsumers($emission);

    expect($consumers['reading']->freshness())->toBe($expected)
        // A leitura continua dizendo o que é: PU de 16/03, carregado para 31/03.
        ->and($consumers['reading']->date->toDateString())->toBe('2026-03-16')
        ->and($consumers['reading']->isCarriedForward())->toBeTrue()
        ->and($consumers['reading']->standsForRequestedDate())->toBeFalse()
        // O saldo devedor, que não mostra a data do PU, fica indisponível.
        ->and($consumers['balance'])->toBe([0.0, false])
        ->and(app(OutstandingBalanceResolver::class)->resolveOrNull($emission->fresh(), '2026-03-01'))->toBeNull()
        // O relatório mostra o PU com a posição dele, nunca como o de 31/03.
        ->and($consumers['report']['debt_position'])->toBe('16/03/2026')
        ->and($consumers['report_debt_position'])->toBe('16/03/2026')
        ->and($consumers['report']['current_pu'])->toContain(number_format((float) $pu, 8, ',', '.'))
        // O PU de uma data que a curva cobre continua valendo.
        ->and(app(EmissionPuReader::class)->readingOn($emission->fresh(), CarbonImmutable::parse('2026-03-16'))?->standsForRequestedDate())->toBeTrue();

    $this->get(route('site.emissions.show', $emission->if_code))
        ->assertOk()
        ->assertDontSee(p31cSitePu($pu))
        ->assertSee('Histórico de PU ainda não disponível para consulta pública.');
})->with('p31c_unsafe_freshness');

it('withholds every official PU the reprocessing put in doubt, and only those', function () {
    $emission = p31cOfficialEmission();
    $this->travelTo(p31cAt('2026-03-16 10:00'));
    $beforeDivergence = p31cRowValue($emission, '2026-03-05');

    // O CDI de 05/03 é republicado: a curva oficial fica imutável, mas a partir de
    // 06/03 (a primeira data que o usou) o gravado não é mais reproduzível.
    app(IndexRateCorrectionService::class)->correct(
        PuIndexer::Cdi,
        '2026-03-05',
        '15.40',
        'BCB republicou a Taxa DI de 05/03.',
        User::factory()->create()->id,
    );

    $reader = app(EmissionPuReader::class);
    $status = $reader->officialStatus($emission->fresh());
    $consumers = p31cConsumers($emission);
    $strictBefore = $reader->officialReadingAt($emission->fresh(), CarbonImmutable::parse('2026-03-05'));
    $strictAfter = $reader->officialReadingAt($emission->fresh(), CarbonImmutable::parse('2026-03-10'));

    expect($status->freshness)->toBe(PuOfficialCurveFreshness::ReprocessingRequired)
        ->and($status->reprocessingFrom?->toDateString())->toBe('2026-03-06')
        ->and($strictBefore['reading']?->unitValue)->toBe($beforeDivergence)
        ->and($strictAfter['reading'])->toBeNull()
        ->and($strictAfter['status']->freshness)->toBe(PuOfficialCurveFreshness::ReprocessingRequired)
        ->and($reader->readingOn($emission->fresh(), CarbonImmutable::parse('2026-03-05'))?->unitValue)->toBe($beforeDivergence)
        ->and($reader->readingOn($emission->fresh(), CarbonImmutable::parse('2026-03-10')))->toBeNull()
        // Nem o Histórico de PU responde por data que a curva oficial cobre.
        ->and($consumers['reading'])->toBeNull()
        ->and($consumers['balance'])->toBe([0.0, false])
        ->and($consumers['report']['current_pu'])->toBe('Não informado');

    $this->get(route('site.emissions.show', $emission->if_code))
        ->assertOk()
        ->assertDontSee(p31cSitePu(p31cRowValue($emission, '2026-03-16')));
});

it('carries the PU of a complete official curve past its last date', function () {
    $emission = p31cOfficialEmission('2026-03-16');
    $this->travelTo(p31cAt('2026-04-20 10:00'));
    $pu = p31cRowValue($emission, '2026-03-16');

    $consumers = p31cConsumers($emission);

    expect($consumers['reading']->freshness())->toBe(PuOfficialCurveFreshness::Complete)
        ->and($consumers['reading']->isCarriedForward())->toBeTrue()
        ->and($consumers['reading']->standsForRequestedDate())->toBeTrue()
        ->and($consumers['balance'])->toBe([round((float) $pu * 100, 2), true]);
});

it('keeps the legacy PU history for an emission without an official curve, with its own date', function () {
    $emission = p31cEmission();
    $this->travelTo(p31cAt('2026-04-20 10:00'));
    PuHistory::query()->create(['emission_id' => $emission->id, 'date' => '2026-03-20', 'unit_value' => '1004.12345600', 'source' => PuHistory::SOURCE_IMPORT]);

    $consumers = p31cConsumers($emission);

    expect($consumers['reading']->source)->toBe(PuReading::SOURCE_PU_HISTORY)
        ->and($consumers['reading']->date->toDateString())->toBe('2026-03-20')
        ->and($consumers['reading']->isCarriedForward())->toBeTrue()
        ->and($consumers['reading']->freshness())->toBeNull()
        ->and($consumers['reading']->standsForRequestedDate())->toBeTrue()
        ->and($consumers['balance'])->toBe([round(1004.123456 * 100, 2), true]);
});
