<?php

use App\Enums\MeasurementResponsibility;
use App\Enums\WorkflowAuthorizationSource;
use App\Exceptions\MeasurementWorkflowException;
use App\Models\Measurement;
use App\Models\MeasurementPayment;
use App\Models\MeasurementReview;
use App\Models\Operation;
use App\Models\ResponsibilityDelegation;
use App\Models\User;
use App\Notifications\MeasurementWorkflowNotification;
use App\Services\MeasurementAuthorizationService;
use App\Services\MeasurementWorkflow;
use App\Services\ResponsibilityDelegationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\MeasurementNoDisbursementScenario as Scenario;

/*
 * Quem conclui a etapa Pagamento sem desembolso e quem dá a ciência na
 * Finalização, no domínio, com um usuário por responsabilidade -- e as
 * chamadas forjadas que o domínio recusa sem mudar nada. A autoridade é a de
 * sempre (MeasurementAuthorizationService::resolveAuthorization()):
 * responsável direto, delegação efetiva ou override administrativo, nessa
 * ordem. A decisão sem desembolso não cria autoridade nova e não mexe na das
 * etapas seguintes.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    Notification::fake();
});

function p4aAuthWorkflow(): MeasurementWorkflow
{
    return app(MeasurementWorkflow::class);
}

/**
 * Editor: tem todas as permissões de medição, `measurements.pay` e
 * `measurements.finalize` inclusive. Toda recusa a um editor, abaixo, é por
 * responsabilidade, não por falta de permissão.
 */
function p4aAuthEditor(): User
{
    $user = User::factory()->withTwoFactor()->create();
    $user->assignRole('editor');

    return $user;
}

/**
 * A operação do cenário compartilhado com os sete papéis do fluxo em
 * usuários distintos -- o ator único daquele cenário sai da operação -- e
 * quem fica de fora dela: só na lista "Notificar em caso de recusa"
 * (`watcher`), dono dos sete papéis de outra operação (`outsider`), editor
 * sem papel algum (`stranger`), o admin e o super-admin, que não participam.
 *
 * `actor` é quem envia a medição no cenário compartilhado: o coordenador.
 *
 * @param  list<array{fund: string|null, name?: string, initial?: string, reference_date?: string|null}>  $developments
 * @return array<string, mixed>
 */
function p4aAuthScenario(array $developments = [['fund' => '1000000.00']]): array
{
    $plan = Scenario::plan($developments);
    $actors = [
        'coordinator' => p4aAuthEditor(),
        'engineering' => p4aAuthEditor(),
        'management' => p4aAuthEditor(),
        'compliance' => p4aAuthEditor(),
        'manager' => p4aAuthEditor(),
        'uploader' => p4aAuthEditor(),
        'finalizer' => p4aAuthEditor(),
    ];

    // Sem usuário autenticado, a troca não passa pela governança de
    // responsáveis: é a montagem do cenário, não o que está sob teste.
    $plan['operation']->forceFill([
        'assigned_user_id' => $actors['coordinator']->id,
        'responsible_user_id' => $actors['engineering']->id,
        'stage2_reviewer_user_id' => $actors['management']->id,
        'stage3_reviewer_user_id' => $actors['compliance']->id,
        'payment_manager_user_id' => $actors['manager']->id,
        'payment_receipt_uploader_user_id' => $actors['uploader']->id,
        'payment_finalizer_user_id' => $actors['finalizer']->id,
    ])->save();

    $watcher = p4aAuthEditor();
    $plan['operation']->rejectionNotifyUsers()->attach($watcher->id);

    $outsider = p4aAuthEditor();
    $otherOperation = Operation::factory()->create(array_fill_keys(Operation::RESPONSIBILITY_FIELDS, $outsider->id));

    $admin = User::factory()->withTwoFactor()->create();
    $admin->assignRole('admin');
    $superAdmin = User::factory()->withTwoFactor()->create();
    $superAdmin->assignRole('super-admin');

    return [
        'actor' => $actors['coordinator'],
        'operation' => $plan['operation']->fresh(),
        'planSets' => $plan['planSets'],
        'lines' => $plan['lines'],
        ...$actors,
        'watcher' => $watcher,
        'outsider' => $outsider,
        'otherOperation' => $otherOperation,
        'stranger' => p4aAuthEditor(),
        'admin' => $admin,
        'superAdmin' => $superAdmin,
    ];
}

/**
 * A medição original de maio, enviada pelo coordenador e decidida etapa a
 * etapa por quem responde por cada uma, parada na etapa `$stage` (1 a 4).
 *
 * @param  array<string, mixed>  $scenario
 * @param  list<int|float|string>  $percents  realizado do mês, na ordem dos empreendimentos
 */
function p4aAuthAtStage(array $scenario, int $stage, array $percents = [0]): Measurement
{
    $measurement = Scenario::measurement($scenario, '2026-05');
    $progress = [];

    foreach ($scenario['planSets'] as $index => $planSet) {
        $progress[$planSet->id] = $percents[$index];
    }

    foreach ([1 => 'engineering', 2 => 'management', 3 => 'compliance'] as $decidedStage => $reviewer) {
        if ($decidedStage >= $stage) {
            break;
        }

        p4aAuthWorkflow()->approve(
            $measurement->fresh(),
            $scenario[$reviewer],
            engineeringProgress: $decidedStage === MeasurementWorkflow::STAGE_ENGINEERING ? $progress : [],
        );
    }

    return $measurement->fresh();
}

/**
 * Delegação válida da responsabilidade nesta operação, criada pelo serviço
 * (que confere a autoridade do delegante) a pedido do admin, do responsável
 * direto para um editor que não participa da operação.
 *
 * @param  array<string, mixed>  $scenario
 * @return array{delegate: User, delegation: ResponsibilityDelegation}
 */
function p4aAuthDelegate(array $scenario, MeasurementResponsibility $responsibility): array
{
    $delegate = p4aAuthEditor();
    $delegation = app(ResponsibilityDelegationService::class)->createDelegation([
        'delegator_user_id' => $scenario['operation']->responsibleUserIdFor($responsibility),
        'delegate_user_id' => $delegate->id,
        'scope_type' => ResponsibilityDelegation::SCOPE_STAGE,
        'scope_stage' => $responsibility->stage(),
        'scope_responsibility' => $responsibility->value,
        'scope_operation_id' => $scenario['operation']->id,
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addDays(5),
        'reason' => 'Férias do responsável direto.',
    ], $scenario['admin']);

    return ['delegate' => $delegate, 'delegation' => $delegation];
}

/**
 * Delegação gravada direto, sem o serviço -- inclusive a que ele não deixaria
 * nascer --, para provar o que a resolução da autoridade recusa. Por padrão:
 * vigente, do Gestor de Pagamento desta operação, escopada na etapa
 * Pagamento dela.
 *
 * @param  array<string, mixed>  $scenario
 * @param  array<string, mixed>  $attributes
 */
function p4aAuthStoredDelegation(
    array $scenario,
    User $delegate,
    array $attributes = [],
    ?User $delegator = null,
    ?Operation $operation = null,
    MeasurementResponsibility $responsibility = MeasurementResponsibility::PaymentManager,
): ResponsibilityDelegation {
    return ResponsibilityDelegation::factory()
        ->forStage($responsibility->stage(), $operation ?? $scenario['operation'], $responsibility)
        ->create([
            'delegator_user_id' => ($delegator ?? $scenario['manager'])->id,
            'delegate_user_id' => $delegate->id,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(5),
            'created_by' => $scenario['admin']->id,
            ...$attributes,
        ]);
}

/**
 * A exceção da ação, ou `null` -- lida inteira (classe, mensagem, campos),
 * não por `toThrow()`: as mensagens têm dois-pontos.
 */
function p4aAuthThrown(callable $action): ?Throwable
{
    try {
        $action();
    } catch (Throwable $exception) {
        return $exception;
    }

    return null;
}

/**
 * Os eventos da trilha protegida desta medição, em ordem.
 *
 * @return Collection<int, Activity>
 */
function p4aAuthActivities(Measurement $measurement, string $description, ?int $stage = null): Collection
{
    return Activity::query()
        ->where('log_name', 'measurement_workflow')
        ->where('description', $description)
        ->where('subject_type', $measurement->getMorphClass())
        ->where('subject_id', $measurement->getKey())
        ->orderBy('id')
        ->get()
        ->filter(fn (Activity $activity): bool => $stage === null || (int) ($activity->properties['stage'] ?? 0) === $stage)
        ->values();
}

/**
 * O que uma recusa não pode ter mudado: a situação, a etapa e a revisão do
 * fluxo, cada revisão de etapa, a trilha protegida e os pagamentos.
 *
 * @return array<string, mixed>
 */
function p4aAuthState(Measurement $measurement): array
{
    $fresh = $measurement->fresh();

    return [
        'status' => $fresh->status,
        'current_stage' => (int) $fresh->current_stage,
        'workflow_revision' => (int) $fresh->workflow_revision,
        'reviews' => $fresh->reviews()->orderBy('stage')->get()
            ->map(fn (MeasurementReview $review): array => $review->only(['stage', 'status', 'reviewer_user_id', 'notes']))
            ->all(),
        'workflow_events' => Activity::query()
            ->where('log_name', 'measurement_workflow')
            ->where('subject_type', $fresh->getMorphClass())
            ->where('subject_id', $fresh->getKey())
            ->count(),
        'payments' => MeasurementPayment::query()->where('measurement_id', $fresh->getKey())->count(),
    ];
}

function p4aAuthNotified(User $user, string $event): int
{
    return Notification::sent(
        $user,
        MeasurementWorkflowNotification::class,
        fn (MeasurementWorkflowNotification $notification): bool => $notification->event === $event,
    )->count();
}

/**
 * Reescreve, direto no banco, as propriedades gravadas de uma Activity.
 *
 * @param  Closure(array<string, mixed>): array<string, mixed>  $rewrite
 */
function p4aAuthRewriteProperties(Activity $activity, Closure $rewrite): void
{
    DB::table($activity->getTable())
        ->where('id', $activity->getKey())
        ->update(['properties' => json_encode($rewrite($activity->properties->all()), JSON_THROW_ON_ERROR)]);
}

// ── Premissa: um usuário por responsabilidade ────────────────────────────────

it('gives every responsibility its own user and keeps everyone else out by responsibility, not by permission', function () {
    $s = p4aAuthScenario();
    $operation = $s['operation'];

    expect($operation->only(Operation::RESPONSIBILITY_FIELDS))->toBe([
        'assigned_user_id' => $s['coordinator']->id,
        'responsible_user_id' => $s['engineering']->id,
        'stage2_reviewer_user_id' => $s['management']->id,
        'stage3_reviewer_user_id' => $s['compliance']->id,
        'payment_manager_user_id' => $s['manager']->id,
        'payment_receipt_uploader_user_id' => $s['uploader']->id,
        'payment_finalizer_user_id' => $s['finalizer']->id,
    ])
        ->and(array_unique($operation->only(Operation::RESPONSIBILITY_FIELDS)))->toHaveCount(7)
        // Todos os não administradores podem pagar e finalizar em alguma operação.
        ->and(collect(['coordinator', 'engineering', 'management', 'compliance', 'manager', 'uploader', 'finalizer', 'watcher', 'outsider', 'stranger'])
            ->reject(fn (string $key): bool => $s[$key]->can('measurements.pay') && $s[$key]->can('measurements.finalize'))
            ->all())->toBe([])
        ->and($operation->rejectionNotifyUsers()->pluck('users.id')->map(fn (mixed $id): int => (int) $id)->all())->toBe([$s['watcher']->id])
        ->and(collect(['watcher', 'outsider', 'stranger', 'admin', 'superAdmin'])
            ->filter(fn (string $key): bool => $operation->hasParticipant($s[$key]))
            ->all())->toBe([])
        ->and($s['otherOperation']->hasDirectResponsibility($s['outsider'], MeasurementResponsibility::PaymentManager))->toBeTrue()
        ->and($s['otherOperation']->hasDirectResponsibility($s['outsider'], MeasurementResponsibility::Finalizer))->toBeTrue();
});

// ── Quem decide a etapa Pagamento sem desembolso ─────────────────────────────

it('lets the direct Payment Manager conclude the Payment stage without disbursement, audited as direct authority', function () {
    $s = p4aAuthScenario();
    $measurement = p4aAuthAtStage($s, MeasurementWorkflow::STAGE_PAYMENT);
    $workflow = p4aAuthWorkflow();

    expect(app(MeasurementAuthorizationService::class)->resolveAuthorization($s['manager'], $measurement, MeasurementResponsibility::PaymentManager)->source)
        ->toBe(WorkflowAuthorizationSource::Direct)
        ->and($workflow->canConcludeWithoutDisbursement($measurement, $s['manager']))->toBeTrue();

    $measurement = Scenario::concludeWithoutDisbursement($s, $measurement, actor: $s['manager']);
    $decision = p4aAuthActivities($measurement, 'measurement_stage_approved', MeasurementWorkflow::STAGE_PAYMENT)->sole();

    expect($measurement->only(['status', 'current_stage']))->toBe(['status' => 'approved', 'current_stage' => MeasurementWorkflow::STAGE_FINALIZATION])
        ->and((int) $decision->causer_id)->toBe($s['manager']->id)
        ->and($decision->properties['actual_actor_user_id'])->toBe($s['manager']->id)
        ->and($decision->properties['responsibility'])->toBe('payment_manager_user_id')
        ->and($decision->properties['expected_responsible_user_id'])->toBe($s['manager']->id)
        ->and($decision->properties['delegated'])->toBeFalse()
        ->and($decision->properties['delegation_id'])->toBeNull()
        ->and($decision->properties['delegator_user_id'])->toBeNull()
        ->and($decision->properties['delegation_scope'])->toBeNull()
        ->and($decision->properties['admin_override'])->toBeFalse()
        ->and(WorkflowAuthorizationSource::fromActivityProperties($decision->properties->all()))->toBe(WorkflowAuthorizationSource::Direct)
        ->and($decision->properties['no_disbursement']['decision'])->toBe('no_disbursement')
        ->and($decision->properties['no_disbursement']['justification'])->toBe(Scenario::JUSTIFICATION)
        ->and($decision->properties['no_disbursement']['payments_registered'])->toBe(0)
        ->and($decision->properties['no_disbursement']['amount_due']['nothing_due'])->toBeTrue()
        ->and($measurement->reviewForStage(MeasurementWorkflow::STAGE_PAYMENT)->reviewer_user_id)->toBe($s['manager']->id)
        ->and($workflow->noDisbursementDecision($measurement)['decided_by'])->toBe($s['manager']->id)
        ->and(MeasurementPayment::query()->count())->toBe(0)
        // O aviso vai só ao Finalizador: nem a quem decidiu, nem à lista de recusa.
        ->and(p4aAuthNotified($s['finalizer'], 'ready_to_finalize_without_disbursement'))->toBe(1)
        ->and(p4aAuthNotified($s['manager'], 'ready_to_finalize_without_disbursement'))->toBe(0)
        ->and(p4aAuthNotified($s['watcher'], 'ready_to_finalize_without_disbursement'))->toBe(0);
});

it('lets an active delegate of the Payment Manager conclude it, audited as delegated authority', function () {
    $s = p4aAuthScenario();
    ['delegate' => $delegate, 'delegation' => $delegation] = p4aAuthDelegate($s, MeasurementResponsibility::PaymentManager);
    $measurement = p4aAuthAtStage($s, MeasurementWorkflow::STAGE_PAYMENT);
    $workflow = p4aAuthWorkflow();

    expect($s['operation']->hasParticipant($delegate))->toBeFalse()
        ->and(app(MeasurementAuthorizationService::class)->resolveAuthorization($delegate, $measurement, MeasurementResponsibility::PaymentManager)->source)
        ->toBe(WorkflowAuthorizationSource::Delegated)
        ->and($workflow->canConcludeWithoutDisbursement($measurement, $delegate))->toBeTrue();

    $measurement = Scenario::concludeWithoutDisbursement($s, $measurement, actor: $delegate);
    $decision = p4aAuthActivities($measurement, 'measurement_stage_approved', MeasurementWorkflow::STAGE_PAYMENT)->sole();

    expect($measurement->status)->toBe('approved')
        ->and((int) $decision->causer_id)->toBe($delegate->id)
        ->and($decision->properties['actual_actor_user_id'])->toBe($delegate->id)
        ->and($decision->properties['responsibility'])->toBe('payment_manager_user_id')
        // O responsável direto continua sendo o Gestor de Pagamento.
        ->and($decision->properties['expected_responsible_user_id'])->toBe($s['manager']->id)
        ->and($decision->properties['delegated'])->toBeTrue()
        ->and($decision->properties['delegation_id'])->toBe($delegation->id)
        ->and($decision->properties['delegator_user_id'])->toBe($s['manager']->id)
        ->and($decision->properties['delegation_scope'])->toBe([
            'type' => ResponsibilityDelegation::SCOPE_STAGE,
            'operation_id' => $s['operation']->id,
            'stage' => MeasurementWorkflow::STAGE_PAYMENT,
            'responsibility' => MeasurementResponsibility::PaymentManager->value,
        ])
        ->and($decision->properties['admin_override'])->toBeFalse()
        ->and($decision->properties['no_disbursement']['justification'])->toBe(Scenario::JUSTIFICATION)
        ->and($workflow->noDisbursementDecision($measurement)['decided_by'])->toBe($delegate->id)
        ->and(p4aAuthNotified($s['finalizer'], 'ready_to_finalize_without_disbursement'))->toBe(1);
});

it('refuses the no-disbursement decision to whoever is not the Payment Manager and changes nothing', function (string $refused) {
    $s = p4aAuthScenario();
    $measurement = p4aAuthAtStage($s, MeasurementWorkflow::STAGE_PAYMENT);
    $before = p4aAuthState($measurement);

    expect(p4aAuthWorkflow()->canConcludeWithoutDisbursement($measurement, $s[$refused]))->toBeFalse();

    $refusal = p4aAuthThrown(fn () => Scenario::concludeWithoutDisbursement($s, $measurement, actor: $s[$refused]));

    expect($refusal)->toBeInstanceOf(AuthorizationException::class)
        ->and($refusal->getMessage())->toBe('Você não possui permissão e responsabilidade para decidir esta etapa.')
        ->and(p4aAuthState($measurement))->toBe($before)
        ->and(p4aAuthActivities($measurement, 'measurement_stage_approved', MeasurementWorkflow::STAGE_PAYMENT))->toBeEmpty()
        ->and(p4aAuthNotified($s['finalizer'], 'ready_to_finalize_without_disbursement'))->toBe(0);

    // A recusa não gastou a etapa: o Gestor de Pagamento ainda decide -- e a
    // decisão de verdade muda o que a recusa deixou intacto.
    expect(Scenario::concludeWithoutDisbursement($s, $measurement, actor: $s['manager'])->status)->toBe('approved')
        ->and(p4aAuthState($measurement))->not->toBe($before);
})->with([
    'the Finalizer' => ['finalizer'],
    'the receipt uploader' => ['uploader'],
    'the coordinator (assigned user)' => ['coordinator'],
    'the Engineering reviewer' => ['engineering'],
    'the Management reviewer' => ['management'],
    'the Compliance (stage 3) reviewer' => ['compliance'],
    'a notification-only recipient (rejection list)' => ['watcher'],
    'the Payment Manager of another operation' => ['outsider'],
    'an editor with every measurement permission and no role anywhere' => ['stranger'],
]);

it('refuses the holder of the Payment Manager column who lacks measurements.pay: the permission gates the responsibility', function () {
    $s = p4aAuthScenario();
    $measurement = p4aAuthAtStage($s, MeasurementWorkflow::STAGE_PAYMENT);
    $holder = User::factory()->withTwoFactor()->create();
    $holder->givePermissionTo(['measurements.view', 'measurements.update', 'measurements.review', 'measurements.receipts', 'measurements.finalize']);
    $s['operation']->forceFill(['payment_manager_user_id' => $holder->id])->save();
    $measurement = $measurement->fresh();
    $before = p4aAuthState($measurement);
    $authorization = app(MeasurementAuthorizationService::class);

    expect($measurement->operation->hasDirectResponsibility($holder, MeasurementResponsibility::PaymentManager))->toBeTrue()
        ->and($holder->can('measurements.pay'))->toBeFalse()
        ->and($authorization->resolveAuthorization($holder, $measurement, MeasurementResponsibility::PaymentManager)->source)->toBe(WorkflowAuthorizationSource::None)
        ->and(p4aAuthWorkflow()->canConcludeWithoutDisbursement($measurement, $holder))->toBeFalse();

    $refusal = p4aAuthThrown(fn () => Scenario::concludeWithoutDisbursement($s, $measurement, actor: $holder));
    // Quem deixou a coluna também não decide mais, ainda que tenha a permissão.
    $former = p4aAuthThrown(fn () => Scenario::concludeWithoutDisbursement($s, $measurement, actor: $s['manager']));

    expect($refusal)->toBeInstanceOf(AuthorizationException::class)
        ->and($former)->toBeInstanceOf(AuthorizationException::class)
        ->and(p4aAuthState($measurement))->toBe($before)
        ->and(p4aAuthActivities($measurement, 'measurement_stage_approved', MeasurementWorkflow::STAGE_PAYMENT))->toBeEmpty();

    // Com a permissão, o mesmo titular decide -- como responsável direto.
    $holder->givePermissionTo('measurements.pay');

    expect(p4aAuthWorkflow()->canConcludeWithoutDisbursement($measurement->fresh(), $holder))->toBeTrue();

    $measurement = Scenario::concludeWithoutDisbursement($s, $measurement, actor: $holder);
    $decision = p4aAuthActivities($measurement, 'measurement_stage_approved', MeasurementWorkflow::STAGE_PAYMENT)->sole();

    expect($measurement->status)->toBe('approved')
        ->and($decision->properties['actual_actor_user_id'])->toBe($holder->id)
        ->and($decision->properties['delegated'])->toBeFalse()
        ->and($decision->properties['admin_override'])->toBeFalse();
});

it('authorizes the stored delegation as built by default: current, of this operation Payment Manager, to a permitted delegate (control)', function () {
    $s = p4aAuthScenario();
    $measurement = p4aAuthAtStage($s, MeasurementWorkflow::STAGE_PAYMENT);
    $delegate = p4aAuthStoredDelegation($s, p4aAuthEditor())->delegate;

    // Sem este controle, as recusas abaixo poderiam vir de uma delegação que
    // nunca cobriria nada, e não do defeito que cada uma introduz.
    expect(app(MeasurementAuthorizationService::class)->resolveAuthorization($delegate, $measurement, MeasurementResponsibility::PaymentManager)->source)
        ->toBe(WorkflowAuthorizationSource::Delegated)
        ->and(p4aAuthWorkflow()->canConcludeWithoutDisbursement($measurement, $delegate))->toBeTrue();
});

it('refuses a delegate whose delegation does not make them the Payment Manager of this operation now', function (Closure $delegationFor) {
    $s = p4aAuthScenario();
    $measurement = p4aAuthAtStage($s, MeasurementWorkflow::STAGE_PAYMENT);
    $delegate = $delegationFor($s)->delegate;
    $before = p4aAuthState($measurement);

    expect(app(MeasurementAuthorizationService::class)->resolveAuthorization($delegate, $measurement, MeasurementResponsibility::PaymentManager)->source)
        ->toBe(WorkflowAuthorizationSource::None)
        ->and(p4aAuthWorkflow()->canConcludeWithoutDisbursement($measurement, $delegate))->toBeFalse()
        ->and(p4aAuthThrown(fn () => Scenario::concludeWithoutDisbursement($s, $measurement, actor: $delegate)))->toBeInstanceOf(AuthorizationException::class)
        ->and(p4aAuthState($measurement))->toBe($before);
})->with([
    'expired' => [fn (array $s): ResponsibilityDelegation => p4aAuthStoredDelegation($s, p4aAuthEditor(), ['starts_at' => now()->subDays(10), 'ends_at' => now()->subDays(2)])],
    'revoked' => [fn (array $s): ResponsibilityDelegation => p4aAuthStoredDelegation($s, p4aAuthEditor(), ['revoked_at' => now(), 'revoked_by' => $s['admin']->id])],
    'not started yet' => [fn (array $s): ResponsibilityDelegation => p4aAuthStoredDelegation($s, p4aAuthEditor(), ['starts_at' => now()->addDay(), 'ends_at' => now()->addDays(3)])],
    'scoped to another operation' => [fn (array $s): ResponsibilityDelegation => p4aAuthStoredDelegation($s, p4aAuthEditor(), operation: $s['otherOperation'])],
    'of the Finalizer, not of the Payment Manager' => [fn (array $s): ResponsibilityDelegation => p4aAuthStoredDelegation($s, p4aAuthEditor(), delegator: $s['finalizer'], responsibility: MeasurementResponsibility::Finalizer)],
    'granted by someone who is not the Payment Manager' => [fn (array $s): ResponsibilityDelegation => p4aAuthStoredDelegation($s, p4aAuthEditor(), delegator: $s['coordinator'])],
    'to a delegate without measurements.pay' => [fn (array $s): ResponsibilityDelegation => p4aAuthStoredDelegation(
        $s,
        tap(User::factory()->withTwoFactor()->create(), fn (User $user) => $user->givePermissionTo(['measurements.view', 'measurements.review'])),
    )],
]);

it('authorizes an administrator who does not participate by the workflow override, audited as such', function (string $administrator) {
    $s = p4aAuthScenario();
    $measurement = p4aAuthAtStage($s, MeasurementWorkflow::STAGE_PAYMENT);
    $actor = $s[$administrator];

    // Comportamento existente, documentado: nas decisões de etapa, o admin e o
    // super-admin que não participam são autorizados pelo override.
    expect($s['operation']->hasParticipant($actor))->toBeFalse()
        ->and(app(MeasurementAuthorizationService::class)->resolveAuthorization($actor, $measurement, MeasurementResponsibility::PaymentManager)->source)
        ->toBe(WorkflowAuthorizationSource::AdminOverride)
        ->and(p4aAuthWorkflow()->canConcludeWithoutDisbursement($measurement, $actor))->toBeTrue();

    $measurement = Scenario::concludeWithoutDisbursement($s, $measurement, actor: $actor);
    $decision = p4aAuthActivities($measurement, 'measurement_stage_approved', MeasurementWorkflow::STAGE_PAYMENT)->sole();

    expect($measurement->status)->toBe('approved')
        ->and((int) $decision->causer_id)->toBe($actor->id)
        ->and($decision->properties['actual_actor_user_id'])->toBe($actor->id)
        ->and($decision->properties['admin_override'])->toBeTrue()
        ->and($decision->properties['delegated'])->toBeFalse()
        ->and($decision->properties['delegation_id'])->toBeNull()
        ->and($decision->properties['responsibility'])->toBe('payment_manager_user_id')
        ->and($decision->properties['expected_responsible_user_id'])->toBe($s['manager']->id)
        ->and($decision->properties['no_disbursement']['justification'])->toBe(Scenario::JUSTIFICATION)
        ->and(p4aAuthWorkflow()->noDisbursementDecision($measurement)['decided_by'])->toBe($actor->id);
})->with([
    'admin' => ['admin'],
    'super-admin' => ['superAdmin'],
]);

// ── Quem dá a ciência na Finalização ─────────────────────────────────────────

it('refuses the Finalization to whoever is not the Finalizer, the Payment Manager who decided included', function (string $refused) {
    $s = p4aAuthScenario();
    $s['managerDelegate'] = p4aAuthDelegate($s, MeasurementResponsibility::PaymentManager)['delegate'];
    $measurement = Scenario::concludeWithoutDisbursement($s, p4aAuthAtStage($s, MeasurementWorkflow::STAGE_PAYMENT), actor: $s['manager']);
    $before = p4aAuthState($measurement);
    $workflow = p4aAuthWorkflow();

    expect($workflow->requiresNoDisbursementAcknowledgment($measurement))->toBeTrue()
        ->and($workflow->canFinalize($measurement, $s[$refused]))->toBeFalse();

    $refusal = p4aAuthThrown(fn () => Scenario::finalizeAcknowledging($s, $measurement, $s[$refused]));

    expect($refusal)->toBeInstanceOf(AuthorizationException::class)
        ->and($refusal->getMessage())->toBe('Você não possui permissão e responsabilidade para esta ação.')
        ->and(p4aAuthState($measurement))->toBe($before)
        ->and(p4aAuthActivities($measurement, 'measurement_finalized'))->toBeEmpty();

    // A recusa não gastou a Finalização: o Finalizador ainda dá a ciência.
    expect(Scenario::finalizeAcknowledging($s, $measurement, $s['finalizer'])->status)->toBe('finalized');
})->with([
    'the Payment Manager who took the decision' => ['manager'],
    'a delegate of the Payment Manager' => ['managerDelegate'],
    'the receipt uploader' => ['uploader'],
    'the coordinator (assigned user)' => ['coordinator'],
    'the Compliance reviewer' => ['compliance'],
    'a notification-only recipient (rejection list)' => ['watcher'],
    'the Finalizer of another operation' => ['outsider'],
]);

it('records the acknowledgment under the Finalizer responsibility, with the Finalizer as causer and the decision maker kept apart', function () {
    $s = p4aAuthScenario();
    $measurement = Scenario::concludeWithoutDisbursement($s, p4aAuthAtStage($s, MeasurementWorkflow::STAGE_PAYMENT), actor: $s['manager']);
    $decision = p4aAuthActivities($measurement, 'measurement_stage_approved', MeasurementWorkflow::STAGE_PAYMENT)->sole();
    $workflow = p4aAuthWorkflow();

    expect($workflow->canFinalize($measurement, $s['finalizer']))->toBeTrue();

    $measurement = Scenario::finalizeAcknowledging($s, $measurement, $s['finalizer']);
    $finalization = p4aAuthActivities($measurement, 'measurement_finalized')->sole();
    $acknowledgment = $workflow->noDisbursementAcknowledgment($measurement);

    expect($measurement->status)->toBe('finalized')
        ->and((int) $finalization->causer_id)->toBe($s['finalizer']->id)
        ->and($finalization->properties['actual_actor_user_id'])->toBe($s['finalizer']->id)
        ->and($finalization->properties['responsibility'])->toBe('payment_finalizer_user_id')
        ->and($finalization->properties['expected_responsible_user_id'])->toBe($s['finalizer']->id)
        ->and($finalization->properties['delegated'])->toBeFalse()
        ->and($finalization->properties['admin_override'])->toBeFalse()
        ->and($finalization->properties['no_disbursement_acknowledged']['decision_activity_id'])->toBe($decision->id)
        ->and($finalization->properties['no_disbursement_acknowledged']['justification'])->toBe(Scenario::JUSTIFICATION)
        // Quem decidiu a etapa Pagamento e quem deu a ciência ficam separados.
        ->and($finalization->properties['no_disbursement_acknowledged']['decided_by'])->toBe($s['manager']->id)
        ->and($finalization->properties['no_disbursement_acknowledged']['same_actor_as_decision'])->toBeFalse()
        ->and($finalization->properties['no_disbursement_acknowledged']['decision_delegated'])->toBeFalse()
        ->and($finalization->properties['no_disbursement_acknowledged']['decision_admin_override'])->toBeFalse()
        ->and($finalization->properties['no_disbursement_acknowledged']['payments_registered'])->toBe(0)
        ->and($acknowledgment['acknowledged_by'])->toBe($s['finalizer']->id)
        ->and($acknowledgment['decided_by'])->toBe($s['manager']->id)
        ->and($measurement->reviewForStage(MeasurementWorkflow::STAGE_FINALIZATION)->reviewer_user_id)->toBe($s['finalizer']->id)
        ->and((int) $measurement->analyzed_by)->toBe($s['finalizer']->id)
        ->and(MeasurementPayment::query()->count())->toBe(0);
});

it('accepts the acknowledgment from a valid delegate of the Finalizer or by the administrative override, audited by its source', function (string $who, WorkflowAuthorizationSource $source) {
    $s = p4aAuthScenario();
    ['delegate' => $finalizerDelegate, 'delegation' => $delegation] = p4aAuthDelegate($s, MeasurementResponsibility::Finalizer);
    $s['finalizerDelegate'] = $finalizerDelegate;
    $measurement = Scenario::concludeWithoutDisbursement($s, p4aAuthAtStage($s, MeasurementWorkflow::STAGE_PAYMENT), actor: $s['manager']);
    $actor = $s[$who];

    expect(app(MeasurementAuthorizationService::class)->resolveAuthorization($actor, $measurement, MeasurementResponsibility::Finalizer)->source)->toBe($source)
        ->and(p4aAuthWorkflow()->canFinalize($measurement, $actor))->toBeTrue();

    $measurement = Scenario::finalizeAcknowledging($s, $measurement, $actor);
    $finalization = p4aAuthActivities($measurement, 'measurement_finalized')->sole();

    expect($measurement->status)->toBe('finalized')
        ->and((int) $finalization->causer_id)->toBe($actor->id)
        ->and($finalization->properties['actual_actor_user_id'])->toBe($actor->id)
        ->and($finalization->properties['responsibility'])->toBe('payment_finalizer_user_id')
        ->and($finalization->properties['expected_responsible_user_id'])->toBe($s['finalizer']->id)
        ->and(WorkflowAuthorizationSource::fromActivityProperties($finalization->properties->all()))->toBe($source)
        ->and($finalization->properties['delegated'])->toBe($source === WorkflowAuthorizationSource::Delegated)
        ->and($finalization->properties['delegation_id'])->toBe($source === WorkflowAuthorizationSource::Delegated ? $delegation->id : null)
        ->and($finalization->properties['admin_override'])->toBe($source === WorkflowAuthorizationSource::AdminOverride)
        ->and($finalization->properties['no_disbursement_acknowledged']['decided_by'])->toBe($s['manager']->id)
        ->and(p4aAuthWorkflow()->noDisbursementAcknowledgment($measurement)['acknowledged_by'])->toBe($actor->id);
})->with([
    'a valid delegate of the Finalizer' => ['finalizerDelegate', WorkflowAuthorizationSource::Delegated],
    'an admin who does not participate' => ['admin', WorkflowAuthorizationSource::AdminOverride],
    'a super-admin' => ['superAdmin', WorkflowAuthorizationSource::AdminOverride],
]);

it('records, without refusing it, an acknowledgment given by whoever took the decision', function () {
    // A segregação de funções é decisão pendente (Fase 4B): o administrador
    // que decide pela sobreposição e depois dá a ciência não é recusado, mas a
    // trilha diz que foi a mesma pessoa e por qual autoridade decidiu.
    $s = p4aAuthScenario();
    $measurement = Scenario::concludeWithoutDisbursement($s, p4aAuthAtStage($s, MeasurementWorkflow::STAGE_PAYMENT), actor: $s['admin']);
    $measurement = Scenario::finalizeAcknowledging($s, $measurement, $s['admin']);
    $acknowledgment = p4aAuthActivities($measurement, 'measurement_finalized')->sole()->properties['no_disbursement_acknowledged'];

    expect($measurement->status)->toBe('finalized')
        ->and($acknowledgment['decided_by'])->toBe($s['admin']->id)
        ->and($acknowledgment['same_actor_as_decision'])->toBeTrue()
        ->and($acknowledgment['decision_delegated'])->toBeFalse()
        ->and($acknowledgment['decision_admin_override'])->toBeTrue();
});

// ── Chamadas forjadas ao domínio ─────────────────────────────────────────────

it('refuses a forged decision without justification, even from whoever is authorized to decide', function (string $who) {
    $s = p4aAuthScenario();
    $measurement = p4aAuthAtStage($s, MeasurementWorkflow::STAGE_PAYMENT);
    $before = p4aAuthState($measurement);

    $refusal = p4aAuthThrown(fn () => Scenario::concludeWithoutDisbursement($s, $measurement, null, $s[$who]));

    expect($refusal)->toBeInstanceOf(ValidationException::class)
        ->and($refusal->errors())->toBe(['notes' => [MeasurementWorkflow::NO_DISBURSEMENT_JUSTIFICATION_REQUIRED]])
        ->and(p4aAuthState($measurement))->toBe($before);
})->with([
    'the Payment Manager' => ['manager'],
    'an admin by override' => ['admin'],
]);

it('refuses a forged no-disbursement decision on a payable original measurement, whoever authorizes it', function (string $who) {
    $s = p4aAuthScenario();
    $measurement = p4aAuthAtStage($s, MeasurementWorkflow::STAGE_PAYMENT, [10]);
    $before = p4aAuthState($measurement);

    expect(p4aAuthWorkflow()->canConcludeWithoutDisbursement($measurement, $s[$who]))->toBeFalse();

    $refusal = p4aAuthThrown(fn () => Scenario::concludeWithoutDisbursement($s, $measurement, actor: $s[$who]));

    // O override autoriza o ator, não a exceção financeira.
    expect($refusal)->toBeInstanceOf(ValidationException::class)
        ->and($refusal->errors())->toBe(['payments' => ['A etapa Pagamento não pode ser concluída sem desembolso: Torre A: valor devido R$ 100.000,00. Registre o pagamento devido.']])
        ->and(p4aAuthState($measurement))->toBe($before);
})->with([
    'the Payment Manager' => ['manager'],
    'an admin by override' => ['admin'],
    'a super-admin by override' => ['superAdmin'],
]);

it('answers a forged decision from outside the responsibility with the authorization refusal, before any financial or justification check', function () {
    $s = p4aAuthScenario();
    $measurement = p4aAuthAtStage($s, MeasurementWorkflow::STAGE_PAYMENT, [10]);
    $before = p4aAuthState($measurement);

    // Valor devido e nenhuma justificativa: quem não decide a etapa não fica
    // sabendo de nada disso pela recusa.
    $refusal = p4aAuthThrown(fn () => Scenario::concludeWithoutDisbursement($s, $measurement, null, $s['finalizer']));

    expect($refusal)->toBeInstanceOf(AuthorizationException::class)
        ->and($refusal->getMessage())->toBe('Você não possui permissão e responsabilidade para decidir esta etapa.')
        ->and(p4aAuthState($measurement))->toBe($before);
});

it('refuses the no-disbursement flag at a review stage, even from that stage own reviewer', function (int $stage, string $reviewer) {
    $s = p4aAuthScenario();
    $measurement = p4aAuthAtStage($s, $stage);
    $before = p4aAuthState($measurement);
    $snapshot = $measurement->engineering_snapshot;
    $progress = [$s['planSets'][0]->id => 0];

    $refusal = p4aAuthThrown(fn () => p4aAuthWorkflow()->approve(
        $measurement->fresh(),
        $s[$reviewer],
        Scenario::JUSTIFICATION,
        $progress,
        expectedStage: $stage,
        withoutDisbursement: true,
    ));

    expect($refusal)->toBeInstanceOf(MeasurementWorkflowException::class)
        ->and($refusal->getMessage())->toBe('A conclusão sem desembolso é uma decisão da etapa Pagamento.')
        ->and(p4aAuthState($measurement))->toBe($before)
        ->and($measurement->fresh()->engineering_snapshot)->toBe($snapshot);

    // A recusa não gastou a etapa: o mesmo revisor aprova pelo caminho comum.
    p4aAuthWorkflow()->approve($measurement->fresh(), $s[$reviewer], engineeringProgress: $progress);

    expect((int) $measurement->fresh()->current_stage)->toBe($stage + 1);
})->with([
    'Engineering (stage 1)' => [1, 'engineering'],
    'Management (stage 2)' => [2, 'management'],
    'Compliance (stage 3)' => [3, 'compliance'],
]);

it('refuses a no-disbursement decision that carries a workflow revision other than the current one', function (int $offset) {
    $s = p4aAuthScenario();
    $measurement = p4aAuthAtStage($s, MeasurementWorkflow::STAGE_PAYMENT);
    $before = p4aAuthState($measurement);

    $refusal = p4aAuthThrown(fn () => p4aAuthWorkflow()->approve(
        $measurement->fresh(),
        $s['manager'],
        Scenario::JUSTIFICATION,
        expectedStage: MeasurementWorkflow::STAGE_PAYMENT,
        expectedRevision: (int) $measurement->workflow_revision + $offset,
        withoutDisbursement: true,
    ));

    expect($refusal)->toBeInstanceOf(MeasurementWorkflowException::class)
        ->and($refusal->getMessage())->toBe('A etapa desta medição foi alterada por outra ação. Atualize a página.')
        ->and(p4aAuthState($measurement))->toBe($before);
})->with([
    'stale: read before the last change' => [-1],
    'forged ahead of the current revision' => [1],
]);

it('refuses a Payment Manager who forges the decision while the Compliance has not decided yet', function () {
    $s = p4aAuthScenario();
    $measurement = p4aAuthAtStage($s, 3);
    $before = p4aAuthState($measurement);
    $workflow = p4aAuthWorkflow();

    // Declarando a etapa Pagamento: a medição não está nela.
    $declared = p4aAuthThrown(fn () => $workflow->approve($measurement->fresh(), $s['manager'], Scenario::JUSTIFICATION, expectedStage: MeasurementWorkflow::STAGE_PAYMENT, withoutDisbursement: true));
    // Sem declarar: a etapa pendente é a da Compliance, que não é dele.
    $undeclared = p4aAuthThrown(fn () => $workflow->approve($measurement->fresh(), $s['manager'], Scenario::JUSTIFICATION, withoutDisbursement: true));

    expect($declared)->toBeInstanceOf(MeasurementWorkflowException::class)
        ->and($declared->getMessage())->toBe('A etapa desta medição foi alterada por outra ação. Atualize a página.')
        ->and($undeclared)->toBeInstanceOf(AuthorizationException::class)
        ->and($workflow->canConcludeWithoutDisbursement($measurement, $s['manager']))->toBeFalse()
        ->and(p4aAuthState($measurement))->toBe($before);
});

it('takes the no-disbursement decision only once: a replay is refused and the trail keeps exactly one decision', function () {
    $s = p4aAuthScenario();
    $measurement = p4aAuthAtStage($s, MeasurementWorkflow::STAGE_PAYMENT);
    $revision = (int) $measurement->workflow_revision;
    $workflow = p4aAuthWorkflow();

    $workflow->approve($measurement->fresh(), $s['manager'], Scenario::JUSTIFICATION, expectedStage: MeasurementWorkflow::STAGE_PAYMENT, expectedRevision: $revision, withoutDisbursement: true);
    $decided = p4aAuthState($measurement);

    // A mesma requisição de novo (o duplo clique, o formulário reenviado).
    $replay = p4aAuthThrown(fn () => $workflow->approve($measurement, $s['manager'], 'Segunda justificativa, forjada.', expectedStage: MeasurementWorkflow::STAGE_PAYMENT, expectedRevision: $revision, withoutDisbursement: true));
    // Nem a página relida reabre a decisão, nem pelo override: a etapa já é a Finalização.
    $reread = p4aAuthThrown(fn () => $workflow->approve($measurement->fresh(), $s['admin'], 'Terceira justificativa, forjada.', expectedStage: MeasurementWorkflow::STAGE_PAYMENT, withoutDisbursement: true));

    expect($replay)->toBeInstanceOf(MeasurementWorkflowException::class)
        ->and($replay->getMessage())->toBe('A etapa desta medição foi alterada por outra ação. Atualize a página.')
        ->and($reread)->toBeInstanceOf(MeasurementWorkflowException::class)
        ->and($reread->getMessage())->toBe('A etapa desta medição foi alterada por outra ação. Atualize a página.')
        ->and(p4aAuthState($measurement))->toBe($decided)
        ->and($decided['workflow_revision'])->toBe($revision + 1)
        ->and(p4aAuthActivities($measurement, 'measurement_stage_approved', MeasurementWorkflow::STAGE_PAYMENT))->toHaveCount(1)
        ->and($workflow->noDisbursementDecision($measurement->fresh())['justification'])->toBe(Scenario::JUSTIFICATION)
        ->and(p4aAuthNotified($s['finalizer'], 'ready_to_finalize_without_disbursement'))->toBe(1);
});

it('refuses the Finalizer a Finalization that omits the acknowledgment, and accepts it once acknowledged', function () {
    $s = p4aAuthScenario();
    $measurement = Scenario::concludeWithoutDisbursement($s, p4aAuthAtStage($s, MeasurementWorkflow::STAGE_PAYMENT), actor: $s['manager']);
    $before = p4aAuthState($measurement);

    $refusal = p4aAuthThrown(fn () => p4aAuthWorkflow()->finalize($measurement->fresh(), $s['finalizer']));

    expect($refusal)->toBeInstanceOf(ValidationException::class)
        ->and($refusal->errors())->toBe(['acknowledge_no_disbursement' => [MeasurementWorkflow::NO_DISBURSEMENT_ACKNOWLEDGMENT_REQUIRED]])
        ->and(p4aAuthState($measurement))->toBe($before)
        ->and(p4aAuthActivities($measurement, 'measurement_finalized'))->toBeEmpty();

    expect(Scenario::finalizeAcknowledging($s, $measurement, $s['finalizer'])->status)->toBe('finalized');
});

it('refuses the acknowledgment when the protected decision no longer matches the approved Payment stage', function (Closure $tamper) {
    $s = p4aAuthScenario();
    $measurement = Scenario::concludeWithoutDisbursement($s, p4aAuthAtStage($s, MeasurementWorkflow::STAGE_PAYMENT), actor: $s['manager']);
    $decision = p4aAuthActivities($measurement, 'measurement_stage_approved', MeasurementWorkflow::STAGE_PAYMENT)->sole();
    $workflow = p4aAuthWorkflow();

    expect($workflow->noDisbursementDecision($measurement))->not->toBeNull();

    $tamper($decision, $measurement);

    // A adulteração chegou ao banco: a decisão deixou de ser reconhecida.
    expect($workflow->noDisbursementDecision($measurement->fresh()))->toBeNull();

    $refusal = p4aAuthThrown(fn () => Scenario::finalizeAcknowledging($s, $measurement, $s['finalizer']));

    expect($refusal)->toBeInstanceOf(MeasurementWorkflowException::class)
        ->and($refusal->getMessage())->toBe(MeasurementWorkflow::NO_DISBURSEMENT_DECISION_MISSING)
        ->and($measurement->fresh()->status)->toBe('approved')
        ->and(p4aAuthActivities($measurement, 'measurement_finalized'))->toBeEmpty();
})->with([
    'justification rewritten in the trail' => [function (Activity $decision): void {
        p4aAuthRewriteProperties($decision, function (array $properties): array {
            $properties['no_disbursement']['justification'] = 'Justificativa trocada depois da decisão.';

            return $properties;
        });
    }],
    'decision kind rewritten in the trail' => [function (Activity $decision): void {
        p4aAuthRewriteProperties($decision, function (array $properties): array {
            $properties['no_disbursement']['decision'] = 'disbursement';

            return $properties;
        });
    }],
    'decision removed from the trail' => [function (Activity $decision): void {
        p4aAuthRewriteProperties($decision, function (array $properties): array {
            unset($properties['no_disbursement']);

            return $properties;
        });
    }],
    'stage note rewritten after the decision' => [function (Activity $decision, Measurement $measurement): void {
        DB::table('measurement_reviews')
            ->where('measurement_id', $measurement->id)
            ->where('stage', MeasurementWorkflow::STAGE_PAYMENT)
            ->update(['notes' => 'Nota trocada depois da decisão.']);
    }],
]);

// ── As etapas seguintes continuam com a autoridade delas ─────────────────────

it('keeps the later stages under their own responsibility after the decision: only the Finalizer finalizes', function () {
    $s = p4aAuthScenario();
    ['delegate' => $managerDelegate, 'delegation' => $delegation] = p4aAuthDelegate($s, MeasurementResponsibility::PaymentManager);
    // A decisão é tomada pelo delegado do Gestor de Pagamento.
    $measurement = Scenario::concludeWithoutDisbursement($s, p4aAuthAtStage($s, MeasurementWorkflow::STAGE_PAYMENT), actor: $managerDelegate);
    $workflow = p4aAuthWorkflow();
    $authorization = app(MeasurementAuthorizationService::class);
    $users = [
        'coordinator' => $s['coordinator'],
        'engineering' => $s['engineering'],
        'management' => $s['management'],
        'compliance' => $s['compliance'],
        'manager' => $s['manager'],
        'managerDelegate' => $managerDelegate,
        'uploader' => $s['uploader'],
        'finalizer' => $s['finalizer'],
        'watcher' => $s['watcher'],
        'outsider' => $s['outsider'],
        'stranger' => $s['stranger'],
    ];
    $administrators = ['admin' => $s['admin'], 'superAdmin' => $s['superAdmin']];
    $allowed = fn (Closure $can, array $candidates): array => array_keys(array_filter($candidates, $can));

    expect($allowed(fn (User $user): bool => $workflow->canFinalize($measurement, $user), $users))->toBe(['finalizer'])
        ->and($allowed(fn (User $user): bool => $workflow->canReturnFromFinalization($measurement, $user), $users))->toBe(['finalizer'])
        // A etapa Pagamento terminou para todos, administradores inclusive.
        ->and($allowed(fn (User $user): bool => $workflow->canConcludeWithoutDisbursement($measurement, $user)
            || $workflow->canApprove($measurement, $user)
            || $workflow->canRegisterPayment($measurement, $user), [...$users, ...$administrators]))->toBe([])
        // A delegação do Gestor de Pagamento segue a mesma: vigente, e só dessa responsabilidade.
        ->and($delegation->fresh()->isActive())->toBeTrue()
        ->and($authorization->resolveAuthorization($managerDelegate, $measurement, MeasurementResponsibility::PaymentManager)->source)->toBe(WorkflowAuthorizationSource::Delegated)
        ->and($authorization->resolveAuthorization($managerDelegate, $measurement, MeasurementResponsibility::Finalizer)->source)->toBe(WorkflowAuthorizationSource::None)
        // O override administrativo documentado continua valendo na Finalização.
        ->and($allowed(fn (User $user): bool => $workflow->canFinalize($measurement, $user), $administrators))->toBe(['admin', 'superAdmin']);

    expect(p4aAuthThrown(fn () => Scenario::finalizeAcknowledging($s, $measurement, $managerDelegate)))->toBeInstanceOf(AuthorizationException::class)
        ->and(Scenario::finalizeAcknowledging($s, $measurement, $s['finalizer'])->status)->toBe('finalized');
});
