<?php

use App\Actions\Emissions\GeneratePuDailyCurve;
use App\Actions\Emissions\HomologatePuCurve;
use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexRateLookupMode;
use App\Domain\PuCalculator\Services\IndexRateCorrectionService;
use App\Domain\PuCalculator\Services\IndexRateObservationRecorder;
use App\Domain\PuCalculator\Services\PuCurveExtensionService;
use App\Domain\PuCalculator\Services\PuCurveVersionService;
use App\Models\BusinessCalendarDate;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuDailyCurve;
use App\Models\IndexRate;
use App\Models\IndexRateCorrection;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Tests\Support\CommittedRowsSweeper;

/**
 * Fase 3 -- integridade do índice e extensão oficial disputando o MySQL real.
 *
 * O SQLite serializa escritores e não mostra a janela entre ler e gravar. Aqui um
 * processo segura a transação aberta logo depois de uma leitura (ou trava)
 * escolhida, e o outro age no meio: a unique de `index_rates`, a trava da linha
 * da observação e a trava emissão → versão é que decidem o resultado.
 */
beforeEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requer MySQL para observar processos disputando a mesma observação de índice.');
    }

    Artisan::call('migrate:fresh', ['--no-interaction' => true]);

    $this->committedRows = CommittedRowsSweeper::afterFreshMigration();
});

afterEach(function () {
    if (DB::getDriverName() !== 'mysql' || ! isset($this->committedRows)) {
        return;
    }

    $this->committedRows->sweep();

    expect($this->committedRows->leftovers())->toBe([]);
});

/**
 * Curva homologada (CDI com defasagem de 1 dia útil) realizada até 16/03/2026.
 *
 * @return array{emission: int, official: int}
 */
function p3mOfficialScenario(): array
{
    $emission = Emission::factory()->active()->create(['type' => 'CRI']);
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

    p3mPublish('2026-02-27', '2026-03-13');
    $result = app(GeneratePuDailyCurve::class)->handle($emission->fresh());
    $official = app(HomologatePuCurve::class)->handle($emission->fresh(), $result->calculationVersion, User::factory()->create()->id, 'Conferida.');

    return ['emission' => (int) $emission->getKey(), 'official' => (int) $official->getKey()];
}

function p3mPublish(string $from, string $to): void
{
    for ($date = CarbonImmutable::parse($from); $date->lte(CarbonImmutable::parse($to)); $date = $date->addDay()) {
        if (! $date->isWeekend()) {
            IndexRate::query()->create([
                'indexer' => PuIndexer::Cdi->value,
                'rate_date' => $date->startOfDay(),
                'rate_value' => '14.90000000',
                'source' => 'bcb_sgs',
                'source_reference' => 'bcb_sgs:4389',
                'is_projected' => false,
            ]);
        }
    }
}

/**
 * Processo filho. Com `hold`, segura a conexão por 800 ms logo depois da primeira
 * consulta que casar com `hold_on` e avisa o outro pelo marcador. Sem `hold`, espera
 * o marcador e só então age.
 *
 * @param  array{action: string, hold: bool, hold_on: list<string>, marker: string, payload: array<string, mixed>}  $instruction
 */
function p3mTask(array $instruction): Closure
{
    return static function () use ($instruction): array {
        try {
            if ($instruction['hold']) {
                $held = false;

                DB::listen(static function (QueryExecuted $query) use ($instruction, &$held): void {
                    $sql = strtolower($query->sql);

                    if ($held) {
                        return;
                    }

                    foreach ($instruction['hold_on'] as $fragment) {
                        if (! str_contains($sql, $fragment)) {
                            return;
                        }
                    }

                    $held = true;
                    file_put_contents($instruction['marker'], 'held');
                    usleep(800_000);
                });
            } else {
                $deadline = microtime(true) + 15;

                while (! is_file($instruction['marker']) && microtime(true) < $deadline) {
                    usleep(10_000);
                }

                if (! is_file($instruction['marker'])) {
                    throw new RuntimeException('O processo concorrente não chegou ao ponto de espera.');
                }
            }

            $payload = $instruction['payload'];

            $outcome = match ($instruction['action']) {
                'record' => app(IndexRateObservationRecorder::class)->recordRealized(
                    PuIndexer::Cdi,
                    CarbonImmutable::parse($payload['date']),
                    $payload['value'],
                    ['source' => 'bcb_sgs', 'source_reference' => 'bcb_sgs:4389'],
                )->status,
                'extend_official' => app(PuCurveExtensionService::class)
                    ->extendOfficial(Emission::query()->findOrFail($payload['emission']))
                    ->action,
                'complete_generation' => app(PuCurveVersionService::class)
                    ->markGenerated(EmissionPuCurveVersion::query()->findOrFail($payload['version']), 0)
                    ->status->value,
                'correct' => (string) app(IndexRateCorrectionService::class)->correct(
                    PuIndexer::Cdi,
                    $payload['date'],
                    $payload['value'],
                    'Republicação da Taxa DI.',
                    $payload['actor'],
                )->id,
            };

            return ['success' => true, 'outcome' => $outcome, 'exception' => null];
        } catch (Throwable $exception) {
            return ['success' => false, 'outcome' => null, 'exception' => $exception::class.': '.$exception->getMessage()];
        }
    };
}

/**
 * @param  list<array{action: string, hold: bool, hold_on?: list<string>, payload: array<string, mixed>}>  $tasks
 * @return list<array{success: bool, outcome: ?string, exception: ?string}>
 */
function p3mRace(array $tasks): array
{
    $marker = temporaryTestFilePath('pu-index-integrity-race-'.getmypid(), 'lock');
    @unlink($marker);

    $results = Concurrency::driver('process')->run(array_map(
        fn (array $task): Closure => p3mTask([...$task, 'hold_on' => $task['hold_on'] ?? [], 'marker' => $marker]),
        $tasks,
    ));

    @unlink($marker);

    return array_values($results);
}

function p3mLastDate(int $versionId): string
{
    return CarbonImmutable::parse((string) EmissionPuDailyCurve::query()->where('curve_version_id', $versionId)->max('curve_date'))->toDateString();
}

it('keeps exactly one observation when two syncs register the same CDI date at once', function () {
    $results = p3mRace([
        ['action' => 'record', 'hold' => true, 'hold_on' => ['select', 'index_rates', 'rate_date'], 'payload' => ['date' => '2026-09-01', 'value' => '14.90']],
        ['action' => 'record', 'hold' => false, 'payload' => ['date' => '2026-09-01', 'value' => '14.90']],
    ]);

    expect(collect($results)->pluck('exception')->filter()->all())->toBe([])
        ->and(collect($results)->pluck('outcome')->sort()->values()->all())->toBe(['created', 'unchanged'])
        ->and(IndexRate::query()->whereDate('rate_date', '2026-09-01')->count())->toBe(1);
})->group('mysql');

it('extends the official curve while a newer candidate finishes generating, in either order', function (bool $extensionFirst) {
    $scenario = p3mOfficialScenario();
    $candidate = app(PuCurveVersionService::class)->startGeneration(Emission::query()->findOrFail($scenario['emission']), null);
    p3mPublish('2026-03-16', '2026-03-20');

    $extension = ['action' => 'extend_official', 'hold' => $extensionFirst, 'hold_on' => ['emission_pu_curve_versions', 'for update'], 'payload' => ['emission' => $scenario['emission']]];
    $generation = ['action' => 'complete_generation', 'hold' => ! $extensionFirst, 'hold_on' => ['emission_pu_curve_versions', 'for update'], 'payload' => ['version' => $candidate->id]];
    $results = p3mRace($extensionFirst ? [$extension, $generation] : [$generation, $extension]);

    expect(collect($results)->pluck('exception')->filter()->all())->toBe([])
        ->and(collect($results)->pluck('outcome')->sort()->values()->all())->toBe(['extended', 'generated'])
        ->and(EmissionPuCurveVersion::query()->findOrFail($scenario['official'])->status)->toBe(PuCurveStatus::Homologated)
        ->and(EmissionPuCurveVersion::query()->findOrFail($candidate->id)->status)->toBe(PuCurveStatus::Generated)
        ->and(Emission::query()->findOrFail($scenario['emission'])->officialPuCurveVersion()?->id)->toBe($scenario['official'])
        ->and(p3mLastDate($scenario['official']))->toBe('2026-03-23');
})->with([
    'extensao trava primeiro' => [true],
    'geracao trava primeiro' => [false],
])->group('mysql');

it('flags the official curve when a CDI correction waits for the extension that used the old rate', function () {
    $scenario = p3mOfficialScenario();
    p3mPublish('2026-03-16', '2026-03-18');
    $actor = (int) User::factory()->create()->getKey();

    $results = p3mRace([
        ['action' => 'extend_official', 'hold' => true, 'hold_on' => ['index_rates', 'lock in share mode'], 'payload' => ['emission' => $scenario['emission']]],
        ['action' => 'correct', 'hold' => false, 'payload' => ['date' => '2026-03-17', 'value' => '15.40', 'actor' => $actor]],
    ]);

    $official = EmissionPuCurveVersion::query()->findOrFail($scenario['official']);
    $correction = IndexRateCorrection::query()->findOrFail((int) $results[1]['outcome']);

    expect(collect($results)->pluck('exception')->filter()->all())->toBe([])
        ->and($results[0]['outcome'])->toBe('extended')
        ->and(p3mLastDate($scenario['official']))->toBe('2026-03-19')
        // A linha anexada usou 14,90; a correção, que esperou o commit, a enxerga e marca a curva.
        ->and((string) EmissionPuDailyCurve::query()->where('curve_version_id', $official->id)->whereDate('curve_date', '2026-03-18')->value('index_rate_value'))->toBe('14.90000000')
        ->and(collect($correction->affected_curve_versions)->pluck('first_dependent_date', 'id')->all())->toBe([$official->id => '2026-03-18'])
        ->and($official->extension_diverged_at)->not->toBeNull()
        ->and($official->extension_divergence['index_rate_correction_id'])->toBe($correction->id);
})->group('mysql');

it('appends nothing when the CDI correction commits before the extension writes', function () {
    $scenario = p3mOfficialScenario();
    p3mPublish('2026-03-16', '2026-03-18');
    $actor = (int) User::factory()->create()->getKey();

    $results = p3mRace([
        ['action' => 'correct', 'hold' => true, 'hold_on' => ['index_rates', 'for update'], 'payload' => ['date' => '2026-03-17', 'value' => '15.40', 'actor' => $actor]],
        ['action' => 'extend_official', 'hold' => false, 'payload' => ['emission' => $scenario['emission']]],
    ]);

    $official = EmissionPuCurveVersion::query()->findOrFail($scenario['official']);

    expect(collect($results)->pluck('exception')->filter()->all())->toBe([])
        ->and($results[1]['outcome'])->toBeIn([PuCurveExtensionService::ACTION_NOT_EXTENDABLE, PuCurveExtensionService::ACTION_EXTENDED])
        ->and($official->extension_diverged_at)->toBeNull();

    // Se a extensão recalculou antes do commit, recusou a cauda; se recalculou depois,
    // anexou com a taxa nova. Nunca há dia anexado com a taxa velha sem marcação.
    $row = EmissionPuDailyCurve::query()->where('curve_version_id', $official->id)->whereDate('curve_date', '2026-03-18')->first();

    expect($row === null || (string) $row->index_rate_value === '15.40000000')->toBeTrue();

    $retry = app(PuCurveExtensionService::class)->extendOfficial(Emission::query()->findOrFail($scenario['emission']));

    expect($retry->action)->toBeIn([PuCurveExtensionService::ACTION_EXTENDED, PuCurveExtensionService::ACTION_UP_TO_DATE])
        ->and((string) EmissionPuDailyCurve::query()->where('curve_version_id', $official->id)->whereDate('curve_date', '2026-03-18')->value('index_rate_value'))->toBe('15.40000000');
})->group('mysql');

it('chains two concurrent corrections of the same CDI date instead of losing one', function () {
    p3mPublish('2026-03-02', '2026-03-06');
    $first = (int) User::factory()->create()->getKey();
    $second = (int) User::factory()->create()->getKey();

    $results = p3mRace([
        ['action' => 'correct', 'hold' => true, 'hold_on' => ['index_rates', 'for update'], 'payload' => ['date' => '2026-03-04', 'value' => '15.40', 'actor' => $first]],
        ['action' => 'correct', 'hold' => false, 'payload' => ['date' => '2026-03-04', 'value' => '15.50', 'actor' => $second]],
    ]);

    $ledger = IndexRateCorrection::query()->orderBy('id')->get();

    expect(collect($results)->pluck('exception')->filter()->all())->toBe([])
        ->and($ledger)->toHaveCount(2)
        ->and([(string) $ledger[0]->previous_rate_value, (string) $ledger[0]->new_rate_value])->toBe(['14.90000000', '15.40000000'])
        ->and([(string) $ledger[1]->previous_rate_value, (string) $ledger[1]->new_rate_value])->toBe(['15.40000000', '15.50000000'])
        ->and((string) IndexRate::query()->whereDate('rate_date', '2026-03-04')->value('rate_value'))->toBe('15.50000000');
})->group('mysql');
