<?php

use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexQuarantineStatus;
use App\Domain\PuCalculator\Services\IndexRateLookupService;
use App\Domain\PuCalculator\Services\IndexRateQuarantineReviewService;
use App\Domain\PuCalculator\Services\IndexRateService;
use App\Domain\PuCalculator\Services\IndexRateSyncService;
use App\Domain\PuCalculator\Services\PuCurveExtensionService;
use App\Enums\AccessPermission;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuDailyCurve;
use App\Models\IndexRate;
use App\Models\IndexRateCorrection;
use App\Models\IndexRateQuarantine;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\CommittedRowsSweeper;
use Tests\Support\Pu\PuObligationFixture as Fx;

/**
 * Fase 8.1 (NEW-02) -- quarentena de índice disputando o MySQL real.
 *
 * Um processo segura a transação logo depois de uma consulta escolhida e o outro
 * age no meio. A unique de `open_fingerprint`, a trava das pendências da data e
 * a unique de `index_rates` é que decidem: nenhuma corrida aceita valor sem
 * decisão, duplica a fila ou aplica duas decisões.
 *
 * Fx::emission() publica CDI de 27/02 a 13/03 a 14,90; 149,00 em 16/03 é salto
 * suspeito.
 */
beforeEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requer MySQL para observar processos disputando a mesma observação retida.');
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

function p81mReviewer(): int
{
    $user = User::factory()->create();
    $user->givePermissionTo(AccessPermission::PuIndexCorrect->value);

    return (int) $user->getKey();
}

/**
 * Processo filho. Com `hold`, segura a conexão por 800 ms depois da primeira
 * consulta que casar com `hold_on` e avisa pelo marcador; sem `hold`, espera o
 * marcador. `crash_on` derruba o processo (SIGKILL) depois da consulta indicada,
 * com a transação aberta.
 *
 * @param  array{action: string, hold: bool, hold_on: list<string>, crash_on: list<string>, marker: string, payload: array<string, mixed>}  $instruction
 */
function p81mTask(array $instruction): Closure
{
    return static function () use ($instruction): array {
        try {
            config(['pu_indexes.bcb.chunk_months' => 12, 'pu_indexes.bcb.retries' => 1, 'pu_indexes.bcb.retry_sleep_ms' => 0]);

            if ($instruction['crash_on'] !== []) {
                DB::listen(static function (QueryExecuted $query) use ($instruction): void {
                    $sql = strtolower($query->sql);

                    foreach ($instruction['crash_on'] as $fragment) {
                        if (! str_contains($sql, $fragment)) {
                            return;
                        }
                    }

                    posix_kill(getmypid(), SIGKILL);
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
                    usleep(800_000);
                });
            } elseif ($instruction['crash_on'] === []) {
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
                'sync' => (function () use ($payload): string {
                    Http::fake(['api.bcb.gov.br/*' => Http::response([['data' => CarbonImmutable::parse($payload['date'])->format('d/m/Y'), 'valor' => $payload['value']]])]);

                    return (string) app(IndexRateSyncService::class)
                        ->sync(PuIndexer::Cdi, CarbonImmutable::parse($payload['date']), CarbonImmutable::parse($payload['date']), overwritePolicy: IndexRateSyncService::POLICY_SKIP)
                        ->quarantined;
                })(),
                'approve' => app(IndexRateQuarantineReviewService::class)->approve(
                    User::query()->findOrFail($payload['actor']),
                    $payload['quarantine'],
                    'Conferido no SGS.',
                    $payload['seen'] ?? null,
                )->status->value,
                'reject' => app(IndexRateQuarantineReviewService::class)->reject(
                    User::query()->findOrFail($payload['actor']),
                    $payload['quarantine'],
                    'Erro de unidade da fonte.',
                )->status->value,
                'extend_official' => app(PuCurveExtensionService::class)
                    ->extendOfficial(Emission::query()->findOrFail($payload['emission']))
                    ->action,
            };

            return ['success' => true, 'outcome' => $outcome, 'exception' => null];
        } catch (Throwable $exception) {
            return ['success' => false, 'outcome' => null, 'exception' => $exception::class.': '.$exception->getMessage()];
        }
    };
}

/**
 * @param  list<array{action: string, hold: bool, hold_on?: list<string>, crash_on?: list<string>, payload: array<string, mixed>}>  $tasks
 * @return list<array{success: bool, outcome: ?string, exception: ?string}>
 */
function p81mRace(array $tasks): array
{
    $marker = temporaryTestFilePath('pu-index-quarantine-race-'.getmypid(), 'lock');
    @unlink($marker);

    $results = Concurrency::driver('process')->run(array_map(
        fn (array $task): Closure => p81mTask([...$task, 'hold_on' => $task['hold_on'] ?? [], 'crash_on' => $task['crash_on'] ?? [], 'marker' => $marker]),
        $tasks,
    ));

    @unlink($marker);

    return array_values($results);
}

/**
 * Observação retida pela sincronização de verdade (no processo do teste).
 */
function p81mRetain(string $date, string $value): IndexRateQuarantine
{
    config(['pu_indexes.bcb.chunk_months' => 12, 'pu_indexes.bcb.retries' => 1, 'pu_indexes.bcb.retry_sleep_ms' => 0]);
    Http::fake(['api.bcb.gov.br/*' => Http::response([['data' => CarbonImmutable::parse($date)->format('d/m/Y'), 'valor' => $value]])]);
    app(IndexRateSyncService::class)->sync(PuIndexer::Cdi, CarbonImmutable::parse($date), CarbonImmutable::parse($date), overwritePolicy: IndexRateSyncService::POLICY_SKIP);

    return IndexRateQuarantine::query()->whereDate('rate_date', $date)->pending()->sole();
}

function p81mCdi(string $date): ?IndexRate
{
    return IndexRate::query()->where('indexer', 'CDI')->whereDate('rate_date', $date)->first();
}

it('keeps one review record when two sync workers receive the same anomaly at once', function () {
    Fx::emission();

    $results = p81mRace([
        ['action' => 'sync', 'hold' => true, 'hold_on' => ['select', 'index_rate_quarantines', 'open_fingerprint'], 'payload' => ['date' => '2026-03-16', 'value' => '149.00']],
        ['action' => 'sync', 'hold' => false, 'payload' => ['date' => '2026-03-16', 'value' => '149.00']],
    ]);
    $retained = IndexRateQuarantine::query()->sole();

    expect(collect($results)->pluck('exception')->filter()->all())->toBe([])
        ->and(collect($results)->pluck('outcome')->all())->toBe(['1', '1'])
        ->and($retained->occurrences)->toBe(2)
        ->and($retained->isPending())->toBeTrue()
        ->and($retained->first_sync_attempt_id)->not->toBe($retained->last_sync_attempt_id)
        ->and(p81mCdi('2026-03-16'))->toBeNull();
})->group('mysql');

it('applies exactly one decision when two reviewers act on the same pending observation', function (string $first, string $second) {
    Fx::emission();
    $retained = p81mRetain('2026-03-16', '149.00');

    $results = p81mRace([
        ['action' => $first, 'hold' => true, 'hold_on' => ['index_rate_quarantines', 'for update'], 'payload' => ['quarantine' => $retained->id, 'actor' => p81mReviewer()]],
        ['action' => $second, 'hold' => false, 'payload' => ['quarantine' => $retained->id, 'actor' => p81mReviewer()]],
    ]);
    $final = $retained->fresh();

    expect($results[0]['success'])->toBeTrue()
        ->and($results[1]['success'])->toBeFalse()
        ->and($results[1]['exception'])->toContain('já foi encerrada')
        ->and($final->status->value)->toBe($results[0]['outcome'])
        ->and(IndexRate::query()->whereDate('rate_date', '2026-03-16')->count())->toBe($first === 'approve' ? 1 : 0)
        ->and(IndexRateQuarantine::query()->count())->toBe(1);
})->with([
    'aprovar × recusar' => ['approve', 'reject'],
    'recusar × aprovar' => ['reject', 'approve'],
    'aprovar × aprovar' => ['approve', 'approve'],
])->group('mysql');

it('lets a provider correction that arrives during the review win over the stale approval', function () {
    Fx::emission();
    $retained = p81mRetain('2026-03-16', '149.00');

    $results = p81mRace([
        ['action' => 'approve', 'hold' => true, 'hold_on' => ['index_rate_quarantines', 'for update'], 'payload' => ['quarantine' => $retained->id, 'actor' => p81mReviewer()]],
        ['action' => 'sync', 'hold' => false, 'payload' => ['date' => '2026-03-16', 'value' => '14.95']],
    ]);
    $accepted = p81mCdi('2026-03-16');
    $final = $retained->fresh();

    // Conforme o instante, a aprovação vê a observação nova ao conferir o valor que o
    // revisor viu ou perde a unique ao gravar: as duas recusam e não gravam nada.
    expect($results[0]['success'])->toBeFalse()
        ->and($results[0]['exception'])->toContain('14.95000000', 'Nada foi gravado')
        ->and($results[1]['success'])->toBeTrue()
        ->and((string) $accepted->rate_value)->toBe('14.95000000')
        ->and($accepted->source_reference)->toBe('bcb_sgs:4389')
        ->and($final->status)->toBe(PuIndexQuarantineStatus::Superseded)
        ->and($final->resulting_index_rate_id)->toBe($accepted->id)
        ->and($final->decided_by)->toBeNull();
})->group('mysql');

it('never lets a pending observation extend the official curve, and lets the approved one in only after the decision', function (bool $extensionFirst) {
    $emission = Fx::emission();
    $official = Fx::official($emission);
    $lastBefore = Fx::lastDate($official);
    $retained = p81mRetain('2026-03-16', '149.00');

    expect(app(PuCurveExtensionService::class)->extendOfficial($emission->fresh())->action)->not->toBe(PuCurveExtensionService::ACTION_EXTENDED)
        ->and(Fx::lastDate($official->fresh()))->toBe($lastBefore);

    // A extensão recalcula fora da transação e só trava para anexar: o ponto de espera
    // dela é a primeira leitura do índice, onde a aprovação entra no meio.
    $approval = ['action' => 'approve', 'hold' => ! $extensionFirst, 'hold_on' => ['index_rate_quarantines', 'for update'], 'payload' => ['quarantine' => $retained->id, 'actor' => p81mReviewer()]];
    $extension = ['action' => 'extend_official', 'hold' => $extensionFirst, 'hold_on' => ['select', 'index_rates', 'rate_date'], 'payload' => ['emission' => $emission->id]];
    $results = p81mRace($extensionFirst ? [$extension, $approval] : [$approval, $extension]);

    expect(collect($results)->pluck('exception')->filter()->all())->toBe([])
        ->and($retained->fresh()->status)->toBe(PuIndexQuarantineStatus::Approved);

    // Na corrida, a extensão só pode ter usado o valor depois de aprovado e gravado.
    $rows = EmissionPuDailyCurve::query()->where('curve_version_id', $official->id)->whereDate('index_rate_date', '2026-03-16')->pluck('index_rate_value')->map(fn ($value): string => (string) $value)->all();

    expect($rows === [] || $rows === ['149.00000000'])->toBeTrue();

    // O leitor de índice memoriza por processo: a aprovação comitou em outro.
    app(IndexRateLookupService::class)->flushCache();
    app(IndexRateService::class)->flushCache();
    app(PuCurveExtensionService::class)->extendOfficial($emission->fresh());

    expect((string) EmissionPuDailyCurve::query()->where('curve_version_id', $official->id)->whereDate('index_rate_date', '2026-03-16')->value('index_rate_value'))->toBe('149.00000000');
})->with([
    'extensão trava primeiro' => [true],
    'aprovação trava primeiro' => [false],
])->group('mysql');

it('flags the official curve when an approved divergence waits for the extension that used the old rate', function () {
    $emission = Fx::emission();
    $official = Fx::official($emission);
    Fx::publish('2026-03-16', '2026-03-18');
    $retained = p81mRetain('2026-03-17', '15.40');

    $results = p81mRace([
        ['action' => 'extend_official', 'hold' => true, 'hold_on' => ['index_rates', 'lock in share mode'], 'payload' => ['emission' => $emission->id]],
        ['action' => 'approve', 'hold' => false, 'payload' => ['quarantine' => $retained->id, 'actor' => p81mReviewer(), 'seen' => '14.90000000']],
    ]);
    $official = EmissionPuCurveVersion::query()->findOrFail($official->id);
    $correction = IndexRateCorrection::query()->sole();

    expect(collect($results)->pluck('exception')->filter()->all())->toBe([])
        ->and($results[0]['outcome'])->toBe(PuCurveExtensionService::ACTION_EXTENDED)
        ->and($results[1]['outcome'])->toBe('approved')
        ->and((string) EmissionPuDailyCurve::query()->where('curve_version_id', $official->id)->whereDate('curve_date', '2026-03-18')->value('index_rate_value'))->toBe('14.90000000')
        ->and(collect($correction->affected_curve_versions)->pluck('first_dependent_date', 'id')->all())->toBe([$official->id => '2026-03-18'])
        ->and($official->extension_diverged_at)->not->toBeNull()
        ->and($official->extension_divergence['index_rate_correction_id'])->toBe($correction->id)
        ->and($retained->fresh()->resulting_correction_id)->toBe($correction->id)
        ->and((string) p81mCdi('2026-03-17')->rate_value)->toBe('15.40000000');
})->group('mysql');

it('does not bring an approved divergence back to the queue on the next synchronization', function () {
    Fx::emission();
    $retained = p81mRetain('2026-03-05', '15.40');
    app(IndexRateQuarantineReviewService::class)->approve(User::query()->findOrFail(p81mReviewer()), $retained->id, 'BCB republicou.', '14.90000000');

    $results = p81mRace([
        ['action' => 'sync', 'hold' => true, 'hold_on' => ['select', 'index_rates', 'rate_date'], 'payload' => ['date' => '2026-03-05', 'value' => '15.40']],
        ['action' => 'sync', 'hold' => false, 'payload' => ['date' => '2026-03-05', 'value' => '15.40']],
    ]);

    expect(collect($results)->pluck('exception')->filter()->all())->toBe([])
        ->and(collect($results)->pluck('outcome')->all())->toBe(['0', '0'])
        ->and(IndexRateQuarantine::query()->count())->toBe(1)
        ->and(IndexRateQuarantine::query()->pending()->count())->toBe(0)
        ->and((string) p81mCdi('2026-03-05')->rate_value)->toBe('15.40000000')
        ->and(IndexRateCorrection::query()->count())->toBe(1);
})->group('mysql');

it('leaves neither an accepted observation nor a lost review when the reviewer process dies mid-approval', function () {
    Fx::emission();
    $retained = p81mRetain('2026-03-16', '149.00');
    $reviewer = p81mReviewer();

    try {
        p81mRace([
            ['action' => 'approve', 'hold' => false, 'crash_on' => ['insert into', 'index_rates'], 'payload' => ['quarantine' => $retained->id, 'actor' => $reviewer]],
        ]);
        $crashed = false;
    } catch (Throwable) {
        $crashed = true;
    }

    expect($crashed)->toBeTrue()
        ->and(p81mCdi('2026-03-16'))->toBeNull()
        ->and($retained->fresh()->isPending())->toBeTrue()
        ->and($retained->fresh()->open_fingerprint)->toBe($retained->fingerprint);

    $approved = app(IndexRateQuarantineReviewService::class)->approve(User::query()->findOrFail($reviewer), $retained->id, 'Conferido no SGS.', null);

    expect($approved->status)->toBe(PuIndexQuarantineStatus::Approved)
        ->and((string) p81mCdi('2026-03-16')->rate_value)->toBe('149.00000000');
})->group('mysql');
