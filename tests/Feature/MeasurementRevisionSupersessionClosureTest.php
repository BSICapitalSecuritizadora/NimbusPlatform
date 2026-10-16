<?php

use App\Enums\AccessPermission;
use App\Enums\MeasurementHistoryCompleteness;
use App\Enums\MeasurementReceiptReviewStatus;
use App\Enums\MeasurementResponsibility;
use App\Enums\MeasurementRevisionStatus;
use App\Enums\MeasurementStageExitReason;
use App\Exceptions\MeasurementWorkflowException;
use App\Filament\Pages\MeasurementCycleReport;
use App\Filament\Resources\Measurements\Pages\ListMeasurements;
use App\Filament\Resources\Measurements\Pages\ViewMeasurement;
use App\Filament\Resources\PaymentWorkspaces\Pages\ListPaymentWorkspace;
use App\Filament\Widgets\Dashboard\MeasurementCockpit;
use App\Models\Measurement;
use App\Models\MeasurementPause;
use App\Models\MeasurementPayment;
use App\Models\MeasurementReview;
use App\Models\SlaConfiguration;
use App\Models\User;
use App\Notifications\MeasurementWorkflowNotification;
use App\Services\MeasurementCockpitService;
use App\Services\MeasurementCycleHistoryReadModel;
use App\Services\MeasurementOperationalReadModel;
use App\Services\MeasurementPendingService;
use App\Services\MeasurementReceiptEvidenceService;
use App\Services\MeasurementRevisionService;
use App\Services\MeasurementSlaService;
use App\Services\MeasurementStageActivityService;
use App\Services\MeasurementTimeline;
use App\Services\MeasurementWorkflow;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\MeasurementPhysicalProgressScenario;
use Tests\Support\MeasurementReceiptEvidenceScenario as ReceiptScenario;
use Tests\Support\MeasurementRevisionScenario as Scenario;

/*
 * O encerramento do fluxo aberto da revisão substituída. Quando a revisão
 * passa a valer, a etapa que a anterior esperava -- a Pagamento sem pagamento
 * (caso B) ou a Finalização da medição paga com a documentação completa (caso
 * C) -- termina sem decisão: a análise fica "Encerrada pela revisão", nada
 * mais a move, nenhuma fila a pede, o prazo dela para de correr e o histórico
 * de ciclo a fecha na etapa em que estava, sem contar como tempo ativo o
 * intervalo em que a revisão em análise a suspendeu. A família fica com uma
 * obrigação aberta só: a da revisão que passou a valer.
 */

uses(RefreshDatabase::class);

const REVISION_CLOSURE_STALE_PAGE = 'A etapa desta medição foi alterada por outra ação. Atualize a página.';

const REVISION_CLOSURE_REVIEW_IMMUTABLE = 'A etapa desta medição foi encerrada pela revisão que passou a valer e não muda mais.';

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    Notification::fake();
    // Relógio parado numa segunda-feira, meio-dia em Brasília: sem avançá-lo,
    // o envio e a vigência da revisão caem no mesmo segundo -- intervalo
    // suspenso vazio, sem pausa. Quem precisa do intervalo avança o relógio.
    $this->travelTo(revisionClosureBusinessNoon('2026-10-05'));
});

/**
 * A vigente com o fluxo aberto de que a revisão parte, montada pelo fluxo
 * real: 'awaiting_payment' (caso B, etapa Pagamento sem pagamento) ou
 * 'paid_awaiting_finalization' (caso C, paga, com todos os comprovantes
 * aprovados, na Finalização).
 *
 * @param  array<string, mixed>  $scenario
 */
function revisionClosureSource(array $scenario, string $case): Measurement
{
    return match ($case) {
        'awaiting_payment' => Scenario::awaitingPayment($scenario, '2026-05', 10),
        'paid_awaiting_finalization' => Scenario::paidAwaitingFinalization($scenario, '2026-05', 10),
    };
}

/**
 * Meio-dia em Brasília do dia informado, no fuso da aplicação: as datas que o
 * domínio escreve (DD/MM/AAAA) saem do calendário de negócio, e as gravadas no
 * banco continuam em UTC.
 */
function revisionClosureBusinessNoon(string $date): CarbonImmutable
{
    return BusinessTime::toApplication(CarbonImmutable::parse("{$date} 12:00:00", BusinessTime::timezone()));
}

/**
 * O evento da trilha protegida gravado uma única vez na medição.
 */
function revisionClosureActivity(Measurement $subject, string $event): Activity
{
    return Activity::query()
        ->where('log_name', 'measurement_workflow')
        ->where('subject_type', $subject->getMorphClass())
        ->where('subject_id', $subject->getKey())
        ->where('description', $event)
        ->sole();
}

/**
 * Os eventos da trilha protegida da medição, na ordem em que foram gravados.
 *
 * @return list<string>
 */
function revisionClosureEvents(Measurement $measurement): array
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
 * Um objeto da auditoria com as chaves em ordem, nos dois lados da
 * comparação: o JSON do MySQL devolve as chaves de um objeto na ordem dele
 * (por tamanho, depois alfabética), não na gravada. As listas mantêm a ordem.
 *
 * @param  array<array-key, mixed>  $value
 * @return array<array-key, mixed>
 */
function revisionClosureKeySorted(array $value): array
{
    if (! array_is_list($value)) {
        ksort($value);
    }

    return array_map(fn (mixed $item): mixed => is_array($item) ? revisionClosureKeySorted($item) : $item, $value);
}

/**
 * A recusa do fluxo -- de estado ou de autorização. Qualquer outra falha (erro
 * de tipo, de banco) sobe e reprova o teste: recusar não é quebrar.
 */
function revisionClosureRefusal(callable $attempt): MeasurementWorkflowException|AuthorizationException
{
    try {
        $attempt();
    } catch (MeasurementWorkflowException|AuthorizationException $exception) {
        return $exception;
    }

    throw new RuntimeException('A ação no fluxo encerrado não foi recusada.');
}

/**
 * As análises da medição como a tela e a auditoria as leem, para comparar
 * antes e depois de uma tentativa recusada.
 *
 * @return list<array<string, mixed>>
 */
function revisionClosureReviews(Measurement $measurement): array
{
    return $measurement->reviews()
        ->orderBy('stage')
        ->get()
        ->map(fn (MeasurementReview $review): array => $review->only(['id', 'stage', 'status', 'reviewer_user_id', 'notes', 'paused_at']))
        ->all();
}

/**
 * Estado legado da Fase 3: a vigência da revisão encerrava o fluxo da
 * substituída na etapa Pagamento, mas deixava a análise dessa etapa
 * 'pending', sem revisor nem data. Gravado direto na linha -- a análise
 * encerrada não muda pelo model, e é assim que deve ser.
 */
function revisionClosureLegacyPendingReview(Measurement $source): void
{
    DB::table('measurement_reviews')
        ->where('measurement_id', $source->getKey())
        ->where('stage', MeasurementWorkflow::STAGE_PAYMENT)
        ->update(['status' => 'pending', 'reviewer_user_id' => null, 'reviewed_at' => null, 'notes' => null]);
}

/**
 * Os cartões de "Aprovação por Responsabilidade" da página: etapa => situação,
 * lidos do HTML renderizado.
 *
 * @return array<string, string>
 */
function revisionClosureResponsibilityCards(string $html): array
{
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8"?>'.$html);
    $xpath = new DOMXPath($document);
    $hasClass = fn (string $class): string => "contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')";
    $text = fn (?DOMNode $node): string => trim((string) preg_replace('/\s+/u', ' ', (string) $node?->textContent));
    $cards = [];

    foreach ($xpath->query("//div[{$hasClass('bsi-responsibilities-compact')}]/div/div") as $card) {
        $role = $xpath->query(".//span[{$hasClass('uppercase')}]", $card)->item(0);
        $status = $xpath->query(".//span[{$hasClass('rounded-full')} and {$hasClass('border')}]", $card)->item(0);

        $cards[$text($role)] = $text($status);
    }

    return $cards;
}

describe('encerramento no fluxo, nas filas e no histórico', function () {
    it('closes the Payment stage of the unpaid source without a decision when its revision becomes effective, recording the suspension as a pause', function () {
        // Segunda-feira (o relógio do beforeEach): a R0 chega à etapa Pagamento.
        $scenario = Scenario::plan();
        $actor = $scenario['actor'];
        $may = Scenario::awaitingPayment($scenario, '2026-05', 10);
        $paymentReview = $may->reviews()->where('stage', MeasurementWorkflow::STAGE_PAYMENT)->sole();
        $workflowRevision = (int) $may->workflow_revision;

        // Terça-feira: a revisão é enviada e suspende a etapa Pagamento.
        $this->travelTo(revisionClosureBusinessNoon('2026-10-06'));
        $revision = Scenario::submit($scenario, Scenario::revise($scenario, $may, 'Percentual de maio superestimado.'));

        // Quinta-feira: a Compliance aprova a revisão, que passa a valer.
        $this->travelTo(revisionClosureBusinessNoon('2026-10-08'));
        MeasurementPhysicalProgressScenario::approveEngineering($scenario, $revision, 8);
        Scenario::approveManagementAndCompliance($scenario, $revision);
        $may->refresh();
        $revision->refresh();
        $closedReview = $paymentReview->fresh();
        $pause = MeasurementPause::query()->where('measurement_id', $may->id)->sole();
        $closed = revisionClosureActivity($may, 'measurement_workflow_closed_by_revision');

        expect($revision->revisionStatus())->toBe(MeasurementRevisionStatus::Effective)
            ->and($revision->status)->toBe('awaiting_payment')
            ->and($may->revisionStatus())->toBe(MeasurementRevisionStatus::Superseded)
            ->and($may->status)->toBe('superseded')
            ->and($may->current_stage)->toBe(MeasurementWorkflow::STAGE_PAYMENT)
            ->and($may->analyzed_by)->toBe($actor->id)
            ->and($may->analyzed_at->equalTo(now()))->toBeTrue()
            ->and($may->workflow_revision)->toBe($workflowRevision + 1)
            // A análise da etapa Pagamento fica encerrada sem decisão: nem
            // aprovada, nem recusada, sem revisor, com o motivo e a data.
            ->and($closedReview->status)->toBe(MeasurementReview::STATUS_SUPERSEDED)
            ->and($closedReview->isSupersededByRevision())->toBeTrue()
            ->and($closedReview->reviewer_user_id)->toBeNull()
            ->and($closedReview->reviewed_at->equalTo(now()))->toBeTrue()
            ->and($closedReview->notes)->toBe('Encerrada pela revisão R1, que passou a valer na aprovação da Compliance em 08/10/2026: a etapa não foi decidida nesta medição.')
            ->and($closedReview->only(['paused_at', 'paused_by', 'pause_reason', 'paused_operation_status']))->toBe([
                'paused_at' => null,
                'paused_by' => null,
                'pause_reason' => null,
                'paused_operation_status' => null,
            ])
            ->and(MeasurementReview::STATUS_OPTIONS[$closedReview->status])->toBe('Encerrada pela revisão')
            // As etapas decididas antes continuam como estavam, e a Finalização
            // nunca foi aberta.
            ->and($may->reviews()->orderBy('stage')->pluck('status', 'stage')->all())->toBe([
                1 => 'approved',
                2 => 'approved',
                3 => 'approved',
                4 => MeasurementReview::STATUS_SUPERSEDED,
            ])
            ->and(Activity::query()
                ->where('log_name', 'measurement_workflow')
                ->where('subject_id', $may->id)
                ->where('description', 'measurement_stage_approved')
                ->get()
                ->map(fn (Activity $activity): int => (int) $activity->properties['stage'])
                ->all())->toBe([1, 2, 3])
            // De terça a quinta a etapa Pagamento ficou suspensa pela revisão:
            // o intervalo vira pausa encerrada, que o prazo não conta.
            ->and($pause->stage)->toBe(MeasurementWorkflow::STAGE_PAYMENT)
            ->and($pause->paused_operation_status)->toBe('awaiting_payment')
            ->and($pause->paused_at->equalTo($revision->uploaded_at))->toBeTrue()
            ->and($pause->resumed_at->equalTo(now()))->toBeTrue()
            // Fase 4B-1: quem envia a revisão do cenário -- e suspende o fluxo
            // -- é o Finalizador; quem a faz valer é a Compliance do ator.
            ->and($pause->paused_by)->toBe($scenario['finalizer']->id)
            ->and($pause->resumed_by)->toBe($actor->id)
            ->and($pause->pause_reason)->toBe('Fluxo suspenso pela revisão R1, em análise de 06/10/2026 a 08/10/2026; a revisão passou a valer.')
            // O encerramento na trilha protegida, com a autoridade da
            // Compliance que fez a revisão valer.
            ->and((int) $closed->causer_id)->toBe($actor->id)
            ->and($closed->properties['stage'])->toBe(MeasurementWorkflow::STAGE_PAYMENT)
            ->and($closed->properties['target_stage'])->toBeNull()
            ->and($closed->properties['from_status'])->toBe('awaiting_payment')
            ->and($closed->properties['to_status'])->toBe('superseded')
            ->and($closed->properties['responsibility'])->toBe('stage3_reviewer_user_id')
            ->and($closed->properties['expected_responsible_user_id'])->toBe($actor->id)
            ->and($closed->properties['superseding_measurement_id'])->toBe($revision->id)
            ->and($closed->properties['superseding_revision_number'])->toBe(1)
            ->and(revisionClosureKeySorted($closed->properties['closed_reviews']))
            ->toBe(revisionClosureKeySorted([['review_id' => $paymentReview->id, 'stage' => MeasurementWorkflow::STAGE_PAYMENT]]))
            ->and(revisionClosureKeySorted($closed->properties['historical_payments']))
            ->toBe(revisionClosureKeySorted(['count' => 0, 'total_amount' => '0.00', 'payment_ids' => []]))
            ->and($closed->properties['workflow_revision'])->toBe($workflowRevision + 1)
            ->and($closed->properties['admin_override'])->toBeFalse()
            ->and($closed->properties['delegated'])->toBeFalse()
            ->and(revisionClosureActivity($may, 'measurement_revision_superseded')->properties['workflow_closed'])->toBeTrue()
            ->and(revisionClosureActivity($revision, 'measurement_revision_became_effective')->properties['closes_previous_workflow'])->toBeTrue();
    });

    it('refuses every action of the closed stage, also from a page opened before the revision became effective, and never decides it', function (string $case, int $closedStage) {
        $scenario = Scenario::plan();
        $actor = $scenario['actor'];
        $may = revisionClosureSource($scenario, $case);
        // O que a página aberta antes da vigência mandaria: a versão e a
        // situação do fluxo que ela viu.
        $seenRevision = (int) $may->workflow_revision;
        $seenStatus = $may->status;
        Scenario::effective($scenario, $may, 12);
        $may->refresh();
        $workflow = app(MeasurementWorkflow::class);
        $workflowRevision = (int) $may->workflow_revision;
        $reviews = revisionClosureReviews($may);
        $events = revisionClosureEvents($may);
        $payments = MeasurementPayment::query()->where('measurement_id', $may->id)->orderBy('id')->pluck('id')->all();
        $pauses = MeasurementPause::query()->where('measurement_id', $may->id)->orderBy('id')->pluck('id')->all();
        $payment = [
            'plan_set_id' => $scenario['planSet']->id,
            'pay_date' => '2026-07-20',
            'amount' => Scenario::expected(10),
            'method' => 'TED',
        ];

        $fromThePageOpenedBefore = match ($case) {
            'awaiting_payment' => [
                fn () => $workflow->approve($may->fresh(), $actor, 'Pagamento conferido.', expectedStage: MeasurementWorkflow::STAGE_PAYMENT, expectedRevision: $seenRevision),
                fn () => $workflow->reject($may->fresh(), $actor, 'Valor divergente.', expectedStage: MeasurementWorkflow::STAGE_PAYMENT, expectedRevision: $seenRevision),
                fn () => $workflow->pause($may->fresh(), $actor, 'Aguardar o banco.', expectedStage: MeasurementWorkflow::STAGE_PAYMENT, expectedRevision: $seenRevision),
                fn () => $workflow->registerPayment($may->fresh(), $actor, $payment, $seenRevision),
            ],
            'paid_awaiting_finalization' => [
                fn () => $workflow->finalize($may->fresh(), $actor, expectedRevision: $seenRevision, expectedStatus: $seenStatus, acceptFinancialExceptions: true),
                fn () => $workflow->returnToStage($may->fresh(), $actor, MeasurementWorkflow::STAGE_PAYMENT, 'Rever o pagamento.', expectedRevision: $seenRevision, expectedStatus: $seenStatus),
            ],
        };
        $withTheCurrentState = match ($case) {
            'awaiting_payment' => [
                fn () => $workflow->approve($may->fresh(), $actor, 'Pagamento conferido.'),
                fn () => $workflow->reject($may->fresh(), $actor, 'Valor divergente.'),
                fn () => $workflow->pause($may->fresh(), $actor, 'Aguardar o banco.'),
                fn () => $workflow->registerPayment($may->fresh(), $actor, $payment),
            ],
            'paid_awaiting_finalization' => [
                fn () => $workflow->finalize($may->fresh(), $actor, acceptFinancialExceptions: true),
                fn () => $workflow->returnToStage($may->fresh(), $actor, MeasurementWorkflow::STAGE_PAYMENT, 'Rever o pagamento.'),
                fn () => $workflow->approve($may->fresh(), $actor),
            ],
        };

        foreach ($fromThePageOpenedBefore as $attempt) {
            $refusal = revisionClosureRefusal($attempt);

            expect($refusal)->toBeInstanceOf(MeasurementWorkflowException::class)
                ->and($refusal->getMessage())->toBe(REVISION_CLOSURE_STALE_PAGE);
        }

        // Chamada nova, com a situação de agora: recusada também -- pela
        // situação ou pela autorização, porque a etapa encerrada não é de
        // ninguém. Qualquer outra saída (sucesso, erro de tipo, de banco)
        // reprova o teste dentro do próprio revisionClosureRefusal().
        foreach ($withTheCurrentState as $attempt) {
            expect(revisionClosureRefusal($attempt))->toBeInstanceOf(Throwable::class);
        }

        // A análise encerrada também não muda pelo model -- nem pela gravação
        // que a aprovação e a reabertura usam, nem por uma pausa.
        $closedReview = $may->reviews()->where('stage', $closedStage)->sole();

        expect(fn () => $may->reviews()->updateOrCreate(
            ['stage' => $closedStage],
            ['status' => 'approved', 'reviewer_user_id' => $actor->id, 'reviewed_at' => now()],
        ))->toThrow(MeasurementWorkflowException::class, REVISION_CLOSURE_REVIEW_IMMUTABLE)
            ->and(fn () => $closedReview->fresh()->forceFill([
                'paused_at' => now(),
                'paused_by' => $actor->id,
                'pause_reason' => 'Retomar depois.',
                'paused_operation_status' => $seenStatus,
            ])->save())->toThrow(MeasurementWorkflowException::class, REVISION_CLOSURE_REVIEW_IMMUTABLE)
            ->and(fn () => $closedReview->fresh()->forceFill(['notes' => 'Aprovada depois do encerramento.'])->save())
            ->toThrow(MeasurementWorkflowException::class, REVISION_CLOSURE_REVIEW_IMMUTABLE);

        // Nada foi gravado: nem decisão, nem pagamento, nem evento.
        $may->refresh();

        expect($may->status)->toBe('superseded')
            ->and($may->current_stage)->toBe($closedStage)
            ->and($may->workflow_revision)->toBe($workflowRevision)
            ->and(revisionClosureReviews($may))->toBe($reviews)
            ->and($closedReview->fresh()->status)->toBe(MeasurementReview::STATUS_SUPERSEDED)
            ->and(revisionClosureEvents($may))->toBe($events)
            ->and(MeasurementPayment::query()->where('measurement_id', $may->id)->orderBy('id')->pluck('id')->all())->toBe($payments)
            ->and(MeasurementPause::query()->where('measurement_id', $may->id)->orderBy('id')->pluck('id')->all())->toBe($pauses);
    })->with([
        'unpaid source at the Payment stage (case B)' => ['awaiting_payment', MeasurementWorkflow::STAGE_PAYMENT],
        'paid source awaiting the Finalization (case C)' => ['paid_awaiting_finalization', MeasurementWorkflow::STAGE_FINALIZATION],
    ]);

    it('takes the closed measurement out of every work queue and leaves its family a single open obligation, on the revision', function (string $case) {
        $scenario = Scenario::plan();
        $actor = $scenario['actor'];
        $may = revisionClosureSource($scenario, $case === 'legacy' ? 'awaiting_payment' : $case);
        $revision = Scenario::effective($scenario, $may, 12);

        if ($case === 'legacy') {
            revisionClosureLegacyPendingReview($may);
        }

        $readModel = app(MeasurementOperationalReadModel::class);
        $workflow = app(MeasurementWorkflow::class);
        // Como as listas leem: com as análises, as pausas, os pagamentos e a
        // revisão em análise carregados.
        $may = $readModel->queryFor($actor)->findOrFail($may->id);
        $revision = $readModel->queryFor($actor)->findOrFail($revision->id);

        expect($may->status)->toBe('superseded')
            ->and($may->reviews->firstWhere('stage', MeasurementWorkflow::STAGE_PAYMENT)?->status)
            ->toBe($case === 'legacy' ? 'pending' : ($case === 'awaiting_payment' ? MeasurementReview::STATUS_SUPERSEDED : 'approved'))
            ->and($workflow->canApprove($may, $actor))->toBeFalse()
            ->and($workflow->canReject($may, $actor))->toBeFalse()
            ->and($workflow->canPause($may, $actor))->toBeFalse()
            ->and($workflow->canResume($may, $actor))->toBeFalse()
            ->and($workflow->canRegisterPayment($may, $actor))->toBeFalse()
            ->and($workflow->canManageReceipts($may, $actor))->toBeFalse()
            ->and($workflow->canFinalize($may, $actor))->toBeFalse()
            ->and($workflow->canReturnFromFinalization($may, $actor))->toBeFalse()
            ->and($workflow->pendingResponsibilities($may))->toBe([])
            ->and($readModel->pendingLabel($may))->toBe('Encerrada pela revisão')
            ->and(app(MeasurementSlaService::class)->evaluate($may)['status'])->toBe(MeasurementSlaService::STATUS_COMPLETED)
            // A obrigação aberta da família é uma só: a etapa Pagamento da
            // revisão que passou a valer.
            ->and($workflow->pendingResponsibilities($revision))->toBe([MeasurementResponsibility::PaymentManager])
            ->and($workflow->canRegisterPayment($revision, $actor))->toBeTrue()
            ->and(Measurement::query()->withPendingWork()->whereKey([$may->id, $revision->id])->pluck('id')->all())->toBe([$revision->id])
            ->and(collect(app(MeasurementPendingService::class)->summaryFor($actor, previewLimit: 10)['items'])->pluck('measurement_id')->all())
            ->toBe([$revision->id]);

        // O cockpit conta só a revisão corrente, na etapa Pagamento.
        $cockpit = app(MeasurementCockpitService::class)->summaryFor($actor);

        expect($cockpit['total'])->toBe(1)
            ->and($cockpit['stages'])->toBe([1 => 0, 2 => 0, 3 => 0, 4 => 1, 5 => 0])
            ->and($cockpit['ready_to_finalize'])->toBe(0)
            ->and($cockpit['awaiting_receipt'])->toBe(0)
            ->and($cockpit['finalized'])->toBe(0)
            ->and($cockpit['paused'])->toBe(0);
    })->with([
        'unpaid source at the Payment stage (case B)' => ['awaiting_payment'],
        'paid source awaiting the Finalization (case C)' => ['paid_awaiting_finalization'],
        'unpaid source replaced before Phase 3.1, its Payment review left pending' => ['legacy'],
    ]);

    it('counts the paid source as ready to finalize only until its revision is submitted, and stops counting it once the revision is effective', function () {
        $scenario = Scenario::plan();
        $actor = $scenario['actor'];
        $may = Scenario::paidAwaitingFinalization($scenario, '2026-05', 10);
        $draft = Scenario::revise($scenario, $may);

        // O rascunho não suspende nada: a R0 continua pronta para finalizar.
        expect(app(MeasurementCockpitService::class)->summaryFor($actor)['ready_to_finalize'])->toBe(1);

        $revision = Scenario::submit($scenario, $draft);
        $suspended = app(MeasurementCockpitService::class)->summaryFor($actor);

        // Suspensa pela revisão em análise: na Finalização, mas não pronta para
        // nada.
        expect($may->fresh()->isFrozenByRevision())->toBeTrue()
            ->and($suspended['ready_to_finalize'])->toBe(0)
            ->and($suspended['total'])->toBe(2)
            ->and($suspended['stages'][MeasurementWorkflow::STAGE_FINALIZATION])->toBe(1)
            ->and($suspended['stages'][MeasurementWorkflow::STAGE_ENGINEERING])->toBe(1);

        MeasurementPhysicalProgressScenario::approveEngineering($scenario, $revision, 12);
        Scenario::approveManagementAndCompliance($scenario, $revision);
        $closed = app(MeasurementCockpitService::class)->summaryFor($actor);

        // Substituída: fora das revisões correntes. O pagamento dela continua
        // nos totais, onde foi registrado.
        expect($closed['total'])->toBe(1)
            ->and($closed['stages'])->toBe([1 => 0, 2 => 0, 3 => 0, 4 => 1, 5 => 0])
            ->and($closed['ready_to_finalize'])->toBe(0)
            ->and($closed['awaiting_receipt'])->toBe(0)
            ->and($closed['payment_count'])->toBe(1)
            ->and($closed['pending_receipt_count'])->toBe(0)
            ->and($closed['recorded_amount'])->toBe(100000.0);
    });

    it('keeps a single deadline for the logical measurement once the revision is effective: the revision Payment stage, never the closed stage', function (string $case) {
        config(['measurements.sla.calendar_code' => null]);
        SlaConfiguration::factory()->forStage(MeasurementWorkflow::STAGE_PAYMENT, 2)->create();
        SlaConfiguration::factory()->forStage(MeasurementWorkflow::STAGE_FINALIZATION, 2)->create();

        // Segunda-feira (o relógio do beforeEach): a etapa aberta da R0 começa.
        $scenario = Scenario::plan();
        $actor = $scenario['actor'];
        $may = revisionClosureSource($scenario, $case);

        // Terça-feira a revisão é enviada; quarta-feira passa a valer.
        $this->travelTo(revisionClosureBusinessNoon('2026-10-06'));
        $revision = Scenario::submit($scenario, Scenario::revise($scenario, $may));
        $this->travelTo(revisionClosureBusinessNoon('2026-10-07'));
        MeasurementPhysicalProgressScenario::approveEngineering($scenario, $revision, 12);
        Scenario::approveManagementAndCompliance($scenario, $revision);
        $effectiveAt = CarbonImmutable::instance(now());

        // Segunda-feira seguinte: três dias úteis depois da vigência, os dois
        // prazos de dois dias já teriam vencido.
        $this->travelTo(revisionClosureBusinessNoon('2026-10-12'));
        $revisionSla = app(MeasurementSlaService::class)->evaluate($revision->fresh(), CarbonImmutable::instance(now()));

        expect(app(MeasurementSlaService::class)->evaluate($may->fresh(), CarbonImmutable::instance(now()))['status'])->toBe(MeasurementSlaService::STATUS_COMPLETED)
            ->and($revisionSla['status'])->toBe(MeasurementSlaService::STATUS_OVERDUE)
            ->and($revisionSla['started_at']->equalTo($effectiveAt))->toBeTrue()
            ->and(app(MeasurementCockpitService::class)->summaryFor($actor)['overdue'])->toBe(1);

        $this->artisan('measurements:evaluate-sla')->assertSuccessful();

        $alerts = DB::table('measurement_sla_alerts')
            ->whereIn('measurement_id', [$may->id, $revision->id])
            ->orderBy('id')
            ->get()
            ->map(fn (object $alert): array => [
                'measurement_id' => (int) $alert->measurement_id,
                'stage' => (int) $alert->stage,
                'alert_type' => (string) $alert->alert_type,
                'recipient_user_id' => (int) $alert->recipient_user_id,
            ])
            ->all();

        expect($alerts)->toBe([[
            'measurement_id' => $revision->id,
            'stage' => MeasurementWorkflow::STAGE_PAYMENT,
            'alert_type' => 'overdue',
            'recipient_user_id' => $actor->id,
        ]]);
    })->with([
        'unpaid source at the Payment stage (case B)' => ['awaiting_payment'],
        'paid source awaiting the Finalization (case C)' => ['paid_awaiting_finalization'],
    ]);

    it('ends the cycle of the replaced measurement at the stage it was waiting on, without counting the suspension as active time', function (string $case, int $closedStage, array $visitedStages) {
        // Segunda-feira (o relógio do beforeEach): a R0 chega à etapa que fica
        // aberta.
        $scenario = Scenario::plan();
        $scenario['actor']->givePermissionTo(AccessPermission::MeasurementsCycleReportsView->value);
        $may = revisionClosureSource($scenario, $case);
        $enteredAt = CarbonImmutable::instance(now());

        // Terça-feira: a revisão é enviada e suspende a etapa.
        $this->travelTo(revisionClosureBusinessNoon('2026-10-06'));
        $revision = Scenario::submit($scenario, Scenario::revise($scenario, $may));
        $suspendedAt = CarbonImmutable::instance(now());

        // Quinta-feira: a revisão passa a valer.
        $this->travelTo(revisionClosureBusinessNoon('2026-10-08'));
        MeasurementPhysicalProgressScenario::approveEngineering($scenario, $revision, 12);
        Scenario::approveManagementAndCompliance($scenario, $revision);
        $closedAt = CarbonImmutable::instance(now());

        $history = app(MeasurementCycleHistoryReadModel::class)->for($scenario['actor'], $may->fresh());
        $visits = collect($history->stageVisits);
        $closure = $visits->last();

        expect($history->completeness)->toBe(MeasurementHistoryCompleteness::Complete)
            ->and($history->warnings)->toBe([])
            ->and($history->currentStatus)->toBe('superseded')
            ->and($history->terminalReason)->toBe(MeasurementStageExitReason::ClosedByRevision)
            ->and($history->cycleEnd->equalTo($closedAt))->toBeTrue()
            ->and($visits->map(fn ($visit): int => $visit->stage)->all())->toBe($visitedStages)
            ->and($visits->filter(fn ($visit): bool => $visit->exitReason === MeasurementStageExitReason::Open)->all())->toBe([])
            ->and($closure->stage)->toBe($closedStage)
            ->and($closure->exitReason)->toBe(MeasurementStageExitReason::ClosedByRevision)
            ->and($closure->exitActorId)->toBe($scenario['actor']->id)
            ->and($closure->responsibility)->toBe(MeasurementResponsibility::ComplianceReviewer)
            ->and($closure->completeness)->toBe(MeasurementHistoryCompleteness::Complete)
            ->and($closure->enteredAt->equalTo($enteredAt))->toBeTrue()
            ->and($closure->exitedAt->equalTo($closedAt))->toBeTrue()
            // Três dias na etapa, dois deles suspensa pela revisão: só um é
            // tempo ativo.
            ->and($closure->calendarDuration)->toBe(3 * 86400)
            ->and($closure->pausedDuration)->toBe(2 * 86400)
            ->and($closure->activeDuration)->toBe(86400)
            ->and($history->pauses)->toHaveCount(1)
            ->and($history->pauses[0]->stage)->toBe($closedStage)
            ->and($history->pauses[0]->pausedAt->equalTo($suspendedAt))->toBeTrue()
            ->and($history->pauses[0]->resumedAt->equalTo($closedAt))->toBeTrue();
    })->with([
        'unpaid source at the Payment stage (case B)' => ['awaiting_payment', MeasurementWorkflow::STAGE_PAYMENT, [1, 2, 3, 4]],
        'paid source awaiting the Finalization (case C)' => ['paid_awaiting_finalization', MeasurementWorkflow::STAGE_FINALIZATION, [1, 2, 3, 4, 5]],
    ]);

    it('records no suspension and counts the whole stay as active when the revision is submitted and becomes effective in the same second', function (string $case, int $closedStage) {
        // Segunda-feira (o relógio do beforeEach): a R0 chega à etapa que fica
        // aberta.
        $scenario = Scenario::plan();
        $scenario['actor']->givePermissionTo(AccessPermission::MeasurementsCycleReportsView->value);
        $may = revisionClosureSource($scenario, $case);

        // Um dia depois, envio e vigência no mesmo segundo: não houve intervalo
        // suspenso.
        $this->travelTo(revisionClosureBusinessNoon('2026-10-06'));
        $revision = Scenario::effective($scenario, $may, 12);
        $history = app(MeasurementCycleHistoryReadModel::class)->for($scenario['actor'], $may->fresh());
        $closure = collect($history->stageVisits)->last();

        expect($revision->uploaded_at->equalTo(now()))->toBeTrue()
            ->and($may->fresh()->status)->toBe('superseded')
            ->and(MeasurementPause::query()->where('measurement_id', $may->id)->exists())->toBeFalse()
            ->and($history->completeness)->toBe(MeasurementHistoryCompleteness::Complete)
            ->and($history->pauses)->toBe([])
            ->and($closure->stage)->toBe($closedStage)
            ->and($closure->exitReason)->toBe(MeasurementStageExitReason::ClosedByRevision)
            ->and($closure->calendarDuration)->toBe(86400)
            ->and($closure->pausedDuration)->toBe(0)
            ->and($closure->activeDuration)->toBe(86400);
    })->with([
        'unpaid source at the Payment stage (case B)' => ['awaiting_payment', MeasurementWorkflow::STAGE_PAYMENT],
        'paid source awaiting the Finalization (case C)' => ['paid_awaiting_finalization', MeasurementWorkflow::STAGE_FINALIZATION],
    ]);

    it('excludes from the active time of the closed stage every suspension, also the one of an earlier revision rejected by Engineering', function (string $case, int $closedStage) {
        $scenario = Scenario::plan();
        $scenario['actor']->givePermissionTo(AccessPermission::MeasurementsCycleReportsView->value);

        // Segunda-feira: a R0 chega à etapa que fica aberta.
        $may = revisionClosureSource($scenario, $case);
        $enteredAt = CarbonImmutable::instance(now());

        // Terça-feira a R1 é enviada; quarta-feira a Engenharia a recusa, e a
        // etapa da R0 volta a correr.
        $this->travelTo(revisionClosureBusinessNoon('2026-10-06'));
        $rejected = Scenario::submit($scenario, Scenario::revise($scenario, $may));
        $this->travelTo(revisionClosureBusinessNoon('2026-10-07'));
        app(MeasurementWorkflow::class)->reject($rejected->fresh(), $scenario['actor'], 'A correção proposta também está errada.');

        // Quinta-feira a R2 é enviada; na segunda-feira seguinte passa a valer.
        $this->travelTo(revisionClosureBusinessNoon('2026-10-08'));
        $revision = Scenario::submit($scenario, Scenario::revise($scenario, $may, 'Percentual de maio subestimado.'));
        $this->travelTo(revisionClosureBusinessNoon('2026-10-12'));
        MeasurementPhysicalProgressScenario::approveEngineering($scenario, $revision, 12);
        Scenario::approveManagementAndCompliance($scenario, $revision);
        $closedAt = CarbonImmutable::instance(now());
        $may->refresh();

        $history = app(MeasurementCycleHistoryReadModel::class)->for($scenario['actor'], $may);
        $closure = collect($history->stageVisits)->last();
        $pauses = MeasurementPause::query()->where('measurement_id', $may->id)->orderBy('paused_at')->get();

        expect($rejected->fresh()->revisionStatus())->toBe(MeasurementRevisionStatus::Rejected)
            ->and($revision->fresh()->revisionNumber())->toBe(2)
            ->and($may->status)->toBe('superseded')
            ->and($may->reviews()->where('stage', $closedStage)->value('notes'))
            ->toBe('Encerrada pela revisão R2, que passou a valer na aprovação da Compliance em 12/10/2026: a etapa não foi decidida nesta medição.')
            ->and(revisionClosureActivity($may, 'measurement_workflow_closed_by_revision')->properties['superseding_revision_number'])->toBe(2)
            ->and(app(MeasurementStageActivityService::class)->for($may->fresh())->last()['status_label'])->toBe('Encerrada pela revisão R2')
            // Duas suspensões na mesma etapa: a da revisão recusada e a da que
            // passou a valer.
            ->and($pauses->map(fn (MeasurementPause $pause): array => [$pause->stage, $pause->pause_reason])->all())->toBe([
                [$closedStage, 'Fluxo suspenso pela revisão R1, em análise de 06/10/2026 a 07/10/2026; a revisão foi recusada.'],
                [$closedStage, 'Fluxo suspenso pela revisão R2, em análise de 08/10/2026 a 12/10/2026; a revisão passou a valer.'],
            ])
            ->and($history->completeness)->toBe(MeasurementHistoryCompleteness::Complete)
            ->and($history->warnings)->toBe([])
            ->and($history->pauses)->toHaveCount(2)
            ->and($closure->stage)->toBe($closedStage)
            ->and($closure->exitReason)->toBe(MeasurementStageExitReason::ClosedByRevision)
            ->and($closure->enteredAt->equalTo($enteredAt))->toBeTrue()
            ->and($closure->exitedAt->equalTo($closedAt))->toBeTrue()
            // Uma semana na etapa, cinco dias dela suspensa -- um pela R1,
            // quatro pela R2 --: dois dias de tempo ativo.
            ->and($closure->calendarDuration)->toBe(7 * 86400)
            ->and($closure->pausedDuration)->toBe(5 * 86400)
            ->and($closure->activeDuration)->toBe(2 * 86400);
    })->with([
        'unpaid source at the Payment stage (case B)' => ['awaiting_payment', MeasurementWorkflow::STAGE_PAYMENT],
        'paid source awaiting the Finalization (case C)' => ['paid_awaiting_finalization', MeasurementWorkflow::STAGE_FINALIZATION],
    ]);

    it('closes the Payment stage of an intermediate revision replaced by the next one and leaves the finalized original untouched', function () {
        $scenario = Scenario::plan();
        $actor = $scenario['actor'];
        $may = Scenario::finalized($scenario, '2026-05', 10);
        $first = Scenario::effective($scenario, $may, 12);
        $paymentReview = $first->reviews()->where('stage', MeasurementWorkflow::STAGE_PAYMENT)->sole();

        // A R1 vigente, na etapa Pagamento sem pagamento próprio (a família
        // pagou na R0), é revisada de novo antes de pagar o complemento.
        $second = Scenario::effective($scenario, $first, 11);
        $first->refresh();
        $closed = revisionClosureActivity($first, 'measurement_workflow_closed_by_revision');
        $readModel = app(MeasurementOperationalReadModel::class);

        expect($second->revisionStatus())->toBe(MeasurementRevisionStatus::Effective)
            ->and($second->revisionNumber())->toBe(2)
            ->and($first->revisionStatus())->toBe(MeasurementRevisionStatus::Superseded)
            ->and($first->status)->toBe('superseded')
            ->and($first->current_stage)->toBe(MeasurementWorkflow::STAGE_PAYMENT)
            ->and($first->payments()->exists())->toBeFalse()
            ->and($paymentReview->fresh()->status)->toBe(MeasurementReview::STATUS_SUPERSEDED)
            ->and($paymentReview->fresh()->reviewer_user_id)->toBeNull()
            ->and($paymentReview->fresh()->notes)->toBe('Encerrada pela revisão R2, que passou a valer na aprovação da Compliance em 05/10/2026: a etapa não foi decidida nesta medição.')
            ->and($closed->properties['stage'])->toBe(MeasurementWorkflow::STAGE_PAYMENT)
            ->and($closed->properties['from_status'])->toBe('awaiting_payment')
            ->and($closed->properties['superseding_measurement_id'])->toBe($second->id)
            ->and($closed->properties['superseding_revision_number'])->toBe(2)
            ->and(revisionClosureKeySorted($closed->properties['closed_reviews']))
            ->toBe(revisionClosureKeySorted([['review_id' => $paymentReview->id, 'stage' => MeasurementWorkflow::STAGE_PAYMENT]]))
            // O pagamento da família é da R0: a R1 encerrada não tem pagamento
            // próprio a preservar.
            ->and(revisionClosureKeySorted($closed->properties['historical_payments']))
            ->toBe(revisionClosureKeySorted(['count' => 0, 'total_amount' => '0.00', 'payment_ids' => []]))
            ->and(app(MeasurementStageActivityService::class)->for($first->fresh())->last()['status_label'])->toBe('Encerrada pela revisão R2')
            ->and($readModel->pendingLabel($readModel->queryFor($actor)->findOrFail($first->id)))->toBe('Encerrada pela revisão')
            ->and(Measurement::query()->withPendingWork()->whereKey([$may->id, $first->id, $second->id])->pluck('id')->all())->toBe([$second->id])
            // A R0 finalizada não tinha fluxo a encerrar: nem a vigência da R1
            // nem a da R2 mexem nas análises dela.
            ->and($may->fresh()->status)->toBe('finalized')
            ->and($may->fresh()->revisionStatus())->toBe(MeasurementRevisionStatus::Superseded)
            ->and(revisionClosureEvents($may))->not->toContain('measurement_workflow_closed_by_revision')
            ->and($may->reviews()->orderBy('stage')->pluck('status')->all())->toBe(['approved', 'approved', 'approved', 'approved', 'approved'])
            ->and(Notification::sent(
                $actor,
                MeasurementWorkflowNotification::class,
                fn (MeasurementWorkflowNotification $notification): bool => in_array($notification->event, ['workflow_closed_by_revision', 'paid_workflow_closed_by_revision'], true),
            )->map(fn (MeasurementWorkflowNotification $notification): array => [$notification->event, (int) $notification->measurement->getKey()])->values()->all())
            ->toBe([['workflow_closed_by_revision', $first->id]]);
    });

    it('shows the stage the replaced measurement was waiting on as closed by the revision in its stage activity', function (string $case, array $decisions, string $closureNote) {
        $scenario = Scenario::plan();
        $may = revisionClosureSource($scenario, $case);
        Scenario::effective($scenario, $may, 12);
        $activity = app(MeasurementStageActivityService::class)->for($may->fresh());
        $closure = $activity->last();

        // Nada aparece como em análise ou suspenso: a última linha é o
        // encerramento, na etapa em que a medição estava.
        expect($activity->map(fn (array $item): array => [$item['stage_label'], $item['status_label']])->all())->toBe($decisions)
            ->and($closure['status_key'])->toBe('returned')
            ->and($closure['reviewer_name'])->toBe($scenario['actor']->name)
            ->and($closure['note_label'])->toBe('Encerramento')
            ->and($closure['notes'])->toBe($closureNote)
            ->and($closure['reviewed_at']->equalTo(now()))->toBeTrue();
    })->with([
        'unpaid source at the Payment stage (case B)' => [
            'awaiting_payment',
            [
                ['Engenharia', 'Aprovada'],
                ['Gestão', 'Aprovada'],
                ['Compliance', 'Aprovada'],
                ['Pagamento', 'Encerrada pela revisão R1'],
            ],
            'A revisão R1 foi aprovada pela Compliance e passou a valer no lugar desta medição. O pagamento é registrado na revisão vigente.',
        ],
        'paid source awaiting the Finalization (case C)' => [
            'paid_awaiting_finalization',
            [
                ['Engenharia', 'Aprovada'],
                ['Gestão', 'Aprovada'],
                ['Compliance', 'Aprovada'],
                ['Pagamento', 'Aprovada'],
                ['Finalização', 'Encerrada pela revisão R1'],
            ],
            'A revisão R1 foi aprovada pela Compliance e passou a valer no lugar desta medição antes da Finalização. Os pagamentos registrados nesta medição continuam como histórico; a etapa Pagamento segue na revisão vigente.',
        ],
    ]);

    it('tells whoever cared for the closed stage once, even holding more than one of its roles, what happens to the payment', function (string $case, string $event, string $title, string $description) {
        $scenario = Scenario::plan();
        $actor = $scenario['actor'];
        $may = revisionClosureSource($scenario, $case);
        Scenario::effective($scenario, $may, 12);

        // O ator do cenário ocupa os sete papéis -- no caso C, o Finalizador e
        // quem envia os comprovantes são a mesma pessoa.
        $closures = Notification::sent(
            $actor,
            MeasurementWorkflowNotification::class,
            fn (MeasurementWorkflowNotification $notification): bool => in_array($notification->event, ['workflow_closed_by_revision', 'paid_workflow_closed_by_revision'], true),
        );

        expect($closures)->toHaveCount(1);

        $notice = $closures->first();
        $mail = $notice->toMail($actor);

        expect($notice->event)->toBe($event)
            ->and((int) $notice->measurement->getKey())->toBe($may->id)
            ->and($mail->subject)->toBe("[NimbusOps] {$title} — {$scenario['operation']->title}")
            ->and($mail->viewData['title'])->toBe($title)
            ->and($mail->viewData['description'])->toBe($description);
    })->with([
        'unpaid source at the Payment stage (case B)' => [
            'awaiting_payment',
            'workflow_closed_by_revision',
            'Medição substituída por revisão',
            'A medição que aguardava pagamento foi substituída por uma revisão aprovada. O pagamento deve ser registrado na revisão que passou a valer.',
        ],
        'paid source awaiting the Finalization (case C)' => [
            'paid_awaiting_finalization',
            'paid_workflow_closed_by_revision',
            'Medição paga substituída por revisão',
            'A medição paga que aguardava a Finalização foi substituída por uma revisão aprovada. Os pagamentos registrados nela continuam como histórico; a etapa Pagamento e a Finalização seguem na revisão que passou a valer.',
        ],
    ]);

    it('credits the suspension to whoever submitted the revision and ends it, in the timeline, as closed by the revision that became effective', function (string $case) {
        $scenario = Scenario::plan();
        $source = revisionClosureSource($scenario, $case);
        $submitter = User::factory()->withTwoFactor()->create();
        $submitter->assignRole('editor');
        $scenario['operation']->forceFill(['assigned_user_id' => $submitter->id])->save();
        $draft = Scenario::revise($scenario, $source);

        $this->travelTo(revisionClosureBusinessNoon('2026-10-06'));
        app(MeasurementRevisionService::class)->submit($draft->fresh(), $submitter);

        $this->travelTo(revisionClosureBusinessNoon('2026-10-08'));
        MeasurementPhysicalProgressScenario::approveEngineering($scenario, $draft, 8);
        Scenario::approveManagementAndCompliance($scenario, $draft);
        $source->refresh();
        $pause = MeasurementPause::query()->where('measurement_id', $source->id)->sole();
        $timeline = collect(app(MeasurementTimeline::class)->for($source));

        // Quem suspendeu foi quem enviou a revisão; a suspensão terminou com o
        // encerramento da medição, não com uma retomada.
        $scenario['actor']->givePermissionTo(AccessPermission::MeasurementsCycleReportsView->value);
        $history = app(MeasurementCycleHistoryReadModel::class)->for($scenario['actor'], $source->fresh());

        expect($pause->paused_by)->toBe($submitter->id)
            ->and($pause->resumed_by)->toBe($scenario['actor']->id)
            ->and($pause->resumed_at->equalTo($source->revision_superseded_at))->toBeTrue()
            ->and($timeline->firstWhere('title', 'Análise pausada (Etapa '.$source->current_stage.')')['actor'] ?? null)->toBe($submitter->name)
            ->and($timeline->pluck('title')->all())
            ->toContain('Suspensão encerrada: a revisão seguinte passou a valer')
            ->not->toContain('Análise retomada')
            // O relatório de ciclo diz o mesmo: encerramento, não retomada.
            ->and($history->pauses)->toHaveCount(1)
            ->and($history->pauses[0]->endedBySupersession)->toBeTrue()
            ->and($history->pauses[0]->pausedById)->toBe($submitter->id);

        $this->actingAs($scenario['actor']);
        Livewire::test(MeasurementCycleReport::class)
            ->call('showDetail', $source->id)
            ->assertSee('Pausado por '.$submitter->name.' · suspensão encerrada pela substituição, na decisão de '.$scenario['actor']->name)
            ->assertDontSee('retomado por '.$scenario['actor']->name);
    })->with(['awaiting_payment', 'paid_awaiting_finalization']);

    it('still shows the suspension ended by the rejection of the revision as the analysis resumed', function () {
        $scenario = Scenario::plan();
        $source = revisionClosureSource($scenario, 'paid_awaiting_finalization');
        $revision = Scenario::submit($scenario, Scenario::revise($scenario, $source));

        $this->travelTo(revisionClosureBusinessNoon('2026-10-07'));
        app(MeasurementWorkflow::class)->reject($revision->fresh(), $scenario['actor'], 'Percentual sem respaldo no laudo.');
        $timeline = collect(app(MeasurementTimeline::class)->for($source->fresh()));
        $scenario['actor']->givePermissionTo(AccessPermission::MeasurementsCycleReportsView->value);
        $history = app(MeasurementCycleHistoryReadModel::class)->for($scenario['actor'], $source->fresh());

        expect($timeline->pluck('title')->all())
            ->toContain('Análise retomada')
            ->not->toContain('Suspensão encerrada: a revisão seguinte passou a valer')
            ->and($history->pauses)->toHaveCount(1)
            ->and($history->pauses[0]->endedBySupersession)->toBeFalse();
    });

    it('keeps the effective revision with an open difference in the Payment Workspace while it is back at an earlier stage', function () {
        $scenario = Scenario::plan();
        $may = Scenario::finalized($scenario, '2026-05', 10);
        $revision = Scenario::effective($scenario, $may, 8);
        app(MeasurementWorkflow::class)->reject($revision->fresh(), $scenario['actor'], 'Rever o enquadramento na Compliance.');
        $revision->refresh();
        $workspace = app(MeasurementOperationalReadModel::class)->paymentQueryFor($scenario['actor']);

        // Devolvida à Compliance, sem pagamento próprio: fora das situações da
        // área de pagamentos, mas com R$ 20.000,00 pagos a maior em aberto.
        expect($revision->status)->toBe('in_review')
            ->and($revision->current_stage)->toBe(3)
            ->and($revision->payments()->exists())->toBeFalse()
            ->and($revision->hasOpenRevisionFinancialPosition())->toBeTrue()
            ->and((clone $workspace)->whereKey($revision->id)->exists())->toBeTrue()
            ->and((clone $workspace)->withOpenRevisionFinancialPosition()->pluck('measurements.id')->all())->toBe([$revision->id]);
    });
})->group('parity');

describe('telas', function () {
    it('shows the stage closed by the revision as closed, never as awaiting a decision, and offers no action of the closed workflow', function (string $case, array $cards) {
        $scenario = Scenario::plan();
        $may = revisionClosureSource($scenario, $case === 'legacy' ? 'awaiting_payment' : $case);
        Scenario::effective($scenario, $may, 12);

        if ($case === 'legacy') {
            revisionClosureLegacyPendingReview($may);
        }

        $this->actingAs($scenario['actor']);

        $page = Livewire::test(ViewMeasurement::class, ['record' => $may->getRouteKey()])
            ->assertSuccessful()
            ->assertActionHidden('approve')
            ->assertActionHidden('reject')
            ->assertActionHidden('pause')
            ->assertActionHidden('resume')
            ->assertActionHidden('registerPayment')
            ->assertActionHidden('reassessPayment')
            ->assertActionHidden('attachReceipt')
            ->assertActionHidden('reviewReceipt')
            ->assertActionHidden('finalize')
            ->assertActionHidden('returnToStage')
            ->assertActionHidden('createRevision');

        // A medição paga substituída só recebe a correção de comprovante.
        $case === 'paid_awaiting_finalization'
            ? $page->assertActionVisible('correctReceipt')
            : $page->assertActionHidden('correctReceipt');

        expect(revisionClosureResponsibilityCards($page->html()))->toBe($cards);
    })->with([
        'unpaid source at the Payment stage (case B)' => ['awaiting_payment', [
            'Etapa 1 · Engenharia' => 'Aprovada',
            'Etapa 2 · Gestão' => 'Aprovada',
            'Etapa 3 · Compliance' => 'Aprovada',
            'Etapa 4 · Pagamento' => 'Encerrada pela revisão',
            'Etapa 5 · Finalização' => 'Encerrada pela revisão',
        ]],
        'paid source awaiting the Finalization (case C)' => ['paid_awaiting_finalization', [
            'Etapa 1 · Engenharia' => 'Aprovada',
            'Etapa 2 · Gestão' => 'Aprovada',
            'Etapa 3 · Compliance' => 'Aprovada',
            'Etapa 4 · Pagamento' => 'Aprovada',
            'Etapa 5 · Finalização' => 'Encerrada pela revisão',
        ]],
        'unpaid source replaced before Phase 3.1, its Payment review left pending' => ['legacy', [
            'Etapa 1 · Engenharia' => 'Aprovada',
            'Etapa 2 · Gestão' => 'Aprovada',
            'Etapa 3 · Compliance' => 'Aprovada',
            'Etapa 4 · Pagamento' => 'Encerrada pela revisão',
            'Etapa 5 · Finalização' => 'Encerrada pela revisão',
        ]],
    ]);

    it('keeps the replaced measurement out of the Payment Workspace queues that ask for a payment, an approval or the Finalization', function (string $case, string $revisionQueue) {
        $scenario = Scenario::plan();
        $may = revisionClosureSource($scenario, $case);
        $revision = Scenario::effective($scenario, $may, 12);
        $this->actingAs($scenario['actor']);

        $page = Livewire::test(ListPaymentWorkspace::class)->assertCanSeeTableRecords([$revision]);

        // Sem pagamento, a substituída não tem o que fazer na área de
        // pagamentos; com pagamento, aparece como histórico, encerrada.
        $case === 'paid_awaiting_finalization'
            ? $page->assertCanSeeTableRecords([$may])->assertTableColumnStateSet('operational_pending', 'Encerrada pela revisão', $may)
            : $page->assertCanNotSeeTableRecords([$may]);

        foreach (['awaiting_registration', 'awaiting_approval', 'ready_to_finalize'] as $queue) {
            $page->filterTable('operational_pending', $queue)->assertCanNotSeeTableRecords([$may]);
        }

        // A etapa Pagamento segue na revisão.
        $page->filterTable('operational_pending', $revisionQueue)->assertCanSeeTableRecords([$revision]);
    })->with([
        'unpaid source at the Payment stage (case B)' => ['awaiting_payment', 'awaiting_registration'],
        'paid source awaiting the Finalization (case C)' => ['paid_awaiting_finalization', 'awaiting_approval'],
    ]);

    it('lists the paid source as ready to finalize until its revision is submitted and, once replaced, only in the receipt correction queues', function () {
        $scenario = Scenario::plan();
        $actor = $scenario['actor'];
        $may = Scenario::paidAwaitingFinalization($scenario, '2026-05', 10);
        $payment = $may->payments()->sole();
        $draft = Scenario::revise($scenario, $may);
        $this->actingAs($actor);

        // O rascunho não suspende nada.
        Livewire::test(ListPaymentWorkspace::class)
            ->assertTableColumnStateSet('operational_pending', 'Finalizar medição', $may)
            ->filterTable('operational_pending', 'ready_to_finalize')
            ->assertCanSeeTableRecords([$may]);

        $revision = Scenario::submit($scenario, $draft);

        // Suspensa pela revisão em análise: não está pronta para nada.
        Livewire::test(ListPaymentWorkspace::class)
            ->assertTableColumnStateSet('operational_pending', 'Bloqueada pela revisão em análise', $may)
            ->filterTable('operational_pending', 'ready_to_finalize')
            ->assertCanNotSeeTableRecords([$may]);

        MeasurementPhysicalProgressScenario::approveEngineering($scenario, $revision, 12);
        Scenario::approveManagementAndCompliance($scenario, $revision);

        Livewire::test(ListPaymentWorkspace::class)
            ->assertTableColumnStateSet('operational_pending', 'Encerrada pela revisão', $may)
            ->filterTable('operational_pending', 'ready_to_finalize')
            ->assertCanNotSeeTableRecords([$may])
            ->filterTable('operational_pending', 'awaiting_receipt_review')
            ->assertCanNotSeeTableRecords([$may]);

        // Substituída, só a correção de comprovante a traz de volta a uma fila:
        // a conferência, e depois da recusa, o reenvio.
        $receipts = app(MeasurementReceiptEvidenceService::class);
        $correction = $receipts->correctFinalizedReceipt(
            $payment->fresh(),
            $actor,
            ReceiptScenario::file('comprovante reemitido.pdf', '%PDF-1.7 comprovante reemitido'),
            'O banco reemitiu o comprovante com o CNPJ correto.',
            $payment->currentReceiptEvidence->id,
        );

        Livewire::test(ListPaymentWorkspace::class)
            ->assertTableColumnStateSet('operational_pending', 'Conferir correção documental', $may)
            ->filterTable('operational_pending', 'awaiting_receipt_review')
            ->assertCanSeeTableRecords([$may])
            ->filterTable('operational_pending', 'ready_to_finalize')
            ->assertCanNotSeeTableRecords([$may]);

        // Fase 4B-1: a conferência do comprovante é do Finalizador, outra
        // pessoa que não o ator que o envia.
        $receipts->review($correction, $scenario['finalizer'], MeasurementReceiptReviewStatus::Rejected, true, rejectionReason: 'O CNPJ do comprovante reemitido continua errado.');

        expect($may->fresh()->status)->toBe('superseded')
            ->and(app(MeasurementWorkflow::class)->pendingResponsibilities($may->fresh()))->toBe([MeasurementResponsibility::ReceiptUploader]);

        Livewire::test(ListPaymentWorkspace::class)
            ->assertTableColumnStateSet('operational_pending', 'Substituir correção documental rejeitada', $may)
            ->filterTable('operational_pending', 'awaiting_receipt')
            ->assertCanSeeTableRecords([$may])
            ->filterTable('operational_pending', 'awaiting_receipt_review')
            ->assertCanNotSeeTableRecords([$may])
            ->filterTable('operational_pending', 'awaiting_approval')
            ->assertCanNotSeeTableRecords([$may])
            ->filterTable('operational_pending', 'ready_to_finalize')
            ->assertCanNotSeeTableRecords([$may]);
    });

    it('describes the receipts of the paid source closed by its revision like those of a finalized measurement, never as without payments', function () {
        $scenario = Scenario::plan();
        $may = Scenario::paidAwaitingFinalization($scenario, '2026-05', 10);
        $june = Scenario::finalized($scenario, '2026-06', 10);
        Scenario::effective($scenario, $may, 12);
        $receipts = app(MeasurementReceiptEvidenceService::class);
        $this->actingAs($scenario['actor']);

        // As duas encerradas com um pagamento comprovado e nenhuma correção: a
        // documentação delas é a mesma, e a do serviço de comprovantes também.
        expect($may->fresh()->status)->toBe('superseded')
            ->and($may->payments()->count())->toBe(1)
            ->and($receipts->documentaryStatus($may->fresh()))->toBe('Sem pendência')
            ->and($receipts->documentaryStatus($june->fresh()))->toBe('Sem pendência');

        Livewire::test(ListPaymentWorkspace::class)
            ->assertTableColumnStateSet('payments_sum_amount', '100000.00', $may)
            ->assertTableColumnStateSet('receipt_status', 'Sem pendência', $june)
            ->assertTableColumnStateSet('receipt_status', 'Sem pendência', $may);
    });

    it('opens the ready-to-finalize card of the cockpit on the population it counts, without the paid source suspended by its revision', function () {
        $scenario = Scenario::plan();
        $may = Scenario::paidAwaitingFinalization($scenario, '2026-05', 10);
        $june = Scenario::paidAwaitingFinalization($scenario, '2026-06', 10);
        Scenario::submit($scenario, Scenario::revise($scenario, $may));
        $this->actingAs($scenario['actor']);

        $card = collect(Livewire::test(MeasurementCockpit::class)->viewData('signals'))->firstWhere('label', 'Prontas para finalizar');
        $query = [];
        parse_str((string) parse_url((string) $card['url'], PHP_URL_QUERY), $query);

        // O número: só junho. Maio está na Finalização, mas suspensa pela
        // revisão em análise.
        expect($may->fresh()->isFrozenByRevision())->toBeTrue()
            ->and($card['count'])->toBe(1);

        // O destino do cartão é a mesma população: a fila "Pronta para
        // finalizar" da área de pagamentos, aberta já filtrada.
        Livewire::withQueryParams($query)
            ->test(ListPaymentWorkspace::class)
            ->assertSet('tableFilters.operational_pending.value', 'ready_to_finalize')
            ->assertCanSeeTableRecords([$june])
            ->assertCanNotSeeTableRecords([$may])
            ->assertCountTableRecords(1);
    });

    it('counts in the cockpit the finalized revision that leaves an overpayment open and opens the list on that population', function () {
        $scenario = Scenario::plan();
        $may = Scenario::finalized($scenario, '2026-05', 10);
        $june = Scenario::finalized($scenario, '2026-06', 10);
        $overpaid = Scenario::closePaymentAndFinalize($scenario, Scenario::effective($scenario, $may, 8), 'Valor pago a maior tratado fora do sistema.', acceptFinancialExceptions: true);
        $unchanged = Scenario::closePaymentAndFinalize($scenario, Scenario::effective($scenario, $june, 10));
        $this->actingAs($scenario['actor']);

        $card = collect(Livewire::test(MeasurementCockpit::class)->viewData('signals'))->firstWhere('label', 'Regularização financeira pendente');
        $query = [];
        parse_str((string) parse_url((string) $card['url'], PHP_URL_QUERY), $query);

        // Finalizar reconheceu o valor pago a maior, não o regularizou: a
        // revisão continua contada, e a que manteve o valor aprovado não.
        expect($overpaid->status)->toBe('finalized')
            ->and($card['count'])->toBe(1)
            ->and($card['tone'])->toBe('warning')
            ->and($query)->toBe(['filters' => ['revision_financial_pending' => ['isActive' => '1']]]);

        Livewire::withQueryParams($query)
            ->test(ListMeasurements::class)
            ->assertSet('tableFilters.revision_financial_pending.isActive', true)
            ->assertCanSeeTableRecords([$overpaid])
            ->assertCanNotSeeTableRecords([$unchanged, $may, $june])
            ->assertCountTableRecords(1);
    });

    it('opens the payment cards of the cockpit on the payment workspace with their filter applied', function () {
        $scenario = Scenario::plan();
        Scenario::paidAwaitingFinalization($scenario, '2026-05', 10);
        $this->actingAs($scenario['actor']);

        $component = Livewire::test(MeasurementCockpit::class);
        $signals = collect($component->viewData('signals'));
        $expected = [
            'Comprovantes pendentes' => ['receipt_state', 'pending'],
            'Documentação pendente' => ['status', 'awaiting_receipt'],
            'Prontas para finalizar' => ['operational_pending', 'ready_to_finalize'],
        ];

        foreach ($expected as $label => [$filter, $value]) {
            $query = [];
            parse_str((string) parse_url((string) $signals->firstWhere('label', $label)['url'], PHP_URL_QUERY), $query);

            expect($query)->toBe(['filters' => [$filter => ['value' => $value]]]);

            Livewire::withQueryParams($query)
                ->test(ListPaymentWorkspace::class)
                ->assertSet("tableFilters.{$filter}.value", $value);
        }

        $workspace = [];
        parse_str((string) parse_url((string) $component->viewData('paymentWorkspaceUrl'), PHP_URL_QUERY), $workspace);

        expect($workspace)->not->toHaveKey('tableFilters');
    });
});
