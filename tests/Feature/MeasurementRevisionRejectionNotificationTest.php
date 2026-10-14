<?php

use App\Enums\MeasurementResponsibility;
use App\Enums\MeasurementRevisionStatus;
use App\Filament\Resources\Measurements\MeasurementResource;
use App\Models\Measurement;
use App\Models\MeasurementPause;
use App\Models\ResponsibilityDelegation;
use App\Models\User;
use App\Notifications\MeasurementWorkflowNotification;
use App\Services\MeasurementAuthorizationService;
use App\Services\MeasurementRevisionService;
use App\Services\MeasurementWorkflow;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\MeasurementPhysicalProgressScenario;
use Tests\Support\MeasurementRevisionScenario as Scenario;

/*
 * A recusa de uma revisão na Engenharia: quem fica sabendo, com que link, e
 * quem cuida do fluxo da vigente que volta a correr. E quem pode criar,
 * enviar, cancelar e decidir uma revisão. A lista "Notificar em caso de
 * recusa" da operação só recebe o aviso: não vê a medição, não revisa, e o
 * e-mail dela não leva link, motivo, observação nem valor.
 *
 * Cada papel é uma pessoa diferente: o ator dos cenários, que monta a medição
 * pelo fluxo real, ocupa os sete papéis e não distingue destinatários.
 */

uses(RefreshDatabase::class);

const REVISION_REJECTION_NOTICE_REASON = 'Percentual de maio lançado em dobro no laudo da construtora.';

const REVISION_REJECTION_NOTICE_NOTES = 'Laudo corrigido sem a assinatura do engenheiro responsável.';

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    Notification::fake();
});

/**
 * @param  list<string>  $permissions
 */
function revisionRejectionNoticeUser(array $permissions): User
{
    $user = User::factory()->withTwoFactor()->create();
    $user->givePermissionTo(['measurements.view', ...$permissions]);

    return $user;
}

/**
 * Grava responsáveis direto na linha da operação -- arranjo do teste, fora da
 * governança de troca de responsáveis -- e recarrega a operação do cenário.
 *
 * @param  array<string, mixed>  $scenario
 * @param  array<string, int>  $columns
 */
function revisionRejectionNoticeAssign(array $scenario, array $columns): void
{
    if ($columns === []) {
        return;
    }

    DB::table('operations')->where('id', $scenario['operation']->id)->update($columns);
    $scenario['operation']->refresh();
}

/**
 * Uma pessoa por papel, cada uma só com a permissão do papel e a de ver
 * medições. Quem cria a revisão é a coordenadora (assigned_user_id) e quem a
 * envia é a revisora da Gestão (stage2_reviewer_user_id), as duas com a
 * permissão de revisar. Os papéis fora de `$roles` continuam com o ator do
 * cenário.
 *
 * @param  array<string, mixed>  $scenario
 * @param  list<string>  $roles
 * @param  array<string, list<string>>  $extraPermissions  permissões a mais, por papel
 * @return array<string, User>
 */
function revisionRejectionNoticeTeam(
    array $scenario,
    array $roles = ['creator', 'engineer', 'submitter', 'compliance', 'paymentManager', 'receiptUploader', 'finalizer'],
    array $extraPermissions = [],
): array {
    $definitions = [
        'creator' => ['assigned_user_id', ['measurements.revise']],
        'engineer' => ['responsible_user_id', ['measurements.review']],
        'submitter' => ['stage2_reviewer_user_id', ['measurements.review', 'measurements.revise']],
        'compliance' => ['stage3_reviewer_user_id', ['measurements.review']],
        'paymentManager' => ['payment_manager_user_id', ['measurements.pay']],
        'receiptUploader' => ['payment_receipt_uploader_user_id', ['measurements.receipts']],
        'finalizer' => ['payment_finalizer_user_id', ['measurements.finalize']],
    ];
    $team = [];
    $columns = [];

    foreach ($roles as $role) {
        [$column, $permissions] = $definitions[$role];
        $team[$role] = revisionRejectionNoticeUser([...$permissions, ...($extraPermissions[$role] ?? [])]);
        $columns[$column] = (int) $team[$role]->id;
    }

    revisionRejectionNoticeAssign($scenario, $columns);

    return $team;
}

/**
 * Alguém só da lista "Notificar em caso de recusa", com as permissões de um
 * editor -- inclusive a de revisar --: cada negativa prova que a lista não é
 * participação, não que falta permissão.
 *
 * @param  array<string, mixed>  $scenario
 */
function revisionRejectionNoticeWatcher(array $scenario): User
{
    $watcher = User::factory()->withTwoFactor()->create();
    $watcher->assignRole('editor');
    $scenario['operation']->rejectionNotifyUsers()->attach($watcher->id);

    return $watcher;
}

/**
 * A vigente de que a revisão parte, montada pelo fluxo real: 'awaiting_payment'
 * (caso B, etapa Pagamento sem pagamento), 'paid_awaiting_finalization' (caso
 * C, paga e com a documentação completa) ou 'finalized' (caso A).
 *
 * @param  array<string, mixed>  $scenario
 */
function revisionRejectionNoticeSource(array $scenario, string $case): Measurement
{
    return match ($case) {
        'awaiting_payment' => Scenario::awaitingPayment($scenario, '2026-05', 10),
        'paid_awaiting_finalization' => Scenario::paidAwaitingFinalization($scenario, '2026-05', 10),
        'finalized' => Scenario::finalized($scenario, '2026-05', 10),
    };
}

/**
 * A revisão criada pela coordenadora e enviada à Engenharia pela revisora da
 * Gestão.
 *
 * @param  array<string, User>  $team
 */
function revisionRejectionNoticeSubmitted(Measurement $source, array $team): Measurement
{
    $revisions = app(MeasurementRevisionService::class);
    $draft = $revisions->create($source->fresh(), $team['creator'], REVISION_REJECTION_NOTICE_REASON);
    $revisions->submit($draft->fresh(), $team['submitter']);

    return $draft->fresh();
}

/**
 * Quem recebeu o aviso do evento: um id por notificação enviada -- repetido se
 * a pessoa recebeu duas --, em ordem crescente.
 *
 * @return list<int>
 */
function revisionRejectionNoticeRecipients(string $event): array
{
    $recipients = [];

    foreach (Notification::sentNotifications()[User::class] ?? [] as $userId => $notifications) {
        foreach ($notifications[MeasurementWorkflowNotification::class] ?? [] as $sent) {
            if ($sent['notification']->event === $event) {
                $recipients[] = (int) $userId;
            }
        }
    }

    sort($recipients);

    return $recipients;
}

/**
 * @return list<int>
 */
function revisionRejectionNoticeIds(User ...$users): array
{
    $ids = array_map(fn (User $user): int => (int) $user->getKey(), $users);
    sort($ids);

    return $ids;
}

/**
 * O único aviso do evento enviado à pessoa.
 */
function revisionRejectionNoticeTo(User $user, string $event): MeasurementWorkflowNotification
{
    return Notification::sent(
        $user,
        MeasurementWorkflowNotification::class,
        fn (MeasurementWorkflowNotification $notification): bool => $notification->event === $event,
    )->sole();
}

/**
 * Os eventos da trilha protegida da medição, na ordem em que foram gravados.
 *
 * @return list<string>
 */
function revisionRejectionNoticeEvents(Measurement $measurement): array
{
    return Activity::query()
        ->where('log_name', 'measurement_workflow')
        ->where('subject_type', $measurement->getMorphClass())
        ->where('subject_id', $measurement->getKey())
        ->orderBy('id')
        ->pluck('description')
        ->all();
}

/**
 * A autoridade registrada num evento da trilha protegida da revisão.
 *
 * @return array{admin_override: mixed, delegated: mixed, actual_actor_user_id: int, causer_id: int}
 */
function revisionRejectionNoticeAuthority(Measurement $revision, string $event): array
{
    $activity = Activity::query()
        ->where('log_name', 'measurement_workflow')
        ->where('subject_type', $revision->getMorphClass())
        ->where('subject_id', $revision->getKey())
        ->where('description', $event)
        ->sole();

    return [
        'admin_override' => $activity->properties['admin_override'] ?? null,
        'delegated' => $activity->properties['delegated'] ?? null,
        'actual_actor_user_id' => (int) ($activity->properties['actual_actor_user_id'] ?? 0),
        'causer_id' => (int) $activity->causer_id,
    ];
}

it('notifies the creator, the submitter and every rejection-list member once, and the pending responsible that the source resumes', function (string $case, string $responsible) {
    $scenario = Scenario::plan();
    $source = revisionRejectionNoticeSource($scenario, $case);
    $team = revisionRejectionNoticeTeam($scenario);
    $watchers = [revisionRejectionNoticeWatcher($scenario), revisionRejectionNoticeWatcher($scenario)];
    $revision = revisionRejectionNoticeSubmitted($source, $team);

    app(MeasurementWorkflow::class)->reject($revision->fresh(), $team['engineer'], REVISION_REJECTION_NOTICE_NOTES);

    expect(revisionRejectionNoticeRecipients('revision_rejected'))
        ->toBe(revisionRejectionNoticeIds($team['creator'], $team['submitter'], ...$watchers))
        ->and(revisionRejectionNoticeRecipients('revision_rejected_source_resumed'))
        ->toBe(revisionRejectionNoticeIds($team[$responsible]));

    $revisionUrl = MeasurementResource::getUrl('view', ['record' => $revision]);
    $creatorNotice = revisionRejectionNoticeTo($team['creator'], 'revision_rejected');
    $submitterNotice = revisionRejectionNoticeTo($team['submitter'], 'revision_rejected');

    expect($creatorNotice->measurement->id)->toBe($revision->id)
        ->and($creatorNotice->toMail($team['creator'])->viewData['url'])->toBe($revisionUrl)
        ->and($submitterNotice->toMail($team['submitter'])->viewData['url'])->toBe($revisionUrl);

    $resumed = revisionRejectionNoticeTo($team[$responsible], 'revision_rejected_source_resumed');

    expect($resumed->measurement->id)->toBe($source->id)
        ->and($resumed->toMail($team[$responsible])->viewData['url'])
        ->toBe(MeasurementResource::getUrl('view', ['record' => $source]));
})->with([
    'unpaid source at the Payment stage (case B)' => ['awaiting_payment', 'paymentManager'],
    'paid source awaiting the Finalization (case C)' => ['paid_awaiting_finalization', 'finalizer'],
]);

it('sends a rejection-list member the notice without a link, the reasons or any amount', function (string $case) {
    $scenario = Scenario::plan();
    $source = revisionRejectionNoticeSource($scenario, $case);
    $team = revisionRejectionNoticeTeam($scenario);
    $watcher = revisionRejectionNoticeWatcher($scenario);
    $revision = revisionRejectionNoticeSubmitted($source, $team);

    app(MeasurementWorkflow::class)->reject($revision->fresh(), $team['engineer'], REVISION_REJECTION_NOTICE_NOTES);

    $notice = revisionRejectionNoticeTo($watcher, 'revision_rejected');
    $mail = $notice->toMail($watcher);
    $revisionUrl = MeasurementResource::getUrl('view', ['record' => $revision]);

    expect($mail->viewData['url'])->toBeNull();

    expect($mail->render())
        ->toContain('Revisão de medição recusada')
        ->not->toContain('Abrir medição')
        ->not->toContain('href=')
        ->not->toContain($revisionUrl)
        ->not->toContain(MeasurementResource::getUrl('view', ['record' => $source]))
        ->not->toContain(REVISION_REJECTION_NOTICE_NOTES)
        ->not->toContain(REVISION_REJECTION_NOTICE_REASON)
        ->not->toContain('R$')
        ->not->toContain('100.000,00')
        ->not->toContain('100000');

    expect($notice->toArray($watcher))->toBe([
        'measurement_id' => $revision->id,
        'operation_id' => $scenario['operation']->id,
        'event' => 'revision_rejected',
        'title' => 'Revisão de medição recusada',
    ]);

    // O mesmo aviso, para quem criou a revisão e pode abri-la, leva o link: o
    // que falta no e-mail da lista é decisão por destinatário, não do modelo.
    expect($notice->toMail($team['creator'])->render())
        ->toContain('Abrir medição')
        ->toContain($revisionUrl);
})->with([
    'unpaid source at the Payment stage (case B)' => ['awaiting_payment'],
    'paid source awaiting the Finalization (case C)' => ['paid_awaiting_finalization'],
]);

it('notifies a rejection-list member who created the revision only once', function () {
    $scenario = Scenario::plan();
    $source = Scenario::awaitingPayment($scenario, '2026-05', 10);
    $team = revisionRejectionNoticeTeam($scenario);
    $scenario['operation']->rejectionNotifyUsers()->attach($team['creator']->id);
    $watcher = revisionRejectionNoticeWatcher($scenario);
    $revision = revisionRejectionNoticeSubmitted($source, $team);

    app(MeasurementWorkflow::class)->reject($revision->fresh(), $team['engineer'], REVISION_REJECTION_NOTICE_NOTES);

    expect(revisionRejectionNoticeRecipients('revision_rejected'))
        ->toBe(revisionRejectionNoticeIds($team['creator'], $team['submitter'], $watcher))
        ->and(revisionRejectionNoticeTo($team['creator'], 'revision_rejected')->toMail($team['creator'])->viewData['url'])
        ->toBe(MeasurementResource::getUrl('view', ['record' => $revision]));
});

it('sends a pending responsible who is also on the rejection list only the actionable notice of the resumed source', function (string $case, string $responsible) {
    $scenario = Scenario::plan();
    $source = revisionRejectionNoticeSource($scenario, $case);
    $team = revisionRejectionNoticeTeam($scenario);
    $scenario['operation']->rejectionNotifyUsers()->attach($team[$responsible]->id);
    $revision = revisionRejectionNoticeSubmitted($source, $team);

    app(MeasurementWorkflow::class)->reject($revision->fresh(), $team['engineer'], REVISION_REJECTION_NOTICE_NOTES);

    // Um aviso por pessoa, e o que fica é o acionável: o da medição que voltou
    // a correr, com o link dela -- que também conta a recusa da revisão.
    expect(revisionRejectionNoticeRecipients('revision_rejected'))
        ->toBe(revisionRejectionNoticeIds($team['creator'], $team['submitter']))
        ->and(revisionRejectionNoticeRecipients('revision_rejected_source_resumed'))
        ->toBe(revisionRejectionNoticeIds($team[$responsible]))
        ->and(revisionRejectionNoticeTo($team[$responsible], 'revision_rejected_source_resumed')->toMail($team[$responsible])->viewData['url'])
        ->toBe(MeasurementResource::getUrl('view', ['record' => $source]))
        ->and(revisionRejectionNoticeTo($team[$responsible], 'revision_rejected_source_resumed')->toMail($team[$responsible])->render())
        ->toContain('A revisão que suspendia o fluxo desta medição foi recusada na etapa de Engenharia.');
})->with([
    'payment manager of an unpaid source (case B)' => ['awaiting_payment', 'paymentManager'],
    'finalizer of a paid source (case C)' => ['paid_awaiting_finalization', 'finalizer'],
]);

it('sends no resumed-source notice when the rejected revision corrected a finalized measurement', function () {
    $scenario = Scenario::plan();
    $source = Scenario::finalized($scenario, '2026-05', 10);
    $team = revisionRejectionNoticeTeam($scenario);
    $revision = revisionRejectionNoticeSubmitted($source, $team);

    app(MeasurementWorkflow::class)->reject($revision->fresh(), $team['engineer'], REVISION_REJECTION_NOTICE_NOTES);

    expect(revisionRejectionNoticeRecipients('revision_rejected'))
        ->toBe(revisionRejectionNoticeIds($team['creator'], $team['submitter']))
        ->and(revisionRejectionNoticeRecipients('revision_rejected_source_resumed'))->toBe([]);

    expect($source->fresh()->status)->toBe('finalized')
        ->and(MeasurementPause::query()->where('measurement_id', $source->id)->exists())->toBeFalse()
        ->and(revisionRejectionNoticeEvents($source))->not->toContain('measurement_revision_source_resumed');
});

it('keeps a rejection-list member from opening, creating, submitting or cancelling a revision', function () {
    $scenario = Scenario::plan();
    $source = Scenario::awaitingPayment($scenario, '2026-05', 10);
    $team = revisionRejectionNoticeTeam($scenario);
    $watcher = revisionRejectionNoticeWatcher($scenario);
    $revisions = app(MeasurementRevisionService::class);
    $draft = $revisions->create($source->fresh(), $team['creator'], REVISION_REJECTION_NOTICE_REASON);

    // Quem participa vê e revisa: a negativa abaixo é da lista, não do estado.
    expect(Gate::forUser($team['creator'])->allows('view', $draft->fresh()))->toBeTrue()
        ->and(Gate::forUser($team['creator'])->allows('revise', $source->fresh()))->toBeTrue();

    expect(Gate::forUser($watcher)->allows('view', $draft->fresh()))->toBeFalse()
        ->and(Gate::forUser($watcher)->allows('revise', $source->fresh()))->toBeFalse()
        ->and(fn () => $revisions->create($source->fresh(), $watcher, REVISION_REJECTION_NOTICE_REASON))
        ->toThrow(AuthorizationException::class, 'Você não pode revisar medições desta operação.')
        ->and(fn () => $revisions->submit($draft->fresh(), $watcher))
        ->toThrow(AuthorizationException::class, 'Você não pode revisar medições desta operação.')
        ->and(fn () => $revisions->cancel($draft->fresh(), $watcher, 'Cancelada pela lista de recusa.'))
        ->toThrow(AuthorizationException::class, 'Você não pode cancelar revisões de medição desta operação.');

    expect($draft->fresh()->revisionStatus())->toBe(MeasurementRevisionStatus::Draft)
        ->and(Measurement::query()->where('revision_family_id', $source->id)->where('revision_number', '>', 0)->count())->toBe(1)
        ->and(revisionRejectionNoticeEvents($draft))->toBe(['measurement_revision_created']);

    $this->actingAs($watcher)
        ->get(MeasurementResource::getUrl('view', ['record' => $draft]))
        ->assertNotFound();
});

it('never lets a delegate create, submit or cancel a revision, even holding the revise permission', function () {
    $scenario = Scenario::plan();
    $source = Scenario::awaitingPayment($scenario, '2026-05', 10);
    $team = revisionRejectionNoticeTeam($scenario);
    $delegate = revisionRejectionNoticeUser(['measurements.revise', 'measurements.review']);
    ResponsibilityDelegation::factory()->active()->forOperation($scenario['operation'])->create([
        'delegator_user_id' => $team['engineer']->id,
        'delegate_user_id' => $delegate->id,
    ]);
    $revisions = app(MeasurementRevisionService::class);
    $draft = $revisions->create($source->fresh(), $team['creator'], REVISION_REJECTION_NOTICE_REASON);

    // A delegação vale: o delegado vê a revisão e decide a Engenharia por ela.
    expect(app(MeasurementAuthorizationService::class)
        ->resolveAuthorization($delegate, $draft->fresh(), MeasurementResponsibility::EngineeringReviewer)
        ->isDelegated())->toBeTrue()
        ->and(Gate::forUser($delegate)->allows('view', $draft->fresh()))->toBeTrue();

    expect(Gate::forUser($delegate)->allows('revise', $source->fresh()))->toBeFalse()
        ->and(fn () => $revisions->create($source->fresh(), $delegate, REVISION_REJECTION_NOTICE_REASON))
        ->toThrow(AuthorizationException::class, 'Você não pode revisar medições desta operação.')
        ->and(fn () => $revisions->submit($draft->fresh(), $delegate))
        ->toThrow(AuthorizationException::class, 'Você não pode revisar medições desta operação.')
        ->and(fn () => $revisions->cancel($draft->fresh(), $delegate, 'Cancelada pelo delegado.'))
        ->toThrow(AuthorizationException::class, 'Você não pode cancelar revisões de medição desta operação.');

    expect($draft->fresh()->revisionStatus())->toBe(MeasurementRevisionStatus::Draft)
        ->and(revisionRejectionNoticeEvents($draft))->toBe(['measurement_revision_created']);
});

it('refuses a participant of another operation to create, submit or cancel a revision', function () {
    $elsewhere = Scenario::plan();
    $outsider = $elsewhere['actor'];
    $ownMeasurement = MeasurementPhysicalProgressScenario::measurement($elsewhere, '2026-05');
    $scenario = Scenario::plan();
    $source = Scenario::awaitingPayment($scenario, '2026-05', 10);
    $draft = Scenario::revise($scenario, $source, REVISION_REJECTION_NOTICE_REASON);
    $revisions = app(MeasurementRevisionService::class);

    // Na própria operação, ele revisa: a negativa abaixo é da outra operação.
    expect(Gate::forUser($outsider)->allows('revise', $ownMeasurement->fresh()))->toBeTrue();

    expect(Gate::forUser($outsider)->allows('revise', $source->fresh()))->toBeFalse()
        ->and(Gate::forUser($outsider)->allows('view', $draft->fresh()))->toBeFalse()
        ->and(fn () => $revisions->create($source->fresh(), $outsider, REVISION_REJECTION_NOTICE_REASON))
        ->toThrow(AuthorizationException::class, 'Você não pode revisar medições desta operação.')
        ->and(fn () => $revisions->submit($draft->fresh(), $outsider))
        ->toThrow(AuthorizationException::class, 'Você não pode revisar medições desta operação.')
        ->and(fn () => $revisions->cancel($draft->fresh(), $outsider, 'Cancelada por outra operação.'))
        ->toThrow(AuthorizationException::class, 'Você não pode cancelar revisões de medição desta operação.');

    expect($draft->fresh()->revisionStatus())->toBe(MeasurementRevisionStatus::Draft)
        ->and(Measurement::query()->where('revision_number', '>', 0)->count())->toBe(1)
        ->and(revisionRejectionNoticeEvents($draft))->toBe(['measurement_revision_created']);
});

it('does not let a coordinator who may revise and review approve, reject or pause the revision at Engineering', function () {
    $scenario = Scenario::plan();
    $source = Scenario::awaitingPayment($scenario, '2026-05', 10);
    $team = revisionRejectionNoticeTeam($scenario, extraPermissions: ['creator' => ['measurements.review']]);
    $coordinator = $team['creator'];
    $revisions = app(MeasurementRevisionService::class);
    $revision = $revisions->create($source->fresh(), $coordinator, REVISION_REJECTION_NOTICE_REASON);
    $revisions->submit($revision->fresh(), $coordinator);
    $workflow = app(MeasurementWorkflow::class);

    expect(fn () => $workflow->approve($revision->fresh(), $coordinator, engineeringProgress: [$scenario['planSet']->id => 12]))
        ->toThrow(AuthorizationException::class, 'Você não possui permissão e responsabilidade para decidir esta etapa.')
        ->and(fn () => $workflow->reject($revision->fresh(), $coordinator, REVISION_REJECTION_NOTICE_NOTES))
        ->toThrow(AuthorizationException::class, 'Você não possui permissão e responsabilidade para decidir esta etapa.')
        ->and(fn () => $workflow->pause($revision->fresh(), $coordinator, 'Aguardar o laudo assinado.'))
        ->toThrow(AuthorizationException::class, 'Você não possui permissão e responsabilidade para esta ação.');

    $revision->refresh();
    $engineeringReview = $revision->reviews()->where('stage', MeasurementWorkflow::STAGE_ENGINEERING)->sole();

    expect($revision->revisionStatus())->toBe(MeasurementRevisionStatus::UnderReview)
        ->and($revision->status)->toBe('in_review')
        ->and($revision->current_stage)->toBe(MeasurementWorkflow::STAGE_ENGINEERING)
        ->and($revision->engineering_snapshot)->toBeNull()
        ->and($source->fresh()->isFrozenByRevision())->toBeTrue();

    expect($engineeringReview->status)->toBe('pending')
        ->and($engineeringReview->reviewer_user_id)->toBeNull()
        ->and($engineeringReview->paused_at)->toBeNull()
        ->and(MeasurementPause::query()->where('measurement_id', $revision->id)->exists())->toBeFalse();

    expect(revisionRejectionNoticeEvents($revision))
        ->not->toContain('measurement_engineering_snapshot_created')
        ->not->toContain('measurement_stage_approved')
        ->not->toContain('measurement_stage_rejected')
        ->not->toContain('measurement_stage_paused');

    // Quem decide a Engenharia é a responsável dela.
    expect($workflow->canApprove($revision, $coordinator))->toBeFalse()
        ->and($workflow->canApprove($revision, $team['engineer']))->toBeTrue();
});

it('records the administrative bypass as the authority of a super-admin only when the super-admin does not participate', function (string $role, array $columns, bool $adminOverride) {
    $scenario = Scenario::plan();
    $source = Scenario::awaitingPayment($scenario, '2026-05', 10);
    $admin = User::factory()->withTwoFactor()->create();
    $admin->assignRole($role);
    revisionRejectionNoticeAssign($scenario, array_fill_keys($columns, (int) $admin->id));
    $revisions = app(MeasurementRevisionService::class);

    $cancelled = $revisions->create($source->fresh(), $admin, 'Rascunho aberto por engano.');
    $revisions->cancel($cancelled->fresh(), $admin, 'Aberto por engano.');
    $submitted = $revisions->create($source->fresh(), $admin, REVISION_REJECTION_NOTICE_REASON);
    $revisions->submit($submitted->fresh(), $admin);

    $authority = [
        'admin_override' => $adminOverride,
        'delegated' => false,
        'actual_actor_user_id' => (int) $admin->id,
        'causer_id' => (int) $admin->id,
    ];

    expect(revisionRejectionNoticeAuthority($cancelled, 'measurement_revision_created'))->toBe($authority)
        ->and(revisionRejectionNoticeAuthority($cancelled, 'measurement_revision_cancelled'))->toBe($authority)
        ->and(revisionRejectionNoticeAuthority($submitted, 'measurement_revision_created'))->toBe($authority)
        ->and(revisionRejectionNoticeAuthority($submitted, 'measurement_revision_submitted'))->toBe($authority);
})->with([
    'super-admin outside the operation' => ['super-admin', [], true],
    'super-admin who coordinates the operation' => ['super-admin', ['assigned_user_id'], false],
    'admin who coordinates the operation' => ['admin', ['assigned_user_id'], false],
]);

it('refuses the revision to an admin who does not participate in the operation, as to an editor', function () {
    $scenario = Scenario::plan();
    $source = Scenario::awaitingPayment($scenario, '2026-05', 10);
    $admin = makeAdminUser();
    $revisions = app(MeasurementRevisionService::class);
    $authorization = app(MeasurementAuthorizationService::class);
    $draft = $revisions->create($source->fresh(), $scenario['actor'], REVISION_REJECTION_NOTICE_REASON);
    // A lista "Notificar em caso de recusa" não é participação.
    $scenario['operation']->rejectionNotifyUsers()->attach($admin->id);

    expect($scenario['operation']->fresh()->hasParticipant($admin))->toBeFalse()
        ->and($admin->can('measurements.revise'))->toBeTrue()
        ->and($authorization->canReviseMeasurement($admin, $source->fresh()))->toBeFalse()
        ->and($authorization->canManageRevision($admin, $draft->fresh()))->toBeFalse()
        ->and($admin->can('revise', $source->fresh()))->toBeFalse()
        ->and(fn () => $revisions->create($source->fresh(), $admin, 'Revisão de fora da operação.'))
        ->toThrow(AuthorizationException::class, 'Você não pode revisar medições desta operação.')
        ->and(fn () => $revisions->submit($draft->fresh(), $admin))
        ->toThrow(AuthorizationException::class, 'Você não pode revisar medições desta operação.')
        ->and(fn () => $revisions->cancel($draft->fresh(), $admin, 'Cancelamento de fora da operação.'))
        ->toThrow(AuthorizationException::class)
        ->and($draft->fresh()->revisionStatus())->toBe(MeasurementRevisionStatus::Draft)
        ->and(Measurement::query()->where('revision_family_id', $source->id)->count())->toBe(2);
});

it('notifies whoever cared for the closed workflow of the source when the revision becomes effective', function (string $case, string $event, array $responsibles, string $otherEvent) {
    $scenario = Scenario::plan();
    $source = revisionRejectionNoticeSource($scenario, $case);
    $staff = revisionRejectionNoticeTeam($scenario, ['paymentManager', 'receiptUploader', 'finalizer']);
    $revision = Scenario::engineeringApproved($scenario, $source, 12);
    $workflow = app(MeasurementWorkflow::class);
    $workflow->approve($revision->fresh(), $scenario['actor']);

    $workflow->approve($revision->fresh(), $scenario['actor']);

    $notified = array_map(fn (string $role): User => $staff[$role], $responsibles);
    $sourceUrl = MeasurementResource::getUrl('view', ['record' => $source]);

    expect(revisionRejectionNoticeRecipients($event))->toBe(revisionRejectionNoticeIds(...$notified))
        ->and(revisionRejectionNoticeRecipients($otherEvent))->toBe([]);

    foreach ($notified as $user) {
        $notice = revisionRejectionNoticeTo($user, $event);

        expect($notice->measurement->id)->toBe($source->id)
            ->and($notice->toMail($user)->viewData['url'])->toBe($sourceUrl);
    }
})->with([
    'unpaid source at the Payment stage (case B)' => [
        'awaiting_payment',
        'workflow_closed_by_revision',
        ['paymentManager'],
        'paid_workflow_closed_by_revision',
    ],
    'paid source awaiting the Finalization (case C)' => [
        'paid_awaiting_finalization',
        'paid_workflow_closed_by_revision',
        ['finalizer', 'receiptUploader'],
        'workflow_closed_by_revision',
    ],
]);
