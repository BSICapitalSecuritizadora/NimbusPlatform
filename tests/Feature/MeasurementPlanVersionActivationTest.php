<?php

use App\DTOs\Measurements\MeasurementPhysicalProgress;
use App\Enums\MeasurementPlanRevisionCategory;
use App\Enums\MeasurementPlanVersionStatus;
use App\Enums\OperationStatus;
use App\Exceptions\MeasurementWorkflowException;
use App\Models\Measurement;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Models\MeasurementPlanVersion;
use App\Models\Operation;
use App\Models\User;
use App\Services\MeasurementPhysicalProgressService;
use App\Services\MeasurementPlanVersionService;
use App\Services\MeasurementWorkflow;
use App\Services\OperationLifecycleService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\MeasurementPhysicalProgressScenario as Scenario;
use Tests\Support\MeasurementPlanVersionFixture;

uses(RefreshDatabase::class);
pest()->group('parity');

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    Notification::fake();
    config()->set('filesystems.private_disk', 'local');
    // A vigência é o mês do calendário de negócio: o fuso fixo deixa as
    // fronteiras de mês destes testes independentes do ambiente.
    config()->set('measurements.business_timezone', 'America/Sao_Paulo');
});

/**
 * Operação em andamento e quem a replaneja: participa de todas as etapas (a
 * Engenharia destes cenários passa pelo fluxo real) e pode alterar a
 * operação -- a mesma porta que o serviço confere antes de escrever no plano.
 *
 * @return array{actor: User, operation: Operation}
 */
function planVersionActivationOperation(): array
{
    $actor = User::factory()->withTwoFactor()->create();
    $actor->givePermissionTo([
        'operations.view', 'operations.update',
        'measurements.view', 'measurements.create', 'measurements.update', 'measurements.review',
        'measurements.pay', 'measurements.receipts', 'measurements.finalize',
    ]);

    $operation = Operation::factory()->create([
        'status' => OperationStatus::Active,
        'assigned_user_id' => $actor->id,
        'responsible_user_id' => $actor->id,
        'stage2_reviewer_user_id' => $actor->id,
        'stage3_reviewer_user_id' => $actor->id,
        'payment_manager_user_id' => $actor->id,
        'payment_receipt_uploader_user_id' => $actor->id,
        'payment_finalizer_user_id' => $actor->id,
    ]);

    return ['actor' => $actor, 'operation' => $operation];
}

/**
 * Uma medição prevista por mês, todas com o mesmo previsto mensal.
 *
 * @return array<string, string> previsto mensal por competência ('Y-m')
 */
function planVersionActivationMonthly(string $firstMonth, int $count, string $percent): array
{
    $first = CarbonImmutable::parse($firstMonth.'-01');
    $months = [];

    for ($index = 0; $index < $count; $index++) {
        $months[$first->addMonthsNoOverflow($index)->format('Y-m')] = $percent;
    }

    return $months;
}

/**
 * Plano criado pela porta de escrita, com a V1 em rascunho e uma medição
 * prevista por competência. O acumulado vai digitado como soma corrida desde
 * zero, como a planilha da obra costuma chegar: é o que a ativação recalcula a
 * partir do avanço físico atual.
 *
 * @param  array<string, string>  $monthlyByMonth  previsto mensal por competência ('Y-m')
 */
function planVersionActivationPlan(
    Operation $operation,
    User $actor,
    array $monthlyByMonth,
    string $initialPercent = '0.00',
    ?string $referenceDate = null,
    string $name = 'Torre A',
    bool $isDefault = true,
    ?string $fund = '1000000.00',
): MeasurementPlanSet {
    $lines = [];
    $running = 0;
    $sequence = 0;

    foreach ($monthlyByMonth as $month => $monthly) {
        $running += (int) MeasurementPhysicalProgress::basisPoints($monthly);
        $lines[] = [
            'sequence_number' => ++$sequence,
            'planned_monthly_percent' => $monthly,
            'planned_cumulative_percent' => MeasurementPhysicalProgress::decimal($running),
            'measurement_date' => $month,
        ];
    }

    return app(MeasurementPlanVersionService::class)->createPlan($operation, $actor, [
        'name' => $name,
        'is_default' => $isDefault,
        'initial_incurred_amount' => '0.00',
        'initial_physical_progress_percent' => $initialPercent,
        'initial_physical_progress_reference_date' => $referenceDate,
    ], ['construction_fund_amount' => $fund], $lines);
}

function planVersionActivationVersion(MeasurementPlanSet $planSet, int $number): MeasurementPlanVersion
{
    return MeasurementPlanVersion::query()
        ->where('plan_set_id', $planSet->id)
        ->where('version_number', $number)
        ->sole();
}

/**
 * Ativa como quem acabou de abrir a tela: com o contador atual do rascunho.
 */
function planVersionActivationActivate(MeasurementPlanVersion $version, User $actor): MeasurementPlanVersion
{
    return app(MeasurementPlanVersionService::class)->activate($version, $actor, (int) $version->fresh()->revision);
}

/**
 * Abre a revisão do plano: rascunho copiado da versão vigente.
 */
function planVersionActivationRevision(MeasurementPlanSet $planSet, User $actor, ?string $reason = 'Fundação atrasou dois meses; cronograma refeito com a construtora.'): MeasurementPlanVersion
{
    return app(MeasurementPlanVersionService::class)->createRevision($planSet, $actor, [
        'revision_category' => MeasurementPlanRevisionCategory::Schedule->value,
        'revision_reason' => $reason,
    ]);
}

/**
 * O cronograma do rascunho como o formulário o devolve, cada linha com o id,
 * indexado pela competência para o teste mexer em uma delas.
 *
 * @return array<string, array<string, mixed>>
 */
function planVersionActivationDraftRows(MeasurementPlanVersion $draft): array
{
    return MeasurementPlanLine::query()
        ->where('plan_version_id', $draft->id)
        ->orderBy('measurement_date')
        ->orderBy('sequence_number')
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
 * Grava o rascunho -- dados e, quando informado, o cronograma inteiro -- com o
 * contador atual: quem edita acabou de abrir a tela.
 *
 * @param  array<string, array<string, mixed>>|null  $rows
 * @param  array<string, mixed>  $data
 */
function planVersionActivationSaveDraft(MeasurementPlanVersion $draft, User $actor, ?array $rows, array $data = []): MeasurementPlanVersion
{
    return app(MeasurementPlanVersionService::class)->updateDraft(
        $draft,
        $actor,
        $data,
        $rows === null ? null : array_values($rows),
        (int) $draft->fresh()->revision,
    );
}

/**
 * Acumulado previsto gravado em cada competência da versão, relido do banco.
 *
 * @return array<string, string>
 */
function planVersionActivationCumulative(MeasurementPlanVersion $version): array
{
    return MeasurementPlanLine::query()
        ->where('plan_version_id', $version->id)
        ->orderBy('measurement_date')
        ->orderBy('sequence_number')
        ->get()
        ->mapWithKeys(fn (MeasurementPlanLine $line): array => [$line->measurement_date->format('Y-m') => $line->planned_cumulative_percent])
        ->all();
}

/**
 * As linhas da versão como estão no banco, coluna a coluna.
 *
 * @return list<array<string, mixed>>
 */
function planVersionActivationRawLines(MeasurementPlanVersion $version): array
{
    return DB::table('measurement_plan_lines')
        ->where('plan_version_id', $version->id)
        ->orderBy('id')
        ->get()
        ->map(fn (object $row): array => (array) $row)
        ->all();
}

/**
 * Fotografia crua do plano -- versões e linhas, coluna a coluna -- e o tamanho
 * da trilha. Ativação recusada precisa deixar tudo exatamente assim.
 *
 * @return array{versions: list<array<string, mixed>>, lines: list<array<string, mixed>>, activities: int}
 */
function planVersionActivationFootprint(MeasurementPlanSet $planSet): array
{
    return [
        'versions' => DB::table('measurement_plan_versions')
            ->where('plan_set_id', $planSet->id)
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->all(),
        'lines' => DB::table('measurement_plan_lines')
            ->where('plan_set_id', $planSet->id)
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->all(),
        'activities' => DB::table('activity_log')->count(),
    ];
}

/**
 * Erros da ativação recusada, exatamente como o domínio os devolve.
 *
 * @return array<string, list<string>>
 */
function planVersionActivationRefusal(Closure $activation): array
{
    try {
        $activation();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    test()->fail('A ativação deveria ter sido recusada.');
}

/**
 * Erros da recusa da Engenharia, exatamente como o modal os recebe.
 *
 * @return array<string, list<string>>
 */
function planVersionActivationEngineeringRefusal(Closure $approval): array
{
    try {
        $approval();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    test()->fail('A Engenharia deveria ter recusado a aprovação.');
}

/**
 * O valor com as chaves de cada objeto em ordem alfabética: o JSON do MySQL
 * não guarda a ordem das chaves de um objeto (o do SQLite guarda), e a
 * comparação estrita precisa ser a mesma nos dois bancos.
 */
function planVersionActivationSortedKeys(mixed $value): mixed
{
    if (! is_array($value)) {
        return $value;
    }

    $sorted = array_map(fn (mixed $item): mixed => planVersionActivationSortedKeys($item), $value);

    if (! array_is_list($sorted)) {
        ksort($sorted);
    }

    return $sorted;
}

/**
 * Eventos de ciclo de vida das versões do plano na trilha protegida, em ordem.
 *
 * @return Collection<int, Activity>
 */
function planVersionActivationLifecycle(MeasurementPlanSet $planSet, string $event): Collection
{
    return Activity::query()
        ->where('log_name', 'measurements')
        ->where('event', $event)
        ->where('subject_type', MeasurementPlanVersion::class)
        ->whereIn('subject_id', MeasurementPlanVersion::query()->where('plan_set_id', $planSet->id)->select('id'))
        ->orderBy('id')
        ->get();
}

/**
 * O cenário que {@see Scenario} espera, com as linhas da versão vigente por
 * competência: a medição nasce na vigente e passa pelo fluxo real.
 *
 * @return array{actor: User, operation: Operation, planSet: MeasurementPlanSet, lines: array<string, MeasurementPlanLine>}
 */
function planVersionActivationScenario(User $actor, Operation $operation, MeasurementPlanSet $planSet): array
{
    return [
        'actor' => $actor,
        'operation' => $operation,
        'planSet' => $planSet,
        'lines' => MeasurementPlanLine::query()
            ->where('plan_set_id', $planSet->id)
            ->ofActiveVersions()
            ->get()
            ->keyBy(fn (MeasurementPlanLine $line): string => $line->measurement_date->format('Y-m'))
            ->all(),
    ];
}

/**
 * Medição da competência com o arquivo de cada medição prevista informada --
 * uma por plano, da versão vigente --, aguardando a Engenharia: como o envio a
 * grava quando a operação tem mais de um plano em vigor.
 *
 * @param  list<MeasurementPlanLine>  $lines
 */
function planVersionActivationMeasurementOf(User $actor, Operation $operation, string $month, array $lines): Measurement
{
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->id,
        'reference_month' => "{$month}-01",
        'status' => 'pending',
        'current_stage' => MeasurementWorkflow::STAGE_ENGINEERING,
        'storage_path' => null,
        'filename' => null,
        'uploaded_by' => $actor->id,
    ]);

    foreach ($lines as $line) {
        $path = "nimbus_docs/measurements/assets/plan-version-activation-{$measurement->id}-{$line->plan_set_id}.pdf";
        Storage::disk('local')->put($path, "%PDF-1.7 medição {$month} do plano {$line->plan_set_id}");
        $measurement->assets()->create([
            'plan_set_id' => $line->plan_set_id,
            'plan_line_id' => $line->id,
            'storage_path' => $path,
            'storage_disk' => 'local',
        ]);
    }

    app(MeasurementWorkflow::class)->startReview($measurement->fresh(), $actor);

    return $measurement->fresh();
}

/**
 * V1 com uma medição prevista por mês de 2027 (5% cada), ativada pela porta de
 * escrita em 10/01/2027 (vigente desde janeiro); o relógio segue para o
 * instante pedido.
 *
 * @return array{actor: User, operation: Operation, planSet: MeasurementPlanSet, v1: MeasurementPlanVersion}
 */
function planVersionActivationYearPlan(string $nowUtc): array
{
    test()->travelTo(CarbonImmutable::parse('2027-01-10 12:00:00', 'UTC'));
    ['actor' => $actor, 'operation' => $operation] = planVersionActivationOperation();
    $planSet = planVersionActivationPlan($operation, $actor, planVersionActivationMonthly('2027-01', 12, '5.00'));
    $v1 = planVersionActivationActivate(planVersionActivationVersion($planSet, 1), $actor);

    test()->travelTo(CarbonImmutable::parse($nowUtc, 'UTC'));

    return ['actor' => $actor, 'operation' => $operation, 'planSet' => $planSet, 'v1' => $v1];
}

/**
 * Obra que entrou com 30% executados (referência 31/12/2026) e mediu 20% em
 * fevereiro sob a V1, com a Engenharia aprovada pelo fluxo real: avanço físico
 * atual de 50%. O relógio fica em abril de 2027.
 *
 * @return array{actor: User, operation: Operation, planSet: MeasurementPlanSet, v1: MeasurementPlanVersion}
 */
function planVersionActivationHalfBuiltPlan(): array
{
    test()->travelTo(CarbonImmutable::parse('2027-01-10 12:00:00', 'UTC'));
    ['actor' => $actor, 'operation' => $operation] = planVersionActivationOperation();
    $planSet = planVersionActivationPlan($operation, $actor, [
        '2027-01' => '10.00',
        '2027-02' => '10.00',
        '2027-03' => '10.00',
        '2027-04' => '15.00',
        '2027-05' => '15.00',
        '2027-06' => '10.00',
    ], initialPercent: '30.00', referenceDate: '2026-12-31');
    $v1 = planVersionActivationActivate(planVersionActivationVersion($planSet, 1), $actor);

    test()->travelTo(CarbonImmutable::parse('2027-03-05 12:00:00', 'UTC'));
    Scenario::measured(planVersionActivationScenario($actor, $operation, $planSet), '2027-02', 20);

    test()->travelTo(CarbonImmutable::parse('2027-04-06 12:00:00', 'UTC'));

    return ['actor' => $actor, 'operation' => $operation, 'planSet' => $planSet, 'v1' => $v1];
}

/**
 * Como cada banco nomeia a unique que recusou a escrita: o índice no MySQL, a
 * coluna gerada no SQLite.
 */
function planVersionActivationUniqueMarker(string $column, string $index): string
{
    return DB::getDriverName() === 'mysql' ? $index : "measurement_plan_versions.{$column}";
}

// ── V1: a primeira vigência ──────────────────────────────────────────────────

it('activates V1 from the business month of the activation, anchored on the current physical progress', function () {
    $this->travelTo(CarbonImmutable::parse('2027-03-15 14:00:00', 'UTC'));
    ['actor' => $actor, 'operation' => $operation] = planVersionActivationOperation();
    $planSet = planVersionActivationPlan($operation, $actor, [
        '2027-03' => '10.00',
        '2027-04' => '10.00',
        '2027-05' => '15.00',
    ], initialPercent: '25.00', referenceDate: '2027-02-28');

    planVersionActivationActivate(planVersionActivationVersion($planSet, 1), $actor);

    $v1 = planVersionActivationVersion($planSet, 1);

    // A V1 é o plano original: não substitui nada e não pede justificativa.
    expect($v1->status)->toBe(MeasurementPlanVersionStatus::Active)
        ->and($v1->effective_from->toDateString())->toBe('2027-03-01')
        ->and((int) $v1->activated_by)->toBe($actor->id)
        ->and($v1->activated_at->toDateTimeString())->toBe('2027-03-15 14:00:00')
        ->and($v1->activation_progress_percent)->toBe('25.00')
        ->and($v1->revision_reason)->toBeNull()
        ->and($v1->superseded_at)->toBeNull()
        ->and($v1->superseded_by_version_id)->toBeNull()
        ->and($v1->effectiveUntil())->toBeNull()
        // A obra entrou com 25% executados: o acumulado previsto parte dali, e
        // não da soma desde zero que veio digitada (10, 20, 35).
        ->and(planVersionActivationCumulative($v1))->toBe([
            '2027-03' => '35.00',
            '2027-04' => '45.00',
            '2027-05' => '60.00',
        ]);

    $activation = planVersionActivationLifecycle($planSet, 'plan_version_activated')->sole();

    expect((int) $activation->subject_id)->toBe($v1->id)
        ->and((int) $activation->causer_id)->toBe($actor->id)
        ->and($activation->properties['status'])->toBe('active')
        ->and($activation->properties['version_number'])->toBe(1)
        ->and($activation->properties['previous_version_id'])->toBeNull()
        ->and($activation->properties['effective_from'])->toBe('2027-03-01')
        ->and($activation->properties['construction_fund_amount'])->toBe('1000000.00')
        ->and($activation->properties['line_count'])->toBe(3)
        ->and($activation->properties['physical_progress_percent'])->toBe('25.00')
        ->and($activation->properties['remaining_physical_progress_percent'])->toBe('75.00')
        ->and($activation->properties['actor_user_id'])->toBe($actor->id)
        ->and(planVersionActivationLifecycle($planSet, 'plan_version_superseded'))->toBeEmpty();
});

it('takes the effective month from the business calendar (BRT), not from the UTC date', function (string $instantUtc, string $effectiveFrom, string $pendingBeforeEffective) {
    $this->travelTo(CarbonImmutable::parse('2027-06-20 12:00:00', 'UTC'));
    ['actor' => $actor, 'operation' => $operation] = planVersionActivationOperation();
    $planSet = planVersionActivationPlan($operation, $actor, [
        '2027-06' => '10.00',
        '2027-07' => '10.00',
    ], initialPercent: '40.00', referenceDate: '2027-05-31');

    $this->travelTo(CarbonImmutable::parse($instantUtc, 'UTC'));
    planVersionActivationActivate(planVersionActivationVersion($planSet, 1), $actor);

    $v1 = planVersionActivationVersion($planSet, 1);

    // O instante técnico continua em UTC; a competência é o dia civil em
    // Brasília. Junho não foi medido: vigente desde julho, ele fica antes da
    // vigência, mas continua a medir (atrasado) e a V1 soma o cronograma
    // inteiro sobre o avanço inicial. O acumulado gravado é o mesmo nas duas
    // vigências; muda o que a trilha registra como previsto antes dela.
    expect(MeasurementPlanVersionService::activationCompetence()->toDateString())->toBe($effectiveFrom)
        ->and($v1->effective_from->toDateString())->toBe($effectiveFrom)
        ->and($v1->activated_at->toDateTimeString())->toBe($instantUtc)
        ->and(planVersionActivationCumulative($v1))->toBe(['2027-06' => '50.00', '2027-07' => '60.00'])
        ->and(planVersionActivationLifecycle($planSet, 'plan_version_activated')->sole()->properties['pending_before_effective_percent'])
        ->toBe($pendingBeforeEffective);
})->with([
    '22:30 of 30/06 in Brasília (01:30 UTC of 01/07)' => ['2027-07-01 01:30:00', '2027-06-01', '0.00'],
    '00:30 of 01/07 in Brasília (03:30 UTC)' => ['2027-07-01 03:30:00', '2027-07-01', '10.00'],
]);

// ── Revisão: a V2 substitui a V1 ─────────────────────────────────────────────

it('supersedes V1 and activates V2 in one step, V1 valid until the eve of V2', function () {
    ['actor' => $actor, 'planSet' => $planSet, 'v1' => $v1] = planVersionActivationYearPlan('2027-07-05 12:00:00');
    $v1Lines = planVersionActivationRawLines($v1);
    $draft = planVersionActivationSaveDraft(planVersionActivationRevision($planSet, $actor), $actor, null, [
        'construction_fund_amount' => '1150000.00',
    ]);

    planVersionActivationActivate($draft, $actor);

    $v1 = $v1->fresh();
    $v2 = $draft->fresh();

    // A V1 vale até a véspera da V2: cada competência tem exatamente uma
    // versão que a governou, e o conteúdo da V1 continua como estava.
    expect($v1->status)->toBe(MeasurementPlanVersionStatus::Superseded)
        ->and($v1->superseded_at->toDateTimeString())->toBe('2027-07-05 12:00:00')
        ->and((int) $v1->superseded_by_version_id)->toBe($v2->id)
        ->and($v1->effective_from->toDateString())->toBe('2027-01-01')
        ->and($v1->activated_at->toDateTimeString())->toBe('2027-01-10 12:00:00')
        ->and($v1->effectiveUntil()->toDateString())->toBe('2027-06-30')
        ->and(planVersionActivationRawLines($v1))->toBe($v1Lines)
        ->and($v2->status)->toBe(MeasurementPlanVersionStatus::Active)
        ->and($v2->effective_from->toDateString())->toBe('2027-07-01')
        ->and($v2->activated_at->toDateTimeString())->toBe('2027-07-05 12:00:00')
        ->and((int) $v2->activated_by)->toBe($actor->id)
        ->and($v2->activation_progress_percent)->toBe('0.00')
        ->and($v2->effectiveUntil())->toBeNull()
        ->and(MeasurementPlanVersion::query()->where('plan_set_id', $planSet->id)->active()->pluck('id')->map(fn (mixed $id): int => (int) $id)->all())
        ->toBe([$v2->id]);

    $superseded = planVersionActivationLifecycle($planSet, 'plan_version_superseded')->sole();
    $activations = planVersionActivationLifecycle($planSet, 'plan_version_activated');

    expect($activations)->toHaveCount(2);

    [$firstActivation, $activation] = $activations->all();

    expect((int) $superseded->subject_id)->toBe($v1->id)
        ->and((int) $superseded->causer_id)->toBe($actor->id)
        ->and($superseded->properties['status'])->toBe('superseded')
        ->and($superseded->properties['superseded_by_version_id'])->toBe($v2->id)
        ->and($superseded->properties['superseded_by_version_number'])->toBe(2)
        ->and((int) $activation->subject_id)->toBe($v2->id)
        ->and($activation->properties['version_number'])->toBe(2)
        ->and($activation->properties['previous_version_id'])->toBe($v1->id)
        ->and($activation->properties['previous_version_number'])->toBe(1)
        ->and($activation->properties['effective_from'])->toBe('2027-07-01')
        ->and($activation->properties['revision_category'])->toBe('schedule')
        ->and($activation->properties['revision_reason'])->toBe('Fundação atrasou dois meses; cronograma refeito com a construtora.')
        ->and($activation->properties['construction_fund_amount'])->toBe('1150000.00')
        ->and($activation->properties['previous_construction_fund_amount'])->toBe('1000000.00')
        ->and($activation->properties['construction_fund_variation_amount'])->toBe('150000.00')
        ->and($activation->properties['construction_fund_variation_percent'])->toBe('15.00')
        ->and($activation->properties['line_count'])->toBe(12)
        ->and($activation->properties['physical_progress_percent'])->toBe('0.00')
        ->and($activation->properties['remaining_physical_progress_percent'])->toBe('100.00');

    // Substituição e ativação são uma unidade de trabalho: o mesmo lote na
    // trilha, a substituição antes, e um lote diferente do da ativação da V1.
    expect($superseded->batch_uuid)->not->toBeNull()
        ->and($activation->batch_uuid)->toBe($superseded->batch_uuid)
        ->and($superseded->id)->toBeLessThan($activation->id)
        ->and($firstActivation->batch_uuid)->not->toBeNull()
        ->and($firstActivation->batch_uuid)->not->toBe($activation->batch_uuid);
});

it('undoes the supersede of V1 when the activation fails after it', function () {
    ['actor' => $actor, 'planSet' => $planSet, 'v1' => $v1] = planVersionActivationYearPlan('2027-07-05 12:00:00');
    $draft = planVersionActivationRevision($planSet, $actor);
    $rows = planVersionActivationDraftRows($draft);
    // Julho passa a 10%: a ativação regrava o acumulado de julho em diante
    // (35% copiados da V1; 0% medidos + 30% de janeiro a junho por medir + 10%).
    $rows['2027-07']['planned_monthly_percent'] = '10.00';
    $draft = planVersionActivationSaveDraft($draft, $actor, $rows);
    $before = planVersionActivationFootprint($planSet);

    // A falha vem no último passo -- a trilha da ativação --, quando a V1 já
    // virou substituída, a V2 vigente e o acumulado futuro foi regravado. Tudo
    // precisa voltar junto: metade feita deixaria o plano sem versão vigente.
    $atFailure = [];
    Activity::creating(function (Activity $activity) use (&$atFailure, $v1, $draft): void {
        if ($activity->event !== 'plan_version_activated') {
            return;
        }

        $atFailure = [
            'v1' => DB::table('measurement_plan_versions')->where('id', $v1->id)->value('status'),
            'v2' => DB::table('measurement_plan_versions')->where('id', $draft->id)->value('status'),
            'july' => planVersionActivationCumulative($draft)['2027-07'],
        ];

        throw new RuntimeException('Falha simulada ao gravar a trilha da ativação.');
    });

    expect(fn () => planVersionActivationActivate($draft, $actor))
        ->toThrow(new RuntimeException('Falha simulada ao gravar a trilha da ativação.'));

    expect($atFailure)->toBe(['v1' => 'superseded', 'v2' => 'active', 'july' => '40.00'])
        ->and(planVersionActivationFootprint($planSet))->toBe($before)
        ->and($v1->fresh()->status)->toBe(MeasurementPlanVersionStatus::Active)
        ->and($draft->fresh()->status)->toBe(MeasurementPlanVersionStatus::Draft)
        ->and(planVersionActivationCumulative($draft)['2027-07'])->toBe('35.00')
        ->and(planVersionActivationLifecycle($planSet, 'plan_version_superseded'))->toBeEmpty();
});

it('leaves V1 active and the draft untouched when the activation is refused', function () {
    ['actor' => $actor, 'planSet' => $planSet, 'v1' => $v1] = planVersionActivationYearPlan('2027-07-05 12:00:00');
    $draft = planVersionActivationRevision($planSet, $actor, reason: null);
    $before = planVersionActivationFootprint($planSet);

    // A justificativa é o registro de por que o plano mudou: sem ela, nada
    // muda -- nem a vigente, nem o acumulado do rascunho, nem a trilha.
    expect(planVersionActivationRefusal(fn () => planVersionActivationActivate($draft, $actor)))
        ->toBe(['revision_reason' => ['Justifique a revisão do plano antes de ativá-la.']]);

    $draft = $draft->fresh();

    expect(planVersionActivationFootprint($planSet))->toBe($before)
        ->and($v1->fresh()->status)->toBe(MeasurementPlanVersionStatus::Active)
        ->and($draft->status)->toBe(MeasurementPlanVersionStatus::Draft)
        ->and($draft->effective_from)->toBeNull()
        ->and($draft->activated_at)->toBeNull()
        ->and($draft->activation_progress_percent)->toBeNull()
        ->and(planVersionActivationCumulative($draft)['2027-07'])->toBe('35.00')
        ->and(planVersionActivationLifecycle($planSet, 'plan_version_superseded'))->toBeEmpty()
        ->and(planVersionActivationLifecycle($planSet, 'plan_version_activated'))->toHaveCount(1);
});

// ── Regras do que pode valer ─────────────────────────────────────────────────

it('refuses a revision that leaves blank the construction fund the active version had', function () {
    ['actor' => $actor, 'planSet' => $planSet] = planVersionActivationYearPlan('2027-07-05 12:00:00');
    $draft = planVersionActivationSaveDraft(planVersionActivationRevision($planSet, $actor), $actor, null, [
        'construction_fund_amount' => null,
    ]);
    $before = planVersionActivationFootprint($planSet);

    // O Fundo de Obra é a referência financeira da medição: uma revisão não o
    // apaga em silêncio.
    expect($draft->construction_fund_amount)->toBeNull()
        ->and(planVersionActivationRefusal(fn () => planVersionActivationActivate($draft, $actor)))
        ->toBe(['construction_fund_amount' => ['Informe o Fundo de Obra da revisão: a V1 vigente tem R$ 1.000.000,00, e a revisão não pode deixar o custo previsto em branco.']]);

    expect(planVersionActivationFootprint($planSet))->toBe($before);
});

it('accepts a revision without construction fund when the active version never had one', function () {
    $this->travelTo(CarbonImmutable::parse('2027-01-10 12:00:00', 'UTC'));
    ['actor' => $actor, 'operation' => $operation] = planVersionActivationOperation();
    $planSet = planVersionActivationPlan($operation, $actor, planVersionActivationMonthly('2027-01', 12, '5.00'), fund: null);
    planVersionActivationActivate(planVersionActivationVersion($planSet, 1), $actor);

    $this->travelTo(CarbonImmutable::parse('2027-07-05 12:00:00', 'UTC'));
    $v2 = planVersionActivationActivate(planVersionActivationRevision($planSet, $actor), $actor);

    // A regra protege o fundo que existia; a revisão não inventa um.
    expect($v2->status)->toBe(MeasurementPlanVersionStatus::Active)
        ->and($v2->construction_fund_amount)->toBeNull()
        ->and(planVersionActivationVersion($planSet, 1)->construction_fund_amount)->toBeNull();
});

it('refuses to activate a version without any planned measurement', function (string $case) {
    if ($case === 'plan created without schedule') {
        $this->travelTo(CarbonImmutable::parse('2027-03-15 14:00:00', 'UTC'));
        ['actor' => $actor, 'operation' => $operation] = planVersionActivationOperation();
        $planSet = planVersionActivationPlan($operation, $actor, []);
        $version = planVersionActivationVersion($planSet, 1);
    } else {
        ['actor' => $actor, 'planSet' => $planSet] = planVersionActivationYearPlan('2027-07-05 12:00:00');
        $version = planVersionActivationSaveDraft(planVersionActivationRevision($planSet, $actor), $actor, []);
    }

    $before = planVersionActivationFootprint($planSet);

    // Versão vigente sem cronograma deixaria a obra sem medição prevista.
    expect($version->lines()->count())->toBe(0)
        ->and(planVersionActivationRefusal(fn () => planVersionActivationActivate($version, $actor)))
        ->toBe(['lines' => ['A versão precisa de ao menos uma medição prevista no cronograma.']]);

    expect(planVersionActivationFootprint($planSet))->toBe($before);
})->with([
    'plan created without schedule' => ['plan created without schedule'],
    'revision that removed every line' => ['revision that removed every line'],
]);

it('refuses to activate a schedule with a planned measurement without month', function () {
    $this->travelTo(CarbonImmutable::parse('2027-03-15 14:00:00', 'UTC'));
    ['actor' => $actor, 'operation' => $operation] = planVersionActivationOperation();
    $planSet = planVersionActivationPlan($operation, $actor, ['2027-03' => '10.00', '2027-04' => '10.00']);
    $v1 = planVersionActivationVersion($planSet, 1);
    $rows = planVersionActivationDraftRows($v1);
    $rows['2027-04']['measurement_date'] = null;
    planVersionActivationSaveDraft($v1, $actor, $rows);
    $before = planVersionActivationFootprint($planSet);

    // O rascunho aceita a linha incompleta; a vigência não: sem mês, a medição
    // prevista não pertence a competência nenhuma.
    expect(planVersionActivationRefusal(fn () => planVersionActivationActivate($v1, $actor)))
        ->toBe(['lines' => ['Informe o mês da medição prevista 02.']]);

    expect(planVersionActivationFootprint($planSet))->toBe($before);
});

it('refuses to activate a planned percent outside 0% to 100%', function (string $column, string $value) {
    $this->travelTo(CarbonImmutable::parse('2027-03-15 14:00:00', 'UTC'));
    ['actor' => $actor, 'operation' => $operation] = planVersionActivationOperation();
    $planSet = planVersionActivationPlan($operation, $actor, ['2027-03' => '10.00', '2027-04' => '10.00']);
    $v1 = planVersionActivationVersion($planSet, 1);
    // A tela e o serviço já recusam esse valor; a ativação confere de novo
    // porque é ela que transforma o cronograma em vigente.
    DB::table('measurement_plan_lines')
        ->where('plan_version_id', $v1->id)
        ->where('sequence_number', 2)
        ->update([$column => $value]);
    $before = planVersionActivationFootprint($planSet);

    expect(planVersionActivationRefusal(fn () => planVersionActivationActivate($v1, $actor)))
        ->toBe(['lines' => ['O previsto da medição prevista 02 precisa estar entre 0% e 100%.']]);

    expect(planVersionActivationFootprint($planSet))->toBe($before);
})->with([
    'monthly above 100%' => ['planned_monthly_percent', '100.01'],
    'negative monthly' => ['planned_monthly_percent', '-5.00'],
    'cumulative above 100%' => ['planned_cumulative_percent', '150.00'],
]);

// ── O passado não se reescreve ───────────────────────────────────────────────

it('refuses a revision that rewrites a competence before its effective month', function (string $change, string $message) {
    ['actor' => $actor, 'planSet' => $planSet] = planVersionActivationYearPlan('2027-06-10 12:00:00');
    $draft = planVersionActivationRevision($planSet, $actor);
    $rows = planVersionActivationDraftRows($draft);

    match ($change) {
        'monthly' => $rows['2027-03']['planned_monthly_percent'] = '8.00',
        'cumulative' => $rows['2027-03']['planned_cumulative_percent'] = '18.00',
        'month' => $rows['2027-05']['measurement_date'] = '2027-09',
        'sequence' => $rows['2027-02']['sequence_number'] = 20,
        'removed' => $rows = array_diff_key($rows, ['2027-04' => true]),
        'added' => $rows['2027-02-extra'] = [
            'sequence_number' => 13,
            'planned_monthly_percent' => '0.00',
            'planned_cumulative_percent' => '10.00',
            'measurement_date' => '2027-02',
        ],
        // Um ano digitado errado (2027-02 no lugar de 2028-02) traria de volta
        // fevereiro, que já passou: mesma linhagem da medição prevista de
        // outubro, que nunca foi do passado da V1.
        'future to past' => $rows['2027-10']['measurement_date'] = '2027-02',
    };

    planVersionActivationSaveDraft($draft, $actor, $rows);
    $before = planVersionActivationFootprint($planSet);

    // A versão vigente é o retrato fiel do que cada competência passada tinha
    // planejado: a revisão vale de junho em diante e traz o passado igual.
    expect(planVersionActivationRefusal(fn () => planVersionActivationActivate($draft, $actor)))
        ->toBe(['lines' => [$message]]);

    expect(planVersionActivationFootprint($planSet))->toBe($before);
})->with([
    'past planned monthly changed' => ['monthly', 'A medição prevista 03 (03/2027) é anterior à vigência 06/2027 e precisa continuar igual à da V1: a revisão não reescreve competências passadas.'],
    'past planned cumulative changed' => ['cumulative', 'A medição prevista 03 (03/2027) é anterior à vigência 06/2027 e precisa continuar igual à da V1: a revisão não reescreve competências passadas.'],
    'past line moved to a future month' => ['month', 'A medição prevista 05 (05/2027) é anterior à vigência 06/2027 e precisa continuar igual à da V1: a revisão não reescreve competências passadas.'],
    'past line renumbered' => ['sequence', 'A medição prevista 02 (02/2027) é anterior à vigência 06/2027 e precisa continuar igual à da V1: a revisão não reescreve competências passadas.'],
    // Tirada do rascunho, a recusa diz como trazê-la de volta.
    'past line removed' => ['removed', 'A medição prevista 04 (04/2027) é anterior à vigência 06/2027 e precisa continuar igual à da V1: a revisão não reescreve competências passadas. Se ela saiu do rascunho, inclua-a de novo com a mesma sequência e o mesmo mês.'],
    'new line before the effective month' => ['added', 'A medição prevista 13 (02/2027) é anterior à vigência 06/2027: a revisão só acrescenta medições previstas a partir da vigência.'],
    'future line moved into a past month' => ['future to past', 'A medição prevista 10 (02/2027) é anterior à vigência 06/2027: a revisão só acrescenta medições previstas a partir da vigência.'],
]);

it('accepts a revision that replans only from its effective month onwards', function () {
    ['actor' => $actor, 'planSet' => $planSet, 'v1' => $v1] = planVersionActivationYearPlan('2027-06-10 12:00:00');
    $v1Lines = planVersionActivationRawLines($v1);
    $draft = planVersionActivationRevision($planSet, $actor);
    $rows = planVersionActivationDraftRows($draft);
    $rows['2027-06']['planned_monthly_percent'] = '10.00';
    $rows['2027-07']['planned_monthly_percent'] = '10.00';
    unset($rows['2027-11']);
    $rows['2028-01'] = [
        'sequence_number' => 13,
        'planned_monthly_percent' => '5.00',
        'planned_cumulative_percent' => '0.00',
        'measurement_date' => '2028-01',
    ];
    planVersionActivationSaveDraft($draft, $actor, $rows);

    $v2 = planVersionActivationActivate($draft, $actor);

    $plannedPast = fn (MeasurementPlanVersion $version): array => MeasurementPlanLine::query()
        ->where('plan_version_id', $version->id)
        ->orderBy('measurement_date')
        ->get()
        ->filter(fn (MeasurementPlanLine $line): bool => $line->measurement_date->format('Y-m') < '2027-06')
        ->map(fn (MeasurementPlanLine $line): array => [
            $line->lineage_key,
            (int) $line->sequence_number,
            $line->measurement_date->format('Y-m'),
            $line->planned_monthly_percent,
            $line->planned_cumulative_percent,
        ])
        ->values()
        ->all();

    // Janeiro a maio são a cópia fiel da V1 (mesma linhagem e previsto); de
    // junho em diante vale o replanejado. Nada foi medido: janeiro a maio
    // continuam a medir, atrasados, e o acumulado de junho parte do avanço
    // atual (0%) mais esses 25% ainda por medir, somando depois o replanejado.
    expect($v2->effective_from->toDateString())->toBe('2027-06-01')
        ->and($plannedPast($v2))->toHaveCount(5)
        ->and($plannedPast($v2))->toBe($plannedPast($v1))
        ->and(planVersionActivationCumulative($v2))->toBe([
            '2027-01' => '5.00',
            '2027-02' => '10.00',
            '2027-03' => '15.00',
            '2027-04' => '20.00',
            '2027-05' => '25.00',
            '2027-06' => '35.00',
            '2027-07' => '45.00',
            '2027-08' => '50.00',
            '2027-09' => '55.00',
            '2027-10' => '60.00',
            '2027-12' => '65.00',
            '2028-01' => '70.00',
        ])
        ->and(planVersionActivationRawLines($v1))->toBe($v1Lines);
});

// ── Competência com medição de pé ────────────────────────────────────────────

it('refuses to replan a competence that already has a standing measurement until the next month', function (string $standing, string $progressAtActivation) {
    ['actor' => $actor, 'operation' => $operation, 'planSet' => $planSet, 'v1' => $v1] = planVersionActivationYearPlan('2027-06-10 12:00:00');
    $scenario = planVersionActivationScenario($actor, $operation, $planSet);
    $june = $standing === 'awaiting Engineering'
        ? Scenario::measurement($scenario, '2027-06')
        : Scenario::measured($scenario, '2027-06', 10);
    $draft = planVersionActivationRevision($planSet, $actor);
    $before = planVersionActivationFootprint($planSet);

    // A medição de junho foi enviada contra a V1 e continua de pé: uma revisão
    // vigente desde junho replanejaria a competência dela.
    expect(planVersionActivationRefusal(fn () => planVersionActivationActivate($draft, $actor)))
        ->toBe(['effective_from' => [sprintf(
            'A competência 06/2027 já tem medição de pé (#%d) e não pode ser replanejada. Ativada agora, a revisão valeria a partir de 06/2027; ative-a a partir de 01/07/2027.',
            $june->id,
        )]]);

    expect(planVersionActivationFootprint($planSet))->toBe($before);

    $this->travelTo(CarbonImmutable::parse('2027-07-01 03:30:00', 'UTC'));
    $v2 = planVersionActivationActivate($draft, $actor);

    // Em julho a revisão vale sem tocar junho, e a medição aberta continua na
    // versão em que nasceu.
    expect($v2->status)->toBe(MeasurementPlanVersionStatus::Active)
        ->and($v2->effective_from->toDateString())->toBe('2027-07-01')
        ->and($v2->activation_progress_percent)->toBe($progressAtActivation)
        ->and($v1->fresh()->status)->toBe(MeasurementPlanVersionStatus::Superseded)
        ->and($june->fresh()->status)->toBe('in_review')
        ->and((int) $june->assets()->sole()->plan_version_id)->toBe($v1->id);
})->with([
    'awaiting Engineering' => ['awaiting Engineering', '0.00'],
    'approved by Engineering' => ['approved by Engineering', '10.00'],
]);

it('lets a revision replan the competence of a measurement refused at Engineering', function () {
    ['actor' => $actor, 'operation' => $operation, 'planSet' => $planSet, 'v1' => $v1] = planVersionActivationYearPlan('2027-06-10 12:00:00');
    $june = Scenario::measurement(planVersionActivationScenario($actor, $operation, $planSet), '2027-06');
    app(MeasurementWorkflow::class)->reject($june->fresh(), $actor, 'Arquivo de outra obra.');
    $draft = planVersionActivationRevision($planSet, $actor);

    $v2 = planVersionActivationActivate($draft, $actor);

    // A recusa terminal solta a competência: junho volta a ser planejável, e o
    // arquivo recusado fica como histórico na V1, sem ocupar a linha.
    expect($june->fresh()->status)->toBe('rejected')
        ->and($v2->effective_from->toDateString())->toBe('2027-06-01')
        ->and($v1->fresh()->status)->toBe(MeasurementPlanVersionStatus::Superseded)
        ->and((int) $june->assets()->sole()->plan_version_id)->toBe($v1->id)
        ->and($june->assets()->sole()->line_claim_key)->toBeNull();
});

it('takes the standing competence from the line the measurement holds, not from a cancelled draft that moved it', function () {
    ['actor' => $actor, 'operation' => $operation, 'planSet' => $planSet, 'v1' => $v1] = planVersionActivationYearPlan('2027-06-10 12:00:00');
    $service = app(MeasurementPlanVersionService::class);
    // Em junho, um rascunho adia a medição prevista de agosto para novembro e
    // é abandonado: a cópia cancelada fica gravada para sempre, em novembro.
    $cancelled = planVersionActivationRevision($planSet, $actor);
    $rows = planVersionActivationDraftRows($cancelled);
    $rows['2027-08']['measurement_date'] = '2027-11';
    $cancelled = planVersionActivationSaveDraft($cancelled, $actor, $rows);
    $service->cancel($cancelled, $actor, 'A construtora manteve o cronograma de agosto.', (int) $cancelled->revision);

    $this->travelTo(CarbonImmutable::parse('2027-08-25 12:00:00', 'UTC'));
    $august = Scenario::measurement(planVersionActivationScenario($actor, $operation, $planSet), '2027-08');
    $lineage = $august->assets()->sole()->line_claim_key;
    $draft = planVersionActivationRevision($planSet, $actor);
    $before = planVersionActivationFootprint($planSet);

    // A medição está na linha de agosto da V1, a do próprio arquivo: agosto é
    // a competência de pé, e não o novembro da cópia que nunca valeu.
    expect(MeasurementPlanLine::query()->where('plan_version_id', $cancelled->id)->where('lineage_key', $lineage)->sole()->measurement_date->format('Y-m'))
        ->toBe('2027-11')
        ->and(planVersionActivationRefusal(fn () => planVersionActivationActivate($draft, $actor)))
        ->toBe(['effective_from' => [sprintf(
            'A competência 08/2027 já tem medição de pé (#%d) e não pode ser replanejada. Ativada agora, a revisão valeria a partir de 08/2027; ative-a a partir de 01/09/2027.',
            $august->id,
        )]]);

    expect(planVersionActivationFootprint($planSet))->toBe($before);

    // Em setembro a revisão vale, sem esperar dezembro.
    $this->travelTo(CarbonImmutable::parse('2027-09-01 03:30:00', 'UTC'));
    $v3 = planVersionActivationActivate($draft, $actor);

    expect($v3->version_number)->toBe(3)
        ->and($v3->status)->toBe(MeasurementPlanVersionStatus::Active)
        ->and($v3->effective_from->toDateString())->toBe('2027-09-01')
        ->and($v1->fresh()->status)->toBe(MeasurementPlanVersionStatus::Superseded)
        ->and($august->fresh()->status)->toBe('in_review')
        ->and((int) $august->assets()->sole()->plan_version_id)->toBe($v1->id);
});

it('takes the standing competence from the line the measurement holds, not from the superseded copy of a line pulled earlier', function () {
    ['actor' => $actor, 'operation' => $operation, 'planSet' => $planSet, 'v1' => $v1] = planVersionActivationYearPlan('2027-08-02 12:00:00');
    // A V2 antecipa para agosto -- o mês da própria vigência, que ainda é
    // futuro -- a medição prevista de setembro.
    $draft = planVersionActivationRevision($planSet, $actor);
    $rows = planVersionActivationDraftRows($draft);
    $rows['2027-09']['measurement_date'] = '2027-08';
    $v2 = planVersionActivationActivate(planVersionActivationSaveDraft($draft, $actor, $rows), $actor);
    $pulled = MeasurementPlanLine::query()->where('plan_version_id', $v2->id)->where('sequence_number', 9)->sole();

    // Agosto é medido na linha antecipada, aprovada pela Engenharia.
    $this->travelTo(CarbonImmutable::parse('2027-09-03 12:00:00', 'UTC'));
    $august = Scenario::measured(
        ['actor' => $actor, 'operation' => $operation, 'planSet' => $planSet, 'lines' => ['2027-08' => $pulled]],
        '2027-08',
        5,
    );

    // A cópia da V1, substituída, continua em setembro; a medição está em
    // agosto, na linha da V2. A revisão de setembro vale já.
    $v3 = planVersionActivationActivate(planVersionActivationRevision($planSet, $actor), $actor);

    expect($v2->effective_from->toDateString())->toBe('2027-08-01')
        ->and($pulled->measurement_date->format('Y-m'))->toBe('2027-08')
        ->and(MeasurementPlanLine::query()->where('plan_version_id', $v1->id)->where('lineage_key', $pulled->lineage_key)->sole()->measurement_date->format('Y-m'))
        ->toBe('2027-09')
        ->and($august->assets()->sole()->only(['plan_version_id', 'plan_line_id', 'line_claim_key']))->toBe([
            'plan_version_id' => $v2->id,
            'plan_line_id' => $pulled->id,
            'line_claim_key' => $pulled->lineage_key,
        ])
        ->and($v3->status)->toBe(MeasurementPlanVersionStatus::Active)
        ->and($v3->effective_from->toDateString())->toBe('2027-09-01')
        ->and($v3->activation_progress_percent)->toBe('5.00')
        ->and($v2->fresh()->status)->toBe(MeasurementPlanVersionStatus::Superseded);
});

it('refuses an activation whose business month is earlier than the effective month of the active version', function () {
    $this->travelTo(CarbonImmutable::parse('2027-08-10 12:00:00', 'UTC'));
    ['actor' => $actor, 'operation' => $operation] = planVersionActivationOperation();
    $planSet = planVersionActivationPlan($operation, $actor, planVersionActivationMonthly('2027-08', 5, '10.00'));
    planVersionActivationActivate(planVersionActivationVersion($planSet, 1), $actor);
    $draft = planVersionActivationRevision($planSet, $actor);
    $before = planVersionActivationFootprint($planSet);

    // O relógio volta (correção do horário do servidor) para 23:00 de 31/07 em
    // Brasília: a revisão valeria antes da versão que ela substitui.
    $this->travelTo(CarbonImmutable::parse('2027-08-01 02:00:00', 'UTC'));

    expect(planVersionActivationRefusal(fn () => planVersionActivationActivate($draft, $actor)))
        ->toBe(['effective_from' => ['A V1 vigente vale a partir de 08/2027; uma revisão ativada agora valeria a partir de 07/2027, antes dela.']]);

    expect(planVersionActivationFootprint($planSet))->toBe($before);
});

// ── Avanço físico: um ponto de partida, um teto ──────────────────────────────

it('keeps the physical progress of the plan through a revision: V2 plans only what remains', function () {
    ['actor' => $actor, 'planSet' => $planSet, 'v1' => $v1] = planVersionActivationHalfBuiltPlan();
    $physicalProgress = app(MeasurementPhysicalProgressService::class);
    $v1Lines = planVersionActivationRawLines($v1);

    expect($physicalProgress->forPlanSet($planSet->fresh())->currentBasisPoints())->toBe(5000);

    $draft = planVersionActivationRevision($planSet, $actor);
    $rows = planVersionActivationDraftRows($draft);

    foreach (['2027-04', '2027-05', '2027-06'] as $month) {
        $rows[$month]['planned_monthly_percent'] = '10.00';
    }

    planVersionActivationSaveDraft($draft, $actor, $rows);

    $v2 = planVersionActivationActivate($draft, $actor);
    $progress = $physicalProgress->forPlanSet($planSet->fresh());

    // 30% iniciais + 20% medidos na V1 = 50%: a V2 não recomeça do zero nem
    // cria outro ponto de partida -- planeja os 50% que restam. Janeiro e
    // março (20%) ficaram antes da vigência sem medição e continuam a medir;
    // o acumulado futuro gravado parte de 50% mais eles (80, 90, 100), e
    // abril deixa os 75% copiados da V1.
    expect($v2->activation_progress_percent)->toBe('50.00')
        ->and(planVersionActivationCumulative($v2))->toBe([
            '2027-01' => '40.00',
            '2027-02' => '50.00',
            '2027-03' => '60.00',
            '2027-04' => '80.00',
            '2027-05' => '90.00',
            '2027-06' => '100.00',
        ])
        ->and(planVersionActivationRawLines($v1))->toBe($v1Lines)
        ->and($progress->initialBasisPoints)->toBe(3000)
        ->and($progress->measuredBasisPoints())->toBe(2000)
        ->and($progress->currentBasisPoints())->toBe(5000)
        ->and($progress->remainingBasisPoints())->toBe(5000)
        ->and($planSet->fresh()->initial_physical_progress_percent)->toBe('30.00');

    $activation = planVersionActivationLifecycle($planSet, 'plan_version_activated')->last();

    expect((int) $activation->subject_id)->toBe($v2->id)
        ->and($activation->properties['physical_progress_percent'])->toBe('50.00')
        ->and($activation->properties['remaining_physical_progress_percent'])->toBe('50.00')
        ->and($activation->properties['pending_before_effective_percent'])->toBe('20.00');
});

it('keeps one 100% ceiling per plan: the revision cannot plan more than what remains', function () {
    ['actor' => $actor, 'planSet' => $planSet] = planVersionActivationHalfBuiltPlan();
    $draft = planVersionActivationRevision($planSet, $actor);
    $rows = planVersionActivationDraftRows($draft);

    foreach (['2027-04', '2027-05', '2027-06'] as $month) {
        $rows[$month]['planned_monthly_percent'] = '20.00';
    }

    planVersionActivationSaveDraft($draft, $actor, $rows);
    $before = planVersionActivationFootprint($planSet);

    // 60% previstos caberiam num teto novo, de versão; mas o teto é um só, do
    // plano: a obra já está em 50%, e janeiro e março (20%), anteriores à
    // vigência e ainda não medidos, vêm antes do que a revisão planeja.
    expect(planVersionActivationRefusal(fn () => planVersionActivationActivate($draft, $actor)))
        ->toBe(['lines' => ['O cronograma a partir de 04/2027 prevê 60,00% de avanço físico mensal somado, mas restam 30,00% da obra (avanço atual 50,00%, mais 20,00% previstos antes de 04/2027 e ainda não medidos). O avanço já executado não muda com a revisão.']]);

    expect(planVersionActivationFootprint($planSet))->toBe($before);

    $rows = planVersionActivationDraftRows($draft);
    $rows['2027-05']['planned_monthly_percent'] = '5.00';
    $rows['2027-06']['planned_monthly_percent'] = '5.00';
    planVersionActivationSaveDraft($draft, $actor, $rows);

    $v2 = planVersionActivationActivate($draft, $actor);

    // Exatamente o que resta cabe (20% + 5% + 5% = 30%): o último acumulado
    // previsto fecha em 100%.
    expect($v2->status)->toBe(MeasurementPlanVersionStatus::Active)
        ->and(planVersionActivationCumulative($v2))->toBe([
            '2027-01' => '40.00',
            '2027-02' => '50.00',
            '2027-03' => '60.00',
            '2027-04' => '90.00',
            '2027-05' => '95.00',
            '2027-06' => '100.00',
        ])
        ->and(app(MeasurementPhysicalProgressService::class)->forPlanSet($planSet->fresh())->currentBasisPoints())->toBe(5000);
});

// ── O previsto anterior à vigência ainda por medir ───────────────────────────

it('plans the V1 activated after its first planned month over the whole schedule: the earlier month stays to measure and counts in the 100% ceiling', function () {
    $this->travelTo(CarbonImmutable::parse('2027-03-20 12:00:00', 'UTC'));
    ['actor' => $actor, 'operation' => $operation] = planVersionActivationOperation();
    $planSet = planVersionActivationPlan($operation, $actor, [
        '2027-02' => '10.00',
        '2027-03' => '10.00',
        '2027-04' => '80.00',
    ]);
    $v1 = planVersionActivationVersion($planSet, 1);
    // Abril sobe para 85%: o acumulado digitado fica nos 100% que o
    // formulário aceita, mas o previsto mensal somado vai a 105%.
    $rows = planVersionActivationDraftRows($v1);
    $rows['2027-04']['planned_monthly_percent'] = '85.00';
    $v1 = planVersionActivationSaveDraft($v1, $actor, $rows);
    $before = planVersionActivationFootprint($planSet);

    // Vigente desde março, a V1 deixa fevereiro antes da vigência. Nada o
    // mediu: ele continua a medir, atrasado, e entra no teto do plano.
    expect(planVersionActivationRefusal(fn () => planVersionActivationActivate($v1, $actor)))
        ->toBe(['lines' => ['O cronograma prevê 105,00% de avanço físico mensal somado, mas restam 100,00% da obra (avanço inicial 0,00%).']]);

    expect(planVersionActivationFootprint($planSet))->toBe($before);

    // Abril volta a 80%, e o acumulado de fevereiro chega em branco (0%): a
    // ativação o deriva, como o das competências da vigência em diante.
    $rows = planVersionActivationDraftRows($v1);
    $rows['2027-02']['planned_cumulative_percent'] = '0.00';
    $rows['2027-04']['planned_monthly_percent'] = '80.00';
    $v1 = planVersionActivationActivate(planVersionActivationSaveDraft($v1, $actor, $rows), $actor);
    $february = MeasurementPlanLine::query()->where('plan_version_id', $v1->id)->where('sequence_number', 1)->sole();

    // O acumulado soma o cronograma inteiro sobre o avanço inicial, fevereiro
    // primeiro: março não repete o acumulado de fevereiro.
    expect($v1->effective_from->toDateString())->toBe('2027-03-01')
        ->and(planVersionActivationCumulative($v1))->toBe([
            '2027-02' => '10.00',
            '2027-03' => '20.00',
            '2027-04' => '100.00',
        ])
        ->and(planVersionActivationLifecycle($planSet, 'plan_version_activated')->sole()->properties['pending_before_effective_percent'])->toBe('10.00')
        ->and(MeasurementPlanLine::query()->whereKey($february->id)->availableForMeasurement()->exists())->toBeTrue();

    // Fevereiro é medido depois da ativação, pelo fluxo real.
    $this->travelTo(CarbonImmutable::parse('2027-03-25 12:00:00', 'UTC'));
    $scenario = planVersionActivationScenario($actor, $operation, $planSet);
    $late = Scenario::measured($scenario, '2027-02', 10);

    expect((int) $late->assets()->sole()->plan_line_id)->toBe($february->id)
        ->and(Scenario::progress($scenario)->currentPercent())->toBe('10.00');
});

it('starts the revised cumulative after the previous month still to measure and refuses to plan beyond what remains after it', function () {
    $this->travelTo(CarbonImmutable::parse('2027-01-10 12:00:00', 'UTC'));
    ['actor' => $actor, 'operation' => $operation] = planVersionActivationOperation();
    $planSet = planVersionActivationPlan($operation, $actor, planVersionActivationMonthly('2027-01', 10, '10.00'));
    planVersionActivationActivate(planVersionActivationVersion($planSet, 1), $actor);
    $scenario = planVersionActivationScenario($actor, $operation, $planSet);

    // Janeiro a maio medidos como previstos, cada um no dia 5 do mês seguinte.
    foreach (['2027-01', '2027-02', '2027-03', '2027-04', '2027-05'] as $month) {
        $this->travelTo(CarbonImmutable::parse("{$month}-05 12:00:00", 'UTC')->addMonthNoOverflow());
        Scenario::measured($scenario, $month, 10);
    }

    // 02/07: a medição de junho ainda não chegou. A revisão mantém julho a
    // outubro e acrescenta novembro.
    $this->travelTo(CarbonImmutable::parse('2027-07-02 12:00:00', 'UTC'));
    $draft = planVersionActivationRevision($planSet, $actor);
    $rows = planVersionActivationDraftRows($draft);
    $rows['2027-11'] = [
        'sequence_number' => 11,
        'planned_monthly_percent' => '10.00',
        'planned_cumulative_percent' => '100.00',
        'measurement_date' => '2027-11',
    ];
    planVersionActivationSaveDraft($draft, $actor, $rows);
    $before = planVersionActivationFootprint($planSet);

    // A obra está em 50% e junho (10%) continua a medir: para julho em diante
    // sobram 40%, e não os 50% que o avanço atual sozinho deixaria.
    expect(planVersionActivationRefusal(fn () => planVersionActivationActivate($draft, $actor)))
        ->toBe(['lines' => ['O cronograma a partir de 07/2027 prevê 50,00% de avanço físico mensal somado, mas restam 40,00% da obra (avanço atual 50,00%, mais 10,00% previstos antes de 07/2027 e ainda não medidos). O avanço já executado não muda com a revisão.']]);

    expect(planVersionActivationFootprint($planSet))->toBe($before);

    // Sem novembro, e com julho e agosto redistribuídos (5% e 15%), os 40%
    // cabem.
    $rows = planVersionActivationDraftRows($draft);
    unset($rows['2027-11']);
    $rows['2027-07']['planned_monthly_percent'] = '5.00';
    $rows['2027-08']['planned_monthly_percent'] = '15.00';
    $v2 = planVersionActivationActivate(planVersionActivationSaveDraft($draft, $actor, $rows), $actor);
    $june = MeasurementPlanLine::query()->where('plan_version_id', $v2->id)->where('sequence_number', 6)->sole();

    // Julho parte dos 50% medidos mais os 10% de junho por medir: 65%, e não
    // 55%. Junho fica como na V1 e continua disponível para a medição atrasada.
    expect($v2->effective_from->toDateString())->toBe('2027-07-01')
        ->and($v2->activation_progress_percent)->toBe('50.00')
        ->and(planVersionActivationCumulative($v2))->toBe([
            '2027-01' => '10.00',
            '2027-02' => '20.00',
            '2027-03' => '30.00',
            '2027-04' => '40.00',
            '2027-05' => '50.00',
            '2027-06' => '60.00',
            '2027-07' => '65.00',
            '2027-08' => '80.00',
            '2027-09' => '90.00',
            '2027-10' => '100.00',
        ])
        ->and(planVersionActivationLifecycle($planSet, 'plan_version_activated')->last()->properties['pending_before_effective_percent'])->toBe('10.00')
        ->and(MeasurementPlanLine::query()->whereKey($june->id)->availableForMeasurement()->exists())->toBeTrue();
});

it('accepts a revision that plans 0% from its effective month when the months still to measure before it already exceed what remains', function () {
    $this->travelTo(CarbonImmutable::parse('2027-01-10 12:00:00', 'UTC'));
    ['actor' => $actor, 'operation' => $operation] = planVersionActivationOperation();
    $planSet = planVersionActivationPlan($operation, $actor, [
        '2027-01' => '30.00',
        '2027-02' => '30.00',
        '2027-03' => '40.00',
    ]);
    planVersionActivationActivate(planVersionActivationVersion($planSet, 1), $actor);

    // A obra andou mais rápido que o previsto: janeiro mediu 80%.
    $this->travelTo(CarbonImmutable::parse('2027-02-05 12:00:00', 'UTC'));
    Scenario::measured(planVersionActivationScenario($actor, $operation, $planSet), '2027-01', 80);

    // Em março, fevereiro (30%) ainda não foi medido.
    $this->travelTo(CarbonImmutable::parse('2027-03-05 12:00:00', 'UTC'));
    $draft = planVersionActivationRevision($planSet, $actor);
    $before = planVersionActivationFootprint($planSet);

    // 80% executados mais os 30% de fevereiro já passam dos 100%: não sobra
    // nada para a revisão planejar, e os 40% de março são recusados.
    expect(planVersionActivationRefusal(fn () => planVersionActivationActivate($draft, $actor)))
        ->toBe(['lines' => ['O cronograma a partir de 03/2027 prevê 40,00% de avanço físico mensal somado, mas restam 0,00% da obra (avanço atual 80,00%, mais 30,00% previstos antes de 03/2027 e ainda não medidos). O avanço já executado não muda com a revisão.']]);

    expect(planVersionActivationFootprint($planSet))->toBe($before);

    // Fevereiro é passado e não se reescreve: a revisão que zera março vale,
    // em vez de ficar travada pelo previsto que ela não pode mais mudar.
    $rows = planVersionActivationDraftRows($draft);
    $rows['2027-03']['planned_monthly_percent'] = '0.00';
    $v2 = planVersionActivationActivate(planVersionActivationSaveDraft($draft, $actor, $rows), $actor);

    // Março guarda a soma corrida da ativação -- 80% medidos + 30% de
    // fevereiro por medir + 0% --, parada no teto: a obra não passa de 100%.
    expect($v2->status)->toBe(MeasurementPlanVersionStatus::Active)
        ->and($v2->effective_from->toDateString())->toBe('2027-03-01')
        ->and($v2->activation_progress_percent)->toBe('80.00')
        ->and(planVersionActivationCumulative($v2))->toBe([
            '2027-01' => '30.00',
            '2027-02' => '60.00',
            '2027-03' => '100.00',
        ])
        ->and(planVersionActivationLifecycle($planSet, 'plan_version_activated')->last()->properties['pending_before_effective_percent'])->toBe('30.00');
});

// ── O que a ativação grava, antes e depois ───────────────────────────────────

it('records the cumulative rewrite, the supersession and the activation in one batch, listing each recalculated line', function () {
    ['actor' => $actor, 'planSet' => $planSet] = planVersionActivationHalfBuiltPlan();
    $draft = planVersionActivationRevision($planSet, $actor);
    $rows = planVersionActivationDraftRows($draft);

    foreach (['2027-04', '2027-05', '2027-06'] as $month) {
        $rows[$month]['planned_monthly_percent'] = '10.00';
    }

    $draft = planVersionActivationSaveDraft($draft, $actor, $rows);
    $april = MeasurementPlanLine::query()->where('plan_version_id', $draft->id)->where('sequence_number', 4)->sole();
    $lastBefore = (int) Activity::query()->max('id');

    planVersionActivationActivate($draft, $actor);

    $trail = Activity::query()->where('id', '>', $lastBefore)->orderBy('id')->get();
    $rewrites = $trail->where('subject_type', MeasurementPlanLine::class)->values();
    $superseded = planVersionActivationLifecycle($planSet, 'plan_version_superseded')->sole();
    $activation = planVersionActivationLifecycle($planSet, 'plan_version_activated')->last();

    // Só abril muda: os 75% copiados da V1 viram 80% (50% atuais + 20% de
    // janeiro e março por medir + 10%). Maio e junho já tinham o acumulado da
    // ativação (90% e 100%) e não são regravados.
    expect($rewrites)->toHaveCount(1)
        ->and((int) $rewrites[0]->subject_id)->toBe($april->id)
        ->and($rewrites[0]->event)->toBe('updated')
        ->and($rewrites[0]->attribute_changes['old']['planned_cumulative_percent'])->toBe('75.00')
        ->and($rewrites[0]->attribute_changes['attributes']['planned_cumulative_percent'])->toBe('80.00')
        ->and(planVersionActivationSortedKeys($activation->properties['recalculated_lines']))->toBe(planVersionActivationSortedKeys([[
            'plan_line_id' => $april->id,
            'lineage_key' => $april->lineage_key,
            'sequence_number' => 4,
            'measurement_date' => '2027-04-01',
            'draft_cumulative_percent' => '75.00',
            'activation_cumulative_percent' => '80.00',
        ]]))
        ->and($activation->properties['pending_before_effective_percent'])->toBe('20.00');

    // A regravação do acumulado, a substituição e a ativação são a mesma
    // decisão: um lote só na trilha, nessa ordem.
    expect($activation->batch_uuid)->not->toBeNull()
        ->and($trail->pluck('batch_uuid')->unique()->values()->all())->toBe([$activation->batch_uuid])
        ->and($superseded->batch_uuid)->toBe($activation->batch_uuid)
        ->and($rewrites[0]->id)->toBeLessThan($superseded->id)
        ->and($superseded->id)->toBeLessThan($activation->id);
});

it('previews exactly the cumulative that the activation then writes', function (string $case) {
    if ($case === 'V1 activated after its first planned month') {
        $this->travelTo(CarbonImmutable::parse('2027-06-20 12:00:00', 'UTC'));
        ['actor' => $actor, 'operation' => $operation] = planVersionActivationOperation();
        $planSet = planVersionActivationPlan($operation, $actor, [
            '2027-06' => '10.00',
            '2027-07' => '10.00',
        ], initialPercent: '40.00', referenceDate: '2027-05-31');
        $this->travelTo(CarbonImmutable::parse('2027-07-05 12:00:00', 'UTC'));
        $draft = planVersionActivationVersion($planSet, 1);
        // A V1 deriva todas as linhas, junho (antes da vigência) incluído.
        $expected = ['2027-06' => 5000, '2027-07' => 6000];
    } else {
        ['actor' => $actor, 'planSet' => $planSet] = planVersionActivationHalfBuiltPlan();
        $draft = planVersionActivationRevision($planSet, $actor);
        $rows = planVersionActivationDraftRows($draft);

        foreach (['2027-04', '2027-05', '2027-06'] as $month) {
            $rows[$month]['planned_monthly_percent'] = '10.00';
        }

        $draft = planVersionActivationSaveDraft($draft, $actor, $rows);
        // A revisão deriva só da vigência em diante: janeiro a março são a
        // cópia fiel da V1 e ficam fora.
        $expected = ['2027-04' => 8000, '2027-05' => 9000, '2027-06' => 10000];
    }

    $lineIds = array_map(fn (array $row): int => $row['id'], planVersionActivationDraftRows($draft));
    $before = planVersionActivationFootprint($planSet);

    $preview = app(MeasurementPlanVersionService::class)->activationPreview($draft->fresh());

    // Ver antes não grava nada.
    expect(planVersionActivationFootprint($planSet))->toBe($before)
        ->and($preview)->toBe(collect($expected)->mapWithKeys(fn (int $cumulative, string $month): array => [$lineIds[$month] => $cumulative])->all());

    planVersionActivationActivate($draft, $actor);

    expect(MeasurementPlanLine::query()
        ->whereIn('id', array_keys($preview))
        ->orderBy('measurement_date')
        ->orderBy('sequence_number')
        ->get()
        ->mapWithKeys(fn (MeasurementPlanLine $line): array => [(int) $line->id => MeasurementPhysicalProgress::basisPoints($line->planned_cumulative_percent)])
        ->all())->toBe($preview);
})->with([
    'V1 activated after its first planned month' => ['V1 activated after its first planned month'],
    'revision with earlier months still to measure' => ['revision with earlier months still to measure'],
]);

it('tells where the plan stands for planning now: the current progress, the earlier months still to measure and what remains to plan', function () {
    ['actor' => $actor, 'operation' => $operation, 'planSet' => $planSet] = planVersionActivationHalfBuiltPlan();
    $draft = planVersionActivationRevision($planSet, $actor);
    $context = function () use ($draft): array {
        $planning = app(MeasurementPlanVersionService::class)->planningContext($draft->fresh());

        return ['effective_from' => $planning['effective_from']->toDateString()] + $planning;
    };

    // Abril de 2027: 30% iniciais + 20% de fevereiro; janeiro e março (10%
    // cada) ficaram para trás sem medição e ainda vão ser medidos.
    expect($context())->toBe([
        'effective_from' => '2027-04-01',
        'current' => 5000,
        'pending' => 2000,
        'remaining_to_plan' => 3000,
    ]);

    // Janeiro é medido atrasado, como previsto: sai do pendente e entra no
    // avanço atual, e o que resta planejar não muda.
    Scenario::measured(planVersionActivationScenario($actor, $operation, $planSet), '2027-01', 10);

    expect($context())->toBe([
        'effective_from' => '2027-04-01',
        'current' => 6000,
        'pending' => 1000,
        'remaining_to_plan' => 3000,
    ]);

    // Virado o mês em Brasília, abril (15%) também fica para trás sem medição.
    $this->travelTo(CarbonImmutable::parse('2027-05-01 03:30:00', 'UTC'));

    expect($context())->toBe([
        'effective_from' => '2027-05-01',
        'current' => 6000,
        'pending' => 2500,
        'remaining_to_plan' => 1500,
    ]);
});

// ── V1 de uma obra nova com medição na Engenharia ────────────────────────────

it('activates the V1 of a new construction while a measurement sent without it awaits Engineering, which approves that measurement without the new file', function () {
    $this->travelTo(CarbonImmutable::parse('2027-03-10 12:00:00', 'UTC'));
    ['actor' => $actor, 'operation' => $operation] = planVersionActivationOperation();
    $tower = planVersionActivationPlan($operation, $actor, ['2027-03' => '10.00', '2027-04' => '10.00']);
    planVersionActivationActivate(planVersionActivationVersion($tower, 1), $actor);
    $scenario = planVersionActivationScenario($actor, $operation, $tower);
    $march = Scenario::measurement($scenario, '2027-03');
    // O cronograma do anexo começa em abril: março já tem medição de pé sem
    // ele, e uma medição prevista dele em março nunca seria medida.
    $annex = planVersionActivationPlan($operation, $actor, ['2027-04' => '10.00'], name: 'Anexo', isDefault: false);

    // A medição de março foi enviada quando só a torre valia: ela não passa a
    // depender do anexo, e nada impede ativá-lo agora. A ativação guarda a
    // última medição da operação até ali.
    $annexV1 = planVersionActivationActivate(planVersionActivationVersion($annex, 1), $actor);

    expect($annexV1->status)->toBe(MeasurementPlanVersionStatus::Active)
        ->and($annexV1->effective_from->toDateString())->toBe('2027-03-01')
        ->and($annexV1->last_measurement_id_at_activation)->toBe($march->id);

    // A Engenharia aprova março só com o arquivo da torre. Devolvida pela
    // Gestão, a medição volta à Engenharia e é aprovada de novo sem o anexo:
    // o arquivo dele não se acrescenta pela edição.
    Scenario::approveEngineering($scenario, $march, 10);
    $firstApproval = $march->fresh();
    Scenario::returnToEngineering($scenario, $march);
    $returned = $march->fresh();
    Scenario::approveEngineering($scenario, $march, 10);
    $march = $march->fresh();

    expect($firstApproval->current_stage)->toBe(2)
        ->and($returned->current_stage)->toBe(MeasurementWorkflow::STAGE_ENGINEERING)
        ->and($march->current_stage)->toBe(2)
        ->and(array_column($march->engineering_snapshot['plan_sets'], 'plan_set_id'))->toBe([$tower->id]);

    // A medição de abril, enviada depois da ativação, precisa cobrir o anexo
    // também.
    $this->travelTo(CarbonImmutable::parse('2027-05-05 12:00:00', 'UTC'));
    $april = Scenario::measurement($scenario, '2027-04');

    expect(planVersionActivationEngineeringRefusal(fn () => Scenario::approveEngineering($scenario, $april, 10)))->toBe([
        'assets.coverage' => ['Envie exatamente um arquivo para cada empreendimento da operação.'],
        "assets.{$annex->id}" => ['Envie o arquivo da medição para Anexo.'],
    ]);
});

// ── Competência que a operação já mediu sem o plano ──────────────────────────

it('activates the V1 of a new construction with a planned measurement in a competence the operation already measured without it, leaving it out of the calculation only while that measurement stands', function (string $sentAt, bool $draftedBeforeSending, bool $approvedByEngineering, string $activatedAt, string $effectiveFrom) {
    $this->travelTo(CarbonImmutable::parse('2027-03-01 12:00:00', 'UTC'));
    ['actor' => $actor, 'operation' => $operation] = planVersionActivationOperation();
    $service = app(MeasurementPlanVersionService::class);
    $tower = planVersionActivationPlan($operation, $actor, planVersionActivationMonthly('2027-03', 3, '10.00'));
    planVersionActivationActivate(planVersionActivationVersion($tower, 1), $actor);
    $annexSchedule = planVersionActivationMonthly('2027-03', 3, '10.00');
    $annex = $draftedBeforeSending ? planVersionActivationPlan($operation, $actor, $annexSchedule, name: 'Anexo', isDefault: false) : null;

    // Março é enviado só com o arquivo da torre: o anexo ainda não valia --
    // nem existia, ou tinha a V1 em rascunho.
    $this->travelTo(CarbonImmutable::parse($sentAt, 'UTC'));
    $towerScenario = planVersionActivationScenario($actor, $operation, $tower);
    $march = $approvedByEngineering
        ? Scenario::measured($towerScenario, '2027-03', 10)
        : Scenario::measurement($towerScenario, '2027-03');
    $annex ??= planVersionActivationPlan($operation, $actor, $annexSchedule, name: 'Anexo', isDefault: false);

    // A V1 vale com a medição prevista de março: enquanto a medição de março
    // estiver de pé, outra medição da competência teria de cobrir também a
    // torre, cuja linha de março ela ocupa. Por isso a de março do anexo fica
    // fora do cálculo -- não é prevista e ainda por medir, nem entra no teto --
    // e mantém o acumulado do rascunho; abril e maio partem do avanço atual.
    $this->travelTo(CarbonImmutable::parse($activatedAt, 'UTC'));
    $annexV1 = planVersionActivationActivate(planVersionActivationVersion($annex, 1), $actor);

    expect($service->monthsMeasuredWithoutThePlan($annex))->toBe(['2027-03' => $march->id])
        ->and($annexV1->status)->toBe(MeasurementPlanVersionStatus::Active)
        ->and($annexV1->effective_from->toDateString())->toBe($effectiveFrom)
        ->and($annexV1->last_measurement_id_at_activation)->toBe($march->id)
        ->and(planVersionActivationCumulative($annexV1))->toBe(['2027-03' => '10.00', '2027-04' => '10.00', '2027-05' => '20.00'])
        ->and(planVersionActivationLifecycle($annex, 'plan_version_activated')->sole()->properties['pending_before_effective_percent'])->toBe('0.00')
        ->and($march->fresh()->assets()->pluck('plan_set_id')->map(fn (mixed $id): int => (int) $id)->all())->toBe([$tower->id]);

    // Recusada a medição de março, sem pagamento, a competência volta a ser
    // medida -- com os dois planos, já que o anexo vale para o reenvio --, e
    // a medição prevista de março do anexo volta a contar como ainda por medir.
    if ($approvedByEngineering) {
        Scenario::returnToEngineering($towerScenario, $march);
    }

    app(MeasurementWorkflow::class)->reject($march->fresh(), $actor, 'Arquivo de outra obra.');
    $this->travelTo(CarbonImmutable::parse('2027-04-20 12:00:00', 'UTC'));

    expect($march->fresh()->status)->toBe('rejected')
        ->and($service->monthsMeasuredWithoutThePlan($annex))->toBe([])
        ->and($service->planningContext($annexV1->fresh())['pending'])->toBe(1_000);

    $resent = planVersionActivationMeasurementOf($actor, $operation, '2027-03', [
        $towerScenario['lines']['2027-03'],
        planVersionActivationScenario($actor, $operation, $annex)['lines']['2027-03'],
    ]);
    app(MeasurementWorkflow::class)->approve($resent->fresh(), $actor, engineeringProgress: [$tower->id => 10, $annex->id => 10]);
    $resent = $resent->fresh();

    expect($resent->current_stage)->toBe(2)
        ->and(array_column($resent->engineering_snapshot['plan_sets'], 'plan_set_id'))->toBe([$tower->id, $annex->id])
        ->and(Scenario::progress(planVersionActivationScenario($actor, $operation, $annex))->currentPercent())->toBe('10.00');
})->with([
    'plan created after the sending, V1 activated in that month' => ['2027-03-10 12:00:00', false, false, '2027-03-12 12:00:00', '2027-03-01'],
    'V1 still a draft at the sending, activated in the next month' => ['2027-04-05 12:00:00', true, false, '2027-04-06 12:00:00', '2027-04-01'],
    'measurement already approved by Engineering' => ['2027-04-05 12:00:00', true, true, '2027-04-06 12:00:00', '2027-04-01'],
]);

it('keeps counting the planned measurement of a measured competence while the plan in force still has a free planned measurement in it', function () {
    $this->travelTo(CarbonImmutable::parse('2027-03-01 12:00:00', 'UTC'));
    ['actor' => $actor, 'operation' => $operation] = planVersionActivationOperation();
    $service = app(MeasurementPlanVersionService::class);

    // A torre prevê duas medições em março: a primeira medição de março ocupa
    // uma, e a outra continua livre.
    $tower = $service->createPlan($operation, $actor, [
        'name' => 'Torre A',
        'is_default' => true,
        'initial_incurred_amount' => '0.00',
        'initial_physical_progress_percent' => '0.00',
    ], ['construction_fund_amount' => '1000000.00'], [
        ['sequence_number' => 1, 'planned_monthly_percent' => '5.00', 'planned_cumulative_percent' => '5.00', 'measurement_date' => '2027-03'],
        ['sequence_number' => 2, 'planned_monthly_percent' => '5.00', 'planned_cumulative_percent' => '10.00', 'measurement_date' => '2027-03'],
        ['sequence_number' => 3, 'planned_monthly_percent' => '10.00', 'planned_cumulative_percent' => '20.00', 'measurement_date' => '2027-04'],
    ]);
    planVersionActivationActivate(planVersionActivationVersion($tower, 1), $actor);
    $first = MeasurementPlanLine::query()->where('plan_set_id', $tower->id)->where('sequence_number', 1)->sole();

    $this->travelTo(CarbonImmutable::parse('2027-04-05 12:00:00', 'UTC'));
    planVersionActivationMeasurementOf($actor, $operation, '2027-03', [$first]);
    $annex = planVersionActivationPlan($operation, $actor, planVersionActivationMonthly('2027-03', 3, '10.00'), name: 'Anexo', isDefault: false);

    // Uma segunda medição de março cobre a torre (a medição prevista livre) e
    // o anexo: a linha de março do anexo continua prevista e ainda por medir.
    $this->travelTo(CarbonImmutable::parse('2027-04-06 12:00:00', 'UTC'));
    $annexV1 = planVersionActivationActivate(planVersionActivationVersion($annex, 1), $actor);

    expect($service->monthsMeasuredWithoutThePlan($annex))->toBe([])
        ->and(planVersionActivationCumulative($annexV1))->toBe(['2027-03' => '10.00', '2027-04' => '20.00', '2027-05' => '30.00'])
        ->and(planVersionActivationLifecycle($annex, 'plan_version_activated')->sole()->properties['pending_before_effective_percent'])->toBe('10.00');
});

it('keeps counting the planned measurement of a plan already in force whose file a measurement left out', function () {
    $this->travelTo(CarbonImmutable::parse('2027-03-01 12:00:00', 'UTC'));
    ['actor' => $actor, 'operation' => $operation] = planVersionActivationOperation();
    $service = app(MeasurementPlanVersionService::class);
    $tower = planVersionActivationPlan($operation, $actor, planVersionActivationMonthly('2027-03', 4, '10.00'));
    $annex = planVersionActivationPlan($operation, $actor, planVersionActivationMonthly('2027-03', 4, '10.00'), name: 'Anexo', isDefault: false);
    planVersionActivationActivate(planVersionActivationVersion($tower, 1), $actor);
    planVersionActivationActivate(planVersionActivationVersion($annex, 1), $actor);

    // Os dois planos já valiam quando março foi enviado, mas o envio deixou o
    // anexo de fora: a medição é que está errada (a Engenharia a recusa), e a
    // linha de março do anexo continua prevista e ainda por medir.
    $this->travelTo(CarbonImmutable::parse('2027-04-05 12:00:00', 'UTC'));
    $march = planVersionActivationMeasurementOf($actor, $operation, '2027-03', [planVersionActivationScenario($actor, $operation, $tower)['lines']['2027-03']]);

    expect(planVersionActivationEngineeringRefusal(fn () => app(MeasurementWorkflow::class)->approve($march->fresh(), $actor, engineeringProgress: [$tower->id => 10])))
        ->toHaveKey("assets.{$annex->id}")
        ->and($service->monthsMeasuredWithoutThePlan($annex))->toBe([])
        ->and($service->planningContext(planVersionActivationVersion($annex, 1))['pending'])->toBe(1_000);
});

it('does not count a competence whose only measurement was refused at Engineering: the V1 of the new construction keeps it, and the measurement sent again covers both plans', function () {
    $this->travelTo(CarbonImmutable::parse('2027-03-01 12:00:00', 'UTC'));
    ['actor' => $actor, 'operation' => $operation] = planVersionActivationOperation();
    $tower = planVersionActivationPlan($operation, $actor, planVersionActivationMonthly('2027-03', 3, '10.00'));
    planVersionActivationActivate(planVersionActivationVersion($tower, 1), $actor);

    // Março é enviado só com a torre e recusado na Engenharia. A recusa
    // terminal, sem Engenharia aprovada nem pagamento, solta a competência.
    $this->travelTo(CarbonImmutable::parse('2027-04-05 12:00:00', 'UTC'));
    $refused = Scenario::measurement(planVersionActivationScenario($actor, $operation, $tower), '2027-03');
    app(MeasurementWorkflow::class)->reject($refused->fresh(), $actor, 'Arquivo de outra obra.');
    $annex = planVersionActivationPlan($operation, $actor, planVersionActivationMonthly('2027-03', 3, '10.00'), name: 'Anexo', isDefault: false);

    $this->travelTo(CarbonImmutable::parse('2027-04-06 12:00:00', 'UTC'));
    $annexV1 = planVersionActivationActivate(planVersionActivationVersion($annex, 1), $actor);

    // Março fica antes da vigência, sem medição de pé: continua a medir,
    // atrasado, e entra no acumulado e no teto.
    expect($refused->fresh()->status)->toBe('rejected')
        ->and($annexV1->status)->toBe(MeasurementPlanVersionStatus::Active)
        ->and($annexV1->effective_from->toDateString())->toBe('2027-04-01')
        ->and($annexV1->last_measurement_id_at_activation)->toBe($refused->id)
        ->and(planVersionActivationCumulative($annexV1))->toBe(['2027-03' => '10.00', '2027-04' => '20.00', '2027-05' => '30.00'])
        ->and(planVersionActivationLifecycle($annex, 'plan_version_activated')->sole()->properties['pending_before_effective_percent'])->toBe('10.00');

    // A medição de março, enviada de novo depois da ativação, leva o arquivo
    // dos dois planos, e a Engenharia a aprova.
    $resent = planVersionActivationMeasurementOf($actor, $operation, '2027-03', [
        planVersionActivationScenario($actor, $operation, $tower)['lines']['2027-03'],
        planVersionActivationScenario($actor, $operation, $annex)['lines']['2027-03'],
    ]);
    app(MeasurementWorkflow::class)->approve($resent->fresh(), $actor, engineeringProgress: [$tower->id => 10, $annex->id => 10]);
    $resent = $resent->fresh();

    expect($resent->current_stage)->toBe(2)
        ->and(array_column($resent->engineering_snapshot['plan_sets'], 'plan_set_id'))->toBe([$tower->id, $annex->id])
        ->and(Scenario::progress(planVersionActivationScenario($actor, $operation, $annex))->currentPercent())->toBe('10.00');
});

it('does not count a competence that the initial progress of the new construction already covers', function () {
    $this->travelTo(CarbonImmutable::parse('2027-03-01 12:00:00', 'UTC'));
    ['actor' => $actor, 'operation' => $operation] = planVersionActivationOperation();
    $tower = planVersionActivationPlan($operation, $actor, planVersionActivationMonthly('2027-03', 3, '10.00'));
    planVersionActivationActivate(planVersionActivationVersion($tower, 1), $actor);

    // Março é enviado só com a torre. O anexo entra com 20% executados até
    // 31/03/2027: março já está no avanço inicial dele e não se mede.
    $this->travelTo(CarbonImmutable::parse('2027-04-05 12:00:00', 'UTC'));
    $march = Scenario::measurement(planVersionActivationScenario($actor, $operation, $tower), '2027-03');
    $annex = planVersionActivationPlan(
        $operation,
        $actor,
        planVersionActivationMonthly('2027-03', 3, '10.00'),
        initialPercent: '20.00',
        referenceDate: '2027-03-31',
        name: 'Anexo',
        isDefault: false,
    );

    $this->travelTo(CarbonImmutable::parse('2027-04-06 12:00:00', 'UTC'));
    $annexV1 = planVersionActivationActivate(planVersionActivationVersion($annex, 1), $actor);

    // A medição prevista de março do anexo não fica sem medição: o avanço
    // inicial já a contém. Ela não é recalculada (fica o acumulado digitado),
    // e o acumulado de abril em diante parte dos 20% iniciais.
    expect($march->fresh()->status)->toBe('in_review')
        ->and($annexV1->status)->toBe(MeasurementPlanVersionStatus::Active)
        ->and($annexV1->effective_from->toDateString())->toBe('2027-04-01')
        ->and($annexV1->activation_progress_percent)->toBe('20.00')
        ->and(planVersionActivationCumulative($annexV1))->toBe(['2027-03' => '10.00', '2027-04' => '30.00', '2027-05' => '40.00'])
        ->and(planVersionActivationLifecycle($annex, 'plan_version_activated')->sole()->properties['pending_before_effective_percent'])->toBe('0.00');
});

it('does not count as still to measure the planned measurement of a competence the operation measured before the plan was in force, so a revision may plan exactly what remains', function () {
    $this->travelTo(CarbonImmutable::parse('2027-03-01 12:00:00', 'UTC'));
    ['actor' => $actor, 'operation' => $operation] = planVersionActivationOperation();
    $service = app(MeasurementPlanVersionService::class);
    $tower = planVersionActivationPlan($operation, $actor, planVersionActivationMonthly('2027-03', 10, '10.00'));
    planVersionActivationActivate(planVersionActivationVersion($tower, 1), $actor);
    $towerScenario = planVersionActivationScenario($actor, $operation, $tower);

    // Março é enviado só com a torre. Depois o anexo entra em vigor com uma
    // medição prevista em março, sem as conferências da ativação -- como um
    // plano que passou a valer antes da regra que a recusa: ela fica órfã. A
    // Engenharia aprova março sem o anexo, que passou a valer depois do envio.
    $this->travelTo(CarbonImmutable::parse('2027-03-10 12:00:00', 'UTC'));
    $march = Scenario::measurement($towerScenario, '2027-03');
    $annex = planVersionActivationPlan($operation, $actor, planVersionActivationMonthly('2027-03', 10, '10.00'), name: 'Anexo', isDefault: false);
    MeasurementPlanVersionFixture::activate($annex);
    $annexV1 = planVersionActivationVersion($annex, 1);
    Scenario::approveEngineering($towerScenario, $march, 10);

    // Abril cobre os dois planos; o anexo mede 20%, o trabalho de março e o de
    // abril.
    $this->travelTo(CarbonImmutable::parse('2027-05-04 12:00:00', 'UTC'));
    $april = planVersionActivationMeasurementOf($actor, $operation, '2027-04', [
        $towerScenario['lines']['2027-04'],
        planVersionActivationScenario($actor, $operation, $annex)['lines']['2027-04'],
    ]);
    app(MeasurementWorkflow::class)->approve($april->fresh(), $actor, engineeringProgress: [$tower->id => 10, $annex->id => 20]);

    $this->travelTo(CarbonImmutable::parse('2027-05-05 12:00:00', 'UTC'));
    $context = function (MeasurementPlanVersion $version) use ($service): array {
        $planning = $service->planningContext($version->fresh());

        return ['effective_from' => $planning['effective_from']->toDateString()] + $planning;
    };
    $whereThePlanStands = [
        'effective_from' => '2027-05-01',
        'current' => 2000,
        'pending' => 0,
        'remaining_to_plan' => 8000,
    ];

    // O anexo está em 20%. Março, anterior à vigência e sem medição do anexo,
    // não está previsto e ainda por medir: a competência tem medição de pé sem
    // o arquivo dele, e nenhuma outra o mediria. Restam os 80% de verdade.
    expect($annexV1->effective_from->toDateString())->toBe('2027-03-01')
        ->and($annexV1->last_measurement_id_at_activation)->toBe($march->id)
        ->and($march->fresh()->assets()->pluck('plan_set_id')->map(fn (mixed $id): int => (int) $id)->all())->toBe([$tower->id])
        ->and($context($annexV1))->toBe($whereThePlanStands);

    // A revisão de custo mantém o cronograma: maio a dezembro somam 80%.
    $draft = $service->createRevision($annex, $actor, [
        'revision_category' => MeasurementPlanRevisionCategory::Cost->value,
        'revision_reason' => 'Reajuste do orçamento do anexo aprovado pelo comitê de obras.',
    ]);
    $draft = planVersionActivationSaveDraft($draft, $actor, null, ['construction_fund_amount' => '1150000.00']);
    $comparison = $service->compare($annexV1->fresh(), $draft->fresh());

    expect($context($draft))->toBe($whereThePlanStands)
        ->and($comparison->pendingBeforeEffectiveBasisPoints)->toBe(0)
        ->and($comparison->toArray()['pending_before_effective_percent'])->toBe('0.00');

    // Cinco pontos a mais em maio passam do teto, que é de 80%: nada fica
    // pendente antes da vigência.
    $rows = planVersionActivationDraftRows($draft);
    $rows['2027-05']['planned_monthly_percent'] = '15.00';
    $draft = planVersionActivationSaveDraft($draft, $actor, $rows);
    $before = planVersionActivationFootprint($annex);

    expect(planVersionActivationRefusal(fn () => planVersionActivationActivate($draft, $actor)))
        ->toBe(['lines' => ['O cronograma a partir de 05/2027 prevê 85,00% de avanço físico mensal somado, mas restam 80,00% da obra (avanço atual 20,00%). O avanço já executado não muda com a revisão.']]);

    expect(planVersionActivationFootprint($annex))->toBe($before);

    // Com os 80% que restam, a revisão vale, e o acumulado previsto fecha em
    // 100% em dezembro -- sem o trabalho de março contado duas vezes.
    $rows['2027-05']['planned_monthly_percent'] = '10.00';
    $v2 = planVersionActivationActivate(planVersionActivationSaveDraft($draft, $actor, $rows), $actor);

    expect($v2->status)->toBe(MeasurementPlanVersionStatus::Active)
        ->and($v2->effective_from->toDateString())->toBe('2027-05-01')
        ->and($v2->activation_progress_percent)->toBe('20.00')
        ->and($v2->construction_fund_amount)->toBe('1150000.00')
        ->and($annexV1->fresh()->status)->toBe(MeasurementPlanVersionStatus::Superseded)
        ->and(planVersionActivationCumulative($v2))->toBe([
            '2027-03' => '10.00',
            '2027-04' => '20.00',
            '2027-05' => '30.00',
            '2027-06' => '40.00',
            '2027-07' => '50.00',
            '2027-08' => '60.00',
            '2027-09' => '70.00',
            '2027-10' => '80.00',
            '2027-11' => '90.00',
            '2027-12' => '100.00',
        ])
        ->and(planVersionActivationLifecycle($annex, 'plan_version_activated')->sole()->properties['pending_before_effective_percent'])->toBe('0.00');
});

// ── Telas velhas e portas fechadas ───────────────────────────────────────────

it('refuses to activate a draft changed after the screen was opened', function () {
    ['actor' => $actor, 'planSet' => $planSet] = planVersionActivationYearPlan('2027-07-05 12:00:00');
    $service = app(MeasurementPlanVersionService::class);
    $draft = planVersionActivationRevision($planSet, $actor);
    $seenRevision = (int) $draft->revision;
    // Outra pessoa grava o rascunho depois que a tela de ativação foi aberta.
    $service->updateDraft($draft, $actor, ['construction_fund_amount' => '1200000.00'], null, $seenRevision);
    $before = planVersionActivationFootprint($planSet);

    // Ativar o que a pessoa não viu poria em vigor um fundo que ela não conferiu.
    expect(fn () => $service->activate($draft, $actor, $seenRevision))
        ->toThrow(new MeasurementWorkflowException(sprintf(MeasurementPlanVersionService::STALE_DRAFT_MESSAGE, 'V2')));

    expect(planVersionActivationFootprint($planSet))->toBe($before)
        ->and($draft->fresh()->revision)->toBe($seenRevision + 1)
        ->and($draft->fresh()->status)->toBe(MeasurementPlanVersionStatus::Draft);
});

it('refuses to activate a version that is no longer a draft', function (string $case, string $label, string $status) {
    ['actor' => $actor, 'planSet' => $planSet, 'v1' => $v1] = planVersionActivationYearPlan('2027-07-05 12:00:00');
    $service = app(MeasurementPlanVersionService::class);
    $target = match ($case) {
        'active' => $v1,
        'superseded' => tap($v1, fn () => planVersionActivationActivate(planVersionActivationRevision($planSet, $actor), $actor)),
        'cancelled' => tap(
            planVersionActivationRevision($planSet, $actor),
            fn (MeasurementPlanVersion $draft) => $service->cancel($draft, $actor, 'Revisão aberta por engano.', (int) $draft->revision),
        ),
    };
    $before = planVersionActivationFootprint($planSet);

    // Duplo clique, segunda aba ou tela antiga: a versão que a pessoa viu como
    // rascunho já mudou de situação, e ativá-la de novo reescreveria a vigência.
    expect(fn () => $service->activate($target, $actor, (int) $target->revision))
        ->toThrow(new MeasurementWorkflowException(sprintf(MeasurementPlanVersionService::NOT_A_DRAFT_MESSAGE, $label, $status)));

    expect(planVersionActivationFootprint($planSet))->toBe($before);
})->with([
    'already active (second click)' => ['active', 'V1', 'vigente'],
    'superseded by a later revision' => ['superseded', 'V1', 'substituída'],
    'cancelled revision' => ['cancelled', 'V2', 'cancelada'],
]);

it('refuses activations while the operation is terminal', function (string $transition, string $label) {
    ['actor' => $actor, 'operation' => $operation, 'planSet' => $planSet] = planVersionActivationYearPlan('2027-07-05 12:00:00');
    $draft = planVersionActivationRevision($planSet, $actor);
    $lifecycle = app(OperationLifecycleService::class);

    if ($transition === 'complete') {
        $lifecycle->complete($operation, $actor);
    } else {
        $lifecycle->cancel($operation, $actor, 'Operação cancelada pelo comitê de crédito.');
    }

    $before = planVersionActivationFootprint($planSet);

    // Operação encerrada não replaneja: a ativação espera a reabertura.
    expect(fn () => planVersionActivationActivate($draft, $actor))
        ->toThrow(new MeasurementWorkflowException(sprintf(MeasurementPlanVersionService::TERMINAL_OPERATION_MESSAGE, $label)));

    expect(planVersionActivationFootprint($planSet))->toBe($before);
})->with([
    'completed' => ['complete', 'concluída'],
    'canceled' => ['cancel', 'cancelada'],
]);

it('refuses an actor who cannot change the operation', function (string $who) {
    ['actor' => $actor, 'operation' => $operation, 'planSet' => $planSet] = planVersionActivationYearPlan('2027-07-05 12:00:00');
    $draft = planVersionActivationRevision($planSet, $actor);
    $intruder = User::factory()->withTwoFactor()->create();

    if ($who === 'participant without permission') {
        // Revisor da etapa 3: participa da operação, mas não pode alterá-la.
        $intruder->givePermissionTo(['operations.view', 'measurements.view', 'measurements.review']);
        $operation->forceFill(['stage3_reviewer_user_id' => $intruder->id])->save();
    } else {
        // Pode alterar operações, mas não participa desta.
        $intruder->givePermissionTo(['operations.view', 'operations.update']);
    }

    $before = planVersionActivationFootprint($planSet);

    expect(fn () => planVersionActivationActivate($draft, $intruder))
        ->toThrow(new AuthorizationException('Você não pode alterar os planos de medição desta operação.'));

    expect(planVersionActivationFootprint($planSet))->toBe($before);
})->with([
    'participant without permission' => ['participant without permission'],
    'permission without participation' => ['permission without participation'],
]);

// ── O banco como última barreira ─────────────────────────────────────────────

it('refuses at the database a second active version of the same plan, even through a raw update', function () {
    ['actor' => $actor, 'planSet' => $planSet] = planVersionActivationYearPlan('2027-07-05 12:00:00');
    $draft = planVersionActivationRevision($planSet, $actor);
    $before = planVersionActivationFootprint($planSet);

    // Uma escrita que pule o serviço (script, correção manual) ainda esbarra
    // na unique da coluna gerada: duas vigentes no mesmo plano dariam duas
    // respostas para "qual cronograma vale".
    expect(fn () => DB::table('measurement_plan_versions')->where('id', $draft->id)->update([
        'status' => MeasurementPlanVersionStatus::Active->value,
        'effective_from' => '2027-07-01',
        'activated_at' => '2027-07-05 12:00:00',
        'activated_by' => $actor->id,
    ]))->toThrow(UniqueConstraintViolationException::class, planVersionActivationUniqueMarker('active_plan_set_id', 'mpv_active_plan_set_unique'));

    expect(planVersionActivationFootprint($planSet))->toBe($before);
});

it('refuses at the database a second draft of the same plan, even through raw writes', function () {
    ['actor' => $actor, 'planSet' => $planSet] = planVersionActivationYearPlan('2027-07-05 12:00:00');
    $service = app(MeasurementPlanVersionService::class);
    $cancelled = planVersionActivationRevision($planSet, $actor);
    $service->cancel($cancelled, $actor, 'Revisão aberta por engano.', (int) $cancelled->revision);
    planVersionActivationRevision($planSet, $actor);
    $before = planVersionActivationFootprint($planSet);
    $marker = planVersionActivationUniqueMarker('draft_plan_set_id', 'mpv_draft_plan_set_unique');

    // Reviver o rascunho cancelado ou inserir outro ao lado do V3 deixaria
    // duas edições paralelas do mesmo plano disputando a próxima vigência.
    expect(fn () => DB::table('measurement_plan_versions')->where('id', $cancelled->id)->update([
        'status' => MeasurementPlanVersionStatus::Draft->value,
        'cancelled_at' => null,
        'cancelled_by' => null,
        'cancellation_reason' => null,
    ]))->toThrow(UniqueConstraintViolationException::class, $marker)
        ->and(fn () => DB::table('measurement_plan_versions')->insert([
            'operation_id' => $planSet->operation_id,
            'plan_set_id' => $planSet->id,
            'version_number' => 4,
            'status' => MeasurementPlanVersionStatus::Draft->value,
            'revision' => 0,
            'created_at' => '2027-07-05 12:00:00',
            'updated_at' => '2027-07-05 12:00:00',
        ]))->toThrow(UniqueConstraintViolationException::class, $marker);

    expect(planVersionActivationFootprint($planSet))->toBe($before)
        ->and(MeasurementPlanVersion::query()->where('plan_set_id', $planSet->id)->orderBy('version_number')->get()
            ->map(fn (MeasurementPlanVersion $version): string => $version->label().' '.$version->status->value)
            ->all())
        ->toBe(['V1 active', 'V2 cancelled', 'V3 draft']);
});
