<?php

use App\Enums\MeasurementPlanRevisionCategory;
use App\Enums\MeasurementPlanVersionStatus;
use App\Exceptions\MeasurementWorkflowException;
use App\Models\Construction;
use App\Models\Measurement;
use App\Models\MeasurementAsset;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Models\MeasurementPlanVersion;
use App\Models\Operation;
use App\Models\User;
use App\Services\MeasurementPlanVersionService;
use App\Services\MeasurementWorkflow;
use App\Services\OperationContextVisibilityService;
use App\Services\OperationLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\CommittedRowsSweeper;
use Tests\Support\MeasurementPhysicalProgressScenario as Scenario;
use Tests\Support\MeasurementPlanVersionFixture;

/*
 * A porta de escrita das versões do plano trava a Operation como primeira
 * instrução da transação -- como o envio de medição, a aprovação da Engenharia
 * e a troca de CNPJ da obra. No MySQL em REPEATABLE READ a fotografia da
 * transação nasce na primeira leitura comum, depois desse lock: quem esperou
 * enxerga o que o outro acabou de commitar (o rascunho aberto, a versão
 * ativada, a medição prevista ocupada) e recusa em vez de decidir sobre o
 * estado anterior. O SQLite serializa escritores e nunca mostra isso. As
 * uniques do banco (uma vigente por plano, uma ocupação por linhagem) são a
 * última barreira, provadas aqui com escritas que pulam o lock.
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
    $this->travelTo(planVersionRaceNow());
});

afterEach(function () {
    if (DB::getDriverName() !== 'mysql' || ! isset($this->committedRows)) {
        return;
    }

    $this->committedRows->sweep();

    expect($this->committedRows->leftovers())->toBe([]);
});

/**
 * 12/08/2026, 10h em Brasília: uma versão ativada agora vale desde 08/2026. Os
 * processos filhos não herdam o `travelTo` do teste e param o relógio no
 * mesmo instante.
 */
function planVersionRaceNow(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-08-12 13:00:00', 'UTC');
}

/**
 * Obra com uma medição prevista por mês (10% cada) e a V1 vigente desde o
 * primeiro mês, como {@see Scenario::plan()}. A mesma pessoa envia, aprova e
 * replaneja: o serviço de versões exige `operations.update` de quem participa
 * da operação.
 *
 * @param  list<string>  $months
 * @return array{actor: User, operation: Operation, planSet: MeasurementPlanSet, lines: array<string, MeasurementPlanLine>, construction: Construction|null}
 */
function planVersionRacePlan(array $months = ['2026-05', '2026-06', '2026-07', '2026-08', '2026-09'], bool $withConstruction = false): array
{
    config()->set('filesystems.private_disk', 'local');

    $actor = User::factory()->withTwoFactor()->create();
    $actor->givePermissionTo([
        'operations.view', 'operations.update',
        'measurements.view', 'measurements.create', 'measurements.update', 'measurements.review',
        'measurements.pay', 'measurements.receipts', 'measurements.finalize',
    ]);

    $operation = Operation::factory()->create([
        'status' => 'active',
        'assigned_user_id' => $actor->id,
        'responsible_user_id' => $actor->id,
        'stage2_reviewer_user_id' => $actor->id,
        'stage3_reviewer_user_id' => $actor->id,
        'payment_manager_user_id' => $actor->id,
        'payment_receipt_uploader_user_id' => $actor->id,
        'payment_finalizer_user_id' => $actor->id,
    ]);

    $construction = $withConstruction
        ? Construction::factory()->create([
            'emission_id' => $operation->emission_id,
            'development_name' => 'Residencial Aurora',
            'development_cnpj' => '12345678000199',
        ])
        : null;

    $planSet = MeasurementPlanSet::factory()->default()->withConstructionFund('1000000.00')->create([
        'operation_id' => $operation->id,
        'construction_id' => $construction?->id,
        'initial_incurred_amount' => '0.00',
    ]);

    $lines = [];

    foreach (array_values($months) as $index => $month) {
        $lines[$month] = MeasurementPlanLine::factory()->create([
            'operation_id' => $operation->id,
            'plan_set_id' => $planSet->id,
            'sequence_number' => $index + 1,
            'measurement_date' => $month.'-01',
            'planned_monthly_percent' => 10,
            'planned_cumulative_percent' => 10 * ($index + 1),
            'initial_realized_cumulative_percent' => 0,
            'realized_monthly_percent' => 0,
            'realized_cumulative_percent' => 0,
        ]);
    }

    MeasurementPlanVersionFixture::activate($planSet);

    return compact('actor', 'operation', 'planSet', 'lines', 'construction');
}

function planVersionRaceActive(MeasurementPlanSet $planSet): MeasurementPlanVersion
{
    return MeasurementPlanVersion::query()->where('plan_set_id', $planSet->id)->active()->sole();
}

/**
 * Rascunho da revisão aberto antes da corrida, copiado da vigente.
 *
 * @param  array{actor: User, planSet: MeasurementPlanSet}  $scenario
 */
function planVersionRaceRevision(array $scenario): MeasurementPlanVersion
{
    return app(MeasurementPlanVersionService::class)->createRevision($scenario['planSet'], $scenario['actor'], [
        'revision_category' => MeasurementPlanRevisionCategory::Schedule->value,
        'revision_reason' => 'Fundação atrasou dois meses; cronograma refeito com a construtora.',
    ], (int) planVersionRaceActive($scenario['planSet'])->id);
}

/**
 * O que todo processo filho recebe: quem age, o relógio e a raiz do disco do
 * teste (onde estão os arquivos de medição e os marcadores).
 *
 * @param  array{actor: User}  $scenario
 * @return array{actor_id: int, now: string, storage_root: string}
 */
function planVersionRaceBase(array $scenario): array
{
    return [
        'actor_id' => (int) $scenario['actor']->id,
        'now' => planVersionRaceNow()->toDateTimeString(),
        'storage_root' => Storage::disk('local')->path(''),
    ];
}

/**
 * Uma escrita do plano num processo próprio -- outra aba, outra pessoa. Quem
 * recebe `lock_marker` segura a transação logo depois da primeira instrução
 * que casar com `hold_after` (por padrão, o lock da Operation) -- ou da N-ésima,
 * com `hold_after_occurrence` --: por 750 ms ou, com `hold_until_marker`, até
 * esse arquivo aparecer. Quem recebe `wait_for_marker` só começa depois disso,
 * e `done_marker` avisa que a ação terminou. `lock_wait_ms` é quanto a própria
 * transação esperou pelo lock da Operation; `elapsed_ms`, quanto a ação levou
 * (inclui esperas que terminam em erro, que não chegam ao `DB::listen`).
 *
 * Tudo o que o filho executa está aqui dentro: ele só carrega as classes da
 * aplicação e as de `Tests\`, não as funções deste arquivo.
 *
 * @param  array<string, mixed>  $instruction
 */
function planVersionRaceTask(array $instruction): Closure
{
    return static function () use ($instruction): array {
        Carbon::setTestNow(CarbonImmutable::parse($instruction['now'], 'UTC'));
        config()->set('filesystems.private_disk', 'local');
        config()->set('filesystems.disks.local.root', $instruction['storage_root']);
        Storage::forgetDisk('local');
        Notification::fake();
        $observed = ['lock_wait_ms' => null, 'held' => false, 'hold_met' => null];
        $holdAfter = $instruction['hold_after'] ?? ['`operations`', 'for update'];
        $matches = 0;

        DB::listen(static function (QueryExecuted $query) use ($instruction, $holdAfter, &$observed, &$matches): void {
            $sql = strtolower($query->sql);

            if ($observed['lock_wait_ms'] === null && str_contains($sql, '`operations`') && str_contains($sql, 'for update')) {
                $observed['lock_wait_ms'] = $query->time;
            }

            if ($observed['held'] || ! isset($instruction['lock_marker'])) {
                return;
            }

            foreach ($holdAfter as $fragment) {
                if (! str_contains($sql, $fragment)) {
                    return;
                }
            }

            if (++$matches < ($instruction['hold_after_occurrence'] ?? 1)) {
                return;
            }

            $observed['held'] = true;
            file_put_contents($instruction['lock_marker'], 'locked');

            if (! isset($instruction['hold_until_marker'])) {
                usleep(750_000);

                return;
            }

            $deadline = microtime(true) + 10;

            while (! is_file($instruction['hold_until_marker']) && microtime(true) < $deadline) {
                usleep(10_000);
            }

            $observed['hold_met'] = is_file($instruction['hold_until_marker']);

            if (! $observed['hold_met']) {
                throw new RuntimeException('O processo concorrente não terminou enquanto este segurava o lock.');
            }
        });

        $started = hrtime(true);

        try {
            if (isset($instruction['wait_for_marker'])) {
                $deadline = microtime(true) + 10;

                while (! is_file($instruction['wait_for_marker']) && microtime(true) < $deadline) {
                    usleep(10_000);
                }

                if (! is_file($instruction['wait_for_marker'])) {
                    throw new RuntimeException('O processo concorrente não confirmou o lock.');
                }
            }

            $actor = User::query()->findOrFail($instruction['actor_id']);
            $service = app(MeasurementPlanVersionService::class);
            $started = hrtime(true);

            $action = match ($instruction['action']) {
                'create_revision' => static fn (): array => ['version_number' => (int) $service->createRevision(
                    MeasurementPlanSet::query()->findOrFail($instruction['plan_set_id']),
                    $actor,
                    ['revision_category' => MeasurementPlanRevisionCategory::Schedule->value, 'revision_reason' => $instruction['reason']],
                    $instruction['expected_active_version_id'],
                )->version_number],
                'activate' => static fn (): array => ['status' => $service->activate(
                    MeasurementPlanVersion::query()->findOrFail($instruction['plan_version_id']),
                    $actor,
                    $instruction['expected_revision'],
                )->status->value],
                'update_draft' => static fn (): array => ['revision' => (int) $service->updateDraft(
                    MeasurementPlanVersion::query()->findOrFail($instruction['plan_version_id']),
                    $actor,
                    $instruction['data'],
                    $instruction['lines'],
                    $instruction['expected_revision'],
                )->revision],
                // "Novo Plano" com cronograma: a V1 nasce em rascunho com o
                // fundo e as medições previstas informados.
                'create_plan' => static fn (): array => ['plan_set_id' => (int) $service->createPlan(
                    $instruction['operation_id'],
                    $actor,
                    $instruction['plan'],
                    ['construction_fund_amount' => $instruction['construction_fund_amount']],
                    $instruction['lines'],
                )->getKey()],
                // O formulário da operação ("Criar Operação de Obra"): o plano
                // de cada empreendimento novo nasce com a V1 em rascunho.
                'sync_development_plans' => static function () use ($instruction, $actor, $service): array {
                    $service->syncDevelopmentPlans(Operation::query()->findOrFail($instruction['operation_id']), $actor, $instruction['developments']);

                    return [];
                },
                // O envio como a tela o faz (CreateMeasurement): a Operation
                // primeiro, depois a medição, o arquivo e o início da análise,
                // tudo numa transação. Sem o lock, só a medição e o arquivo.
                'submit_measurement', 'submit_without_operation_lock' => static fn (): array => ['measurement_id' => DB::transaction(function () use ($instruction, $actor): int {
                    $locksOperation = $instruction['action'] === 'submit_measurement';

                    if ($locksOperation) {
                        app(OperationLifecycleService::class)->lockForNewMeasurement($instruction['operation_id'], $actor);
                    }

                    $measurement = Measurement::query()->create([
                        'operation_id' => $instruction['operation_id'],
                        'reference_month' => $instruction['reference_month'],
                        'status' => 'pending',
                        'current_stage' => MeasurementWorkflow::STAGE_ENGINEERING,
                        'uploaded_by' => $actor->id,
                        'uploaded_at' => now(),
                    ]);
                    $measurement->assets()->create([
                        'plan_set_id' => $instruction['plan_set_id'],
                        'plan_line_id' => $instruction['plan_line_id'],
                        'storage_path' => $instruction['storage_path'],
                        'storage_disk' => 'local',
                    ]);

                    if ($locksOperation) {
                        app(MeasurementWorkflow::class)->startReview($measurement, $actor);
                    }

                    return (int) $measurement->id;
                })],
                // Escrita crua, sem o serviço e sem o lock da Operation.
                'raw_activate' => static fn (): array => DB::transaction(function () use ($instruction, $actor): array {
                    if (isset($instruction['supersede_version_id'])) {
                        DB::table('measurement_plan_versions')->where('id', $instruction['supersede_version_id'])->update([
                            'status' => 'superseded',
                            'superseded_at' => now(),
                            'superseded_by_version_id' => $instruction['plan_version_id'],
                            'updated_at' => now(),
                        ]);
                    }

                    DB::table('measurement_plan_versions')->where('id', $instruction['plan_version_id'])->update([
                        'status' => 'active',
                        'effective_from' => $instruction['effective_from'],
                        'activated_at' => now(),
                        'activated_by' => $actor->id,
                        'updated_at' => now(),
                    ]);

                    return [];
                }),
                'change_cnpj' => static function () use ($instruction): array {
                    $construction = Construction::query()->findOrFail($instruction['construction_id']);
                    $construction->development_cnpj = $instruction['development_cnpj'];
                    $construction->save();

                    return ['development_cnpj' => (string) $construction->fresh()->development_cnpj];
                },
                'approve_engineering' => static function () use ($instruction, $actor): array {
                    $measurement = Measurement::query()->findOrFail($instruction['measurement_id']);
                    app(MeasurementWorkflow::class)->approve(
                        $measurement,
                        $actor,
                        engineeringProgress: [$instruction['plan_set_id'] => $instruction['percent']],
                        expectedStage: MeasurementWorkflow::STAGE_ENGINEERING,
                        expectedRevision: (int) $measurement->workflow_revision,
                    );

                    return [];
                },
            };

            $result = $action();

            return ['success' => true, 'exception' => null, 'message' => null, 'result' => $result, 'elapsed_ms' => (hrtime(true) - $started) / 1_000_000] + $observed;
        } catch (Throwable $exception) {
            return ['success' => false, 'exception' => $exception::class, 'message' => $exception->getMessage(), 'result' => null, 'elapsed_ms' => (hrtime(true) - $started) / 1_000_000] + $observed;
        } finally {
            if (isset($instruction['done_marker'])) {
                file_put_contents($instruction['done_marker'], 'done');
            }
        }
    };
}

/**
 * A primeira escrita segura o lock que pedir; a segunda só começa depois que
 * ele foi obtido -- a ordem de chegada é fixa, não sorte.
 *
 * @param  array<string, mixed>  $first
 * @param  array<string, mixed>  $second
 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
 */
function planVersionRaceRun(array $first, array $second): array
{
    $marker = temporaryTestFilePath('plan-version-race-lock', 'lock');
    @unlink($marker);

    $results = Concurrency::driver('process')->run([
        planVersionRaceTask(['lock_marker' => $marker] + $first),
        planVersionRaceTask(['wait_for_marker' => $marker] + $second),
    ]);
    @unlink($marker);

    return $results;
}

/**
 * Versões do plano em ordem de número, com a situação: 'V1:active', ...
 *
 * @return list<string>
 */
function planVersionRaceStatuses(MeasurementPlanSet $planSet): array
{
    return MeasurementPlanVersion::query()
        ->where('plan_set_id', $planSet->id)
        ->orderBy('version_number')
        ->get()
        ->map(fn (MeasurementPlanVersion $version): string => "V{$version->version_number}:{$version->status->value}")
        ->all();
}

/**
 * Previsto mensal e acumulado de cada competência da versão, relidos do banco.
 *
 * @return array<string, list<string>>
 */
function planVersionRaceSchedule(MeasurementPlanVersion $version): array
{
    return MeasurementPlanLine::query()
        ->where('plan_version_id', $version->id)
        ->orderBy('measurement_date')
        ->orderBy('sequence_number')
        ->get()
        ->mapWithKeys(fn (MeasurementPlanLine $line): array => [
            $line->measurement_date->format('Y-m') => [$line->planned_monthly_percent, $line->planned_cumulative_percent],
        ])
        ->all();
}

/**
 * O cronograma do rascunho como o formulário o devolve, indexado pela
 * competência para o teste mexer em uma linha.
 *
 * @return array<string, array<string, mixed>>
 */
function planVersionRaceDraftRows(MeasurementPlanVersion $draft): array
{
    return MeasurementPlanLine::query()
        ->where('plan_version_id', $draft->id)
        ->orderBy('measurement_date')
        ->get()
        ->mapWithKeys(fn (MeasurementPlanLine $line): array => [$line->measurement_date->format('Y-m') => [
            'id' => (int) $line->id,
            'sequence_number' => (int) $line->sequence_number,
            'planned_monthly_percent' => $line->planned_monthly_percent,
            'planned_cumulative_percent' => $line->planned_cumulative_percent,
            'measurement_date' => $line->measurement_date->format('Y-m'),
        ]])
        ->all();
}

/**
 * Ids das versões do plano que receberam o evento de ciclo de vida, em ordem.
 *
 * @return list<int>
 */
function planVersionRaceEvents(MeasurementPlanSet $planSet, string $event): array
{
    return Activity::query()
        ->where('log_name', 'measurements')
        ->where('event', $event)
        ->where('subject_type', MeasurementPlanVersion::class)
        ->whereIn('subject_id', MeasurementPlanVersion::query()->where('plan_set_id', $planSet->id)->select('id'))
        ->orderBy('id')
        ->pluck('subject_id')
        ->map(fn (mixed $id): int => (int) $id)
        ->all();
}

/**
 * A linha crua, coluna a coluna: a escrita recusada precisa deixá-la igual.
 *
 * @return array<string, mixed>
 */
function planVersionRaceRow(string $table, int $id): array
{
    return (array) DB::table($table)->where('id', $id)->first();
}

/**
 * Arquivo de medição já gravado no disco do teste, como o upload o deixa antes
 * do envio.
 */
function planVersionRaceAssetFile(string $name): string
{
    $path = "nimbus_docs/measurements/assets/plan-version-race-{$name}.pdf";
    Storage::disk('local')->put($path, "%PDF-1.7 corrida da versão do plano {$name}");

    return $path;
}

/**
 * @param  array{operation: Operation, planSet: MeasurementPlanSet}  $scenario
 * @return array<string, mixed>
 */
function planVersionRaceSubmission(array $scenario, MeasurementPlanLine $line, string $storagePath): array
{
    return [
        'action' => 'submit_measurement',
        'operation_id' => (int) $scenario['operation']->id,
        'plan_set_id' => (int) $scenario['planSet']->id,
        'plan_line_id' => (int) $line->id,
        'reference_month' => $line->measurement_date->toDateString(),
        'storage_path' => $storagePath,
    ] + planVersionRaceBase($scenario);
}

// ── Revisão e ativação disputando o mesmo rascunho ───────────────────────────

it('opens only one revision draft when two people revise the same plan at the same time on MySQL', function () {
    $scenario = planVersionRacePlan();
    $v1 = planVersionRaceActive($scenario['planSet']);
    $revision = [
        'action' => 'create_revision',
        'plan_set_id' => (int) $scenario['planSet']->id,
        'expected_active_version_id' => (int) $v1->id,
        'reason' => 'Fundação atrasou dois meses; cronograma refeito com a construtora.',
    ] + planVersionRaceBase($scenario);

    [$first, $second] = planVersionRaceRun($revision, $revision);

    $v2 = MeasurementPlanVersion::query()->where('plan_set_id', $scenario['planSet']->id)->where('version_number', 2)->sole();
    $lineages = fn (MeasurementPlanVersion $version): array => MeasurementPlanLine::query()
        ->where('plan_version_id', $version->id)
        ->orderBy('sequence_number')
        ->pluck('lineage_key')
        ->all();

    // A segunda revisão espera a Operation e, ao entrar, já vê o rascunho da
    // primeira: recusa em vez de abrir uma V3 paralela disputando a vigência.
    expect($first['success'])->toBeTrue()
        ->and($first['result'])->toBe(['version_number' => 2])
        ->and($second['success'])->toBeFalse()
        ->and($second['exception'])->toBe(MeasurementWorkflowException::class)
        ->and($second['message'])->toBe('O plano já tem a V2 em rascunho: conclua, ative ou cancele esse rascunho antes de abrir outra revisão.')
        ->and($second['lock_wait_ms'])->toBeGreaterThan(250)
        ->and(planVersionRaceStatuses($scenario['planSet']))->toBe(['V1:active', 'V2:draft'])
        ->and((int) $v2->previous_version_id)->toBe($v1->id)
        ->and($lineages($v2))->toBe($lineages($v1))
        ->and(planVersionRaceEvents($scenario['planSet'], 'plan_version_created'))->toBe([$v2->id]);
})->group('mysql');

it('activates a draft only once when two people activate it at the same time on MySQL', function () {
    $scenario = planVersionRacePlan();
    $v1 = planVersionRaceActive($scenario['planSet']);
    $draft = planVersionRaceRevision($scenario);
    $activation = [
        'action' => 'activate',
        'plan_version_id' => (int) $draft->id,
        'expected_revision' => (int) $draft->revision,
    ] + planVersionRaceBase($scenario);

    [$first, $second] = planVersionRaceRun($activation, $activation);

    // Duplo clique ou duas abas: a segunda ativação encontra a V2 já vigente e
    // não regrava vigência, substituição nem trilha.
    expect($first['success'])->toBeTrue()
        ->and($first['result'])->toBe(['status' => 'active'])
        ->and($second['success'])->toBeFalse()
        ->and($second['exception'])->toBe(MeasurementWorkflowException::class)
        ->and($second['message'])->toBe(sprintf(MeasurementPlanVersionService::NOT_A_DRAFT_MESSAGE, 'V2', 'vigente'))
        ->and($second['lock_wait_ms'])->toBeGreaterThan(250)
        ->and(planVersionRaceStatuses($scenario['planSet']))->toBe(['V1:superseded', 'V2:active'])
        ->and((int) $v1->fresh()->superseded_by_version_id)->toBe($draft->id)
        ->and($draft->fresh()->effective_from->toDateString())->toBe('2026-08-01')
        ->and(planVersionRaceEvents($scenario['planSet'], 'plan_version_superseded'))->toBe([$v1->id])
        ->and(planVersionRaceEvents($scenario['planSet'], 'plan_version_activated'))->toBe([$draft->id]);
})->group('mysql');

/**
 * Edição e ativação abertas sobre o mesmo rascunho (mesmo contador). Quem
 * chega depois do lock vê o que o outro gravou: a edição encontra a versão já
 * vigente, a ativação encontra o rascunho alterado. Nunca as duas.
 *
 * @param  array{actor: User, planSet: MeasurementPlanSet}  $scenario
 * @return array{edit: array<string, mixed>, activation: array<string, mixed>}
 */
function planVersionRaceEditAndActivation(array $scenario, MeasurementPlanVersion $draft): array
{
    $rows = planVersionRaceDraftRows($draft);
    $rows['2026-09']['planned_monthly_percent'] = '25.00';
    $rows['2026-09']['planned_cumulative_percent'] = '55.00';
    $base = ['plan_version_id' => (int) $draft->id, 'expected_revision' => (int) $draft->revision] + planVersionRaceBase($scenario);

    return [
        'edit' => ['action' => 'update_draft', 'data' => ['construction_fund_amount' => '1150000.00'], 'lines' => array_values($rows)] + $base,
        'activation' => ['action' => 'activate'] + $base,
    ];
}

it('refuses the draft edit that arrives after the activation, keeping the schedule the activation validated on MySQL', function () {
    $scenario = planVersionRacePlan();
    $draft = planVersionRaceRevision($scenario);
    ['edit' => $edit, 'activation' => $activation] = planVersionRaceEditAndActivation($scenario, $draft);

    [$activated, $edited] = planVersionRaceRun($activation, $edit);

    $v2 = $draft->fresh();

    // A V2 vigente é exatamente o rascunho conferido: a cópia da V1 com o
    // acumulado de 08/2026 em diante recalculado sobre o avanço atual (0%)
    // mais os 30% previstos de 05 a 07/2026, que ainda não foram medidos e
    // vêm antes. O previsto de 25% e o fundo novo da edição não entram por trás.
    expect($activated['success'])->toBeTrue()
        ->and($edited['success'])->toBeFalse()
        ->and($edited['exception'])->toBe(MeasurementWorkflowException::class)
        ->and($edited['message'])->toBe(sprintf(MeasurementPlanVersionService::NOT_A_DRAFT_MESSAGE, 'V2', 'vigente'))
        ->and($edited['lock_wait_ms'])->toBeGreaterThan(250)
        ->and(planVersionRaceStatuses($scenario['planSet']))->toBe(['V1:superseded', 'V2:active'])
        ->and($v2->revision)->toBe(0)
        ->and($v2->construction_fund_amount)->toBe('1000000.00')
        ->and(planVersionRaceSchedule($v2))->toBe([
            '2026-05' => ['10.00', '10.00'],
            '2026-06' => ['10.00', '20.00'],
            '2026-07' => ['10.00', '30.00'],
            '2026-08' => ['10.00', '40.00'],
            '2026-09' => ['10.00', '50.00'],
        ]);
})->group('mysql');

it('refuses the activation that arrives after a draft edit as stale, keeping V1 in force on MySQL', function () {
    $scenario = planVersionRacePlan();
    $v1 = planVersionRaceActive($scenario['planSet']);
    $v1Row = planVersionRaceRow('measurement_plan_versions', $v1->id);
    $v1Schedule = planVersionRaceSchedule($v1);
    $draft = planVersionRaceRevision($scenario);
    ['edit' => $edit, 'activation' => $activation] = planVersionRaceEditAndActivation($scenario, $draft);

    [$edited, $activated] = planVersionRaceRun($edit, $activation);

    $v2 = $draft->fresh();

    // Ativar agora poria em vigor um fundo e um cronograma que quem ativou não
    // conferiu: a ativação é recusada e a V1 continua vigente, intocada.
    expect($edited['success'])->toBeTrue()
        ->and($edited['result'])->toBe(['revision' => 1])
        ->and($activated['success'])->toBeFalse()
        ->and($activated['exception'])->toBe(MeasurementWorkflowException::class)
        ->and($activated['message'])->toBe(sprintf(MeasurementPlanVersionService::STALE_DRAFT_MESSAGE, 'V2'))
        ->and($activated['lock_wait_ms'])->toBeGreaterThan(250)
        ->and(planVersionRaceStatuses($scenario['planSet']))->toBe(['V1:active', 'V2:draft'])
        ->and(planVersionRaceRow('measurement_plan_versions', $v1->id))->toBe($v1Row)
        ->and(planVersionRaceSchedule($v1))->toBe($v1Schedule)
        ->and($v2->revision)->toBe(1)
        ->and($v2->construction_fund_amount)->toBe('1150000.00')
        ->and(planVersionRaceSchedule($v2))->toBe([
            '2026-05' => ['10.00', '10.00'],
            '2026-06' => ['10.00', '20.00'],
            '2026-07' => ['10.00', '30.00'],
            '2026-08' => ['10.00', '40.00'],
            '2026-09' => ['25.00', '55.00'],
        ])
        ->and(planVersionRaceEvents($scenario['planSet'], 'plan_version_activated'))->toBe([])
        ->and(planVersionRaceEvents($scenario['planSet'], 'plan_version_superseded'))->toBe([]);
})->group('mysql');

// ── Envio de medição durante a ativação de uma revisão ───────────────────────

it('refuses a measurement sent on a line of the version the activation just superseded on MySQL', function () {
    $scenario = planVersionRacePlan();
    $draft = planVersionRaceRevision($scenario);
    $path = planVersionRaceAssetFile('superseded-june');
    $activation = ['action' => 'activate', 'plan_version_id' => (int) $draft->id, 'expected_revision' => (int) $draft->revision] + planVersionRaceBase($scenario);

    [$activated, $submitted] = planVersionRaceRun($activation, planVersionRaceSubmission($scenario, $scenario['lines']['2026-06'], $path));

    // O formulário mostrava a linha de 06/2026 da V1; quando o envio obtém a
    // Operation, a V1 já foi substituída. A medição não nasce presa a uma
    // versão que deixou de valer -- nada fica gravado, nem o arquivo.
    expect($activated['success'])->toBeTrue()
        ->and($submitted['success'])->toBeFalse()
        ->and($submitted['exception'])->toBe(MeasurementWorkflowException::class)
        ->and($submitted['message'])->toBe(sprintf(MeasurementAsset::SUPERSEDED_PLAN_REFUSAL, 'Plano padrão'))
        ->and($submitted['lock_wait_ms'])->toBeGreaterThan(250)
        ->and(planVersionRaceStatuses($scenario['planSet']))->toBe(['V1:superseded', 'V2:active'])
        ->and(Measurement::query()->where('operation_id', $scenario['operation']->id)->exists())->toBeFalse()
        ->and(MeasurementAsset::query()->where('plan_set_id', $scenario['planSet']->id)->exists())->toBeFalse()
        ->and(Storage::disk('local')->exists($path))->toBeFalse();
})->group('mysql');

it('keeps on V1 a measurement sent before the activation, which still activates V2 from the next competence on MySQL', function () {
    $scenario = planVersionRacePlan();
    $v1 = planVersionRaceActive($scenario['planSet']);
    $draft = planVersionRaceRevision($scenario);
    $june = $scenario['lines']['2026-06'];
    $activation = ['action' => 'activate', 'plan_version_id' => (int) $draft->id, 'expected_revision' => (int) $draft->revision] + planVersionRaceBase($scenario);

    [$submitted, $activated] = planVersionRaceRun(planVersionRaceSubmission($scenario, $june, planVersionRaceAssetFile('kept-june')), $activation);

    $asset = MeasurementAsset::query()->where('plan_set_id', $scenario['planSet']->id)->sole();
    $assetCreated = Activity::query()
        ->where('log_name', 'measurement_assets')
        ->where('description', 'measurement_asset_created')
        ->where('subject_id', $asset->id)
        ->sole();
    $superseded = Activity::query()->where('event', 'plan_version_superseded')->where('subject_id', $v1->id)->sole();

    // 06/2026 é anterior à vigência da V2 (08/2026): a medição de pé não
    // impede a ativação, e continua na versão em que foi enviada -- capturada
    // enquanto a V1 ainda valia, antes da substituição.
    expect($submitted['success'])->toBeTrue()
        ->and($activated['success'])->toBeTrue()
        ->and($activated['result'])->toBe(['status' => 'active'])
        ->and($activated['lock_wait_ms'])->toBeGreaterThan(250)
        ->and((int) $asset->measurement_id)->toBe($submitted['result']['measurement_id'])
        ->and((int) $asset->plan_version_id)->toBe($v1->id)
        ->and((int) $asset->plan_line_id)->toBe($june->id)
        ->and($asset->line_claim_key)->toBe($june->lineage_key)
        ->and(Measurement::query()->findOrFail($submitted['result']['measurement_id'])->status)->toBe('in_review')
        ->and(planVersionRaceStatuses($scenario['planSet']))->toBe(['V1:superseded', 'V2:active'])
        ->and($draft->fresh()->effective_from->toDateString())->toBe('2026-08-01')
        ->and($assetCreated->id)->toBeLessThan($superseded->id);
})->group('mysql');

// ── O marco da ativação: medição enviada antes ou depois do plano valer ──────

/**
 * A Torre B entra na operação do cenário com a V1 em rascunho e cronograma de
 * 08 e 09/2026: ativada agora, passa a valer desde 08/2026 ao lado do plano
 * padrão.
 *
 * @param  array{actor: User, operation: Operation}  $scenario
 */
function planVersionRaceSecondPlan(array $scenario): MeasurementPlanVersion
{
    return app(MeasurementPlanVersionService::class)->createPlan($scenario['operation'], $scenario['actor'], [
        'name' => 'Torre B',
        'is_default' => false,
        'initial_incurred_amount' => '0.00',
    ], ['construction_fund_amount' => '500000.00'], [
        // A Torre B prevê maio e junho, as competências das medições da
        // corrida: a Engenharia só cobra o arquivo do plano que prevê medição
        // na competência.
        ['sequence_number' => 1, 'planned_monthly_percent' => '10.00', 'planned_cumulative_percent' => '10.00', 'measurement_date' => '2026-05'],
        ['sequence_number' => 2, 'planned_monthly_percent' => '10.00', 'planned_cumulative_percent' => '20.00', 'measurement_date' => '2026-06'],
    ])->draftVersion()->sole();
}

/**
 * O desfecho de uma escrita da corrida, com a exceção e a mensagem por
 * extenso: um deadlock (1213) ou uma espera esgotada (1205) aparece aqui.
 *
 * @param  array<string, mixed>  $result
 * @return array{success: bool, exception: string|null, message: string|null, hold_met: bool|null}
 */
function planVersionRaceOutcome(array $result): array
{
    return [
        'success' => $result['success'],
        'exception' => $result['exception'],
        'message' => $result['message'],
        'hold_met' => $result['hold_met'],
    ];
}

/**
 * A Engenharia aprova o plano padrão da medição com 10%: os erros da recusa,
 * ou [] quando aprova.
 *
 * @param  array{actor: User, operation: Operation, planSet: MeasurementPlanSet, lines: array<string, MeasurementPlanLine>}  $scenario
 * @return array<string, list<string>>
 */
function planVersionRaceEngineeringErrors(array $scenario, Measurement $measurement): array
{
    try {
        Scenario::approveEngineering($scenario, $measurement, 10);
    } catch (ValidationException $refusal) {
        return $refusal->errors();
    }

    return [];
}

it('records at activation the last measurement of the operation, and a submission that waited for the activation comes after it on MySQL', function () {
    $scenario = planVersionRacePlan();
    $sentBefore = Scenario::measurement($scenario, '2026-05');
    $sentElsewhere = Scenario::measurement(planVersionRacePlan(), '2026-05');
    $draft = planVersionRaceSecondPlan($scenario);
    $activation = ['action' => 'activate', 'plan_version_id' => (int) $draft->id, 'expected_revision' => (int) $draft->revision] + planVersionRaceBase($scenario);

    [$activated, $submitted] = planVersionRaceRun(
        $activation,
        planVersionRaceSubmission($scenario, $scenario['lines']['2026-06'], planVersionRaceAssetFile('after-activation-june')),
    );

    expect([planVersionRaceOutcome($activated), planVersionRaceOutcome($submitted)])->toBe([
        ['success' => true, 'exception' => null, 'message' => null, 'hold_met' => null],
        ['success' => true, 'exception' => null, 'message' => null, 'hold_met' => null],
    ]);

    $firstVersion = $draft->fresh();
    $sentAfter = Measurement::query()->findOrFail($submitted['result']['measurement_id']);

    // O marco é a última medição DESTA operação no instante em que a ativação
    // obteve a Operation -- a de outra operação, mais nova, não conta --, e o
    // envio que esperou o lock grava uma medição depois dele.
    expect($submitted['lock_wait_ms'])->toBeGreaterThan(250)
        ->and($firstVersion->status)->toBe(MeasurementPlanVersionStatus::Active)
        ->and($firstVersion->last_measurement_id_at_activation)->toBe($sentBefore->id)
        ->and($sentElsewhere->id)->toBeGreaterThan($sentBefore->id)
        ->and($sentAfter->id)->toBeGreaterThan($firstVersion->last_measurement_id_at_activation);

    // É o marco que separa as duas na Engenharia: a enviada antes não precisa
    // cobrir a Torre B, que ainda não valia; a enviada depois precisa.
    expect(planVersionRaceEngineeringErrors($scenario, $sentBefore))->toBe([])
        ->and(collect($sentBefore->fresh()->engineering_snapshot['plan_sets'])->pluck('plan_set_id')->all())->toBe([$scenario['planSet']->id])
        ->and(planVersionRaceEngineeringErrors($scenario, $sentAfter))->toBe([
            'assets.coverage' => ['Envie exatamente um arquivo para cada empreendimento da operação.'],
            "assets.{$draft->plan_set_id}" => ['Envie o arquivo da medição para Torre B.'],
        ])
        ->and($sentAfter->fresh()->hasApprovedEngineering())->toBeFalse();
})->group('mysql');

it('counts in the watermark the submission the activation waited for, which then does not need to cover the new plan on MySQL', function () {
    $scenario = planVersionRacePlan();
    $draft = planVersionRaceSecondPlan($scenario);
    $activation = ['action' => 'activate', 'plan_version_id' => (int) $draft->id, 'expected_revision' => (int) $draft->revision] + planVersionRaceBase($scenario);

    [$submitted, $activated] = planVersionRaceRun(
        planVersionRaceSubmission($scenario, $scenario['lines']['2026-05'], planVersionRaceAssetFile('before-activation-may')),
        $activation,
    );

    expect([planVersionRaceOutcome($submitted), planVersionRaceOutcome($activated)])->toBe([
        ['success' => true, 'exception' => null, 'message' => null, 'hold_met' => null],
        ['success' => true, 'exception' => null, 'message' => null, 'hold_met' => null],
    ]);

    $firstVersion = $draft->fresh();
    $sentBefore = Measurement::query()->findOrFail($submitted['result']['measurement_id']);

    // A ativação esperou a Operation e a fotografia dela nasceu depois do lock:
    // enxerga a medição que o envio acabou de commitar. O marco é essa medição,
    // e a Engenharia não cobra dela a Torre B, que só passou a valer depois.
    expect($activated['lock_wait_ms'])->toBeGreaterThan(250)
        ->and($firstVersion->status)->toBe(MeasurementPlanVersionStatus::Active)
        ->and($firstVersion->last_measurement_id_at_activation)->toBe($sentBefore->id)
        ->and(planVersionRaceEngineeringErrors($scenario, $sentBefore))->toBe([])
        ->and(collect($sentBefore->fresh()->engineering_snapshot['plan_sets'])->pluck('plan_set_id')->all())->toBe([$scenario['planSet']->id]);
})->group('mysql');

// ── Duas medições disputando a mesma medição prevista ────────────────────────

it('lets only one of two concurrent submissions claim the same planned measurement on MySQL', function () {
    $scenario = planVersionRacePlan();
    $june = $scenario['lines']['2026-06'];
    $firstPath = planVersionRaceAssetFile('first-june');
    $secondPath = planVersionRaceAssetFile('second-june');

    [$winner, $loser] = planVersionRaceRun(
        planVersionRaceSubmission($scenario, $june, $firstPath),
        planVersionRaceSubmission($scenario, $june, $secondPath),
    );

    // A segunda aba entra depois da primeira e a conferência do modelo já vê a
    // linhagem ocupada: recusa legível, antes de a unique do banco precisar agir.
    expect($winner['success'])->toBeTrue()
        ->and($loser['success'])->toBeFalse()
        ->and($loser['exception'])->toBe(MeasurementWorkflowException::class)
        ->and($loser['message'])->toBe(sprintf(MeasurementAsset::LINE_ALREADY_CLAIMED_REFUSAL, '02', '06/2026', 'Plano padrão', $winner['result']['measurement_id']))
        ->and($loser['lock_wait_ms'])->toBeGreaterThan(250)
        ->and(Measurement::query()->where('operation_id', $scenario['operation']->id)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all())
        ->toBe([$winner['result']['measurement_id']])
        ->and(MeasurementAsset::query()->where('line_claim_key', $june->lineage_key)->pluck('measurement_id')->map(fn (mixed $id): int => (int) $id)->all())
        ->toBe([$winner['result']['measurement_id']])
        ->and(Storage::disk('local')->exists($firstPath))->toBeTrue()
        ->and(Storage::disk('local')->exists($secondPath))->toBeFalse();
})->group('mysql');

it('refuses at the database a second claim of the same planned measurement written without the Operation lock on MySQL', function () {
    $scenario = planVersionRacePlan();
    $june = $scenario['lines']['2026-06'];
    $first = ['action' => 'submit_without_operation_lock', 'hold_after' => ['insert into `measurement_assets`']]
        + planVersionRaceSubmission($scenario, $june, planVersionRaceAssetFile('unlocked-first-june'));
    $second = ['action' => 'submit_without_operation_lock']
        + planVersionRaceSubmission($scenario, $june, planVersionRaceAssetFile('unlocked-second-june'));

    [$winner, $loser] = planVersionRaceRun($first, $second);

    // Sem a Operation, a conferência do modelo lê a linhagem livre (a primeira
    // ocupação ainda não commitou); a inserção espera a unique e, quando a
    // primeira commita, é recusada pelo banco. Uma linhagem, uma medição.
    expect($winner['success'])->toBeTrue()
        ->and($loser['success'])->toBeFalse()
        ->and($loser['exception'])->toBe(UniqueConstraintViolationException::class)
        ->and($loser['message'])->toContain('ma_line_claim_unique')
        ->and($loser['lock_wait_ms'])->toBeNull()
        ->and($loser['elapsed_ms'])->toBeGreaterThan(250)
        ->and(Measurement::query()->where('operation_id', $scenario['operation']->id)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all())
        ->toBe([$winner['result']['measurement_id']])
        ->and(MeasurementAsset::query()->where('line_claim_key', $june->lineage_key)->pluck('measurement_id')->map(fn (mixed $id): int => (int) $id)->all())
        ->toBe([$winner['result']['measurement_id']]);
})->group('mysql');

// ── O banco como última barreira da vigência ─────────────────────────────────

it('refuses at the database a second active version written concurrently without the service on MySQL', function () {
    $scenario = planVersionRacePlan();
    $v1 = planVersionRaceActive($scenario['planSet']);
    $v2 = planVersionRaceRevision($scenario);
    app(MeasurementPlanVersionService::class)->cancel($v2, $scenario['actor'], 'Revisão aberta por engano.', (int) $v2->revision);
    $v3 = planVersionRaceRevision($scenario);
    $v2Row = planVersionRaceRow('measurement_plan_versions', $v2->id);
    $base = ['action' => 'raw_activate', 'effective_from' => '2026-08-01'] + planVersionRaceBase($scenario);

    [$first, $second] = planVersionRaceRun(
        ['plan_version_id' => (int) $v3->id, 'supersede_version_id' => (int) $v1->id, 'hold_after' => ['update `measurement_plan_versions`', '`activated_at`']] + $base,
        ['plan_version_id' => (int) $v2->id] + $base,
    );

    // Duas escritas que pulam o serviço (script, correção manual) tentam pôr
    // versões diferentes em vigor: a segunda espera a unique da coluna gerada
    // e é recusada quando a primeira commita. Uma vigente por plano.
    expect($first['success'])->toBeTrue()
        ->and($second['success'])->toBeFalse()
        ->and($second['exception'])->toBe(UniqueConstraintViolationException::class)
        ->and($second['message'])->toContain('mpv_active_plan_set_unique')
        ->and($second['elapsed_ms'])->toBeGreaterThan(250)
        ->and(planVersionRaceStatuses($scenario['planSet']))->toBe(['V1:superseded', 'V2:cancelled', 'V3:active'])
        ->and(planVersionRaceRow('measurement_plan_versions', $v2->id))->toBe($v2Row);
})->group('mysql');

// ── Operações diferentes não se esperam ──────────────────────────────────────

it('revises the plan of another operation while an activation holds its own Operation on MySQL', function () {
    $first = planVersionRacePlan();
    $second = planVersionRacePlan();
    $draft = planVersionRaceRevision($first);
    $lockMarker = temporaryTestFilePath('plan-version-race-first-operation', 'lock');
    $doneMarker = temporaryTestFilePath('plan-version-race-second-done', 'lock');
    @unlink($lockMarker);
    @unlink($doneMarker);

    // A ativação da primeira operação só solta o lock depois que a revisão da
    // segunda terminou: se a revisão precisasse dele, a ativação desistiria em
    // 10 s e o teste falharia.
    $results = Concurrency::driver('process')->run([
        planVersionRaceTask([
            'action' => 'activate',
            'plan_version_id' => (int) $draft->id,
            'expected_revision' => (int) $draft->revision,
            'lock_marker' => $lockMarker,
            'hold_until_marker' => $doneMarker,
        ] + planVersionRaceBase($first)),
        planVersionRaceTask([
            'action' => 'create_revision',
            'plan_set_id' => (int) $second['planSet']->id,
            'expected_active_version_id' => (int) planVersionRaceActive($second['planSet'])->id,
            'reason' => 'Reajuste do orçamento da obra aprovado pelo comitê de crédito.',
            'wait_for_marker' => $lockMarker,
            'done_marker' => $doneMarker,
        ] + planVersionRaceBase($second)),
    ]);
    @unlink($lockMarker);
    @unlink($doneMarker);

    expect(collect($results)->pluck('success')->all())->toBe([true, true])
        ->and($results[0]['hold_met'])->toBeTrue()
        ->and($results[1]['lock_wait_ms'])->toBeLessThan(250)
        ->and($results[1]['result'])->toBe(['version_number' => 2])
        ->and(planVersionRaceStatuses($first['planSet']))->toBe(['V1:superseded', 'V2:active'])
        ->and(planVersionRaceStatuses($second['planSet']))->toBe(['V1:active', 'V2:draft']);
})->group('mysql');

/**
 * Quem cria os planos nas corridas entre operações: participa delas, com
 * `operations.update` para o serviço de versões, e é administrador -- só
 * assim o formulário da operação enxerga a obra que ainda não tem plano em
 * operação nenhuma ({@see OperationContextVisibilityService}).
 */
function planVersionRacePlanner(): User
{
    $planner = User::factory()->withTwoFactor()->create();
    $planner->givePermissionTo(['operations.view', 'operations.update', 'measurements.view']);
    $planner->assignRole('admin');

    return $planner;
}

/**
 * Operação recém-criada, ainda sem plano, e a obra da emissão dela: o que o
 * "Criar Operação de Obra" e o "Novo Plano" encontram.
 *
 * @return array{operation: Operation, construction: Construction}
 */
function planVersionRaceNewOperation(User $planner, string $developmentName): array
{
    $operation = Operation::factory()->create([
        'status' => 'active',
        ...array_fill_keys(Operation::RESPONSIBILITY_FIELDS, $planner->id),
    ]);
    $construction = Construction::factory()->create([
        'emission_id' => $operation->emission_id,
        'development_name' => $developmentName,
    ]);

    return compact('operation', 'construction');
}

/**
 * "Novo Plano" da obra com o Fundo de Obra e três medições previstas de 10%,
 * de 08 a 10/2026.
 *
 * @param  array{operation: Operation, construction: Construction}  $target
 * @return array<string, mixed>
 */
function planVersionRacePlanCreation(User $planner, array $target): array
{
    return [
        'action' => 'create_plan',
        'operation_id' => (int) $target['operation']->id,
        'plan' => [
            'name' => 'Plano '.$target['construction']->development_name,
            'construction_id' => (int) $target['construction']->id,
            'is_default' => true,
            'initial_incurred_amount' => '0.00',
        ],
        'construction_fund_amount' => '1500000.00',
        'lines' => [
            ['sequence_number' => 1, 'planned_monthly_percent' => '10.00', 'planned_cumulative_percent' => '10.00', 'measurement_date' => '2026-08'],
            ['sequence_number' => 2, 'planned_monthly_percent' => '10.00', 'planned_cumulative_percent' => '20.00', 'measurement_date' => '2026-09'],
            ['sequence_number' => 3, 'planned_monthly_percent' => '10.00', 'planned_cumulative_percent' => '30.00', 'measurement_date' => '2026-10'],
        ],
    ] + planVersionRaceBase(['actor' => $planner]);
}

/**
 * A gravação do formulário da operação com o empreendimento novo e o Fundo de
 * Obra digitado.
 *
 * @param  array{operation: Operation, construction: Construction}  $target
 * @return array<string, mixed>
 */
function planVersionRaceDevelopmentSync(User $planner, array $target): array
{
    return [
        'action' => 'sync_development_plans',
        'operation_id' => (int) $target['operation']->id,
        'developments' => [[
            'construction_id' => (int) $target['construction']->id,
            'construction_fund_amount' => '2500000.00',
        ]],
    ] + planVersionRaceBase(['actor' => $planner]);
}

/**
 * Duas escritas em operações diferentes. A primeira segura a própria
 * transação logo depois da instrução de `hold_after` até a segunda terminar a
 * dela inteira: se a segunda precisasse de qualquer lock da primeira -- o
 * intervalo de um índice, inclusive --, as duas se esperariam, a primeira
 * desistiria em 10 s e o desfecho dela diria isso.
 *
 * @param  array<string, mixed>  $holder
 * @param  array<string, mixed>  $runner
 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
 */
function planVersionRaceRunAlongside(array $holder, array $runner): array
{
    $lockMarker = temporaryTestFilePath('plan-version-race-holder', 'lock');
    $doneMarker = temporaryTestFilePath('plan-version-race-runner-done', 'lock');
    @unlink($lockMarker);
    @unlink($doneMarker);

    $results = Concurrency::driver('process')->run([
        planVersionRaceTask(['lock_marker' => $lockMarker, 'hold_until_marker' => $doneMarker] + $holder),
        planVersionRaceTask(['wait_for_marker' => $lockMarker, 'done_marker' => $doneMarker] + $runner),
    ]);
    @unlink($lockMarker);
    @unlink($doneMarker);

    return $results;
}

/**
 * Os planos da operação como ficaram: identidade, as versões, o fundo e o
 * cronograma da V1, e quantas vezes a criação dela foi registrada.
 *
 * @return list<array{name: string, construction_id: int|null, is_default: bool, versions: list<string>, construction_fund_amount: string|null, schedule: array<string, list<string>>, created_events: int}>
 */
function planVersionRaceOperationPlans(Operation $operation): array
{
    return MeasurementPlanSet::query()
        ->where('operation_id', $operation->id)
        ->orderBy('id')
        ->get()
        ->map(function (MeasurementPlanSet $planSet): array {
            $firstVersion = MeasurementPlanVersion::query()->where('plan_set_id', $planSet->id)->where('version_number', 1)->sole();

            return [
                'name' => $planSet->name,
                'construction_id' => $planSet->construction_id === null ? null : (int) $planSet->construction_id,
                'is_default' => $planSet->is_default,
                'versions' => planVersionRaceStatuses($planSet),
                'construction_fund_amount' => $firstVersion->construction_fund_amount,
                'schedule' => planVersionRaceSchedule($firstVersion),
                'created_events' => count(planVersionRaceEvents($planSet, 'plan_version_created')),
            ];
        })
        ->all();
}

/*
 * Plano novo em operação nova grava no fim dos índices -- (operação, obra) dos
 * planos, versão das medições previstas --, um fim que toda operação nova
 * compartilha. Uma leitura com FOR UPDATE que não acha nada ali trava esse
 * intervalo, e o INSERT da outra operação espera por ele: no MySQL em
 * REPEATABLE READ, duas criações simultâneas fechavam um deadlock (1213) sem
 * ninguém ter tocado a operação da outra. Cada volta segura a primeira
 * criação num ponto diferente da transação dela -- inclusive logo depois da
 * leitura que era travada -- enquanto a segunda faz a dela inteira.
 */

it('creates plans with a schedule in two new operations at the same time, neither waiting for the other, on MySQL', function () {
    $planner = planVersionRacePlanner();

    foreach ([
        'com o plano gravado' => [['insert into `measurement_plan_sets`'], 1],
        'com a V1 aberta' => [['insert into `measurement_plan_versions`'], 1],
        'com o cronograma vazio da V1 lido' => [['from `measurement_plan_lines`', '`measurement_plan_lines`.`plan_version_id` = ?', 'order by `id` asc'], 1],
        'com a primeira medição prevista gravada' => [['insert into `measurement_plan_lines`'], 1],
        'com a última medição prevista gravada' => [['insert into `measurement_plan_lines`'], 3],
    ] as $holdPoint => [$holdAfter, $occurrence]) {
        $first = planVersionRaceNewOperation($planner, "Torre Aurora ({$holdPoint})");
        $second = planVersionRaceNewOperation($planner, "Torre Boreal ({$holdPoint})");

        [$held, $ran] = planVersionRaceRunAlongside(
            ['hold_after' => $holdAfter, 'hold_after_occurrence' => $occurrence] + planVersionRacePlanCreation($planner, $first),
            planVersionRacePlanCreation($planner, $second),
        );

        expect([$holdPoint => [planVersionRaceOutcome($held), planVersionRaceOutcome($ran)]])->toBe([$holdPoint => [
            ['success' => true, 'exception' => null, 'message' => null, 'hold_met' => true],
            ['success' => true, 'exception' => null, 'message' => null, 'hold_met' => null],
        ]])
            ->and($ran['lock_wait_ms'])->toBeLessThan(250);

        // Cada operação termina com o próprio plano, a V1 em rascunho, o fundo
        // e o cronograma informados -- nada da outra, nada pela metade.
        foreach ([$first, $second] as $target) {
            expect(planVersionRaceOperationPlans($target['operation']))->toBe([[
                'name' => 'Plano '.$target['construction']->development_name,
                'construction_id' => $target['construction']->id,
                'is_default' => true,
                'versions' => ['V1:draft'],
                'construction_fund_amount' => '1500000.00',
                'schedule' => [
                    '2026-08' => ['10.00', '10.00'],
                    '2026-09' => ['10.00', '20.00'],
                    '2026-10' => ['10.00', '30.00'],
                ],
                'created_events' => 1,
            ]]);
        }
    }
})->group('mysql');

it('creates the development plans of two new operations at the same time, neither waiting for the other, on MySQL', function () {
    $planner = planVersionRacePlanner();

    foreach ([
        'com o plano da obra procurado' => [['from `measurement_plan_sets`', '`construction_id` = ?', 'order by `id` asc limit 1'], 1],
        'com o plano gravado' => [['insert into `measurement_plan_sets`'], 1],
        'com a V1 aberta' => [['insert into `measurement_plan_versions`'], 1],
        'com o fundo gravado na V1' => [['update `measurement_plan_versions`'], 1],
        'com a operação renomeada' => [['update `operations`'], 1],
    ] as $holdPoint => [$holdAfter, $occurrence]) {
        $first = planVersionRaceNewOperation($planner, "Torre Aurora ({$holdPoint})");
        $second = planVersionRaceNewOperation($planner, "Torre Boreal ({$holdPoint})");

        [$held, $ran] = planVersionRaceRunAlongside(
            ['hold_after' => $holdAfter, 'hold_after_occurrence' => $occurrence] + planVersionRaceDevelopmentSync($planner, $first),
            planVersionRaceDevelopmentSync($planner, $second),
        );

        expect([$holdPoint => [planVersionRaceOutcome($held), planVersionRaceOutcome($ran)]])->toBe([$holdPoint => [
            ['success' => true, 'exception' => null, 'message' => null, 'hold_met' => true],
            ['success' => true, 'exception' => null, 'message' => null, 'hold_met' => null],
        ]])
            ->and($ran['lock_wait_ms'])->toBeLessThan(250);

        // O "Criar Operação de Obra" das duas termina inteiro: o plano do
        // empreendimento com a V1 em rascunho e o fundo digitado, e a operação
        // com o nome do empreendimento.
        foreach ([$first, $second] as $target) {
            expect(planVersionRaceOperationPlans($target['operation']))->toBe([[
                'name' => $target['construction']->development_name,
                'construction_id' => $target['construction']->id,
                'is_default' => true,
                'versions' => ['V1:draft'],
                'construction_fund_amount' => '2500000.00',
                'schedule' => [],
                'created_events' => 1,
            ]])
                ->and($target['operation']->fresh()->title)->toBe($target['construction']->development_name);
        }
    }
})->group('mysql');

// ── A identidade da obra disputando com aprovação e ativação ─────────────────

it('refuses a CNPJ change that waited for an Engineering approval of the construction on MySQL', function () {
    $scenario = planVersionRacePlan(withConstruction: true);
    $construction = $scenario['construction'];
    $measurement = Scenario::measurement($scenario, '2026-06');
    $constructionRow = planVersionRaceRow('constructions', $construction->id);

    [$approved, $changed] = planVersionRaceRun(
        ['action' => 'approve_engineering', 'measurement_id' => (int) $measurement->id, 'plan_set_id' => (int) $scenario['planSet']->id, 'percent' => 10] + planVersionRaceBase($scenario),
        ['action' => 'change_cnpj', 'construction_id' => (int) $construction->id, 'development_cnpj' => '98765432000110'] + planVersionRaceBase($scenario),
    );

    // A troca espera a Operation e confere a guarda de novo depois do lock: a
    // Engenharia que acabou de aprovar congelou o CNPJ no snapshot, e gravar o
    // novo por cima deixaria o snapshot falando de outra obra.
    expect($approved['success'])->toBeTrue()
        ->and($changed['success'])->toBeFalse()
        ->and($changed['exception'])->toBe(MeasurementWorkflowException::class)
        ->and($changed['message'])->toBe('A identidade de um empreendimento aprovado pela Engenharia está bloqueada.')
        ->and($changed['lock_wait_ms'])->toBeGreaterThan(250)
        ->and(planVersionRaceRow('constructions', $construction->id))->toBe($constructionRow)
        ->and($measurement->fresh()->engineering_snapshot['plan_sets'][0]['construction_cnpj'])->toBe('12345678000199');
})->group('mysql');

it('applies a CNPJ change only after the activation that holds the Operation commits on MySQL', function () {
    $scenario = planVersionRacePlan(withConstruction: true);
    $construction = $scenario['construction'];
    $draft = planVersionRaceRevision($scenario);

    [$activated, $changed] = planVersionRaceRun(
        ['action' => 'activate', 'plan_version_id' => (int) $draft->id, 'expected_revision' => (int) $draft->revision] + planVersionRaceBase($scenario),
        ['action' => 'change_cnpj', 'construction_id' => (int) $construction->id, 'development_cnpj' => '98765432000110'] + planVersionRaceBase($scenario),
    );

    // Sem Engenharia aprovada a troca é permitida, mas passa pela mesma fila
    // da Operation: grava depois da ativação, e as duas mudanças ficam.
    expect($activated['success'])->toBeTrue()
        ->and($changed['success'])->toBeTrue()
        ->and($changed['result'])->toBe(['development_cnpj' => '98765432000110'])
        ->and($changed['lock_wait_ms'])->toBeGreaterThan(250)
        ->and(planVersionRaceStatuses($scenario['planSet']))->toBe(['V1:superseded', 'V2:active'])
        ->and($construction->fresh()->development_cnpj)->toBe('98765432000110')
        ->and((int) $scenario['planSet']->fresh()->construction_id)->toBe($construction->id);
})->group('mysql');
