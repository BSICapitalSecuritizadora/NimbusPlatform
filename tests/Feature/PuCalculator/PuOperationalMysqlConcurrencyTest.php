<?php

use App\Actions\Emissions\HomologatePuCurve;
use App\Domain\PuCalculator\DTOs\PuObligationRefreshOutcome;
use App\Domain\PuCalculator\DTOs\PuSettlementCorrectionData;
use App\Domain\PuCalculator\Enums\PuEventType;
use App\Domain\PuCalculator\Enums\PuIncidentStatus;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuObligationCalculationState;
use App\Domain\PuCalculator\Enums\PuObligationRefreshStatus;
use App\Domain\PuCalculator\Enums\PuObligationType;
use App\Domain\PuCalculator\Enums\PuOperationalConditionType;
use App\Domain\PuCalculator\Enums\PuOperationalFailureCategory;
use App\Domain\PuCalculator\Enums\PuReconciliationStatus;
use App\Domain\PuCalculator\Enums\PuSettlementStatus;
use App\Domain\PuCalculator\Services\IndexRateCorrectionService;
use App\Domain\PuCalculator\Services\PuFinancialObligationService;
use App\Domain\PuCalculator\Services\PuObligationRefreshRecovery;
use App\Domain\PuCalculator\Services\PuOperationalMonitor;
use App\Domain\PuCalculator\Services\PuSettlementService;
use App\Models\Emission;
use App\Models\EmissionPuObligation;
use App\Models\EmissionPuObligationCalculation;
use App\Models\EmissionPuSettlement;
use App\Models\PuObligationRefreshRequest;
use App\Models\PuOperationalIncident;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Tests\Support\CommittedRowsSweeper;
use Tests\Support\Pu\PuObligationFixture as Fx;

/**
 * Fase 6 -- monitor e recuperação disputando o MySQL real.
 *
 * O SQLite serializa escritores e não mostra a janela entre ler e gravar. Aqui
 * processos de verdade correm um contra o outro (um segura a conexão logo depois
 * de uma consulta escolhida, o outro age no meio), e processos morrem no meio do
 * trabalho: o incidente continua um por identidade, o pedido de atualização é
 * executado por um só, nada se perde num processo morto, e nenhum fato financeiro
 * é duplicado ou sobrescrito.
 */
beforeEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requer MySQL para observar processos disputando o monitor e a recuperação.');
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
 * Oficial com o cupom de 09/03 calculado e liquidado a menor (divergência que o
 * monitor transforma em incidente).
 *
 * @return array{emission: int, obligation: int, expected: string}
 */
function p6mScenario(bool $divergentSettlement = false): array
{
    $emission = Fx::emission([[PuEventType::InterestPayment, '2026-03-09']]);
    Fx::official($emission);
    $obligation = Fx::obligation($emission, PuObligationType::ScheduledPayment, '2026-03-09');
    $expected = Fx::expectedTotal($obligation);

    if ($divergentSettlement) {
        Fx::settle($obligation, bcsub($expected, '5.00', 2), reference: 'B3-P6M');
    }

    return ['emission' => (int) $emission->id, 'obligation' => (int) $obligation->id, 'expected' => $expected];
}

/**
 * Pedido de atualização gravado e não executado (o processo que o gravou
 * morreu antes da tentativa pós-commit).
 */
function p6mPendingRequest(int $emissionId, string $trigger = 'index_rate_corrected'): PuObligationRefreshRequest
{
    $request = app(PuObligationRefreshRecovery::class)->request($emissionId, $trigger);
    $request->forceFill(['next_attempt_at' => now()->subMinute()])->save();

    return $request;
}

/**
 * Processo filho. Com `hold`, segura a conexão por `hold_ms` logo depois da
 * primeira consulta que contiver todos os trechos de `hold_on`; sem, espera o
 * marcador e age. Com `crash_on`, encerra o processo (exit) na primeira consulta
 * que contiver o trecho -- o worker que morre no meio.
 *
 * @param  array<string, mixed>  $instruction
 */
function p6mTask(array $instruction): Closure
{
    return static function () use ($instruction): array {
        try {
            if ($instruction['crash_on'] !== null) {
                DB::listen(static function (QueryExecuted $query) use ($instruction): void {
                    if (str_contains(strtolower($query->sql), $instruction['crash_on'])) {
                        exit(1);
                    }
                });
            }

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
                    usleep($instruction['hold_ms'] * 1000);
                });
            } elseif ($instruction['wait']) {
                $deadline = microtime(true) + 30;

                while (! is_file($instruction['marker']) && microtime(true) < $deadline) {
                    usleep(10_000);
                }

                if (! is_file($instruction['marker'])) {
                    throw new RuntimeException('O processo concorrente não chegou ao ponto de espera.');
                }
            }

            $payload = $instruction['payload'];

            $outcome = match ($instruction['action']) {
                'monitor' => app(PuOperationalMonitor::class)->run('race')->status->value,
                'process' => app(PuObligationRefreshRecovery::class)->process($payload['emission'], PuObligationRefreshRecovery::VIA_SCANNER)->status,
                'recover' => implode(',', array_map(fn (PuObligationRefreshOutcome $outcome): string => $outcome->status, app(PuObligationRefreshRecovery::class)->recoverDue())),
                'homologate' => app(HomologatePuCurve::class)
                    ->handle(Emission::query()->findOrFail($payload['emission']), $payload['version'], $payload['actor'], 'Conferida.')
                    ->status->value,
                'refresh' => (string) app(PuFinancialObligationService::class)
                    ->refresh(Emission::query()->findOrFail($payload['emission']), 'race')
                    ->count('reconciliations_changed'),
                'correct_settlement' => app(PuSettlementService::class)->correct(
                    $payload['settlement'],
                    new PuSettlementCorrectionData($payload['date'], $payload['amount']),
                    'Arquivo reprocessado.',
                    Fx::integration(),
                )->outcome->value,
                'restore_blocked_then_monitor' => (function () use ($payload): string {
                    DB::table('pu_obligation_refresh_requests')->where('id', $payload['request'])->update(['status' => PuObligationRefreshStatus::Blocked->value]);

                    return app(PuOperationalMonitor::class)->run('race')->status->value;
                })(),
                // A correção comita; a primeira consulta da tentativa pós-commit (a
                // reserva do pedido) mata o processo -- ele morre depois do commit e
                // antes de despachar a atualização.
                'correct_cdi' => (string) app(IndexRateCorrectionService::class)
                    ->correct(PuIndexer::Cdi, '2026-03-04', '15.10000000', 'Revisão do BCB.', $payload['actor'])
                    ->id,
            };

            return ['success' => true, 'outcome' => $outcome, 'exception' => null];
        } catch (Throwable $exception) {
            return ['success' => false, 'outcome' => null, 'exception' => $exception::class.': '.$exception->getMessage()];
        }
    };
}

/**
 * @param  list<array<string, mixed>>  $tasks
 * @return list<array{success: bool, outcome: ?string, exception: ?string}|string>
 */
function p6mRace(array $tasks): array
{
    $marker = temporaryTestFilePath('pu-operational-race-'.getmypid(), 'lock');
    @unlink($marker);

    $closures = array_map(fn (array $task): Closure => p6mTask([
        'hold' => false,
        'wait' => false,
        'hold_on' => [],
        'hold_ms' => 800,
        'crash_on' => null,
        'payload' => [],
        ...$task,
        'marker' => $marker,
    ]), $tasks);

    try {
        $results = Concurrency::driver('process')->run($closures);
    } finally {
        @unlink($marker);
    }

    return array_values($results);
}

/**
 * Um processo que morre: o pool o reporta como falho, e é o que se quer.
 *
 * @param  array<string, mixed>  $task
 */
function p6mDie(array $task): string
{
    try {
        p6mRace([$task]);
    } catch (Exception $exception) {
        return $exception->getMessage();
    }

    return 'survived';
}

it('opens exactly one incident when two monitors detect the same condition at the same time', function () {
    p6mScenario(divergentSettlement: true);

    $results = p6mRace([
        ['action' => 'monitor', 'hold' => true, 'hold_on' => ['from `pu_operational_incidents`', '`open_key`'], 'hold_ms' => 3000],
        ['action' => 'monitor', 'wait' => true],
    ]);
    $incidents = PuOperationalIncident::query()->where('type', PuOperationalConditionType::SettlementDivergent->value)->get();

    expect(collect($results)->pluck('exception')->filter()->all())->toBe([])
        ->and($incidents)->toHaveCount(1)
        ->and($incidents->first()->detection_count)->toBe(2)
        ->and($incidents->first()->status)->toBe(PuIncidentStatus::Active);
})->group('mysql');

it('lets only one of two recovery workers execute the same pending refresh, without duplicating any financial fact', function () {
    $scenario = p6mScenario();
    $request = p6mPendingRequest($scenario['emission']);
    $before = [EmissionPuObligation::query()->count(), EmissionPuObligationCalculation::query()->count()];

    $results = p6mRace([
        ['action' => 'process', 'hold' => true, 'hold_on' => ['from `pu_obligation_refresh_requests`', 'for update'], 'hold_ms' => 1500, 'payload' => ['emission' => $scenario['emission']]],
        ['action' => 'process', 'wait' => true, 'payload' => ['emission' => $scenario['emission']]],
    ]);

    expect(collect($results)->pluck('exception')->filter()->all())->toBe([])
        ->and(collect($results)->pluck('outcome')->sort()->values()->all())->toBe([PuObligationRefreshOutcome::NOTHING_TO_DO, PuObligationRefreshOutcome::SUCCEEDED])
        ->and($request->fresh()->status)->toBe(PuObligationRefreshStatus::Succeeded)
        ->and($request->fresh()->attempts)->toBe(1)
        ->and([EmissionPuObligation::query()->count(), EmissionPuObligationCalculation::query()->count()])->toBe($before);
})->group('mysql');

it('keeps a refresh discoverable when the worker dies in the middle of the attempt, and recovers it as interrupted', function () {
    $scenario = p6mScenario();
    $request = p6mPendingRequest($scenario['emission']);

    $death = p6mDie(['action' => 'process', 'crash_on' => 'from `emission_pu_obligations` where `emission_id` = ? order by `id` asc', 'payload' => ['emission' => $scenario['emission']]]);
    $afterCrash = $request->fresh();

    expect($death)->toContain('exit code [1]')
        ->and($afterCrash->status)->toBe(PuObligationRefreshStatus::Running)
        ->and($afterCrash->attempts)->toBe(1)
        ->and($afterCrash->claim_expires_at)->not->toBeNull();

    // A concessão vence: a varredura retoma.
    $request->forceFill(['claim_expires_at' => now()->subSecond()])->save();
    [$outcome] = app(PuObligationRefreshRecovery::class)->recoverDue();

    expect($outcome->status)->toBe(PuObligationRefreshOutcome::SUCCEEDED)
        ->and($request->fresh()->status)->toBe(PuObligationRefreshStatus::Succeeded)
        ->and($request->fresh()->attempts)->toBe(2)
        ->and($request->fresh()->last_error_category)->toBe(PuOperationalFailureCategory::Interrupted);
})->group('mysql');

it('never loses the refresh of a fact whose process died between the commit and the first attempt', function () {
    $scenario = p6mScenario();

    $death = p6mDie([
        'action' => 'correct_cdi',
        'crash_on' => 'from `pu_obligation_refresh_requests` where `emission_id`',
        'payload' => ['actor' => (int) User::factory()->create()->getKey()],
    ]);
    $request = PuObligationRefreshRequest::query()->where('trigger', 'index_rate_corrected')->sole();

    expect($death)->toContain('exit code [1]')
        ->and($request->status)->toBe(PuObligationRefreshStatus::Pending)
        ->and(EmissionPuObligation::query()->findOrFail($scenario['obligation'])->calculation_state)->toBe(PuObligationCalculationState::Calculated)
        ->and(DB::table('index_rate_corrections')->count())->toBe(1);

    // Sem homologação nova e sem reparo manual: a varredura, depois da carência.
    $request->forceFill(['next_attempt_at' => now()->subSecond()])->save();
    $this->artisan('pu:obligations:recover')->assertSuccessful();

    expect($request->fresh()->status)->toBe(PuObligationRefreshStatus::Succeeded)
        ->and(EmissionPuObligation::query()->findOrFail($scenario['obligation'])->calculation_state)->toBe(PuObligationCalculationState::ReprocessingRequired);
})->group('mysql');

it('ends a stale refresh racing a homologation on the newly governed official version, in either order', function (bool $homologationFirst) {
    $scenario = p6mScenario();
    Fx::publish('2026-03-04', '2026-03-04', '15.50000000');
    $v2 = Fx::generate(Emission::query()->findOrFail($scenario['emission']));
    $request = p6mPendingRequest($scenario['emission'], 'official_curve_diverged');
    $homologate = ['action' => 'homologate', 'payload' => ['emission' => $scenario['emission'], 'version' => $v2->calculation_version, 'actor' => (int) User::factory()->create()->getKey()]];
    $process = ['action' => 'process', 'payload' => ['emission' => $scenario['emission']]];

    $results = p6mRace($homologationFirst
        ? [[...$homologate, 'hold' => true, 'hold_on' => ['insert into `emission_pu_obligation_calculations`']], [...$process, 'wait' => true]]
        : [[...$process, 'hold' => true, 'hold_on' => ['from `emission_pu_obligations`', 'for update']], [...$homologate, 'wait' => true]]);
    $obligation = EmissionPuObligation::query()->findOrFail($scenario['obligation']);

    expect(collect($results)->pluck('exception')->filter()->all())->toBe([])
        ->and($request->fresh()->status)->toBe(PuObligationRefreshStatus::Succeeded)
        ->and($obligation->currentCalculation->curve_version_id)->toBe($v2->id)
        ->and(EmissionPuObligationCalculation::query()->where('obligation_id', $obligation->id)->whereNull('superseded_at')->count())->toBe(1)
        ->and(EmissionPuObligation::query()->where('emission_id', $scenario['emission'])->where('obligation_type', PuObligationType::ScheduledPayment->value)->whereDate('contractual_date', '2026-03-09')->count())->toBe(1);
})->with(['homologação primeiro' => [true], 'atualização primeiro' => [false]])->group('mysql');

it('keeps the settlement evidence when a correction races a reconciliation refresh', function () {
    $scenario = p6mScenario();
    $settlement = Fx::settle(EmissionPuObligation::query()->findOrFail($scenario['obligation']), bcsub($scenario['expected'], '5.00', 2), reference: 'B3-P6M-RACE')->settlement;

    $results = p6mRace([
        ['action' => 'refresh', 'hold' => true, 'hold_on' => ['from `emission_pu_obligations`', 'for update'], 'payload' => ['emission' => $scenario['emission']]],
        ['action' => 'correct_settlement', 'wait' => true, 'payload' => ['settlement' => $settlement->id, 'date' => '2026-03-09', 'amount' => $scenario['expected']]],
    ]);
    $entries = EmissionPuSettlement::query()->where('obligation_id', $scenario['obligation'])->orderBy('id')->get();

    expect(collect($results)->pluck('exception')->filter()->all())->toBe([])
        ->and($entries)->toHaveCount(2)
        ->and((string) $entries[0]->amount)->toBe(bcsub($scenario['expected'], '5.00', 2))
        ->and($entries[0]->status)->toBe(PuSettlementStatus::Corrected)
        ->and((string) $entries[1]->amount)->toBe($scenario['expected'])
        ->and($entries[1]->status)->toBe(PuSettlementStatus::Active)
        ->and(EmissionPuObligation::query()->findOrFail($scenario['obligation'])->reconciliation_status)->toBe(PuReconciliationStatus::Matched);
})->group('mysql');

it('does not resolve an incident whose condition came back while the resolving monitor was running', function () {
    $scenario = p6mScenario();
    $request = p6mPendingRequest($scenario['emission']);
    $request->forceFill(['status' => PuObligationRefreshStatus::Blocked, 'attempts' => 1, 'last_error_category' => PuOperationalFailureCategory::Invariant])->save();
    app(PuOperationalMonitor::class)->run('setup');
    $incident = PuOperationalIncident::query()->open()->where('type', PuOperationalConditionType::ObligationRefreshBlocked->value)->sole();
    // A condição some (alguém atendeu o pedido)...
    DB::table('pu_obligation_refresh_requests')->where('id', $request->id)->update(['status' => PuObligationRefreshStatus::Superseded->value]);

    // ...um monitor a vê sumida e, antes de resolver, outro a vê de volta.
    $results = p6mRace([
        ['action' => 'monitor', 'hold' => true, 'hold_on' => ['from `pu_operational_incidents`', '`open_key`'], 'hold_ms' => 4000],
        ['action' => 'restore_blocked_then_monitor', 'wait' => true, 'payload' => ['request' => $request->id]],
    ]);

    expect(collect($results)->pluck('exception')->filter()->all())->toBe([])
        ->and($incident->fresh()->resolved_at)->toBeNull()
        ->and(PuOperationalIncident::query()->open()->where('type', PuOperationalConditionType::ObligationRefreshBlocked->value)->count())->toBe(1);

    // Estado final determinístico: com a condição presente, segue um incidente aberto.
    app(PuOperationalMonitor::class)->run('final');

    expect(PuOperationalIncident::query()->open()->where('type', PuOperationalConditionType::ObligationRefreshBlocked->value)->count())->toBe(1)
        ->and(PuOperationalIncident::query()->where('type', PuOperationalConditionType::ObligationRefreshBlocked->value)->count())->toBe(1);
})->group('mysql');
