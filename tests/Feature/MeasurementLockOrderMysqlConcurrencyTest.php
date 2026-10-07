<?php

use App\Enums\OperationStatus;
use App\Exceptions\OperationLifecycleException;
use App\Models\Measurement;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Models\Operation;
use App\Models\User;
use App\Services\MeasurementWorkflow;
use App\Services\OperationLifecycleService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\CommittedRowsSweeper;
use Tests\Support\MeasurementPhysicalProgressScenario as Scenario;
use Tests\Support\MeasurementReceiptEvidenceScenario;

/*
 * Registrar Pagamento travava a medição e os planos aprovados, e só chegava à
 * Operation no INSERT do pagamento: a chave estrangeira `operation_id` pede um
 * lock compartilhado na operação, depois da avaliação financeira. A aprovação
 * da Engenharia de outra medição da mesma operação faz o caminho oposto --
 * Operation primeiro, depois todos os planos --, e as duas transações se
 * esperavam em círculo até o MySQL matar uma delas com o erro 1213. O mesmo
 * pivô fechava ciclo com o encerramento da operação e com o envio de medição.
 *
 * Cada teste monta uma barreira bilateral: quem registra o pagamento (ou
 * finaliza) avisa que já tem os planos travados e espera, por até dois
 * segundos, que o processo concorrente consiga travar a Operation. Com a ordem
 * antiga a barreira se fecha -- os dois seguram metades do ciclo ao mesmo
 * tempo -- e o deadlock vem logo depois. Com a Operation travada primeiro, o
 * concorrente fica parado no lock da Operation: a barreira nunca se fecha e
 * ninguém morre. O SQLite serializa escritores e não mostra nada disso.
 */
beforeEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requer MySQL para validar locks concorrentes. Execute: ./vendor/bin/sail composer test:measurements:mysql');
    }

    $this->assertStringStartsWith(
        'nimbus_parity_check',
        DB::connection()->getDatabaseName(),
        'Estes testes recriam o banco. Use o banco temporário de composer test:measurements:mysql.',
    );

    Artisan::call('migrate:fresh', ['--no-interaction' => true]);
    $this->committedRows = CommittedRowsSweeper::afterFreshMigration();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Notification::fake();
});

afterEach(function () {
    if (DB::getDriverName() !== 'mysql' || ! isset($this->committedRows)) {
        return;
    }

    $this->committedRows->sweep();

    expect($this->committedRows->leftovers())->toBe([]);
});

/**
 * Ação do fluxo num processo próprio, observando os próprios locks.
 *
 * Quem recebe `barrier_table` segura o primeiro lock que pedir nessa tabela:
 * cria `own_marker`, espera o concorrente avisar em `ready_marker` que vai
 * começar e então dá até `barrier_timeout_ms` para ele travar a Operation
 * (`peer_marker`). `barrier_met` diz se isso aconteceu. Quem recebe
 * `wait_for_marker` só começa depois desse arquivo, cria `ready_marker` e, ao
 * obter o lock da Operation, cria `signal_marker`.
 *
 * `operation_locks` conta os pedidos de lock da Operation -- uma repetição da
 * transação aparece aqui -- e `operation_lock_wait_ms` é quanto o primeiro
 * deles esperou.
 *
 * @param  array{action: string, actor_id: int, storage_root: string, measurement_id?: int, operation_id?: int, revision?: int, plan_set_id?: int, plan_line_id?: int, percent?: int|string, amount?: string, pay_date?: string, reference_month?: string, asset_path?: string, barrier_table?: string, own_marker?: string, ready_marker?: string, peer_marker?: string, barrier_timeout_ms?: int, wait_for_marker?: string, signal_marker?: string}  $instruction
 */
function measurementLockOrderTask(array $instruction): Closure
{
    return static function () use ($instruction): array {
        config()->set('filesystems.private_disk', 'local');
        config()->set('filesystems.disks.local.root', $instruction['storage_root']);
        Storage::forgetDisk('local');
        Notification::fake();
        $observed = ['operation_locks' => 0, 'operation_lock_wait_ms' => null, 'barrier_met' => null];
        $barrier = ['reached' => false];

        DB::listen(static function (QueryExecuted $query) use ($instruction, &$observed, &$barrier): void {
            $sql = strtolower($query->sql);

            if (! str_contains($sql, 'for update')) {
                return;
            }

            if (str_contains($sql, '`operations`')) {
                $observed['operation_locks']++;

                if ($observed['operation_lock_wait_ms'] === null) {
                    $observed['operation_lock_wait_ms'] = $query->time;

                    if (isset($instruction['signal_marker'])) {
                        file_put_contents($instruction['signal_marker'], 'locked');
                    }
                }
            }

            if ($barrier['reached'] || ! isset($instruction['barrier_table'])
                || ! str_contains($sql, '`'.$instruction['barrier_table'].'`')) {
                return;
            }

            $barrier['reached'] = true;
            file_put_contents($instruction['own_marker'], 'locked');
            $deadline = microtime(true) + 10;

            while (! is_file($instruction['ready_marker']) && microtime(true) < $deadline) {
                usleep(10_000);
            }

            if (! is_file($instruction['ready_marker'])) {
                throw new RuntimeException('O processo concorrente não começou enquanto o lock era mantido.');
            }

            $deadline = microtime(true) + (($instruction['barrier_timeout_ms'] ?? 2000) / 1000);

            while (! is_file($instruction['peer_marker']) && microtime(true) < $deadline) {
                usleep(10_000);
            }

            $observed['barrier_met'] = is_file($instruction['peer_marker']);
        });

        try {
            $actor = User::query()->findOrFail($instruction['actor_id']);
            $measurement = isset($instruction['measurement_id'])
                ? Measurement::query()->findOrFail($instruction['measurement_id'])
                : null;

            if (isset($instruction['wait_for_marker'])) {
                $deadline = microtime(true) + 10;

                while (! is_file($instruction['wait_for_marker']) && microtime(true) < $deadline) {
                    usleep(10_000);
                }

                if (! is_file($instruction['wait_for_marker'])) {
                    throw new RuntimeException('O processo concorrente não confirmou o lock dos planos.');
                }

                file_put_contents($instruction['ready_marker'], 'ready');
            }

            $workflow = app(MeasurementWorkflow::class);

            if ($instruction['action'] === 'payment') {
                $workflow->registerPayment($measurement, $actor, [
                    'plan_set_id' => $instruction['plan_set_id'],
                    'pay_date' => $instruction['pay_date'],
                    'amount' => $instruction['amount'],
                ], expectedRevision: $instruction['revision']);
            } elseif ($instruction['action'] === 'approve_engineering') {
                $workflow->approve(
                    $measurement,
                    $actor,
                    engineeringProgress: [$instruction['plan_set_id'] => $instruction['percent']],
                    expectedStage: MeasurementWorkflow::STAGE_ENGINEERING,
                    expectedRevision: $instruction['revision'],
                );
            } elseif ($instruction['action'] === 'finalize') {
                $workflow->finalize($measurement, $actor, expectedRevision: $instruction['revision'], expectedStatus: 'approved');
            } elseif ($instruction['action'] === 'complete_operation') {
                app(OperationLifecycleService::class)->complete(Operation::query()->findOrFail($instruction['operation_id']), $actor);
            } elseif ($instruction['action'] === 'submit_measurement') {
                // O envio como a página faz: transação da página, a medição nasce
                // sob o lock da operação e os arquivos entram depois, na mesma
                // transação, antes de a Engenharia ser acionada.
                DB::beginTransaction();

                try {
                    $submitted = DB::transaction(static function () use ($instruction, $actor): Measurement {
                        app(OperationLifecycleService::class)->lockForNewMeasurement($instruction['operation_id'], $actor);

                        return Measurement::query()->create([
                            'operation_id' => $instruction['operation_id'],
                            'reference_month' => $instruction['reference_month'],
                            'status' => 'pending',
                            'current_stage' => MeasurementWorkflow::STAGE_ENGINEERING,
                            'uploaded_by' => $actor->getKey(),
                            'uploaded_at' => now(),
                        ]);
                    }, 3);
                    $submitted->assets()->create([
                        'plan_set_id' => $instruction['plan_set_id'],
                        'plan_line_id' => $instruction['plan_line_id'],
                        'storage_path' => $instruction['asset_path'],
                        'storage_disk' => 'local',
                    ]);
                    $workflow->startReview($submitted->refresh(), $actor);
                    DB::commit();
                } catch (Throwable $exception) {
                    if (DB::transactionLevel() > 0) {
                        DB::rollBack();
                    }

                    throw $exception;
                }
            } else {
                throw new RuntimeException("Ação desconhecida: {$instruction['action']}.");
            }

            return ['success' => true, 'exception' => null, 'message' => null] + $observed;
        } catch (Throwable $exception) {
            return ['success' => false, 'exception' => $exception::class, 'message' => $exception->getMessage()] + $observed;
        }
    };
}

/**
 * Maio aprovado até a etapa Pagamento, na operação e no plano do cenário de
 * avanço físico (fundo de obra de R$ 1.000.000,00, maio medido a 10%).
 *
 * @return array{actor: User, operation: Operation, planSet: MeasurementPlanSet, lines: array<string, MeasurementPlanLine>, may: Measurement}
 */
function measurementLockOrderScenario(): array
{
    $scenario = Scenario::plan();
    $workflow = app(MeasurementWorkflow::class);
    $may = Scenario::measured($scenario, '2026-05', 10);
    $workflow->approve($may->fresh(), $scenario['actor']);
    $workflow->approve($may->fresh(), $scenario['actor']);

    return $scenario + ['may' => $may->fresh()];
}

/**
 * @return array{plans: string, ready: string, operation: string}
 */
function measurementLockOrderMarkers(string $prefix): array
{
    $markers = [
        'plans' => temporaryTestFilePath("{$prefix}-plans-locked", 'lock'),
        'ready' => temporaryTestFilePath("{$prefix}-peer-ready", 'lock'),
        'operation' => temporaryTestFilePath("{$prefix}-operation-locked", 'lock'),
    ];

    measurementLockOrderForgetMarkers($markers);

    return $markers;
}

/**
 * @param  array{plans: string, ready: string, operation: string}  $markers
 */
function measurementLockOrderForgetMarkers(array $markers): void
{
    foreach ($markers as $marker) {
        @unlink($marker);
    }
}

/**
 * Quem trava os planos e espera o concorrente travar a Operation.
 *
 * @param  array{plans: string, ready: string, operation: string}  $markers
 * @return array<string, mixed>
 */
function measurementLockOrderHolder(array $markers): array
{
    return [
        'barrier_table' => 'measurement_plan_sets',
        'own_marker' => $markers['plans'],
        'ready_marker' => $markers['ready'],
        'peer_marker' => $markers['operation'],
        'barrier_timeout_ms' => 2000,
    ];
}

/**
 * Quem começa depois dos planos travados e avisa quando obtém a Operation.
 *
 * @param  array{plans: string, ready: string, operation: string}  $markers
 * @return array<string, mixed>
 */
function measurementLockOrderPeer(array $markers): array
{
    return [
        'wait_for_marker' => $markers['plans'],
        'ready_marker' => $markers['ready'],
        'signal_marker' => $markers['operation'],
    ];
}

/**
 * @param  array{actor: User, planSet: MeasurementPlanSet, may: Measurement}  $scenario
 * @return array<string, mixed>
 */
function measurementLockOrderPayment(array $scenario): array
{
    return [
        'action' => 'payment',
        'actor_id' => $scenario['actor']->id,
        'measurement_id' => $scenario['may']->id,
        'revision' => (int) $scenario['may']->fresh()->workflow_revision,
        'plan_set_id' => $scenario['planSet']->id,
        'amount' => '100000.00',
        'pay_date' => '2026-05-20',
        'storage_root' => Storage::disk('local')->path(''),
    ];
}

it('registers a payment while Engineering approves another measurement of the same operation without deadlock on MySQL', function () {
    $scenario = measurementLockOrderScenario();
    $june = Scenario::measurement($scenario, '2026-06');
    $revisionBeforePayment = (int) $scenario['may']->fresh()->workflow_revision;
    $markers = measurementLockOrderMarkers('lock-order-payment-engineering');

    $results = Concurrency::driver('process')->run([
        measurementLockOrderTask(measurementLockOrderPayment($scenario) + measurementLockOrderHolder($markers)),
        measurementLockOrderTask([
            'action' => 'approve_engineering',
            'actor_id' => $scenario['actor']->id,
            'measurement_id' => $june->id,
            'revision' => (int) $june->workflow_revision,
            'plan_set_id' => $scenario['planSet']->id,
            'percent' => 5,
            'storage_root' => Storage::disk('local')->path(''),
        ] + measurementLockOrderPeer($markers)),
    ]);
    measurementLockOrderForgetMarkers($markers);

    $may = $scenario['may']->fresh();
    $june = $june->fresh();

    expect(collect($results)->pluck('message')->filter()->values()->all())->toBe([])
        ->and(collect($results)->pluck('success')->all())->toBe([true, true])
        ->and($results[0]['operation_locks'])->toBe(1)
        ->and($results[0]['barrier_met'])->toBeFalse()
        ->and($results[1]['operation_locks'])->toBe(1)
        ->and($results[1]['operation_lock_wait_ms'])->toBeGreaterThan(1000)
        ->and($may->payments()->pluck('amount')->all())->toBe(['100000.00'])
        ->and($may->status)->toBe('awaiting_payment')
        ->and($may->workflow_revision)->toBe($revisionBeforePayment + 1)
        ->and($june->current_stage)->toBe(2)
        ->and($june->status)->toBe('in_review')
        ->and($june->engineering_snapshot['plan_sets'][0]['prior_realized_cumulative_percent'])->toBe('10.00')
        ->and($june->engineering_snapshot['plan_sets'][0]['realized_cumulative_percent'])->toBe('15.00')
        ->and($scenario['lines']['2026-06']->fresh()->measurement_id)->toBe($june->id);
})->group('mysql');

it('makes the closing of an operation wait for a payment registration instead of deadlocking on MySQL', function () {
    $scenario = measurementLockOrderScenario();
    $scenario['actor']->givePermissionTo('operations.update');
    $markers = measurementLockOrderMarkers('lock-order-payment-closing');

    $results = Concurrency::driver('process')->run([
        measurementLockOrderTask(measurementLockOrderPayment($scenario) + measurementLockOrderHolder($markers)),
        measurementLockOrderTask([
            'action' => 'complete_operation',
            'actor_id' => $scenario['actor']->id,
            'operation_id' => $scenario['operation']->id,
            'storage_root' => Storage::disk('local')->path(''),
        ] + measurementLockOrderPeer($markers)),
    ]);
    measurementLockOrderForgetMarkers($markers);

    // A conclusão seria recusada de qualquer jeito -- maio está aberto na etapa
    // Pagamento --, mas por regra, uma vez só, depois de esperar o pagamento; e
    // não como vítima de deadlock repetida em silêncio pelo retry do lifecycle.
    expect($results[0]['message'])->toBeNull()
        ->and($results[0]['success'])->toBeTrue()
        ->and($results[0]['operation_locks'])->toBe(1)
        ->and($results[0]['barrier_met'])->toBeFalse()
        ->and($results[1]['exception'])->toBe(OperationLifecycleException::class)
        ->and($results[1]['operation_locks'])->toBe(1)
        ->and($results[1]['operation_lock_wait_ms'])->toBeGreaterThan(1000)
        ->and($scenario['operation']->fresh()->status)->toBe(OperationStatus::Active)
        ->and($scenario['may']->payments()->pluck('amount')->all())->toBe(['100000.00']);
})->group('mysql');

it('makes a measurement submission wait for a payment registration of the same operation instead of deadlocking on MySQL', function () {
    $scenario = measurementLockOrderScenario();
    $assetPath = 'nimbus_docs/measurements/assets/lock-order-submission.pdf';
    Storage::disk('local')->put($assetPath, '%PDF-1.7 envio concorrente de junho');
    $markers = measurementLockOrderMarkers('lock-order-payment-submission');

    $results = Concurrency::driver('process')->run([
        measurementLockOrderTask(measurementLockOrderPayment($scenario) + measurementLockOrderHolder($markers)),
        measurementLockOrderTask([
            'action' => 'submit_measurement',
            'actor_id' => $scenario['actor']->id,
            'operation_id' => $scenario['operation']->id,
            'plan_set_id' => $scenario['planSet']->id,
            'plan_line_id' => $scenario['lines']['2026-06']->id,
            'reference_month' => '2026-06-01',
            'asset_path' => $assetPath,
            'storage_root' => Storage::disk('local')->path(''),
        ] + measurementLockOrderPeer($markers)),
    ]);
    measurementLockOrderForgetMarkers($markers);

    $june = Measurement::query()
        ->where('operation_id', $scenario['operation']->id)
        ->whereKeyNot($scenario['may']->id)
        ->first();

    expect(collect($results)->pluck('message')->filter()->values()->all())->toBe([])
        ->and(collect($results)->pluck('success')->all())->toBe([true, true])
        ->and($results[0]['operation_locks'])->toBe(1)
        ->and($results[0]['barrier_met'])->toBeFalse()
        ->and($results[1]['operation_lock_wait_ms'])->toBeGreaterThan(1000)
        ->and($june?->reference_month?->toDateString())->toBe('2026-06-01')
        ->and($june?->status)->toBe('in_review')
        ->and($june?->reviewForStage(MeasurementWorkflow::STAGE_ENGINEERING)?->status)->toBe('pending')
        ->and($scenario['may']->payments()->pluck('amount')->all())->toBe(['100000.00']);
})->group('mysql');

it('serializes the Finalization and an Engineering approval of the same operation on the Operation lock on MySQL', function () {
    $scenario = measurementLockOrderScenario();
    $workflow = app(MeasurementWorkflow::class);
    $payment = $workflow->registerPayment($scenario['may']->fresh(), $scenario['actor'], [
        'plan_set_id' => $scenario['planSet']->id,
        'pay_date' => '2026-05-20',
        'amount' => '100000.00',
    ]);
    $workflow->approve($scenario['may']->fresh(), $scenario['actor']);
    $workflow->attachReceipt($payment->fresh(), $scenario['actor'], MeasurementReceiptEvidenceScenario::file());
    MeasurementReceiptEvidenceScenario::approveCurrentReceipt($payment, $scenario['actor']);
    $june = Scenario::measurement($scenario, '2026-06');
    $markers = measurementLockOrderMarkers('lock-order-finalization-engineering');

    expect($scenario['may']->fresh()->status)->toBe('approved');

    $results = Concurrency::driver('process')->run([
        measurementLockOrderTask([
            'action' => 'finalize',
            'actor_id' => $scenario['actor']->id,
            'measurement_id' => $scenario['may']->id,
            'revision' => (int) $scenario['may']->fresh()->workflow_revision,
            'storage_root' => Storage::disk('local')->path(''),
        ] + measurementLockOrderHolder($markers)),
        measurementLockOrderTask([
            'action' => 'approve_engineering',
            'actor_id' => $scenario['actor']->id,
            'measurement_id' => $june->id,
            'revision' => (int) $june->workflow_revision,
            'plan_set_id' => $scenario['planSet']->id,
            'percent' => 5,
            'storage_root' => Storage::disk('local')->path(''),
        ] + measurementLockOrderPeer($markers)),
    ]);
    measurementLockOrderForgetMarkers($markers);

    expect(collect($results)->pluck('message')->filter()->values()->all())->toBe([])
        ->and(collect($results)->pluck('success')->all())->toBe([true, true])
        ->and($results[0]['operation_locks'])->toBe(1)
        ->and($results[0]['barrier_met'])->toBeFalse()
        ->and($results[1]['operation_lock_wait_ms'])->toBeGreaterThan(1000)
        ->and($scenario['may']->fresh()->status)->toBe('finalized')
        ->and($june->fresh()->current_stage)->toBe(2)
        ->and($june->fresh()->engineering_snapshot['plan_sets'][0]['realized_cumulative_percent'])->toBe('15.00');
})->group('mysql');
