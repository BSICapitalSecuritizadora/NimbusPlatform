<?php

use App\Actions\Emissions\GeneratePuDailyCurve;
use App\Actions\Emissions\HomologatePuCurve;
use App\Domain\PuCalculator\Enums\PuEventType;
use App\Domain\PuCalculator\Enums\PuOperationalActivationStatus;
use App\Domain\PuCalculator\Exceptions\PuOperationalActivationException;
use App\Domain\PuCalculator\Services\PuCurveExtensionService;
use App\Domain\PuCalculator\Services\PuOperationalActivationPolicy;
use App\Domain\PuCalculator\Services\PuOperationalActivationService;
use App\Enums\AccessPermission;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuDailyCurve;
use App\Models\EmissionPuHistoryAnchor;
use App\Models\EmissionPuObligation;
use App\Models\EmissionPuOperationalStatusChange;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CommittedRowsSweeper;
use Tests\Support\Pu\PuObligationFixture as Fx;
use Tests\Support\Pu\PuOperationalActivationFixture as Activation;

/**
 * Fase 7 -- o portão de ativação e a âncora do histórico governado no MySQL real.
 *
 * O SQLite serializa escritores e não mostra a janela entre ler e gravar; aqui
 * processos de verdade correm um contra o outro (um segura a conexão logo depois
 * de uma consulta escolhida, o outro age no meio). A suspensão que comita durante
 * um cálculo é vista na gravação; duas ativações gravam uma decisão; a exclusão da
 * emissão para na chave estrangeira da âncora; duas extensões não duplicam dia.
 */
beforeEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requer MySQL para observar a chave estrangeira e processos disputando a ativação.');
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
 * Processo filho. Com `hold`, segura a conexão por `hold_ms` logo depois da
 * primeira consulta que contiver todos os trechos de `hold_on`; com `wait`, espera
 * o marcador do outro processo e age.
 *
 * @param  array<string, mixed>  $instruction
 */
function p7mTask(array $instruction): Closure
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
            $emission = fn (): Emission => Emission::query()->findOrFail($payload['emission']);

            $outcome = match ($instruction['action']) {
                'generate' => app(GeneratePuDailyCurve::class)->handle($emission())->calculationVersion,
                'suspend' => app(PuOperationalActivationService::class)
                    ->suspend($emission(), User::query()->findOrFail($payload['actor']), 'Suspensão concorrente.')
                    ->status->value,
                'activate' => app(PuOperationalActivationService::class)
                    ->activate($emission(), User::query()->findOrFail($payload['actor']), 'Ativação concorrente.', 'ATA-RACE')
                    ->status->value,
                'homologate' => app(HomologatePuCurve::class)
                    ->handle($emission(), $payload['version'], $payload['actor'], 'Conferida.')
                    ->status->value,
                'delete_emission' => (string) DB::table('emissions')->where('id', $payload['emission'])->delete(),
                'extend_official' => app(PuCurveExtensionService::class)->extendOfficial($emission())->action,
            };

            return ['success' => true, 'outcome' => $outcome, 'exception' => null];
        } catch (Throwable $exception) {
            return ['success' => false, 'outcome' => null, 'exception' => $exception::class.': '.$exception->getMessage()];
        }
    };
}

/**
 * @param  list<array<string, mixed>>  $tasks
 * @return list<array{success: bool, outcome: ?string, exception: ?string}>
 */
function p7mRace(array $tasks): array
{
    $marker = temporaryTestFilePath('pu-activation-race-'.getmypid(), 'lock');
    @unlink($marker);

    $closures = array_map(fn (array $task): Closure => p7mTask([
        'hold' => false,
        'wait' => false,
        'hold_on' => [],
        'hold_ms' => 1500,
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

function p7mActivator(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo(AccessPermission::PuOperationsActivate->value);

    return $user;
}

it('stops a direct SQL delete of an emission with homologated history at the MySQL foreign keys, keeping every governed row', function () {
    $emission = Fx::emission([[PuEventType::InterestPayment, '2026-03-09']]);
    Fx::official($emission);
    $before = [
        EmissionPuCurveVersion::query()->where('emission_id', $emission->id)->count(),
        EmissionPuDailyCurve::query()->where('emission_id', $emission->id)->count(),
        EmissionPuObligation::query()->where('emission_id', $emission->id)->count(),
    ];

    expect(fn () => DB::statement('DELETE FROM emissions WHERE id = ?', [$emission->id]))->toThrow(QueryException::class, '1451')
        ->and(fn () => Emission::query()->whereKey($emission->id)->delete())->toThrow(QueryException::class, '1451')
        ->and([
            EmissionPuCurveVersion::query()->where('emission_id', $emission->id)->count(),
            EmissionPuDailyCurve::query()->where('emission_id', $emission->id)->count(),
            EmissionPuObligation::query()->where('emission_id', $emission->id)->count(),
        ])->toBe($before)
        ->and($before[1])->toBeGreaterThan(0)
        ->and($before[2])->toBeGreaterThan(0);
})->group('mysql');

it('blocks the delete through the anchor alone, independently of the curve rows that also happen to restrict it', function () {
    // Sem versão nem linha de curva: só a âncora segura a emissão.
    $emission = Emission::factory()->create();
    EmissionPuHistoryAnchor::query()->create([
        'emission_id' => $emission->id,
        'reason' => EmissionPuHistoryAnchor::REASON_PREEXISTING_GOVERNED_HISTORY,
        'anchored_at' => now(),
    ]);

    expect(fn () => DB::statement('DELETE FROM emissions WHERE id = ?', [$emission->id]))
        ->toThrow(QueryException::class, 'emission_pu_history_anchors')
        ->and(Emission::query()->whereKey($emission->id)->exists())->toBeTrue();
})->group('mysql');

it('keeps the existing deletion behaviour for an emission without governed history', function () {
    // Só parâmetros e eventos: a cascata de sempre apaga tudo.
    $configured = Fx::emission([[PuEventType::InterestPayment, '2026-03-09']]);
    DB::statement('DELETE FROM emissions WHERE id = ?', [$configured->id]);

    // Curva só gerada (não homologada): o RESTRICT de linha -> versão, que já existia,
    // continua recusando -- comportamento anterior, inalterado pela âncora.
    $generated = Fx::emission([[PuEventType::InterestPayment, '2026-03-09']]);
    Fx::generate($generated);

    expect(Emission::query()->whereKey($configured->id)->exists())->toBeFalse()
        ->and(DB::table('emission_pu_parameters')->where('emission_id', $configured->id)->exists())->toBeFalse()
        ->and(fn () => DB::statement('DELETE FROM emissions WHERE id = ?', [$generated->id]))->toThrow(QueryException::class, 'emission_pu_daily_curves_curve_version_id_foreign')
        ->and(EmissionPuHistoryAnchor::query()->where('emission_id', $generated->id)->exists())->toBeFalse();
})->group('mysql');

it('sees a suspension committed while a generation is calculating: the generation writes nothing', function () {
    $emission = Fx::emission([[PuEventType::InterestPayment, '2026-03-09']]);
    $actor = p7mActivator();

    $results = p7mRace([
        // Segura logo depois de ler o índice no cálculo -- antes da transação de gravação.
        ['action' => 'generate', 'hold' => true, 'hold_on' => ['from `index_rates`'], 'hold_ms' => 2500, 'payload' => ['emission' => $emission->id]],
        ['action' => 'suspend', 'wait' => true, 'payload' => ['emission' => $emission->id, 'actor' => $actor->id]],
    ]);

    expect($results[1]['success'])->toBeTrue()
        ->and($results[0]['success'])->toBeFalse()
        ->and($results[0]['exception'])->toContain('PU_OPERATIONS_SUSPENDED')
        ->and(EmissionPuCurveVersion::query()->where('emission_id', $emission->id)->count())->toBe(0)
        ->and(EmissionPuDailyCurve::query()->where('emission_id', $emission->id)->count())->toBe(0)
        ->and(app(PuOperationalActivationPolicy::class)->status($emission))->toBe(PuOperationalActivationStatus::Suspended);
})->group('mysql');

it('serializes a generation that already holds the emission lock and a suspension: the curve commits first, the suspension after', function () {
    $emission = Fx::emission([[PuEventType::InterestPayment, '2026-03-09']]);
    $actor = p7mActivator();

    $results = p7mRace([
        // Segura dentro da transação de gravação, já com a emissão travada.
        ['action' => 'generate', 'hold' => true, 'hold_on' => ['from `emission_pu_operational_status_changes`', 'lock in share mode'], 'hold_ms' => 2500, 'payload' => ['emission' => $emission->id]],
        ['action' => 'suspend', 'wait' => true, 'payload' => ['emission' => $emission->id, 'actor' => $actor->id]],
    ]);
    $version = EmissionPuCurveVersion::query()->where('emission_id', $emission->id)->sole();
    $suspension = EmissionPuOperationalStatusChange::query()->where('emission_id', $emission->id)->latest('id')->first();

    expect(collect($results)->pluck('exception')->filter()->all())->toBe([])
        ->and($suspension->status)->toBe(PuOperationalActivationStatus::Suspended)
        ->and($version->created_at->lte($suspension->created_at))->toBeTrue()
        ->and(fn () => app(GeneratePuDailyCurve::class)->handle($emission->fresh()))->toThrow(PuOperationalActivationException::class, 'PU_OPERATIONS_SUSPENDED');
})->group('mysql');

it('reads the activation ledger with a locking read: a suspension committed after the transaction snapshot is still seen', function () {
    $emission = Fx::emission([[PuEventType::InterestPayment, '2026-03-09']]);
    $actor = p7mActivator();
    $policy = app(PuOperationalActivationPolicy::class);

    DB::beginTransaction();

    try {
        // Leitura comum: o REPEATABLE READ fixa o retrato da transação aqui.
        expect($policy->status($emission->id))->toBe(PuOperationalActivationStatus::Activated);

        $results = p7mRace([['action' => 'suspend', 'payload' => ['emission' => $emission->id, 'actor' => $actor->id]]]);

        expect($results[0]['success'])->toBeTrue()
            // O retrato antigo ainda diz "ativada"; a leitura travada vê a suspensão.
            ->and($policy->status($emission->id))->toBe(PuOperationalActivationStatus::Activated)
            ->and($policy->status($emission->id, lockForShare: true))->toBe(PuOperationalActivationStatus::Suspended)
            ->and($policy->allows($emission->id, lockForShare: true))->toBeFalse();
    } finally {
        DB::rollBack();
    }
})->group('mysql');

it('records a single decision when two activations race', function () {
    Activation::withoutAutomaticActivation();
    $emission = Fx::emission([[PuEventType::InterestPayment, '2026-03-09']]);
    $actor = p7mActivator();

    $results = p7mRace([
        ['action' => 'activate', 'hold' => true, 'hold_on' => ['from `emission_pu_operational_status_changes`', 'lock in share mode'], 'hold_ms' => 2500, 'payload' => ['emission' => $emission->id, 'actor' => $actor->id]],
        ['action' => 'activate', 'wait' => true, 'payload' => ['emission' => $emission->id, 'actor' => $actor->id]],
    ]);

    expect(collect($results)->where('success', true))->toHaveCount(1)
        ->and(collect($results)->firstWhere('success', false)['exception'])->toContain('PU_ACTIVATION_INVALID_TRANSITION')
        ->and(EmissionPuOperationalStatusChange::query()->where('emission_id', $emission->id)->count())->toBe(1);
})->group('mysql');

it('never lets an emission delete racing a homologation remove governed history', function () {
    $emission = Fx::emission([[PuEventType::InterestPayment, '2026-03-09']]);
    $version = Fx::generate($emission);
    $checker = User::factory()->create();

    $results = p7mRace([
        // A homologação segura logo depois de gravar a âncora, ainda sem commit.
        ['action' => 'homologate', 'hold' => true, 'hold_on' => ['insert into `emission_pu_history_anchors`'], 'hold_ms' => 2500, 'payload' => ['emission' => $emission->id, 'version' => $version->calculation_version, 'actor' => $checker->id]],
        ['action' => 'delete_emission', 'wait' => true, 'payload' => ['emission' => $emission->id]],
    ]);

    expect($results[0]['success'])->toBeTrue()
        ->and($results[1]['success'])->toBeFalse()
        ->and($results[1]['exception'])->toContain('1451')
        ->and(Emission::query()->whereKey($emission->id)->exists())->toBeTrue()
        ->and(EmissionPuHistoryAnchor::query()->where('emission_id', $emission->id)->exists())->toBeTrue()
        ->and($version->fresh()->homologated_at)->not->toBeNull();
})->group('mysql');

it('appends no day to the official curve when a suspension commits during the extension calculation', function () {
    $emission = Fx::emission([[PuEventType::InterestPayment, '2026-03-09']]);
    $official = Fx::official($emission);
    $lastDate = Fx::lastDate($official);
    Fx::publish('2026-03-16', '2026-03-20');
    $actor = p7mActivator();

    $results = p7mRace([
        ['action' => 'extend_official', 'hold' => true, 'hold_on' => ['from `index_rates`'], 'hold_ms' => 2500, 'payload' => ['emission' => $emission->id]],
        ['action' => 'suspend', 'wait' => true, 'payload' => ['emission' => $emission->id, 'actor' => $actor->id]],
    ]);

    expect(collect($results)->pluck('exception')->filter()->all())->toBe([])
        ->and($results[0]['outcome'])->toBe(PuCurveExtensionService::ACTION_OPERATIONS_NOT_ACTIVE)
        ->and(Fx::lastDate($official->fresh()))->toBe($lastDate)
        ->and($official->fresh()->extension_failed_at)->toBeNull();
})->group('mysql');

it('extends the official curve once when two extensions race, without duplicating a day or failing', function () {
    $emission = Fx::emission([[PuEventType::InterestPayment, '2026-03-09']]);
    $official = Fx::official($emission);
    Fx::publish('2026-03-16', '2026-03-20');

    $results = p7mRace([
        ['action' => 'extend_official', 'hold' => true, 'hold_on' => ['from `emission_pu_curve_versions`', 'for update'], 'hold_ms' => 2500, 'payload' => ['emission' => $emission->id]],
        ['action' => 'extend_official', 'wait' => true, 'payload' => ['emission' => $emission->id]],
    ]);
    $dates = EmissionPuDailyCurve::query()->where('curve_version_id', $official->id)->pluck('curve_date')->map(fn ($date): string => substr((string) $date, 0, 10));

    expect(collect($results)->pluck('exception')->filter()->all())->toBe([])
        ->and(collect($results)->pluck('outcome')->all())->toContain(PuCurveExtensionService::ACTION_EXTENDED)
        ->and($dates->count())->toBe($dates->unique()->count())
        ->and(Fx::lastDate($official->fresh()))->toBeGreaterThan('2026-03-16');
})->group('mysql');

it('runs the Phase 7 migrations down and up on MySQL, refusing to drop an anchored protection', function () {
    $activation = require database_path('migrations/2026_10_13_150000_create_emission_pu_operational_status_changes_table.php');
    $anchors = require database_path('migrations/2026_10_13_150100_create_emission_pu_history_anchors_table.php');

    $anchors->down();
    $activation->down();
    expect(Schema::hasTable('emission_pu_history_anchors'))->toBeFalse()
        ->and(Schema::hasTable('emission_pu_operational_status_changes'))->toBeFalse();

    $activation->up();
    $anchors->up();
    $anchors->down();
    $anchors->up();

    $emission = Fx::emission([[PuEventType::InterestPayment, '2026-03-09']]);
    Fx::official($emission);

    expect(fn () => $anchors->down())->toThrow(RuntimeException::class, 'Rollback recusado')
        ->and(Schema::hasTable('emission_pu_history_anchors'))->toBeTrue()
        ->and(EmissionPuHistoryAnchor::query()->where('emission_id', $emission->id)->exists())->toBeTrue();
})->group('mysql');
