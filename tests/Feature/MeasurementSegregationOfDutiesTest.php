<?php

use App\Enums\MeasurementReceiptReviewStatus;
use App\Enums\MeasurementResponsibility;
use App\Enums\MeasurementRevisionStatus;
use App\Enums\WorkflowAuthorizationSource;
use App\Exceptions\MeasurementSegregationException;
use App\Exceptions\MeasurementWorkflowException;
use App\Models\Measurement;
use App\Models\MeasurementFinancialRule;
use App\Models\MeasurementPayment;
use App\Models\MeasurementPaymentReceiptEvidence;
use App\Models\MeasurementReview;
use App\Models\Operation;
use App\Models\ResponsibilityDelegation;
use App\Models\User;
use App\Services\MeasurementAuthorizationService;
use App\Services\MeasurementFinancialRuleService;
use App\Services\MeasurementPaymentFinancialService;
use App\Services\MeasurementReceiptEvidenceService;
use App\Services\MeasurementRevisionService;
use App\Services\MeasurementSegregationService;
use App\Services\MeasurementWorkflow;
use App\Services\ResponsibilityDelegationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\MeasurementGovernanceScenario as Governance;
use Tests\Support\MeasurementReceiptEvidenceScenario;

/**
 * Segregação de funções da medição (Fase 4B-1): quem fez o ato crítico não o
 * confere -- o comprovante, a exceção financeira, a decisão sem desembolso e a
 * revisão na Compliance --, qualquer que seja a origem da autoridade de quem
 * confere (direta, delegada ou override administrativo). Cada papel do fluxo é
 * de uma pessoa (MeasurementGovernanceScenario); quando um teste dá dois papéis
 * à mesma pessoa, é o que está sob teste.
 */
pest()->group('parity');

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    Notification::fake();
});

/**
 * A exceção da ação, ou `null` -- lida inteira (classe, mensagem, campos), não
 * por `toThrow()`: as mensagens têm dois-pontos.
 */
function segregationThrown(callable $action): ?Throwable
{
    try {
        $action();
    } catch (Throwable $exception) {
        return $exception;
    }

    return null;
}

/**
 * Usuário só com as permissões dadas: a autoridade vem do papel na operação.
 *
 * @param  list<string>  $permissions
 */
function segregationUser(array $permissions): User
{
    $user = User::factory()->withTwoFactor()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

/**
 * Troca responsáveis da operação sem usuário autenticado: é a montagem do
 * cenário, não a governança de responsáveis que está sob teste.
 *
 * @param  array<string, mixed>  $scenario
 * @param  array<string, User>  $columns
 */
function segregationAssign(array $scenario, array $columns): void
{
    $scenario['operation']->forceFill(array_map(fn (User $user): int => $user->id, $columns))->save();
}

/**
 * Delegação vigente da responsabilidade, do responsável direto de agora para
 * o delegado, criada pelo serviço a pedido do admin e escopada na etapa desta
 * operação.
 *
 * @param  array<string, mixed>  $scenario
 */
function segregationDelegate(array $scenario, MeasurementResponsibility $responsibility, User $delegate): ResponsibilityDelegation
{
    return app(ResponsibilityDelegationService::class)->createDelegation([
        'delegator_user_id' => $scenario['operation']->fresh()->responsibleUserIdFor($responsibility),
        'delegate_user_id' => $delegate->id,
        'scope_type' => ResponsibilityDelegation::SCOPE_STAGE,
        'scope_stage' => $responsibility->stage(),
        'scope_responsibility' => $responsibility->value,
        'scope_operation_id' => $scenario['operation']->id,
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addDays(5),
        'reason' => 'Férias do responsável direto.',
    ], $scenario['admin']);
}

/**
 * O que uma recusa não pode ter mudado: o fluxo, as revisões de etapa, a
 * trilha protegida, os pagamentos e os comprovantes da medição.
 *
 * @return array<string, mixed>
 */
function segregationState(Measurement $measurement): array
{
    $fresh = $measurement->fresh();
    $paymentIds = MeasurementPayment::query()->where('measurement_id', $fresh->id)->pluck('id');

    return [
        'status' => $fresh->status,
        'current_stage' => (int) $fresh->current_stage,
        'workflow_revision' => (int) $fresh->workflow_revision,
        'revision_status' => $fresh->revisionStatus()->value,
        'reviews' => $fresh->reviews()->orderBy('stage')->get()
            ->map(fn (MeasurementReview $review): array => $review->only(['stage', 'status', 'reviewer_user_id', 'notes']))
            ->all(),
        'workflow_events' => Activity::query()
            ->where('log_name', 'measurement_workflow')
            ->where('subject_type', $fresh->getMorphClass())
            ->where('subject_id', $fresh->getKey())
            ->count(),
        'evidence_events' => Activity::query()->where('log_name', 'measurement_evidence')->count(),
        'payments' => MeasurementPayment::query()
            ->where('measurement_id', $fresh->id)
            ->orderBy('id')
            ->get(['id', 'plan_set_id', 'amount', 'created_by', 'financial_rule_id', 'financial_assessment'])
            ->toArray(),
        'evidences' => MeasurementPaymentReceiptEvidence::query()
            ->whereIn('measurement_payment_id', $paymentIds)
            ->orderBy('id')
            ->get(['id', 'measurement_payment_id', 'version', 'review_status', 'uploaded_by', 'reviewer_user_id'])
            ->toArray(),
    ];
}

/**
 * Os eventos da trilha protegida do fluxo desta medição, em ordem.
 *
 * @return Collection<int, Activity>
 */
function segregationActivities(Measurement $measurement, string $description, ?int $stage = null): Collection
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
 * Os eventos da trilha protegida documental desta versão do comprovante.
 *
 * @return Collection<int, Activity>
 */
function segregationEvidenceActivities(MeasurementPaymentReceiptEvidence $evidence, string $description): Collection
{
    return Activity::query()
        ->where('log_name', 'measurement_evidence')
        ->where('description', $description)
        ->where('subject_type', $evidence->getMorphClass())
        ->where('subject_id', $evidence->getKey())
        ->orderBy('id')
        ->get();
}

/**
 * A medição de maio, medida a 10% nas duas torres, paga pelo valor aprovado
 * (Torre Norte R$ 100.000,00; Torre Sul R$ 50.000,00), com a etapa Pagamento
 * aprovada e esperando a conferência dos comprovantes -- cada um enviado,
 * na versão v1, por quem registrou o pagamento (`$payer`, o Gestor de
 * Pagamento por padrão).
 *
 * @param  array<string, mixed>  $scenario
 */
function segregationAwaitingReceipts(array $scenario, ?User $payer = null, ?string $overrideReason = null): Measurement
{
    $measurement = Governance::awaitingPayment($scenario, '2026-05', [10, 10]);
    Governance::pay($scenario, $measurement, [
        Governance::row($scenario, 0, '100000.00'),
        Governance::row($scenario, 1, '50000.00'),
    ], $payer, $overrideReason);

    return Governance::approvePaymentStage($scenario, $measurement);
}

/**
 * A versão de substituição do comprovante: o Finalizador rejeita a versão
 * atual do pagamento (o primeiro, por padrão) e quem responde pelo envio de
 * comprovantes (`$by`, o `uploader` por padrão) envia a nova, com o motivo.
 *
 * @param  array<string, mixed>  $scenario
 */
function segregationReplacementVersion(array $scenario, Measurement $measurement, ?User $by = null, ?MeasurementPayment $payment = null): MeasurementPaymentReceiptEvidence
{
    $payment ??= $measurement->fresh()->payments()->orderBy('id')->firstOrFail();
    $current = $payment->fresh()->currentReceiptEvidence()->firstOrFail();
    segregationReview($current, $scenario['finalizer'], MeasurementReceiptReviewStatus::Rejected, rejectionReason: 'Comprovante de outro favorecido.');

    return app(MeasurementReceiptEvidenceService::class)->upload(
        $payment->fresh(),
        $by ?? $scenario['uploader'],
        MeasurementReceiptEvidenceScenario::file("comprovante-{$payment->id}-v2.pdf", "%PDF-1.7 comprovante {$payment->id} v2"),
        $current->id,
        'Comprovante correto enviado pelo banco.',
    );
}

/**
 * A versão atual do comprovante do primeiro pagamento da medição.
 */
function segregationFirstEvidence(Measurement $measurement): MeasurementPaymentReceiptEvidence
{
    return $measurement->fresh()->payments()->orderBy('id')->firstOrFail()->currentReceiptEvidence()->firstOrFail();
}

/**
 * A conferência da versão do comprovante, com a confirmação marcada.
 */
function segregationReview(MeasurementPaymentReceiptEvidence $evidence, User $actor, MeasurementReceiptReviewStatus $decision = MeasurementReceiptReviewStatus::Approved, ?string $overrideReason = null, ?string $rejectionReason = null): MeasurementPaymentReceiptEvidence
{
    return app(MeasurementReceiptEvidenceService::class)->review($evidence->fresh(), $actor, $decision, true, rejectionReason: $rejectionReason, overrideReason: $overrideReason);
}

// ── SOD-1: o comprovante não é aprovado por quem o enviou ───────────────────

it('refuses the payer who sent the receipt with the payment and also holds the Finalizer column the approval of the own receipt and keeps the version pending', function () {
    $s = Governance::plan();
    $measurement = segregationAwaitingReceipts($s);
    // A versão v1 é enviada com o registro do pagamento, por quem o registra.
    // Quem a enviou passa a responder também pela Finalização: a coluna nova
    // não apaga quem enviou a versão.
    $s['payer']->givePermissionTo('measurements.finalize');
    segregationAssign($s, ['payment_finalizer_user_id' => $s['payer']]);
    $evidence = segregationFirstEvidence($measurement);
    $before = segregationState($measurement);

    expect((int) $evidence->uploaded_by)->toBe($s['payer']->id);

    $refusal = segregationThrown(fn () => segregationReview($evidence, $s['payer']));

    expect($refusal)->toBeInstanceOf(MeasurementSegregationException::class)
        ->and($refusal->getMessage())->toBe(MeasurementSegregationService::RECEIPT_SELF_APPROVAL.' Você enviou a versão v1 do comprovante.')
        ->and($refusal->context())->toMatchArray([
            'control' => MeasurementSegregationService::CONTROL_RECEIPT,
            'checker_user_id' => $s['payer']->id,
            'maker_user_ids' => [$s['payer']->id],
        ])
        ->and($evidence->fresh()->review_status)->toBe(MeasurementReceiptReviewStatus::Pending)
        ->and(segregationState($measurement))->toBe($before)
        ->and(segregationEvidenceActivities($evidence, 'receipt_evidence_approved'))->toBeEmpty()
        // A tela desabilita a aprovação com a mesma frase do domínio.
        ->and(app(MeasurementReceiptEvidenceService::class)->approvalConflict($evidence, $measurement->fresh(), $s['payer']))->toBe($refusal->getMessage());
});

it('refuses the uploader who sent a replacement version and also holds the Finalizer column the approval of that version', function () {
    $s = Governance::plan();
    $measurement = segregationAwaitingReceipts($s);
    $replacement = segregationReplacementVersion($s, $measurement);
    $s['uploader']->givePermissionTo('measurements.finalize');
    segregationAssign($s, ['payment_finalizer_user_id' => $s['uploader']]);
    $before = segregationState($measurement);

    $refusal = segregationThrown(fn () => segregationReview($replacement, $s['uploader']));

    expect($refusal)->toBeInstanceOf(MeasurementSegregationException::class)
        ->and($refusal->getMessage())->toBe(MeasurementSegregationService::RECEIPT_SELF_APPROVAL.' Você enviou a versão v2 do comprovante.')
        ->and($refusal->context())->toMatchArray([
            'control' => MeasurementSegregationService::CONTROL_RECEIPT,
            'checker_user_id' => $s['uploader']->id,
            'maker_user_ids' => [$s['uploader']->id],
        ])
        ->and($replacement->fresh()->review_status)->toBe(MeasurementReceiptReviewStatus::Pending)
        ->and(segregationState($measurement))->toBe($before);
});

it('lets the Finalizer approve the receipt the payer sent with the payment and the replacement the uploader sent, recording the independent check in the protected trail', function () {
    $s = Governance::plan();
    $measurement = segregationAwaitingReceipts($s);
    $evidence = segregationFirstEvidence($measurement);

    segregationReview($evidence, $s['finalizer']);
    $upload = segregationEvidenceActivities($evidence, 'receipt_evidence_uploaded')->sole();
    $approval = segregationEvidenceActivities($evidence, 'receipt_evidence_approved')->sole();

    expect($evidence->fresh()->review_status)->toBe(MeasurementReceiptReviewStatus::Approved)
        ->and((int) $evidence->fresh()->reviewer_user_id)->toBe($s['finalizer']->id)
        // O envio da v1: quem registrou o pagamento, pela autoridade direta de
        // Gestor de Pagamento.
        ->and((int) $upload->causer_id)->toBe($s['payer']->id)
        ->and($upload->properties['context'])->toBe('payment_registration')
        ->and($upload->properties['uploaded_by'])->toBe($s['payer']->id)
        ->and($upload->properties['actual_actor_user_id'])->toBe($s['payer']->id)
        ->and($upload->properties['expected_responsible_user_id'])->toBe($s['payer']->id)
        ->and($upload->properties['delegated'])->toBeFalse()
        ->and($upload->properties['admin_override'])->toBeFalse()
        // A conferência: outra pessoa, e a trilha diz quem enviou.
        ->and((int) $approval->causer_id)->toBe($s['finalizer']->id)
        ->and($approval->properties['uploaded_by'])->toBe($s['payer']->id)
        ->and($approval->properties['actual_actor_user_id'])->toBe($s['finalizer']->id)
        ->and($approval->properties['expected_responsible_user_id'])->toBe($s['finalizer']->id)
        ->and($approval->properties['delegated'])->toBeFalse()
        ->and($approval->properties['admin_override'])->toBeFalse()
        ->and($approval->properties->all())->not->toHaveKey('admin_override_reason')
        ->and($approval->properties['segregation'])->toMatchArray([
            'control' => MeasurementSegregationService::CONTROL_RECEIPT,
            'makers' => [['user_id' => $s['payer']->id, 'act' => 'uploaded_evidence']],
            'checker_user_id' => $s['finalizer']->id,
            'checker_authorization_source' => 'direct',
            'checker_delegation_id' => null,
            'checker_delegator_user_id' => null,
            'independent' => true,
        ]);

    // A versão de substituição do segundo pagamento, enviada por quem responde
    // pelo envio de comprovantes, também é conferida por outra pessoa.
    $second = $measurement->fresh()->payments()->orderBy('id')->get()->last();
    $replacement = segregationReplacementVersion($s, $measurement, payment: $second);
    segregationReview($replacement, $s['finalizer']);
    $replacementUpload = segregationEvidenceActivities($replacement, 'receipt_evidence_uploaded')->sole();
    $replacementApproval = segregationEvidenceActivities($replacement, 'receipt_evidence_approved')->sole();

    expect((int) $replacementUpload->causer_id)->toBe($s['uploader']->id)
        ->and($replacementUpload->properties['expected_responsible_user_id'])->toBe($s['uploader']->id)
        ->and($replacementApproval->properties['segregation']['makers'])->toBe([['user_id' => $s['uploader']->id, 'act' => 'uploaded_evidence']])
        ->and($replacementApproval->properties['segregation']['independent'])->toBeTrue();

    expect($measurement->fresh()->status)->toBe('approved');
});

it('refuses the payer acting as delegate of the Finalizer: the delegation changes who may act, not who uploaded', function () {
    $s = Governance::plan();
    $measurement = segregationAwaitingReceipts($s);
    $s['payer']->givePermissionTo('measurements.finalize');
    segregationDelegate($s, MeasurementResponsibility::Finalizer, $s['payer']);
    $evidence = segregationFirstEvidence($measurement);
    $before = segregationState($measurement);

    expect(app(MeasurementAuthorizationService::class)->resolveAuthorization($s['payer'], $measurement->fresh(), MeasurementResponsibility::Finalizer)->source)
        ->toBe(WorkflowAuthorizationSource::Delegated);

    $refusal = segregationThrown(fn () => segregationReview($evidence, $s['payer']));

    expect($refusal)->toBeInstanceOf(MeasurementSegregationException::class)
        ->and($refusal->getMessage())->toBe(MeasurementSegregationService::RECEIPT_SELF_APPROVAL.' Você enviou a versão v1 do comprovante.')
        ->and(segregationState($measurement))->toBe($before);

    // Quem delegou, e não enviou nada, continua conferindo.
    segregationReview($evidence, $s['finalizer']);

    expect($evidence->fresh()->review_status)->toBe(MeasurementReceiptReviewStatus::Approved);
});

it('refuses a Finalizer delegate whose delegator sent the receipt version', function () {
    $s = Governance::plan();
    $measurement = segregationAwaitingReceipts($s);
    // Quem enviou a v1 (com o pagamento) passa a ser o Finalizador e delega a
    // Finalização a outra pessoa.
    $s['payer']->givePermissionTo('measurements.finalize');
    segregationAssign($s, ['payment_finalizer_user_id' => $s['payer']]);
    $delegate = segregationUser(['measurements.view', 'measurements.finalize']);
    $delegation = segregationDelegate($s, MeasurementResponsibility::Finalizer, $delegate);
    $evidence = segregationFirstEvidence($measurement);
    $before = segregationState($measurement);

    $refusal = segregationThrown(fn () => segregationReview($evidence, $delegate));

    expect($refusal)->toBeInstanceOf(MeasurementSegregationException::class)
        ->and($refusal->getMessage())->toBe(sprintf(
            '%s Você age por delegação de %s, que enviou a versão v1 do comprovante: a delegação muda quem pode agir, não quem fez a ação, e a conferência precisa ser de outro responsável.',
            MeasurementSegregationService::RECEIPT_SELF_APPROVAL,
            $s['payer']->name,
        ))
        ->and((int) $delegation->delegator_user_id)->toBe($s['payer']->id)
        ->and(segregationState($measurement))->toBe($before);
});

it('refuses the delegator of the upload the approval: the delegate sent the version with the delegator authority', function () {
    $s = Governance::plan();
    $measurement = segregationAwaitingReceipts($s);
    $delegate = segregationUser(['measurements.view', 'measurements.receipts']);
    $delegation = segregationDelegate($s, MeasurementResponsibility::ReceiptUploader, $delegate);
    // A v1 rejeitada é substituída pela v2, enviada pelo delegado de quem
    // responde pelo envio de comprovantes.
    $evidence = segregationReplacementVersion($s, $measurement, $delegate);
    $upload = segregationEvidenceActivities($evidence, 'receipt_evidence_uploaded')->sole();
    // O delegante, que não enviou nada pessoalmente, passa a ser o Finalizador.
    $s['uploader']->givePermissionTo('measurements.finalize');
    segregationAssign($s, ['payment_finalizer_user_id' => $s['uploader']]);
    $before = segregationState($measurement);

    expect((int) $evidence->uploaded_by)->toBe($delegate->id)
        ->and($upload->properties['delegated'])->toBeTrue()
        ->and($upload->properties['delegation_id'])->toBe($delegation->id)
        ->and((int) $upload->properties['delegator_user_id'])->toBe($s['uploader']->id);

    $refusal = segregationThrown(fn () => segregationReview($evidence, $s['uploader']));

    expect($refusal)->toBeInstanceOf(MeasurementSegregationException::class)
        ->and($refusal->getMessage())->toBe(MeasurementSegregationService::RECEIPT_SELF_APPROVAL.' Você delegou o envio da versão v2 do comprovante.')
        ->and(segregationState($measurement))->toBe($before);

    // O Finalizador original confere, e a trilha guarda os dois autores do envio.
    segregationAssign($s, ['payment_finalizer_user_id' => $s['finalizer']]);
    segregationReview($evidence, $s['finalizer']);
    $approval = segregationEvidenceActivities($evidence, 'receipt_evidence_approved')->sole();

    expect($approval->properties['segregation']['makers'])->toEqual([
        ['user_id' => $delegate->id, 'act' => 'uploaded_evidence'],
        ['user_id' => $s['uploader']->id, 'act' => 'delegated_evidence_upload'],
    ])
        ->and($approval->properties['segregation']['independent'])->toBeTrue();
});

it('refuses an administrator who sent the receipt by override the approval by override, even with the override reason', function (string $administrator) {
    $s = Governance::plan();
    $actor = $s[$administrator];
    // O administrador registra o pagamento -- e envia a v1 do comprovante com
    // ele -- pelo override.
    $measurement = segregationAwaitingReceipts($s, $actor, 'Comprovante recebido do banco por e-mail.');
    $evidence = segregationFirstEvidence($measurement);
    $upload = segregationEvidenceActivities($evidence, 'receipt_evidence_uploaded')->sole();
    $before = segregationState($measurement);

    expect((int) $evidence->uploaded_by)->toBe($actor->id)
        ->and($upload->properties['context'])->toBe('payment_registration')
        ->and($upload->properties['expected_responsible_user_id'])->toBe($s['payer']->id)
        ->and($upload->properties['admin_override'])->toBeTrue()
        ->and($upload->properties['admin_override_reason'])->toBe('Comprovante recebido do banco por e-mail.');

    $refusal = segregationThrown(fn () => segregationReview($evidence, $actor, overrideReason: 'Conferência urgente do fechamento do mês.'));

    // O override autoriza quem age, não dispensa a segregação.
    expect($refusal)->toBeInstanceOf(MeasurementSegregationException::class)
        ->and($refusal->getMessage())->toBe(MeasurementSegregationService::RECEIPT_SELF_APPROVAL.' Você enviou a versão v1 do comprovante.')
        ->and(segregationState($measurement))->toBe($before);

    segregationReview($evidence, $s['finalizer']);

    expect($evidence->fresh()->review_status)->toBe(MeasurementReceiptReviewStatus::Approved);
})->with([
    'admin' => ['admin'],
    'super-admin' => ['superAdmin'],
]);

it('lets the uploader holding the Finalizer column reject the own receipt: refusing it validates nothing', function () {
    $s = Governance::plan();
    $measurement = segregationAwaitingReceipts($s);
    Governance::uploadReceipts($s, $measurement);
    $s['uploader']->givePermissionTo('measurements.finalize');
    segregationAssign($s, ['payment_finalizer_user_id' => $s['uploader']]);
    $evidence = segregationFirstEvidence($measurement);

    segregationReview($evidence, $s['uploader'], MeasurementReceiptReviewStatus::Rejected, rejectionReason: 'Arquivo ilegível: envie o comprovante de novo.');
    $rejection = segregationEvidenceActivities($evidence, 'receipt_evidence_rejected')->sole();

    expect($evidence->fresh()->review_status)->toBe(MeasurementReceiptReviewStatus::Rejected)
        ->and((int) $evidence->fresh()->reviewer_user_id)->toBe($s['uploader']->id)
        ->and($measurement->fresh()->status)->toBe('awaiting_receipt')
        ->and((int) $rejection->causer_id)->toBe($s['uploader']->id)
        ->and($rejection->properties->all())->not->toHaveKey('segregation');
});

it('fails closed on a pending receipt version without a known uploader and accepts the version sent again by a known uploader', function () {
    $s = Governance::plan();
    $measurement = segregationAwaitingReceipts($s);
    $payment = $measurement->payments()->orderBy('id')->firstOrFail();
    // A v1 enviada com o pagamento é rejeitada e substituída por uma versão
    // sem registro de quem a enviou, nem o envio na trilha (anterior ao
    // controle).
    $registered = $payment->currentReceiptEvidence()->firstOrFail();
    segregationReview($registered, $s['finalizer'], MeasurementReceiptReviewStatus::Rejected, rejectionReason: 'Comprovante de outro favorecido.');
    $legacy = MeasurementPaymentReceiptEvidence::factory()->create([
        'measurement_payment_id' => $payment->id,
        'version' => 2,
        'supersedes_id' => $registered->id,
        'uploaded_by' => null,
    ]);
    $before = segregationState($measurement);

    expect(segregationEvidenceActivities($legacy, 'receipt_evidence_uploaded'))->toBeEmpty();

    $refusal = segregationThrown(fn () => segregationReview($legacy, $s['finalizer']));

    expect($refusal)->toBeInstanceOf(MeasurementSegregationException::class)
        ->and($refusal->getMessage())->toBe(MeasurementSegregationService::RECEIPT_UPLOADER_UNKNOWN)
        ->and($refusal->context()['maker_user_ids'])->toBe([])
        ->and(segregationState($measurement))->toBe($before)
        ->and(app(MeasurementReceiptEvidenceService::class)->approvalConflict($legacy, $measurement->fresh(), $s['finalizer']))
        ->toBe(MeasurementSegregationService::RECEIPT_UPLOADER_UNKNOWN);

    // O caminho é uma nova versão, enviada com o motivo por quem responde pelo envio.
    app(MeasurementReceiptEvidenceService::class)->upload(
        $payment->fresh(),
        $s['uploader'],
        MeasurementReceiptEvidenceScenario::file("comprovante-{$payment->id}-v3.pdf", "%PDF-1.7 comprovante {$payment->id} v3"),
        $legacy->id,
        'A versão anterior não tem registro de quem a enviou.',
    );
    $current = $payment->fresh()->currentReceiptEvidence;

    expect($current->version)->toBe(3)
        ->and((int) $current->supersedes_id)->toBe($legacy->id)
        ->and((int) $current->uploaded_by)->toBe($s['uploader']->id);

    segregationReview($current, $s['finalizer']);

    expect($current->fresh()->review_status)->toBe(MeasurementReceiptReviewStatus::Approved);
});

// ── SOD-2: a exceção financeira é aceita por quem não a fez ─────────────────

/**
 * Maio medido a 10% nas duas torres, parado na etapa Pagamento, com o
 * pagamento excepcional da Torre Norte -- R$ 102.000,00 contra R$ 100.000,00
 * aprovados, coberto pela regra de quem cadastra regras -- e o comum da Torre
 * Sul, registrados por `$payer` (o Gestor de Pagamento, por padrão).
 *
 * @param  array<string, mixed>  $scenario
 * @return array{measurement: Measurement, rule: MeasurementFinancialRule, excess: MeasurementPayment}
 */
function segregationExcessRegistered(array $scenario, ?User $payer = null): array
{
    $measurement = Governance::awaitingPayment($scenario, '2026-05', [10, 10]);
    $rule = Governance::rule($scenario);
    $payments = Governance::pay($scenario, $measurement, [
        Governance::row($scenario, 0, '102000.00', ['financial_rule_id' => $rule->id, 'financial_justification' => Governance::JUSTIFICATION]),
        Governance::row($scenario, 1, '50000.00'),
    ], $payer);

    return ['measurement' => $measurement->fresh(), 'rule' => $rule, 'excess' => $payments->first()];
}

it('refuses the payer who registered the excess the acceptance at Finalization, whatever makes them the Finalizer now', function (Closure $makeFinalizer, WorkflowAuthorizationSource $source) {
    $s = Governance::plan();
    ['measurement' => $measurement, 'excess' => $excess] = segregationExcessRegistered($s);
    $measurement = Governance::readyToFinalize($s, $measurement);
    $columns = $s['operation']->fresh()->only(Operation::RESPONSIBILITY_FIELDS);
    $s['payer']->givePermissionTo('measurements.finalize');
    $makeFinalizer($s);
    $workflow = app(MeasurementWorkflow::class);
    $before = segregationState($measurement);

    expect(app(MeasurementAuthorizationService::class)->resolveAuthorization($s['payer'], $measurement->fresh(), MeasurementResponsibility::Finalizer)->source)->toBe($source)
        ->and($workflow->canFinalize($measurement->fresh(), $s['payer']))->toBeTrue()
        ->and($excess->financial_assessment['reference']['status'])->toBe('over')
        ->and($excess->financial_assessment['requires_acceptance'])->toBeTrue();

    $refusal = segregationThrown(fn () => Governance::finalize($s, $measurement, accept: true, by: $s['payer']));

    // Quem só registrou o pagamento é descrito como quem o registrou: o
    // enquadramento do registro não é uma reavaliação.
    expect($refusal)->toBeInstanceOf(MeasurementSegregationException::class)
        ->and($refusal->getMessage())->toBe(sprintf(
            '%s Você registrou o pagamento #%d e aprovou a etapa Pagamento com a exceção.',
            MeasurementSegregationService::FINANCIAL_EXCEPTION_SELF_ACCEPTANCE,
            $excess->id,
        ))
        ->and($refusal->context())->toMatchArray([
            'control' => MeasurementSegregationService::CONTROL_FINANCIAL_EXCEPTION,
            'checker_user_id' => $s['payer']->id,
        ])
        ->and($refusal->context()['maker_user_ids'])->toEqualCanonicalizing([$s['payer']->id, $s['ruleAuthor']->id])
        ->and(segregationState($measurement))->toBe($before)
        ->and(segregationActivities($measurement, 'measurement_finalized'))->toBeEmpty()
        // A tela desabilita a Finalização com a mesma frase do domínio.
        ->and($workflow->finalizationBlockReason($measurement->fresh(), $s['payer']))->toBe($refusal->getMessage());

    // Quem responde pela Finalização, e não fez nada da exceção, a aceita -- e
    // o modal lista quem fez o quê.
    $s['operation']->forceFill($columns)->save();

    expect($workflow->finalizationBlockReason($measurement->fresh(), $s['finalizer']))->toBeNull()
        ->and(app(MeasurementSegregationService::class)->describeFinalizationMakers($measurement->fresh()))->toBe([
            sprintf('%s registrou o pagamento #%d e aprovou a etapa Pagamento com a exceção', $s['payer']->name, $excess->id),
            sprintf('%s registrou a regra financeira aplicada ao pagamento #%d', $s['ruleAuthor']->name, $excess->id),
        ]);

    $measurement = Governance::finalize($s, $measurement, accept: true);
    $finalization = segregationActivities($measurement, 'measurement_finalized')->sole();
    $check = $finalization->properties['segregation']['financial_exceptions'];

    expect($measurement->status)->toBe('finalized')
        ->and((int) $finalization->causer_id)->toBe($s['finalizer']->id)
        ->and($finalization->properties['segregation'])->not->toHaveKey('no_disbursement')
        ->and($finalization->properties['financial_exceptions_accepted'])->toHaveCount(1)
        ->and($finalization->properties['financial_exceptions_accepted'][0]['payment_id'])->toBe($excess->id)
        ->and($check)->toMatchArray([
            'control' => MeasurementSegregationService::CONTROL_FINANCIAL_EXCEPTION,
            'checker_user_id' => $s['finalizer']->id,
            'checker_authorization_source' => 'direct',
            'checker_delegation_id' => null,
            'checker_delegator_user_id' => null,
            'independent' => true,
        ])
        ->and($check['makers'])->toEqual([
            ['user_id' => $s['payer']->id, 'act' => 'registered_payment'],
            ['user_id' => $s['ruleAuthor']->id, 'act' => 'authored_financial_rule'],
            ['user_id' => $s['payer']->id, 'act' => 'approved_payment_stage'],
        ]);
})->with([
    'holding the Finalizer column as well' => [
        fn (array $s) => segregationAssign($s, ['payment_finalizer_user_id' => $s['payer']]),
        WorkflowAuthorizationSource::Direct,
    ],
    'moved from the Payment Manager column to the Finalizer one after the payment' => [
        fn (array $s) => segregationAssign($s, [
            'payment_manager_user_id' => segregationUser(['measurements.view', 'measurements.pay']),
            'payment_finalizer_user_id' => $s['payer'],
        ]),
        WorkflowAuthorizationSource::Direct,
    ],
    'acting as delegate of the Finalizer' => [
        fn (array $s) => segregationDelegate($s, MeasurementResponsibility::Finalizer, $s['payer']),
        WorkflowAuthorizationSource::Delegated,
    ],
]);

it('refuses the Payment Manager whose delegate registered the excess: the delegator of the maker act does not accept it', function () {
    $s = Governance::plan();
    $delegate = segregationUser(['measurements.view', 'measurements.pay']);
    segregationDelegate($s, MeasurementResponsibility::PaymentManager, $delegate);
    ['measurement' => $measurement, 'excess' => $excess] = segregationExcessRegistered($s, $delegate);
    $registration = segregationActivities($measurement, 'measurement_payment_registered')->sole();
    // A etapa Pagamento é aprovada pelo admin, pelo override: o único ato do
    // Gestor de Pagamento nesta exceção é a delegação do registro.
    app(MeasurementWorkflow::class)->approve($measurement->fresh(), $s['admin'], expectedStage: MeasurementWorkflow::STAGE_PAYMENT, overrideReason: 'Gestor de Pagamento afastado no fechamento do mês.');
    Governance::uploadReceipts($s, $measurement);
    Governance::approveReceipts($s, $measurement);
    $s['payer']->givePermissionTo('measurements.finalize');
    segregationAssign($s, ['payment_finalizer_user_id' => $s['payer']]);
    $before = segregationState($measurement);

    expect((int) $excess->created_by)->toBe($delegate->id)
        ->and($registration->properties['delegated'])->toBeTrue()
        ->and((int) $registration->properties['delegator_user_id'])->toBe($s['payer']->id);

    $refusal = segregationThrown(fn () => Governance::finalize($s, $measurement, accept: true, by: $s['payer']));

    expect($refusal)->toBeInstanceOf(MeasurementSegregationException::class)
        ->and($refusal->getMessage())->toBe(sprintf('%s Você delegou o registro do pagamento #%d.', MeasurementSegregationService::FINANCIAL_EXCEPTION_SELF_ACCEPTANCE, $excess->id))
        ->and(segregationState($measurement))->toBe($before);

    segregationAssign($s, ['payment_finalizer_user_id' => $s['finalizer']]);
    $measurement = Governance::finalize($s, $measurement, accept: true);
    $check = segregationActivities($measurement, 'measurement_finalized')->sole()->properties['segregation']['financial_exceptions'];

    expect($measurement->status)->toBe('finalized')
        ->and($check['independent'])->toBeTrue()
        ->and($check['makers'])->toContainEqual(
            ['user_id' => $delegate->id, 'act' => 'registered_payment'],
            ['user_id' => $s['payer']->id, 'act' => 'delegated_payment_registration'],
            ['user_id' => $s['admin']->id, 'act' => 'approved_payment_stage'],
        );
});

it('refuses whoever reassessed the excess payment the acceptance at Finalization', function () {
    $s = Governance::plan();
    ['measurement' => $measurement, 'excess' => $excess, 'rule' => $rule] = segregationExcessRegistered($s);
    // Outra pessoa passa a responder pelo pagamento e reavalia o enquadramento:
    // mantém a regra e troca a justificativa.
    $reassessor = segregationUser(['measurements.view', 'measurements.pay', 'measurements.finalize']);
    segregationAssign($s, ['payment_manager_user_id' => $reassessor]);
    app(MeasurementWorkflow::class)->reassessPayment(
        $excess->fresh(),
        $reassessor,
        ['financial_rule_id' => null, 'financial_justification' => 'Justificativa revista com o aditivo do contrato de obra.'],
        (int) $measurement->fresh()->workflow_revision,
    );
    segregationAssign($s, ['payment_manager_user_id' => $s['payer']]);
    $measurement = Governance::readyToFinalize($s, $measurement);
    segregationAssign($s, ['payment_finalizer_user_id' => $reassessor]);
    $before = segregationState($measurement);

    expect($excess->fresh()->financial_assessment['assessed_by_user_id'])->toBe($reassessor->id)
        ->and($excess->fresh()->financial_assessment['rule']['id'])->toBe($rule->id)
        ->and((int) $excess->fresh()->created_by)->toBe($s['payer']->id);

    $refusal = segregationThrown(fn () => Governance::finalize($s, $measurement, accept: true, by: $reassessor));

    expect($refusal)->toBeInstanceOf(MeasurementSegregationException::class)
        ->and($refusal->getMessage())->toBe(sprintf('%s Você reavaliou o enquadramento do pagamento #%d.', MeasurementSegregationService::FINANCIAL_EXCEPTION_SELF_ACCEPTANCE, $excess->id))
        ->and(segregationState($measurement))->toBe($before);

    segregationAssign($s, ['payment_finalizer_user_id' => $s['finalizer']]);
    $measurement = Governance::finalize($s, $measurement, accept: true);
    $check = segregationActivities($measurement, 'measurement_finalized')->sole()->properties['segregation']['financial_exceptions'];

    expect($measurement->status)->toBe('finalized')
        ->and($check['makers'])->toContainEqual(
            ['user_id' => $s['payer']->id, 'act' => 'registered_payment'],
            ['user_id' => $reassessor->id, 'act' => 'assessed_payment'],
        );
});

it('refuses the author of the financial rule applied to the excess the acceptance at Finalization', function () {
    $s = Governance::plan();
    ['measurement' => $measurement, 'excess' => $excess] = segregationExcessRegistered($s);
    $measurement = Governance::readyToFinalize($s, $measurement);
    $s['ruleAuthor']->givePermissionTo(['measurements.view', 'measurements.finalize']);
    segregationAssign($s, ['payment_finalizer_user_id' => $s['ruleAuthor']]);
    $before = segregationState($measurement);

    expect((int) $excess->financial_assessment['rule']['created_by'])->toBe($s['ruleAuthor']->id);

    $refusal = segregationThrown(fn () => Governance::finalize($s, $measurement, accept: true, by: $s['ruleAuthor']));

    expect($refusal)->toBeInstanceOf(MeasurementSegregationException::class)
        ->and($refusal->getMessage())->toBe(sprintf('%s Você registrou a regra financeira aplicada ao pagamento #%d.', MeasurementSegregationService::FINANCIAL_EXCEPTION_SELF_ACCEPTANCE, $excess->id))
        ->and(segregationState($measurement))->toBe($before);
});

it('refuses the payer who justified a construction left unpaid the acceptance of that absence at Finalization', function () {
    $s = Governance::plan();
    $measurement = Governance::awaitingPayment($s, '2026-05', [10, 10]);
    // Só a Torre Norte é paga; a Torre Sul fica sem pagamento, justificado na etapa.
    Governance::pay($s, $measurement, [Governance::row($s, 0, '100000.00')]);
    $measurement = Governance::readyToFinalize($s, $measurement, 'Torre Sul retida até a regularização documental da obra.');
    $s['payer']->givePermissionTo('measurements.finalize');
    segregationAssign($s, ['payment_finalizer_user_id' => $s['payer']]);
    $before = segregationState($measurement);

    expect(app(MeasurementPaymentFinancialService::class)->unpaidRequiredPlanSets($measurement->fresh()))->toHaveCount(1)
        ->and(app(MeasurementSegregationService::class)->exceptionalPayments($measurement->fresh()))->toBe([]);

    $refusal = segregationThrown(fn () => Governance::finalize($s, $measurement, accept: true, by: $s['payer']));

    expect($refusal)->toBeInstanceOf(MeasurementSegregationException::class)
        ->and($refusal->getMessage())->toBe(MeasurementSegregationService::FINANCIAL_EXCEPTION_SELF_ACCEPTANCE.' Você aprovou a etapa Pagamento com a exceção.')
        ->and(segregationState($measurement))->toBe($before);

    segregationAssign($s, ['payment_finalizer_user_id' => $s['finalizer']]);
    $measurement = Governance::finalize($s, $measurement, accept: true);
    $finalization = segregationActivities($measurement, 'measurement_finalized')->sole();

    expect($measurement->status)->toBe('finalized')
        ->and($finalization->properties['unpaid_plan_sets_accepted'])->toHaveCount(1)
        ->and($finalization->properties['unpaid_plan_sets_accepted'][0]['plan_set_id'])->toBe($s['planSets'][1]->id)
        ->and($finalization->properties['unpaid_plan_sets_accepted'][0]['justified_by'])->toBe($s['payer']->id)
        ->and($finalization->properties['segregation']['financial_exceptions']['makers'])->toEqual([
            ['user_id' => $s['payer']->id, 'act' => 'approved_payment_stage'],
        ])
        ->and($finalization->properties['segregation']['financial_exceptions']['independent'])->toBeTrue();
});

it('requires an independent Finalizer for a partial payment: the payer who is also the Finalizer does not accept the outstanding balance', function () {
    $s = Governance::plan();
    $measurement = Governance::awaitingPayment($s, '2026-05', [10, 10]);
    $payments = Governance::pay($s, $measurement, [
        Governance::row($s, 0, '90000.00', ['financial_justification' => 'Retenção contratual de 10% até a vistoria da obra.']),
        Governance::row($s, 1, '50000.00'),
    ]);
    $measurement = Governance::readyToFinalize($s, $measurement);
    $columns = $s['operation']->fresh()->only(Operation::RESPONSIBILITY_FIELDS);
    $s['payer']->givePermissionTo('measurements.finalize');
    segregationAssign($s, ['payment_finalizer_user_id' => $s['payer']]);
    $workflow = app(MeasurementWorkflow::class);
    $partial = $payments->first();
    $before = segregationState($measurement);

    // O pagamento parcial continua um pagamento legítimo, sem regra; o que
    // pede conferência independente é o aceite do saldo que ele deixa aberto.
    expect($partial->financial_assessment['reference']['status'])->toBe('under')
        ->and($partial->financial_assessment['requires_acceptance'])->toBeTrue()
        ->and($partial->financial_assessment['rule'])->toBeNull()
        ->and($payments->last()->financial_assessment['requires_acceptance'])->toBeFalse()
        ->and(collect(app(MeasurementSegregationService::class)->financialExceptionMakers($measurement->fresh()))
            ->map(fn (array $maker): array => ['user_id' => $maker['user_id'], 'act' => $maker['act']])
            ->unique(fn (array $maker): string => $maker['user_id'].':'.$maker['act'])
            ->values()
            ->all())->toEqual([
                ['user_id' => $s['payer']->id, 'act' => 'registered_payment'],
                ['user_id' => $s['payer']->id, 'act' => 'approved_payment_stage'],
            ])
        ->and(app(MeasurementSegregationService::class)->financialAcceptanceReasons($measurement->fresh()))->toBe(['partial_payment_outstanding_balance']);

    $refusal = segregationThrown(fn () => Governance::finalize($s, $measurement, accept: true, by: $s['payer']));

    expect($refusal)->toBeInstanceOf(MeasurementSegregationException::class)
        ->and($refusal->getMessage())->toBe(sprintf(
            '%s Você registrou o pagamento #%d e aprovou a etapa Pagamento com a exceção.',
            MeasurementSegregationService::FINANCIAL_EXCEPTION_SELF_ACCEPTANCE,
            $partial->id,
        ))
        ->and($refusal->context())->toMatchArray([
            'control' => MeasurementSegregationService::CONTROL_FINANCIAL_EXCEPTION,
            'checker_user_id' => $s['payer']->id,
        ])
        ->and(segregationState($measurement))->toBe($before)
        ->and(segregationActivities($measurement, 'measurement_finalized'))->toBeEmpty()
        ->and($workflow->finalizationBlockReason($measurement->fresh(), $s['payer']))->toBe($refusal->getMessage());

    // O Finalizador independente aceita o saldo em aberto.
    $s['operation']->forceFill($columns)->save();

    expect($workflow->finalizationBlockReason($measurement->fresh(), $s['finalizer']))->toBeNull();

    $measurement = Governance::finalize($s, $measurement, accept: true);
    $finalization = segregationActivities($measurement, 'measurement_finalized')->sole();
    $check = $finalization->properties['segregation']['financial_exceptions'];

    expect($measurement->status)->toBe('finalized')
        ->and((int) $finalization->causer_id)->toBe($s['finalizer']->id)
        ->and($finalization->properties['financial_exceptions_accepted'])->toHaveCount(1)
        ->and($finalization->properties['financial_exceptions_accepted'][0]['payment_id'])->toBe($partial->id)
        ->and($check)->toMatchArray([
            'control' => MeasurementSegregationService::CONTROL_FINANCIAL_EXCEPTION,
            'checker_user_id' => $s['finalizer']->id,
            'checker_authorization_source' => 'direct',
            'independent' => true,
        ])
        ->and($check['required_because'])->toContain('partial_payment_outstanding_balance')
        ->and($check['required_because'])->not->toContain('excess_payment')
        ->and($check['makers'])->toEqual([
            ['user_id' => $s['payer']->id, 'act' => 'registered_payment'],
            ['user_id' => $s['payer']->id, 'act' => 'approved_payment_stage'],
        ]);
});

it('keeps an exact payment outside the financial exception control: the payer who is also the Finalizer finalizes it', function () {
    $s = Governance::plan();
    $measurement = Governance::awaitingPayment($s, '2026-05', [10, 10]);
    Governance::pay($s, $measurement, [
        Governance::row($s, 0, '100000.00'),
        Governance::row($s, 1, '50000.00'),
    ]);
    $measurement = Governance::readyToFinalize($s, $measurement);
    $s['payer']->givePermissionTo('measurements.finalize');
    segregationAssign($s, ['payment_finalizer_user_id' => $s['payer']]);

    expect(app(MeasurementSegregationService::class)->financialExceptionMakers($measurement->fresh()))->toBe([])
        ->and(app(MeasurementWorkflow::class)->finalizationBlockReason($measurement->fresh(), $s['payer']))->toBeNull();

    $measurement = Governance::finalize($s, $measurement, by: $s['payer']);
    $finalization = segregationActivities($measurement, 'measurement_finalized')->sole();

    expect($measurement->status)->toBe('finalized')
        ->and((int) $finalization->causer_id)->toBe($s['payer']->id)
        ->and($finalization->properties->all())->not->toHaveKey('segregation');
});

// ── SOD-3: a ciência da ausência de desembolso é de quem não decidiu ────────

/**
 * Maio medido a 0% nas duas torres -- nada é devido -- e a etapa Pagamento
 * concluída sem desembolso por `$decider`.
 *
 * @param  array<string, mixed>  $scenario
 */
function segregationDecidedWithoutDisbursement(array $scenario, User $decider, ?string $overrideReason = null): Measurement
{
    $measurement = Governance::awaitingPayment($scenario, '2026-05', [0, 0]);
    app(MeasurementWorkflow::class)->approve(
        $measurement->fresh(),
        $decider,
        Governance::JUSTIFICATION,
        expectedStage: MeasurementWorkflow::STAGE_PAYMENT,
        withoutDisbursement: true,
        overrideReason: $overrideReason,
    );

    return $measurement->fresh();
}

it('refuses the payer who concluded the Payment stage without disbursement the acknowledgment and creates no payment', function () {
    $s = Governance::plan();
    $measurement = segregationDecidedWithoutDisbursement($s, $s['payer']);
    $s['payer']->givePermissionTo('measurements.finalize');
    segregationAssign($s, ['payment_finalizer_user_id' => $s['payer']]);
    $workflow = app(MeasurementWorkflow::class);
    $before = segregationState($measurement);

    expect($measurement->status)->toBe('approved')
        ->and($workflow->requiresNoDisbursementAcknowledgment($measurement))->toBeTrue()
        ->and($workflow->noDisbursementDecision($measurement)['decided_by'])->toBe($s['payer']->id);

    $refusal = segregationThrown(fn () => Governance::finalize($s, $measurement, by: $s['payer'], acknowledgeNoDisbursement: true));

    expect($refusal)->toBeInstanceOf(MeasurementSegregationException::class)
        ->and($refusal->getMessage())->toBe(MeasurementSegregationService::NO_DISBURSEMENT_SELF_ACKNOWLEDGMENT.' Você decidiu concluir a etapa Pagamento sem desembolso.')
        ->and($refusal->context())->toMatchArray([
            'control' => MeasurementSegregationService::CONTROL_NO_DISBURSEMENT,
            'checker_user_id' => $s['payer']->id,
            'maker_user_ids' => [$s['payer']->id],
        ])
        ->and(segregationState($measurement))->toBe($before)
        ->and(MeasurementPayment::query()->count())->toBe(0)
        ->and(segregationActivities($measurement, 'measurement_finalized'))->toBeEmpty()
        ->and($workflow->finalizationBlockReason($measurement->fresh(), $s['payer']))->toBe($refusal->getMessage());

    // O Finalizador, que não decidiu nada, dá a ciência.
    segregationAssign($s, ['payment_finalizer_user_id' => $s['finalizer']]);
    $measurement = Governance::finalize($s, $measurement, acknowledgeNoDisbursement: true);
    $finalization = segregationActivities($measurement, 'measurement_finalized')->sole();
    $acknowledgment = $finalization->properties['no_disbursement_acknowledged'];

    expect($measurement->status)->toBe('finalized')
        ->and(MeasurementPayment::query()->count())->toBe(0)
        ->and((int) $finalization->causer_id)->toBe($s['finalizer']->id)
        ->and($acknowledgment['decided_by'])->toBe($s['payer']->id)
        ->and($acknowledgment['same_actor_as_decision'])->toBeFalse()
        ->and($acknowledgment['payments_registered'])->toBe(0)
        ->and($finalization->properties['segregation'])->not->toHaveKey('financial_exceptions')
        ->and($finalization->properties['segregation']['no_disbursement'])->toMatchArray([
            'control' => MeasurementSegregationService::CONTROL_NO_DISBURSEMENT,
            'makers' => [['user_id' => $s['payer']->id, 'act' => 'decided_no_disbursement']],
            'checker_user_id' => $s['finalizer']->id,
            'checker_authorization_source' => 'direct',
            'checker_delegation_id' => null,
            'checker_delegator_user_id' => null,
            'independent' => true,
        ]);
});

it('refuses the Payment Manager whose delegate decided without disbursement the acknowledgment', function () {
    $s = Governance::plan();
    $delegate = segregationUser(['measurements.view', 'measurements.pay']);
    $delegation = segregationDelegate($s, MeasurementResponsibility::PaymentManager, $delegate);
    $measurement = segregationDecidedWithoutDisbursement($s, $delegate);
    $s['payer']->givePermissionTo('measurements.finalize');
    segregationAssign($s, ['payment_finalizer_user_id' => $s['payer']]);
    $before = segregationState($measurement);

    expect(app(MeasurementWorkflow::class)->noDisbursementDecision($measurement)['decided_by'])->toBe($delegate->id)
        ->and(app(MeasurementWorkflow::class)->noDisbursementDecision($measurement)['delegated'])->toBeTrue();

    $refusal = segregationThrown(fn () => Governance::finalize($s, $measurement, by: $s['payer'], acknowledgeNoDisbursement: true));

    expect($refusal)->toBeInstanceOf(MeasurementSegregationException::class)
        ->and($refusal->getMessage())->toBe(MeasurementSegregationService::NO_DISBURSEMENT_SELF_ACKNOWLEDGMENT.' Você delegou a decisão de concluir a etapa Pagamento sem desembolso.')
        ->and(segregationState($measurement))->toBe($before);

    segregationAssign($s, ['payment_finalizer_user_id' => $s['finalizer']]);
    $measurement = Governance::finalize($s, $measurement, acknowledgeNoDisbursement: true);
    $check = segregationActivities($measurement, 'measurement_finalized')->sole()->properties['segregation']['no_disbursement'];

    expect($measurement->status)->toBe('finalized')
        ->and($check['makers'])->toEqual([
            ['user_id' => $delegate->id, 'act' => 'decided_no_disbursement'],
            ['user_id' => $s['payer']->id, 'act' => 'delegated_no_disbursement_decision'],
        ])
        ->and((int) $delegation->delegator_user_id)->toBe($s['payer']->id);
});

it('refuses an administrator who decided without disbursement by override the acknowledgment by override, even with the reason', function (string $administrator) {
    $s = Governance::plan();
    $actor = $s[$administrator];
    $measurement = segregationDecidedWithoutDisbursement($s, $actor, 'Gestor de Pagamento afastado no fechamento do mês.');
    $before = segregationState($measurement);

    expect(app(MeasurementWorkflow::class)->noDisbursementDecision($measurement)['admin_override'])->toBeTrue();

    $refusal = segregationThrown(fn () => Governance::finalize($s, $measurement, by: $actor, acknowledgeNoDisbursement: true, overrideReason: 'Finalizador afastado no fechamento do mês.'));

    expect($refusal)->toBeInstanceOf(MeasurementSegregationException::class)
        ->and($refusal->getMessage())->toBe(MeasurementSegregationService::NO_DISBURSEMENT_SELF_ACKNOWLEDGMENT.' Você decidiu concluir a etapa Pagamento sem desembolso.')
        ->and(segregationState($measurement))->toBe($before)
        ->and(MeasurementPayment::query()->count())->toBe(0);

    $measurement = Governance::finalize($s, $measurement, acknowledgeNoDisbursement: true);
    $acknowledgment = segregationActivities($measurement, 'measurement_finalized')->sole()->properties['no_disbursement_acknowledged'];

    expect($measurement->status)->toBe('finalized')
        ->and($acknowledgment['decided_by'])->toBe($actor->id)
        ->and($acknowledgment['decision_admin_override'])->toBeTrue()
        ->and($acknowledgment['same_actor_as_decision'])->toBeFalse();
})->with([
    'admin' => ['admin'],
    'super-admin' => ['superAdmin'],
]);

// ── SOD-4: a revisão não é aprovada na Compliance por quem a fez ────────────

/**
 * O que a revisão não pode ter mexido na anterior: o registro da Engenharia,
 * a situação e os pagamentos (o histórico da família).
 *
 * @return array<string, mixed>
 */
function segregationSourceRecord(Measurement $source): array
{
    $fresh = $source->fresh();

    return [
        'status' => $fresh->status,
        'engineering_snapshot' => $fresh->engineering_snapshot,
        'payments' => MeasurementPayment::query()
            ->where('measurement_id', $fresh->id)
            ->orderBy('id')
            ->get(['id', 'plan_set_id', 'amount', 'pay_date', 'created_by', 'financial_rule_id', 'financial_assessment'])
            ->toArray(),
    ];
}

/**
 * A aprovação da etapa pendente da medição, como a página a pede.
 */
function segregationApprove(Measurement $measurement, User $actor, ?string $overrideReason = null): void
{
    app(MeasurementWorkflow::class)->approve($measurement->fresh(), $actor, overrideReason: $overrideReason);
}

it('refuses the coordinator who created and submitted the revision its Compliance approval and keeps the previous revision in force', function () {
    $s = Governance::plan();
    $source = Governance::finalized($s, '2026-05', [10, 10]);
    $revision = Governance::revisionAtCompliance($s, $source, [12, 10]);
    // A coordenação passa a responder também pela Compliance.
    $s['coordinator']->givePermissionTo('measurements.review');
    segregationAssign($s, ['stage3_reviewer_user_id' => $s['coordinator']]);
    $workflow = app(MeasurementWorkflow::class);
    $sourceBefore = segregationSourceRecord($source);
    $before = segregationState($revision);

    expect($revision->revisionStatus())->toBe(MeasurementRevisionStatus::UnderReview)
        ->and($workflow->unifiedStage($revision))->toBe(3)
        ->and($workflow->canApprove($revision, $s['coordinator']))->toBeTrue();

    $refusal = segregationThrown(fn () => segregationApprove($revision, $s['coordinator']));

    expect($refusal)->toBeInstanceOf(MeasurementSegregationException::class)
        ->and($refusal->getMessage())->toBe(MeasurementSegregationService::REVISION_SELF_APPROVAL.' Você criou a revisão e enviou a revisão.')
        ->and($refusal->context())->toMatchArray([
            'control' => MeasurementSegregationService::CONTROL_REVISION,
            'checker_user_id' => $s['coordinator']->id,
            'maker_user_ids' => [$s['coordinator']->id],
        ])
        ->and(segregationState($revision))->toBe($before)
        ->and($revision->fresh()->revisionStatus())->toBe(MeasurementRevisionStatus::UnderReview)
        ->and($source->fresh()->isEffectiveRevision())->toBeTrue()
        ->and(segregationSourceRecord($source))->toBe($sourceBefore)
        ->and(segregationActivities($revision, 'measurement_revision_became_effective'))->toBeEmpty()
        // A tela desabilita a aprovação com a mesma frase do domínio.
        ->and($workflow->approvalBlockReason($revision->fresh(), $s['coordinator']))->toBe($refusal->getMessage());

    // A Compliance independente aprova: a revisão passa a valer e substitui a
    // anterior na mesma aprovação, sem tocar no histórico dela.
    segregationAssign($s, ['stage3_reviewer_user_id' => $s['compliance']]);

    expect($workflow->approvalBlockReason($revision->fresh(), $s['compliance']))->toBeNull();

    segregationApprove($revision, $s['compliance']);
    $approval = segregationActivities($revision, 'measurement_stage_approved', 3)->sole();

    expect($revision->fresh()->revisionStatus())->toBe(MeasurementRevisionStatus::Effective)
        ->and($revision->fresh()->status)->toBe('awaiting_payment')
        ->and($source->fresh()->revisionStatus())->toBe(MeasurementRevisionStatus::Superseded)
        ->and(segregationSourceRecord($source))->toBe($sourceBefore)
        ->and((int) $approval->causer_id)->toBe($s['compliance']->id)
        ->and($approval->properties['segregation'])->toMatchArray([
            'control' => MeasurementSegregationService::CONTROL_REVISION,
            'makers' => [
                ['user_id' => $s['coordinator']->id, 'act' => 'created_revision'],
                ['user_id' => $s['coordinator']->id, 'act' => 'submitted_revision'],
            ],
            'checker_user_id' => $s['compliance']->id,
            'checker_authorization_source' => 'direct',
            'independent' => true,
        ]);
});

it('bars both the creator and a different submitter from the Compliance approval of the revision', function () {
    $s = Governance::plan();
    $source = Governance::finalized($s, '2026-05', [10, 10]);
    // Quem envia é a Gestão, que também pode revisar medições nesta operação.
    $s['manager']->givePermissionTo('measurements.revise');
    $revision = Governance::revisionAtCompliance($s, $source, [12, 10], creator: $s['coordinator'], submitter: $s['manager']);
    $s['coordinator']->givePermissionTo('measurements.review');
    $before = segregationState($revision);

    expect((int) $revision->revision_created_by)->toBe($s['coordinator']->id)
        ->and((int) $revision->uploaded_by)->toBe($s['manager']->id);

    $refusals = [];

    foreach (['coordinator' => 'criou a revisão', 'manager' => 'enviou a revisão'] as $maker => $act) {
        segregationAssign($s, ['stage3_reviewer_user_id' => $s[$maker]]);
        $refusal = segregationThrown(fn () => segregationApprove($revision, $s[$maker]));
        $refusals[$maker] = [$refusal::class, $refusal->getMessage()];

        expect($refusals[$maker])->toBe([MeasurementSegregationException::class, MeasurementSegregationService::REVISION_SELF_APPROVAL." Você {$act}."]);
    }

    expect(segregationState($revision))->toBe($before)
        ->and($source->fresh()->isEffectiveRevision())->toBeTrue();

    segregationAssign($s, ['stage3_reviewer_user_id' => $s['compliance']]);
    segregationApprove($revision, $s['compliance']);

    expect($revision->fresh()->revisionStatus())->toBe(MeasurementRevisionStatus::Effective)
        ->and(segregationActivities($revision, 'measurement_stage_approved', 3)->sole()->properties['segregation']['makers'])->toEqual([
            ['user_id' => $s['coordinator']->id, 'act' => 'created_revision'],
            ['user_id' => $s['manager']->id, 'act' => 'submitted_revision'],
        ]);
});

it('lets the maker of the revision refuse it at Compliance, returning it to Management', function () {
    $s = Governance::plan();
    $source = Governance::finalized($s, '2026-05', [10, 10]);
    $revision = Governance::revisionAtCompliance($s, $source, [12, 10]);
    $s['coordinator']->givePermissionTo('measurements.review');
    segregationAssign($s, ['stage3_reviewer_user_id' => $s['coordinator']]);

    app(MeasurementWorkflow::class)->reject($revision->fresh(), $s['coordinator'], 'Percentual da Torre Norte sem o laudo do mês.');
    $rejection = segregationActivities($revision, 'measurement_stage_rejected', 3)->sole();

    expect($revision->fresh()->only(['status', 'current_stage']))->toBe(['status' => 'in_review', 'current_stage' => 2])
        ->and($revision->fresh()->revisionStatus())->toBe(MeasurementRevisionStatus::UnderReview)
        ->and($source->fresh()->isEffectiveRevision())->toBeTrue()
        ->and((int) $rejection->causer_id)->toBe($s['coordinator']->id)
        ->and($rejection->properties->all())->not->toHaveKey('segregation');
});

it('refuses a Compliance delegate of the revision maker, and accepts an administrator who did not take part', function () {
    $s = Governance::plan();
    $source = Governance::finalized($s, '2026-05', [10, 10]);
    // A coordenação responde pela Compliance e, depois de criar e enviar a
    // revisão, delega a Compliance a outra pessoa.
    $s['coordinator']->givePermissionTo('measurements.review');
    segregationAssign($s, ['stage3_reviewer_user_id' => $s['coordinator']]);
    $revision = Governance::revisionAtCompliance($s, $source, [12, 10]);
    $delegate = segregationUser(['measurements.view', 'measurements.review']);
    segregationDelegate($s, MeasurementResponsibility::ComplianceReviewer, $delegate);
    $before = segregationState($revision);

    $refusal = segregationThrown(fn () => segregationApprove($revision, $delegate));

    expect($refusal)->toBeInstanceOf(MeasurementSegregationException::class)
        ->and($refusal->getMessage())->toBe(sprintf(
            '%s Você age por delegação de %s, que criou a revisão e enviou a revisão: a delegação muda quem pode agir, não quem fez a ação, e a conferência precisa ser de outro responsável.',
            MeasurementSegregationService::REVISION_SELF_APPROVAL,
            $s['coordinator']->name,
        ))
        ->and(segregationState($revision))->toBe($before);

    // O admin, que não participou de nada, aprova pelo override, com o motivo.
    segregationApprove($revision, $s['admin'], 'Compliance da operação toda impedida nesta revisão.');
    $approval = segregationActivities($revision, 'measurement_stage_approved', 3)->sole();

    expect($revision->fresh()->revisionStatus())->toBe(MeasurementRevisionStatus::Effective)
        ->and($approval->properties['admin_override'])->toBeTrue()
        ->and($approval->properties['admin_override_reason'])->toBe('Compliance da operação toda impedida nesta revisão.')
        ->and($approval->properties['segregation']['checker_authorization_source'])->toBe('admin_override')
        ->and($approval->properties['segregation']['independent'])->toBeTrue();
});

it('refuses the super-admin who created and submitted the revision by override its Compliance approval by override', function () {
    $s = Governance::plan();
    $source = Governance::finalized($s, '2026-05', [10, 10]);
    $draft = Governance::revise($s, $source, $s['superAdmin'], 'Correção pedida pela auditoria externa.');
    $draftBefore = segregationState($draft);
    // Fora da operação, o envio é só pelo override: sem motivo, recusado.
    $unexplained = segregationThrown(fn () => Governance::submit($s, $draft, $s['superAdmin']));

    expect($unexplained)->toBeInstanceOf(ValidationException::class)
        ->and($unexplained->errors())->toBe(['override_reason' => [MeasurementSegregationService::OVERRIDE_REASON_REQUIRED]])
        ->and(segregationState($draft))->toBe($draftBefore);

    $revision = Governance::submit($s, $draft, $s['superAdmin'], 'Envio pedido pela auditoria externa.');
    $workflow = app(MeasurementWorkflow::class);
    $workflow->approve($revision->fresh(), $s['engineer'], engineeringProgress: [$s['planSets'][0]->id => 12, $s['planSets'][1]->id => 10]);
    $workflow->approve($revision->fresh(), $s['manager']);
    $created = segregationActivities($revision, 'measurement_revision_created')->sole();
    $submitted = segregationActivities($revision, 'measurement_revision_submitted')->sole();
    $before = segregationState($revision);

    expect($s['operation']->fresh()->hasParticipant($s['superAdmin']))->toBeFalse()
        ->and($created->properties['admin_override'])->toBeTrue()
        ->and($created->properties['admin_override_reason'])->toBe('Correção pedida pela auditoria externa.')
        ->and($submitted->properties['admin_override'])->toBeTrue()
        ->and($submitted->properties['admin_override_reason'])->toBe('Envio pedido pela auditoria externa.');

    $refusal = segregationThrown(fn () => segregationApprove($revision, $s['superAdmin'], 'Aprovação pedida pela auditoria externa.'));

    expect($refusal)->toBeInstanceOf(MeasurementSegregationException::class)
        ->and($refusal->getMessage())->toBe(MeasurementSegregationService::REVISION_SELF_APPROVAL.' Você criou a revisão e enviou a revisão.')
        ->and(segregationState($revision))->toBe($before)
        ->and($source->fresh()->isEffectiveRevision())->toBeTrue();

    segregationApprove($revision, $s['compliance']);

    expect($revision->fresh()->revisionStatus())->toBe(MeasurementRevisionStatus::Effective);
});

it('refuses to submit a revision when nobody independent could approve it at Compliance', function () {
    $s = Governance::plan();
    $source = Governance::finalized($s, '2026-05', [10, 10]);
    $draft = Governance::revise($s, $source);
    // A coordenação é também a Compliance, e nenhum administrador do fluxo resta.
    $s['coordinator']->givePermissionTo('measurements.review');
    segregationAssign($s, ['stage3_reviewer_user_id' => $s['coordinator']]);
    $s['admin']->removeRole('admin');
    $s['superAdmin']->removeRole('super-admin');
    $revisions = app(MeasurementRevisionService::class);
    $before = segregationState($draft);
    $sourceBefore = segregationState($source);

    expect($revisions->submissionBlockReason($draft->fresh(), $s['coordinator']))->toBe(MeasurementSegregationService::NO_INDEPENDENT_COMPLIANCE_APPROVER);

    $refusal = segregationThrown(fn () => Governance::submit($s, $draft));

    expect($refusal)->toBeInstanceOf(MeasurementSegregationException::class)
        ->and($refusal->getMessage())->toBe(MeasurementSegregationService::NO_INDEPENDENT_COMPLIANCE_APPROVER)
        ->and($refusal->context())->toMatchArray([
            'control' => MeasurementSegregationService::CONTROL_REVISION,
            'maker_user_ids' => [$s['coordinator']->id],
        ])
        ->and(segregationState($draft))->toBe($before)
        ->and($draft->fresh()->revisionStatus())->toBe(MeasurementRevisionStatus::Draft)
        ->and(segregationState($source))->toBe($sourceBefore)
        ->and(segregationActivities($draft, 'measurement_revision_submitted'))->toBeEmpty();

    // Com um administrador do fluxo de novo, há quem aprove: o envio passa.
    $s['admin']->assignRole('admin');

    expect($revisions->submissionBlockReason($draft->fresh(), $s['coordinator']))->toBeNull()
        ->and(Governance::submit($s, $draft)->revisionStatus())->toBe(MeasurementRevisionStatus::UnderReview);
});

/**
 * Altera o conteúdo da revisão como esta pessoa faria pela tela de edição:
 * a trilha atribui a alteração a quem está autenticado.
 */
function segregationEditRevisionAs(User $editor, Measurement $revision, string $edit): void
{
    test()->actingAs($editor);

    if ($edit === 'notes') {
        $revision->fresh()->update(['notes' => 'Texto da revisão ajustado por quem a confere.']);
    } else {
        $path = 'nimbus_docs/measurements/assets/revisao-'.$revision->id.'-trocada.pdf';
        Storage::disk('local')->put($path, '%PDF-1.7 arquivo trocado depois do envio');
        $revision->assets()->orderBy('id')->firstOrFail()->update(['storage_path' => $path]);
    }

    auth()->forgetUser();
}

it('bars from the Compliance approval whoever changed the content of the revision after it was sent', function (string $edit) {
    $s = Governance::plan();
    $source = Governance::finalized($s, '2026-05', [10, 10]);
    $revision = Governance::submit($s, Governance::revise($s, $source));
    // A Compliance altera a revisão enquanto ela está na Engenharia.
    segregationEditRevisionAs($s['compliance'], $revision, $edit);
    $workflow = app(MeasurementWorkflow::class);
    $workflow->approve($revision->fresh(), $s['engineer'], engineeringProgress: [$s['planSets'][0]->id => 12, $s['planSets'][1]->id => 10]);
    $workflow->approve($revision->fresh(), $s['manager']);
    $before = segregationState($revision);

    expect(app(MeasurementSegregationService::class)->revisionContentEditorIds($revision->fresh()))->toBe([$s['compliance']->id]);

    $refusal = segregationThrown(fn () => segregationApprove($revision, $s['compliance']));

    expect($refusal)->toBeInstanceOf(MeasurementSegregationException::class)
        ->and($refusal->getMessage())->toBe(MeasurementSegregationService::REVISION_SELF_APPROVAL.' Você alterou o conteúdo da revisão.')
        ->and($workflow->approvalBlockReason($revision->fresh(), $s['compliance']))->toBe($refusal->getMessage())
        ->and(segregationState($revision))->toBe($before)
        ->and($source->fresh()->isEffectiveRevision())->toBeTrue();

    // Quem não tocou na revisão a aprova, e a trilha diz quem a alterou.
    segregationApprove($revision, $s['admin'], 'Compliance alterou a revisão e não pode aprová-la.');

    expect($revision->fresh()->revisionStatus())->toBe(MeasurementRevisionStatus::Effective)
        ->and(segregationActivities($revision, 'measurement_stage_approved', 3)->sole()->properties['segregation']['makers'])
        ->toContainEqual(['user_id' => $s['compliance']->id, 'act' => 'edited_revision']);
})->with(['notes', 'file']);

it('counts whoever changed the draft among its makers when checking that someone independent can approve it', function () {
    $s = Governance::plan();
    $source = Governance::finalized($s, '2026-05', [10, 10]);
    $draft = Governance::revise($s, $source);
    // A única Compliance possível altera o rascunho, e nenhum administrador do fluxo resta.
    segregationEditRevisionAs($s['compliance'], $draft, 'notes');
    $s['admin']->removeRole('admin');
    $s['superAdmin']->removeRole('super-admin');
    $before = segregationState($draft);

    $refusal = segregationThrown(fn () => Governance::submit($s, $draft));

    expect($refusal)->toBeInstanceOf(MeasurementSegregationException::class)
        ->and($refusal->getMessage())->toBe(MeasurementSegregationService::NO_INDEPENDENT_COMPLIANCE_APPROVER)
        ->and($refusal->context()['maker_user_ids'])->toEqualCanonicalizing([$s['coordinator']->id, $s['compliance']->id])
        ->and(app(MeasurementRevisionService::class)->submissionBlockReason($draft->fresh(), $s['coordinator']))->toBe(MeasurementSegregationService::NO_INDEPENDENT_COMPLIANCE_APPROVER)
        ->and(segregationState($draft))->toBe($before);
});

it('refuses whoever registered the family payments that form the overpayment of a revision its acceptance at the revision Finalization', function () {
    $s = Governance::plan();
    // A R0 pagou R$ 100.000,00 na Torre Norte; a R1 reduz o realizado a 8%
    // (R$ 80.000,00): a família pagou R$ 20.000,00 a maior.
    $source = Governance::finalized($s, '2026-05', [10, 10]);
    $familyPayment = MeasurementPayment::query()->where('measurement_id', $source->id)->where('plan_set_id', $s['planSets'][0]->id)->sole();
    $revision = Governance::revisionAtCompliance($s, $source, [8, 10]);
    segregationApprove($revision, $s['compliance']);
    // A decisão da etapa Pagamento da R1 é do admin, pelo override: o único ato
    // do Gestor de Pagamento nesta exceção é o pagamento da R0.
    app(MeasurementWorkflow::class)->approve($revision->fresh(), $s['admin'], 'Valor pago a maior mantido em aberto até o acerto com a construtora.', expectedStage: MeasurementWorkflow::STAGE_PAYMENT, overrideReason: 'Gestor de Pagamento afastado no fechamento do mês.');
    $s['payer']->givePermissionTo('measurements.finalize');
    segregationAssign($s, ['payment_finalizer_user_id' => $s['payer']]);
    $revision = $revision->fresh();
    $before = segregationState($revision);

    expect(app(MeasurementRevisionService::class)->unacknowledgedOverpayments($revision))->toHaveCount(1)
        ->and((int) $familyPayment->created_by)->toBe($s['payer']->id);

    $refusal = segregationThrown(fn () => Governance::finalize($s, $revision, accept: true, by: $s['payer']));

    expect($refusal)->toBeInstanceOf(MeasurementSegregationException::class)
        ->and($refusal->getMessage())->toBe(sprintf('%s Você registrou o pagamento #%d da R0, que forma o valor pago a maior.', MeasurementSegregationService::FINANCIAL_EXCEPTION_SELF_ACCEPTANCE, $familyPayment->id))
        ->and(segregationState($revision))->toBe($before);

    segregationAssign($s, ['payment_finalizer_user_id' => $s['finalizer']]);
    $revision = Governance::finalize($s, $revision, accept: true);
    $check = segregationActivities($revision, 'measurement_finalized')->sole()->properties['segregation']['financial_exceptions'];

    expect($revision->status)->toBe('finalized')
        ->and($check['independent'])->toBeTrue()
        ->and($check['makers'])->toContainEqual(
            ['user_id' => $s['payer']->id, 'act' => 'registered_family_payment'],
            ['user_id' => $s['admin']->id, 'act' => 'approved_payment_stage'],
        );
});

it('refuses to revise a paid measurement before its Finalization while an excess payment awaits the independent acceptance', function () {
    $s = Governance::plan();
    ['measurement' => $measurement, 'excess' => $excess] = segregationExcessRegistered($s);
    $measurement = Governance::readyToFinalize($s, $measurement);
    $revisions = app(MeasurementRevisionService::class);
    $refusalText = sprintf(MeasurementRevisionService::PAID_EXCEPTION_UNACCEPTED_REFUSAL, $excess->id);
    $before = segregationState($measurement);

    expect($measurement->status)->toBe('approved')
        ->and($revisions->creationBlockReason($measurement))->toBe($refusalText);

    // Substituída antes da Finalização, a exceção iria para o histórico sem o
    // aceite independente.
    $refusal = segregationThrown(fn () => Governance::revise($s, $measurement));

    expect($refusal)->toBeInstanceOf(MeasurementWorkflowException::class)
        ->and($refusal->getMessage())->toBe($refusalText)
        ->and(segregationState($measurement))->toBe($before)
        ->and(Measurement::query()->where('revision_family_id', $measurement->id)->where('revision_number', '>', 0)->exists())->toBeFalse();

    // Aceita pelo Finalizador independente, a medição finalizada é revisável.
    $measurement = Governance::finalize($s, $measurement, accept: true);

    expect($revisions->creationBlockReason($measurement))->toBeNull()
        ->and(Governance::revise($s, $measurement)->revisionStatus())->toBe(MeasurementRevisionStatus::Draft);
});

// ── Regras financeiras: quem registra a versão não a usa ────────────────────

/**
 * Nova versão da regra financeira da emissão, registrada pelo serviço por
 * `$author` com o limite em reais dado, substituindo `$previous`.
 *
 * @param  array<string, mixed>  $scenario
 */
function segregationRuleVersion(array $scenario, User $author, MeasurementFinancialRule $previous, string $limit): MeasurementFinancialRule
{
    $author->givePermissionTo(['measurements.financial-rules.view', 'measurements.financial-rules.manage']);

    return app(MeasurementFinancialRuleService::class)->register([
        'emission_id' => $scenario['emission']->id,
        'construction_id' => null,
        'name' => 'Tolerância contratual',
        'description' => 'Tolerância de excedente prevista no contrato de obra.',
        'direction' => 'over',
        'maximum_difference_amount' => $limit,
        'maximum_difference_percent' => null,
        'requires_document' => false,
        'effective_from' => '2026-01-01',
        'effective_until' => null,
    ], $author, $previous);
}

it('refuses the payer the use of a rule version they registered, directly or through their delegate, and lets another payer use it', function () {
    $s = Governance::plan();
    $measurement = Governance::awaitingPayment($s, '2026-05', [10, 10]);
    $original = Governance::rule($s);
    // Com a regra de quem cadastra regras, o Gestor de Pagamento paga o
    // excedente da Torre Sul (R$ 1.000,00, dentro dos R$ 5.000,00).
    $allowed = Governance::pay($s, $measurement, [Governance::row($s, 1, '51000.00', ['financial_rule_id' => $original->id, 'financial_justification' => Governance::JUSTIFICATION])])->sole();
    // Ele mesmo amplia a regra (v2) para o excedente maior da Torre Norte.
    $own = segregationRuleVersion($s, $s['payer'], $original, '20000.00');
    $row = Governance::row($s, 0, '110000.00', ['financial_rule_id' => $own->id, 'financial_justification' => Governance::JUSTIFICATION]);
    $financial = app(MeasurementPaymentFinancialService::class);
    $before = segregationState($measurement);

    expect($allowed->financial_assessment['rule']['id'])->toBe($original->id)
        ->and($own->version)->toBe(2)
        ->and((int) $own->supersedes_id)->toBe($original->id)
        ->and((int) $own->created_by)->toBe($s['payer']->id)
        ->and($financial->selectableRules($measurement->fresh(), $s['planSets'][0]->id, Governance::PAY_DATE, $s['payer'])->pluck('id')->all())->not->toContain($own->id);

    $refusal = segregationThrown(fn () => Governance::pay($s, $measurement, [$row]));

    expect($refusal)->toBeInstanceOf(ValidationException::class)
        ->and($refusal->errors())->toBe(['payments.0.financial_rule_id' => [MeasurementPaymentFinancialService::RULE_AUTHOR_SELF_USE_REFUSAL.' Você registrou a versão 2 desta regra.']])
        ->and(segregationState($measurement))->toBe($before);

    // O delegado de quem registrou a versão age com a autoridade dele.
    $delegate = segregationUser(['measurements.view', 'measurements.pay']);
    segregationDelegate($s, MeasurementResponsibility::PaymentManager, $delegate);

    $delegated = segregationThrown(fn () => Governance::pay($s, $measurement, [$row], $delegate));

    expect($delegated)->toBeInstanceOf(ValidationException::class)
        ->and($delegated->errors())->toBe(['payments.0.financial_rule_id' => [MeasurementPaymentFinancialService::RULE_AUTHOR_SELF_USE_REFUSAL." Você age por delegação de {$s['payer']->name}, que registrou a versão 2 desta regra."]])
        ->and($financial->selectableRules($measurement->fresh(), $s['planSets'][0]->id, Governance::PAY_DATE, $delegate)->pluck('id')->all())->not->toContain($own->id)
        ->and(segregationState($measurement))->toBe($before);

    // Outra pessoa, Gestora de Pagamento direta, usa a versão.
    $otherPayer = segregationUser(['measurements.view', 'measurements.pay']);
    segregationAssign($s, ['payment_manager_user_id' => $otherPayer]);

    expect($financial->selectableRules($measurement->fresh(), $s['planSets'][0]->id, Governance::PAY_DATE, $otherPayer)->pluck('id')->all())->toContain($own->id);

    $payment = Governance::pay($s, $measurement, [$row], $otherPayer)->sole();

    expect((int) $payment->created_by)->toBe($otherPayer->id)
        ->and($payment->financial_assessment['rule']['id'])->toBe($own->id)
        ->and($payment->financial_assessment['rule']['created_by'])->toBe($s['payer']->id)
        ->and($payment->financial_assessment['coverage']['covered'])->toBeTrue();
});

it('keeps every author of the rule chain from using it: a version re-registered unchanged by someone else does not clear who raised the limit', function () {
    $s = Governance::plan();
    $measurement = Governance::awaitingPayment($s, '2026-05', [10, 10]);
    $original = Governance::rule($s);
    // O Gestor de Pagamento amplia o limite (v2); quem cadastra regras registra
    // a v3 idêntica à v2.
    $raised = segregationRuleVersion($s, $s['payer'], $original, '20000.00');
    $reissued = segregationRuleVersion($s, $s['ruleAuthor'], $raised, '20000.00');
    $row = Governance::row($s, 0, '110000.00', ['financial_rule_id' => $reissued->id, 'financial_justification' => Governance::JUSTIFICATION]);
    $before = segregationState($measurement);

    expect($reissued->version)->toBe(3)
        ->and((int) $reissued->created_by)->toBe($s['ruleAuthor']->id)
        ->and(app(MeasurementFinancialRuleService::class)->authorsOf($reissued->id))->toEqualCanonicalizing([$s['ruleAuthor']->id, $s['payer']->id]);

    $refusal = segregationThrown(fn () => Governance::pay($s, $measurement, [$row]));

    expect($refusal)->toBeInstanceOf(ValidationException::class)
        ->and($refusal->errors())->toBe(['payments.0.financial_rule_id' => [MeasurementPaymentFinancialService::RULE_AUTHOR_SELF_USE_REFUSAL.' Você registrou a versão 2 desta regra, que a versão 3 substitui.']])
        ->and(segregationState($measurement))->toBe($before);

    // Outra Gestora de Pagamento usa a v3, e o excedente segue o fluxo.
    $otherPayer = segregationUser(['measurements.view', 'measurements.pay']);
    segregationAssign($s, ['payment_manager_user_id' => $otherPayer]);
    $excess = Governance::pay($s, $measurement, [$row, Governance::row($s, 1, '50000.00')], $otherPayer)->first();
    Governance::approvePaymentStage($s, $measurement, by: $otherPayer);
    Governance::uploadReceipts($s, $measurement);
    Governance::approveReceipts($s, $measurement);
    // Quem ampliou o limite também não aceita a exceção na Finalização.
    $s['payer']->givePermissionTo('measurements.finalize');
    segregationAssign($s, ['payment_finalizer_user_id' => $s['payer']]);
    $before = segregationState($measurement);

    $acceptance = segregationThrown(fn () => Governance::finalize($s, $measurement, accept: true, by: $s['payer']));

    expect($acceptance)->toBeInstanceOf(MeasurementSegregationException::class)
        ->and($acceptance->getMessage())->toBe(sprintf('%s Você registrou a regra financeira aplicada ao pagamento #%d.', MeasurementSegregationService::FINANCIAL_EXCEPTION_SELF_ACCEPTANCE, $excess->id))
        ->and(segregationState($measurement))->toBe($before);

    segregationAssign($s, ['payment_finalizer_user_id' => $s['finalizer']]);
    $measurement = Governance::finalize($s, $measurement, accept: true);

    expect($measurement->status)->toBe('finalized')
        ->and(segregationActivities($measurement, 'measurement_finalized')->sole()->properties['segregation']['financial_exceptions']['makers'])->toContainEqual(
            ['user_id' => $s['ruleAuthor']->id, 'act' => 'authored_financial_rule'],
            ['user_id' => $s['payer']->id, 'act' => 'authored_financial_rule'],
        );
});

it('leaves ordinary payments outside the rule controls: a rule author keeps registering payments within the balance', function () {
    $s = Governance::plan();
    $measurement = Governance::awaitingPayment($s, '2026-05', [10, 10]);
    $own = segregationRuleVersion($s, $s['payer'], Governance::rule($s), '20000.00');

    $payments = Governance::pay($s, $measurement, [
        Governance::row($s, 0, '100000.00'),
        Governance::row($s, 1, '40000.00', ['financial_justification' => 'Retenção contratual até a vistoria da obra.']),
    ]);

    expect((int) $own->created_by)->toBe($s['payer']->id)
        ->and($payments)->toHaveCount(2)
        ->and($payments->pluck('financial_rule_id')->all())->toBe([null, null])
        ->and($payments->first()->financial_assessment['requires_acceptance'])->toBeFalse()
        ->and($payments->last()->financial_assessment['reference']['status'])->toBe('under');

    $measurement = Governance::finalize($s, Governance::readyToFinalize($s, $measurement), accept: true);
    $check = segregationActivities($measurement, 'measurement_finalized')->sole()->properties['segregation']['financial_exceptions'];

    // O parcial pede o Finalizador independente pelo saldo em aberto, mas a
    // regra de quem paga não entra: nenhum pagamento a aplicou.
    expect($measurement->status)->toBe('finalized')
        ->and($check['required_because'])->toBe(['partial_payment_outstanding_balance'])
        ->and($check['independent'])->toBeTrue()
        ->and(collect($check['makers'])->pluck('act')->all())->not->toContain('authored_financial_rule');
});

// ── Motivo do override administrativo ───────────────────────────────────────

it('requires the override reason from an administrator who decides a stage only by the override, and records it', function () {
    $s = Governance::plan();
    $measurement = Governance::measured($s, '2026-05', [10, 10]);
    $before = segregationState($measurement);

    $missing = segregationThrown(fn () => segregationApprove($measurement, $s['admin']));
    $invisible = segregationThrown(fn () => segregationApprove($measurement, $s['admin'], " \u{00A0}\u{200B}\t"));
    $tooLong = segregationThrown(fn () => segregationApprove($measurement, $s['admin'], str_repeat('a', MeasurementSegregationService::OVERRIDE_REASON_MAX_LENGTH + 1)));

    expect($missing)->toBeInstanceOf(ValidationException::class)
        ->and($missing->errors())->toBe(['override_reason' => [MeasurementSegregationService::OVERRIDE_REASON_REQUIRED]])
        ->and($invisible->errors())->toBe(['override_reason' => [MeasurementSegregationService::OVERRIDE_REASON_REQUIRED]])
        ->and($tooLong->errors())->toBe(['override_reason' => ['O motivo do override administrativo tem no máximo 2000 caracteres.']])
        ->and(segregationState($measurement))->toBe($before);

    segregationApprove($measurement, $s['admin'], '  Gestão afastada no fechamento do mês.  ');
    $approval = segregationActivities($measurement, 'measurement_stage_approved', 2)->sole();

    expect((int) $measurement->fresh()->current_stage)->toBe(3)
        ->and((int) $approval->causer_id)->toBe($s['admin']->id)
        ->and($approval->properties['admin_override'])->toBeTrue()
        ->and($approval->properties['delegated'])->toBeFalse()
        ->and($approval->properties['admin_override_reason'])->toBe('Gestão afastada no fechamento do mês.');
});

it('ignores an override reason sent by whoever acts by their own responsibility or by delegation', function () {
    $s = Governance::plan();
    $measurement = Governance::measured($s, '2026-05', [10, 10]);
    $delegate = segregationUser(['measurements.view', 'measurements.review']);
    segregationDelegate($s, MeasurementResponsibility::ComplianceReviewer, $delegate);

    segregationApprove($measurement, $s['manager'], 'Motivo que não se aplica a quem responde pela etapa.');
    segregationApprove($measurement, $delegate, 'Motivo que não se aplica a quem age por delegação.');
    $management = segregationActivities($measurement, 'measurement_stage_approved', 2)->sole();
    $compliance = segregationActivities($measurement, 'measurement_stage_approved', 3)->sole();

    expect($management->properties['admin_override'])->toBeFalse()
        ->and($management->properties->all())->not->toHaveKey('admin_override_reason')
        ->and($compliance->properties['delegated'])->toBeTrue()
        ->and($compliance->properties['admin_override'])->toBeFalse()
        ->and($compliance->properties->all())->not->toHaveKey('admin_override_reason');
});

it('asks no reason from an administrator who is the direct responsible: the override is not what authorizes them', function () {
    $s = Governance::plan();
    $measurement = Governance::measured($s, '2026-05', [10, 10]);
    segregationAssign($s, ['stage2_reviewer_user_id' => $s['admin']]);

    segregationApprove($measurement, $s['admin']);
    $approval = segregationActivities($measurement, 'measurement_stage_approved', 2)->sole();

    expect((int) $measurement->fresh()->current_stage)->toBe(3)
        ->and($approval->properties['admin_override'])->toBeFalse()
        ->and($approval->properties['delegated'])->toBeFalse()
        ->and($approval->properties->all())->not->toHaveKey('admin_override_reason');
});

it('requires the override reason to register or reassess payments only by the override, and records it', function () {
    $s = Governance::plan();
    $measurement = Governance::awaitingPayment($s, '2026-05', [10, 10]);
    $rows = [Governance::row($s, 0, '100000.00'), Governance::row($s, 1, '50000.00')];
    $before = segregationState($measurement);

    $refusal = segregationThrown(fn () => Governance::pay($s, $measurement, $rows, $s['admin']));

    expect($refusal)->toBeInstanceOf(ValidationException::class)
        ->and($refusal->errors())->toBe(['override_reason' => [MeasurementSegregationService::OVERRIDE_REASON_REQUIRED]])
        ->and(segregationState($measurement))->toBe($before);

    $payment = Governance::pay($s, $measurement, $rows, $s['admin'], 'Gestor de Pagamento afastado; pagamento feito pela tesouraria.')->first();
    $registration = segregationActivities($measurement, 'measurement_payment_registered')->sole();

    expect(MeasurementPayment::query()->where('measurement_id', $measurement->id)->count())->toBe(2)
        ->and($registration->properties['admin_override'])->toBeTrue()
        ->and($registration->properties['admin_override_reason'])->toBe('Gestor de Pagamento afastado; pagamento feito pela tesouraria.');

    $workflow = app(MeasurementWorkflow::class);
    $data = ['financial_rule_id' => null, 'financial_justification' => null];
    $before = segregationState($measurement);

    $reassessment = segregationThrown(fn () => $workflow->reassessPayment($payment->fresh(), $s['admin'], $data, (int) $measurement->fresh()->workflow_revision));

    expect($reassessment)->toBeInstanceOf(ValidationException::class)
        ->and($reassessment->errors())->toBe(['override_reason' => [MeasurementSegregationService::OVERRIDE_REASON_REQUIRED]])
        ->and(segregationState($measurement))->toBe($before);

    $workflow->reassessPayment($payment->fresh(), $s['admin'], $data, (int) $measurement->fresh()->workflow_revision, 'Conferência do enquadramento pela tesouraria.');

    expect(segregationActivities($measurement, 'measurement_payment_reassessed')->sole()->properties['admin_override_reason'])->toBe('Conferência do enquadramento pela tesouraria.');
});

it('requires the override reason to finalize only by the override, and records it', function () {
    $s = Governance::plan();
    $measurement = segregationAwaitingReceipts($s);
    Governance::uploadReceipts($s, $measurement);
    Governance::approveReceipts($s, $measurement);
    $before = segregationState($measurement);

    $refusal = segregationThrown(fn () => Governance::finalize($s, $measurement, by: $s['admin']));

    expect($refusal)->toBeInstanceOf(ValidationException::class)
        ->and($refusal->errors())->toBe(['override_reason' => [MeasurementSegregationService::OVERRIDE_REASON_REQUIRED]])
        ->and(segregationState($measurement))->toBe($before);

    $measurement = Governance::finalize($s, $measurement, by: $s['admin'], overrideReason: 'Finalizador afastado no fechamento do mês.');
    $finalization = segregationActivities($measurement, 'measurement_finalized')->sole();

    expect($measurement->status)->toBe('finalized')
        ->and($finalization->properties['admin_override'])->toBeTrue()
        ->and($finalization->properties['admin_override_reason'])->toBe('Finalizador afastado no fechamento do mês.')
        // Pagamento comum: nada que peça conferência independente.
        ->and($finalization->properties->all())->not->toHaveKey('segregation');
});

it('reuses the reason of a pause or a refusal made only by the override as its override reason, and requires one to resume', function () {
    $s = Governance::plan();
    $measurement = Governance::measured($s, '2026-05', [10, 10]);
    $workflow = app(MeasurementWorkflow::class);

    $workflow->pause($measurement->fresh(), $s['admin'], 'Aguardando o laudo complementar da obra.');
    $pause = segregationActivities($measurement, 'measurement_stage_paused')->sole();
    $before = segregationState($measurement);

    $refusal = segregationThrown(fn () => $workflow->resume($measurement->fresh(), $s['admin']));

    expect($pause->properties['admin_override'])->toBeTrue()
        ->and($pause->properties['admin_override_reason'])->toBe('Aguardando o laudo complementar da obra.')
        ->and($refusal)->toBeInstanceOf(ValidationException::class)
        ->and($refusal->errors())->toBe(['override_reason' => [MeasurementSegregationService::OVERRIDE_REASON_REQUIRED]])
        ->and(segregationState($measurement))->toBe($before);

    $workflow->resume($measurement->fresh(), $s['admin'], overrideReason: 'Laudo complementar recebido.');
    $workflow->reject($measurement->fresh(), $s['admin'], 'Percentual da Torre Norte sem o laudo do mês.');
    $resume = segregationActivities($measurement, 'measurement_stage_resumed')->sole();
    $rejection = segregationActivities($measurement, 'measurement_stage_rejected', 2)->sole();

    expect($resume->properties['admin_override'])->toBeTrue()
        ->and($resume->properties['admin_override_reason'])->toBe('Laudo complementar recebido.')
        ->and($rejection->properties['admin_override'])->toBeTrue()
        ->and($rejection->properties['admin_override_reason'])->toBe('Percentual da Torre Norte sem o laudo do mês.')
        ->and((int) $measurement->fresh()->current_stage)->toBe(MeasurementWorkflow::STAGE_ENGINEERING);
});

it('requires the override reason to send or review a receipt only by the override, the correction reason counting as one', function () {
    $s = Governance::plan();
    $measurement = segregationAwaitingReceipts($s);
    [$first, $second] = $measurement->payments()->orderBy('id')->get()->all();
    $receipts = app(MeasurementReceiptEvidenceService::class);
    $before = segregationState($measurement);

    $upload = segregationThrown(fn () => $receipts->upload($first->fresh(), $s['admin'], MeasurementReceiptEvidenceScenario::file()));

    expect($upload)->toBeInstanceOf(ValidationException::class)
        ->and($upload->errors())->toBe(['override_reason' => [MeasurementSegregationService::OVERRIDE_REASON_REQUIRED]])
        ->and(segregationState($measurement))->toBe($before);

    // Quem responde pelo envio não precisa de motivo; a conferência pelo override, sim.
    Governance::uploadReceipts($s, $measurement);
    $evidence = segregationFirstEvidence($measurement);
    $before = segregationState($measurement);

    $review = segregationThrown(fn () => segregationReview($evidence, $s['admin']));

    expect($review)->toBeInstanceOf(ValidationException::class)
        ->and($review->errors())->toBe(['override_reason' => [MeasurementSegregationService::OVERRIDE_REASON_REQUIRED]])
        ->and(segregationState($measurement))->toBe($before);

    segregationReview($evidence, $s['admin'], overrideReason: 'Finalizador afastado no fechamento do mês.');
    $approval = segregationEvidenceActivities($evidence, 'receipt_evidence_approved')->sole();

    expect($approval->properties['admin_override'])->toBeTrue()
        ->and($approval->properties['admin_override_reason'])->toBe('Finalizador afastado no fechamento do mês.')
        ->and($approval->properties['segregation']['checker_authorization_source'])->toBe('admin_override')
        ->and($approval->properties['segregation']['independent'])->toBeTrue();

    // A nova versão enviada pelo override com o motivo da correção: ele é o motivo do override.
    $rejected = $second->fresh()->currentReceiptEvidence;
    segregationReview($rejected, $s['finalizer'], MeasurementReceiptReviewStatus::Rejected, rejectionReason: 'Comprovante de outro favorecido.');
    // Como motivo do override, o da correção segue o limite dele (5.000
    // caracteres), e não o do campo próprio do override (2.000).
    $longCorrection = 'Comprovante correto enviado pelo banco. '.str_repeat('Detalhe da conferência com o extrato. ', 70);
    $corrected = $receipts->upload($second->fresh(), $s['admin'], MeasurementReceiptEvidenceScenario::file('comprovante-correto.pdf', '%PDF-1.7 comprovante correto'), $rejected->id, $longCorrection);

    expect(mb_strlen(trim($longCorrection)))->toBeGreaterThan(MeasurementSegregationService::OVERRIDE_REASON_MAX_LENGTH)
        ->and(segregationEvidenceActivities($corrected, 'receipt_evidence_uploaded')->sole()->properties['admin_override_reason'])->toBe(trim($longCorrection));
});

// ── Acesso: quem não responde pela etapa não age nela ──────────────────────

it('refuses payment registration and Finalization to whoever only receives the rejection notice or takes no part in the operation', function (Closure $outsider) {
    $s = Governance::plan();
    $user = $outsider($s);
    $measurement = Governance::awaitingPayment($s, '2026-05', [10, 10]);
    $rows = [Governance::row($s, 0, '100000.00'), Governance::row($s, 1, '50000.00')];
    $before = segregationState($measurement);

    expect($user->can('measurements.pay') && $user->can('measurements.finalize'))->toBeTrue()
        ->and($s['operation']->fresh()->hasParticipant($user))->toBeFalse();

    $registration = segregationThrown(fn () => Governance::pay($s, $measurement, $rows, $user));

    expect($registration)->toBeInstanceOf(AuthorizationException::class)
        ->and($registration->getMessage())->toBe('Você não possui permissão e responsabilidade para esta ação.')
        ->and(segregationState($measurement))->toBe($before);

    Governance::pay($s, $measurement, $rows);
    $measurement = Governance::readyToFinalize($s, $measurement);
    $before = segregationState($measurement);

    $finalization = segregationThrown(fn () => Governance::finalize($s, $measurement, accept: true, by: $user));

    expect($finalization)->toBeInstanceOf(AuthorizationException::class)
        ->and($finalization->getMessage())->toBe('Você não possui permissão e responsabilidade para esta ação.')
        ->and(segregationState($measurement))->toBe($before)
        ->and(Governance::finalize($s, $measurement)->status)->toBe('finalized');
})->with([
    'a notification-only recipient (rejection list)' => [function (array $s): User {
        $user = tap(User::factory()->withTwoFactor()->create(), fn (User $user) => $user->assignRole('editor'));
        $s['operation']->rejectionNotifyUsers()->attach($user->id);

        return $user;
    }],
    'an editor with every measurement permission who takes no part' => [
        fn (array $s): User => tap(User::factory()->withTwoFactor()->create(), fn (User $user) => $user->assignRole('editor')),
    ],
]);

it('keeps a delegate inside the delegated responsibility: the Finalizer delegate neither registers payments nor decides the Payment stage', function () {
    $s = Governance::plan();
    $delegate = tap(User::factory()->withTwoFactor()->create(), fn (User $user) => $user->assignRole('editor'));
    $delegation = segregationDelegate($s, MeasurementResponsibility::Finalizer, $delegate);
    $measurement = Governance::awaitingPayment($s, '2026-05', [10, 10]);
    $rows = [Governance::row($s, 0, '100000.00'), Governance::row($s, 1, '50000.00')];
    $before = segregationState($measurement);

    $registration = segregationThrown(fn () => Governance::pay($s, $measurement, $rows, $delegate));
    $decision = segregationThrown(fn () => Governance::approvePaymentStage($s, $measurement, by: $delegate));

    expect($registration)->toBeInstanceOf(AuthorizationException::class)
        ->and($decision)->toBeInstanceOf(AuthorizationException::class)
        ->and(segregationState($measurement))->toBe($before);

    // Dentro da responsabilidade delegada, o delegado finaliza.
    Governance::pay($s, $measurement, $rows);
    $measurement = Governance::finalize($s, Governance::readyToFinalize($s, $measurement), by: $delegate);
    $finalization = segregationActivities($measurement, 'measurement_finalized')->sole();

    expect($measurement->status)->toBe('finalized')
        ->and($finalization->properties['delegated'])->toBeTrue()
        ->and($finalization->properties['delegation_id'])->toBe($delegation->id)
        ->and((int) $finalization->properties['delegator_user_id'])->toBe($s['finalizer']->id);
});

// ── Autor não identificado só quando nenhuma fonte o identifica ───────────
// O delegante de um ato sem delegação não é autor desconhecido: a trilha
// chegou a registrar "delegated_*" sem autor em todo fluxo direto.

it('records no unidentified maker act when every act was done directly by an identified user', function () {
    $s = Governance::plan();
    ['measurement' => $measurement] = segregationExcessRegistered($s);
    $measurement = Governance::readyToFinalize($s, $measurement);
    $measurement = Governance::finalize($s, $measurement, accept: true);
    $evidence = segregationFirstEvidence($measurement);

    // Nenhum ato foi feito por delegação nem por autor desconhecido: a trilha
    // não tem ato de autor não identificado a registrar.
    expect(segregationEvidenceActivities($evidence, 'receipt_evidence_approved')->sole()->properties['segregation']['unidentified_maker_acts'])->toBe([])
        ->and(segregationActivities($measurement, 'measurement_finalized')->sole()->properties['segregation']['financial_exceptions']['unidentified_maker_acts'])->toBe([]);
});

it('records no unidentified maker act for a no-disbursement decision made directly', function () {
    $s = Governance::plan();
    $measurement = segregationDecidedWithoutDisbursement($s, $s['payer']);
    $measurement = Governance::finalize($s, $measurement, acknowledgeNoDisbursement: true);

    expect($measurement->status)->toBe('finalized')
        ->and(segregationActivities($measurement, 'measurement_finalized')->sole()->properties['segregation']['no_disbursement']['unidentified_maker_acts'])->toBe([]);
});
