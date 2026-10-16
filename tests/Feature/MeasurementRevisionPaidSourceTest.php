<?php

use App\DTOs\Measurements\MeasurementRevisionPlanSetPosition;
use App\Enums\MeasurementReceiptReviewStatus;
use App\Enums\MeasurementResponsibility;
use App\Enums\MeasurementRevisionAdjustmentStatus;
use App\Enums\MeasurementRevisionStatus;
use App\Exceptions\MeasurementWorkflowException;
use App\Models\Measurement;
use App\Models\MeasurementFinancialRule;
use App\Models\MeasurementPause;
use App\Models\MeasurementPayment;
use App\Models\MeasurementReview;
use App\Models\SlaConfiguration;
use App\Notifications\MeasurementWorkflowNotification;
use App\Services\MeasurementPaymentFinancialService;
use App\Services\MeasurementReceiptEvidenceService;
use App\Services\MeasurementRevisionService;
use App\Services\MeasurementSlaService;
use App\Services\MeasurementWorkflow;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\MeasurementPhysicalProgressScenario;
use Tests\Support\MeasurementReceiptEvidenceScenario as ReceiptScenario;
use Tests\Support\MeasurementRevisionScenario as Scenario;

/*
 * Revisão de medição paga e ainda não finalizada (caso C): a vigente na
 * Finalização com a documentação financeira completa. Do que a torna
 * revisável à Finalização da revisão -- elegibilidade, criação, suspensão,
 * recusa, vigência e o histórico de pagamentos que a revisão preserva.
 */

uses(RefreshDatabase::class);
pest()->group('parity');

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    Notification::fake();
});

/**
 * A recusa do fluxo, lida inteira da exceção -- para comparar com o texto
 * exato, inclusive o que tem dois-pontos.
 */
function revisionPaidSourceRefusal(callable $attempt): string
{
    try {
        $attempt();
    } catch (MeasurementWorkflowException $exception) {
        return $exception->getMessage();
    }

    throw new RuntimeException('A tentativa não foi recusada pelo fluxo.');
}

/**
 * A mensagem de validação de um campo, sem passar pelo `toThrow`.
 */
function revisionPaidSourceValidationMessage(callable $attempt, string $field): string
{
    try {
        $attempt();
    } catch (ValidationException $exception) {
        return (string) collect($exception->errors()[$field] ?? [])->first();
    }

    throw new RuntimeException("A tentativa não foi recusada no campo {$field}.");
}

/**
 * A posição única do empreendimento do cenário.
 */
function revisionPaidSourcePosition(Measurement $revision): MeasurementRevisionPlanSetPosition
{
    $positions = app(MeasurementRevisionService::class)->positions($revision->fresh());

    expect($positions)->toHaveCount(1);

    return $positions[0];
}

/**
 * O evento da trilha protegida gravado uma única vez na medição.
 */
function revisionPaidSourceActivity(Measurement $subject, string $event): Activity
{
    return Activity::query()
        ->where('log_name', 'measurement_workflow')
        ->where('subject_type', $subject->getMorphClass())
        ->where('subject_id', $subject->getKey())
        ->where('description', $event)
        ->sole();
}

/**
 * Meio-dia em Brasília do dia informado, no fuso da aplicação: as datas que o
 * domínio escreve (DD/MM/AAAA) saem do calendário de negócio, e as gravadas no
 * banco continuam em UTC.
 */
function revisionPaidSourceBusinessNoon(string $date): CarbonImmutable
{
    return BusinessTime::toApplication(CarbonImmutable::parse("{$date} 12:00:00", BusinessTime::timezone()));
}

function revisionPaidSourceRevisionCount(): int
{
    return Measurement::query()->where('revision_number', '>', 0)->count();
}

it('refuses to revise a paid measurement while its Payment stage is open, paused or not', function () {
    $scenario = Scenario::plan();
    $may = Scenario::awaitingPayment($scenario, '2026-05', 10);
    $payment = Scenario::pay($scenario, $may, Scenario::expected(10));
    $workflow = app(MeasurementWorkflow::class);
    $revisions = app(MeasurementRevisionService::class);

    expect($revisions->creationBlockReason($may->fresh()))->toBe(MeasurementRevisionService::PAID_PAYMENT_STAGE_REFUSAL)
        ->and($revisions->canCreate($may->fresh(), $scenario['actor']))->toBeFalse()
        ->and(revisionPaidSourceRefusal(fn () => Scenario::revise($scenario, $may)))->toBe(MeasurementRevisionService::PAID_PAYMENT_STAGE_REFUSAL);

    $workflow->pause($may->fresh(), $scenario['actor'], 'Aguardar a conciliação bancária.');

    expect($may->fresh()->status)->toBe('paused')
        ->and($revisions->creationBlockReason($may->fresh()))->toBe(MeasurementRevisionService::PAUSED_REFUSAL)
        ->and(revisionPaidSourceRefusal(fn () => Scenario::revise($scenario, $may)))->toBe(MeasurementRevisionService::PAUSED_REFUSAL);

    $workflow->resume($may->fresh(), $scenario['actor']);

    expect($may->fresh()->status)->toBe('awaiting_payment')
        ->and($revisions->creationBlockReason($may->fresh()))->toBe(MeasurementRevisionService::PAID_PAYMENT_STAGE_REFUSAL)
        ->and(revisionPaidSourceRevisionCount())->toBe(0)
        ->and($may->payments()->pluck('id')->all())->toBe([$payment->id]);
});

it('refuses to revise a paid measurement sent back into analysis, naming the stage it is in', function () {
    $scenario = Scenario::plan();
    $may = Scenario::awaitingPayment($scenario, '2026-05', 10);
    Scenario::pay($scenario, $may, Scenario::expected(10));
    $workflow = app(MeasurementWorkflow::class);
    $revisions = app(MeasurementRevisionService::class);

    // A etapa Pagamento devolve à Compliance: a medição paga volta à análise.
    $workflow->reject($may->fresh(), $scenario['actor'], 'Rever a aprovação da Compliance.');
    $atCompliance = sprintf(MeasurementRevisionService::PAID_IN_ANALYSIS_REFUSAL, 'Compliance');

    expect($may->fresh()->status)->toBe('in_review')
        ->and($may->fresh()->current_stage)->toBe(3)
        ->and($revisions->creationBlockReason($may->fresh()))->toBe($atCompliance)
        ->and(revisionPaidSourceRefusal(fn () => Scenario::revise($scenario, $may)))->toBe($atCompliance);

    $workflow->reject($may->fresh(), $scenario['actor'], 'Rever a aprovação da Gestão.');
    $atManagement = sprintf(MeasurementRevisionService::PAID_IN_ANALYSIS_REFUSAL, 'Gestão');

    expect($may->fresh()->current_stage)->toBe(2)
        ->and($revisions->creationBlockReason($may->fresh()))->toBe($atManagement)
        ->and(revisionPaidSourceRefusal(fn () => Scenario::revise($scenario, $may)))->toBe($atManagement)
        ->and(revisionPaidSourceRevisionCount())->toBe(0);
});

it('keeps refusing the paid measurement until every current receipt is approved, whatever its status says', function () {
    $scenario = Scenario::plan();
    $may = Scenario::awaitingPayment($scenario, '2026-05', 10);
    $payment = Scenario::pay($scenario, $may, Scenario::expected(10));
    app(MeasurementWorkflow::class)->approve($may->fresh(), $scenario['actor']);
    $receipts = app(MeasurementReceiptEvidenceService::class);
    $revisions = app(MeasurementRevisionService::class);
    $refusal = MeasurementRevisionService::PAID_RECEIPTS_PENDING_REFUSAL;

    // Sem comprovante.
    expect($may->fresh()->status)->toBe('awaiting_receipt')
        ->and($revisions->creationBlockReason($may->fresh()))->toBe($refusal)
        ->and(revisionPaidSourceRefusal(fn () => Scenario::revise($scenario, $may)))->toBe($refusal);

    // Em conferência.
    $first = $receipts->upload($payment->fresh(), $scenario['actor'], ReceiptScenario::file());

    expect($may->fresh()->status)->toBe('awaiting_receipt')
        ->and($revisions->creationBlockReason($may->fresh()))->toBe($refusal)
        ->and(revisionPaidSourceRefusal(fn () => Scenario::revise($scenario, $may)))->toBe($refusal);

    // Rejeitado.
    $receipts->review($first, $scenario['finalizer'], MeasurementReceiptReviewStatus::Rejected, true, rejectionReason: 'Comprovante ilegível.');

    expect($may->fresh()->status)->toBe('awaiting_receipt')
        ->and($revisions->creationBlockReason($may->fresh()))->toBe($refusal)
        ->and(revisionPaidSourceRefusal(fn () => Scenario::revise($scenario, $may)))->toBe($refusal);

    $second = $receipts->upload(
        $payment->fresh(),
        $scenario['actor'],
        ReceiptScenario::file('comprovante legível.pdf', '%PDF-1.7 comprovante legível'),
        $first->id,
        'A primeira versão estava ilegível.',
    );

    // Situação gravada à mão como "documentação completa" com o comprovante
    // atual ainda em conferência: a elegibilidade confere os comprovantes, não
    // só a situação da medição.
    DB::table('measurements')->where('id', $may->id)->update(['status' => 'approved']);

    expect($revisions->creationBlockReason($may->fresh()))->toBe($refusal)
        ->and(revisionPaidSourceRefusal(fn () => Scenario::revise($scenario, $may)))->toBe($refusal);

    DB::table('measurements')->where('id', $may->id)->update(['status' => 'awaiting_receipt']);
    $receipts->review($second, $scenario['finalizer'], MeasurementReceiptReviewStatus::Approved, true);

    expect($may->fresh()->status)->toBe('approved')
        ->and($revisions->creationBlockReason($may->fresh()))->toBeNull()
        ->and(revisionPaidSourceRevisionCount())->toBe(0)
        ->and(Scenario::revise($scenario, $may)->revisionNumber())->toBe(1);
});

it('refuses to revise a revision waiting at Finalization without a payment of its own until it is finalized', function () {
    $scenario = Scenario::plan();
    $may = Scenario::paidAwaitingFinalization($scenario, '2026-05', 10);
    $first = Scenario::effective($scenario, $may, 10, 'Laudo de maio substituído; o percentual não muda.');
    $workflow = app(MeasurementWorkflow::class);
    $revisions = app(MeasurementRevisionService::class);

    // A família já pagou o que a R1 aprova: ela atravessa a etapa Pagamento
    // sem pagamento próprio e chega à Finalização com a documentação completa.
    $workflow->approve($first->fresh(), $scenario['actor']);

    expect($first->fresh()->status)->toBe('approved')
        ->and($first->fresh()->current_stage)->toBe(MeasurementWorkflow::STAGE_FINALIZATION)
        ->and($first->payments()->exists())->toBeFalse()
        ->and($revisions->creationBlockReason($first->fresh()))->toBe(MeasurementRevisionService::UNPAID_FINALIZATION_REFUSAL)
        ->and(revisionPaidSourceRefusal(fn () => Scenario::revise($scenario, $first)))->toBe(MeasurementRevisionService::UNPAID_FINALIZATION_REFUSAL)
        ->and(Measurement::query()->where('revision_number', 2)->exists())->toBeFalse();

    $workflow->finalize($first->fresh(), $scenario['finalizer']);
    $second = Scenario::revise($scenario, $first->fresh());

    expect($first->fresh()->status)->toBe('finalized')
        ->and($second->revisionNumber())->toBe(2)
        ->and(revisionPaidSourceActivity($second, 'measurement_revision_created')->properties['source_eligibility'])->toBe('finalized');
});

it('refuses a legacy paid source whose frozen financial assessment no longer matches its Engineering record until the payment is reassessed', function () {
    $scenario = Scenario::plan();
    // Regra da emissão para pagar até R$ 10.000,00 acima do saldo, registrada
    // por outro responsável antes do pagamento.
    $rule = MeasurementFinancialRule::factory()->create([
        'emission_id' => $scenario['operation']->emission_id,
        'direction' => 'over',
        'maximum_difference_amount' => '10000.00',
        'maximum_difference_percent' => null,
    ]);
    $may = Scenario::paidAwaitingFinalization($scenario, '2026-05', 10);
    $payment = $may->payments()->sole();
    $workflow = app(MeasurementWorkflow::class);
    $revisions = app(MeasurementRevisionService::class);

    // Linha legada: paga, devolvida à Engenharia antes da Fase 3.1 e aprovada
    // de novo com outro percentual. O enquadramento do pagamento ficou
    // congelado sobre o registro anterior, e o comprovante continua aprovado.
    $legacy = Scenario::legacyPaidAtEngineering($may);
    MeasurementPhysicalProgressScenario::approveEngineering($scenario, $legacy, 9);
    Scenario::approveManagementAndCompliance($scenario, $legacy);
    $workflow->approve($may->fresh(), $scenario['actor']);
    $stale = sprintf(
        'O enquadramento financeiro do pagamento #%d não corresponde mais ao registro aprovado pela Engenharia: devolva a medição à etapa Pagamento para reavaliá-lo antes de revisá-la.',
        $payment->id,
    );

    expect($may->fresh()->status)->toBe('approved')
        ->and($payment->fresh()->currentReceiptEvidence->review_status)->toBe(MeasurementReceiptReviewStatus::Approved)
        ->and($revisions->creationBlockReason($may->fresh()))->toBe($stale)
        ->and(revisionPaidSourceRefusal(fn () => Scenario::revise($scenario, $may)))->toBe($stale)
        // A Finalização recusa pelo mesmo motivo.
        ->and(revisionPaidSourceRefusal(fn () => $workflow->finalize($may->fresh(), $scenario['finalizer'], acceptFinancialExceptions: true)))
        ->toBe('O contexto financeiro mudou. Devolva à etapa Pagamento para reavaliar o enquadramento.')
        ->and(revisionPaidSourceRevisionCount())->toBe(0);

    // O caminho que a recusa aponta: a etapa Pagamento reavalia o pagamento.
    $workflow->returnToStage($may->fresh(), $scenario['finalizer'], MeasurementWorkflow::STAGE_PAYMENT, 'Reavaliar o enquadramento do pagamento.');
    $reassessment = ['financial_justification' => 'Pago sobre os 10% aprovados antes da reaprovação em 9%.'];
    $assessment = $payment->fresh()->financial_assessment;

    // Fase 4B-1: a justificativa sozinha não autoriza o excedente sobre os 9% reaprovados.
    expect(revisionPaidSourceValidationMessage(
        fn () => $workflow->reassessPayment($payment->fresh(), $scenario['actor'], $reassessment, $may->fresh()->workflow_revision),
        'financial_rule_id',
    ))->toStartWith(MeasurementPaymentFinancialService::EXCESS_WITHOUT_RULE_REFUSAL)
        ->and($payment->fresh()->financial_assessment)->toEqual($assessment);

    $workflow->reassessPayment($payment->fresh(), $scenario['actor'], [...$reassessment, 'financial_rule_id' => $rule->id], $may->fresh()->workflow_revision);
    $workflow->approve($may->fresh(), $scenario['actor']);
    $unaccepted = sprintf(MeasurementRevisionService::PAID_EXCEPTION_UNACCEPTED_REFUSAL, $payment->id);

    // Reavaliado, o enquadramento volta a valer. Fase 4B-1: o excedente só é
    // aceito na Finalização, por outro responsável -- antes dela, a medição
    // paga não é revisada.
    expect($may->fresh()->status)->toBe('approved')
        ->and($revisions->creationBlockReason($may->fresh()))->toBe($unaccepted)
        ->and(revisionPaidSourceRefusal(fn () => Scenario::revise($scenario, $may)))->toBe($unaccepted)
        ->and(revisionPaidSourceRevisionCount())->toBe(0);

    $workflow->finalize($may->fresh(), $scenario['finalizer'], acceptFinancialExceptions: true);
    $revision = Scenario::revise($scenario, $may);

    expect($revision->revisionNumber())->toBe(1)
        ->and(revisionPaidSourceActivity($revision, 'measurement_revision_created')->properties['source_eligibility'])->toBe('finalized');
});

it('refuses to create the revision when a proof of the paid source is no longer intact in storage', function (string $damage) {
    $scenario = Scenario::plan();
    $may = Scenario::awaitingPayment($scenario, '2026-05', 10);
    $payment = app(MeasurementWorkflow::class)->registerPayment($may->fresh(), $scenario['actor'], [
        'plan_set_id' => $scenario['planSet']->id,
        'pay_date' => '2026-07-20',
        'amount' => '60000.00',
        'method' => 'TED',
        'financial_justification' => 'Retenção contratual de 40% até a vistoria final.',
        'financial_support' => ReceiptScenario::file('retencao-contratual.pdf', '%PDF-1.7 retenção contratual'),
    ]);
    app(MeasurementWorkflow::class)->approve($may->fresh(), $scenario['actor']);
    Scenario::receiptsApproved($scenario, $may);
    $evidence = $payment->fresh()->currentReceiptEvidence;
    $support = $payment->fresh()->financial_assessment['support'];
    $source = $may->fresh();

    expect($source->status)->toBe('approved')
        ->and(app(MeasurementRevisionService::class)->creationBlockReason($source))->toBeNull();

    match ($damage) {
        'receipt missing' => Storage::disk('local')->delete($evidence->storage_path),
        'receipt altered' => Storage::disk('local')->put($evidence->storage_path, '%PDF-1.7 outro comprovante'),
        'support missing' => Storage::disk('local')->delete($support['path']),
    };

    $expected = $damage === 'support missing'
        ? sprintf('O documento de suporte do enquadramento do pagamento #%d está ausente ou inválido: devolva a medição à etapa Pagamento para substituí-lo antes de revisá-la.', $payment->id)
        : sprintf('O comprovante do pagamento #%d está ausente ou não corresponde ao conteúdo aprovado: envie uma nova versão do comprovante antes de revisar a medição.', $payment->id);

    expect(revisionPaidSourceRefusal(fn () => Scenario::revise($scenario, $source)))->toBe($expected)
        ->and(revisionPaidSourceRevisionCount())->toBe(0)
        ->and(Activity::query()->where('description', 'measurement_revision_created')->exists())->toBeFalse()
        ->and($source->fresh()->status)->toBe('approved')
        ->and($source->fresh()->workflow_revision)->toBe($source->workflow_revision);
})->with(['receipt missing', 'receipt altered', 'support missing']);

/**
 * Paga a medição de maio com retenção (documento de suporte), aprova a etapa
 * Pagamento e o comprovante: a medição fica paga aguardando a Finalização.
 *
 * @param  array<string, mixed>  $scenario
 * @return array{0: Measurement, 1: MeasurementPayment}
 */
function revisionPaidSourceWithSupport(array $scenario, Measurement $may): array
{
    $payment = app(MeasurementWorkflow::class)->registerPayment($may->fresh(), $scenario['actor'], [
        'plan_set_id' => $scenario['planSet']->id,
        'pay_date' => '2026-07-20',
        'amount' => '60000.00',
        'method' => 'TED',
        'financial_justification' => 'Retenção contratual de 40% até a vistoria final.',
        'financial_support' => ReceiptScenario::file('retencao-contratual.pdf', '%PDF-1.7 retenção contratual'),
    ]);
    app(MeasurementWorkflow::class)->approve($may->fresh(), $scenario['actor']);
    Scenario::receiptsApproved($scenario, $may);

    expect($may->fresh()->status)->toBe('approved');

    return [$may->fresh(), $payment->fresh()];
}

function revisionPaidSourceDamage(MeasurementPayment $payment, string $damage): void
{
    $evidence = $payment->fresh()->currentReceiptEvidence;
    $support = $payment->fresh()->financial_assessment['support'];

    match ($damage) {
        'receipt missing' => Storage::disk('local')->delete($evidence->storage_path),
        'receipt altered' => Storage::disk('local')->put($evidence->storage_path, '%PDF-1.7 outro comprovante'),
        'support missing' => Storage::disk('local')->delete($support['path']),
    };
}

it('refuses to submit the draft when a proof of the paid source broke after the draft was created, and keeps the draft', function (string $damage, bool $paidAfterDraft) {
    $scenario = Scenario::plan();
    $may = Scenario::awaitingPayment($scenario, '2026-05', 10);

    // O rascunho criado com a anterior ainda sem pagamento (caso B) segue
    // válido depois que ela é paga e chega à Finalização: o envio é que
    // confere os arquivos da anterior paga.
    $draft = $paidAfterDraft ? Scenario::revise($scenario, $may) : null;
    [$source, $payment] = revisionPaidSourceWithSupport($scenario, $may);
    $draft ??= Scenario::revise($scenario, $source);
    $stored = $source->fresh()->workflow_revision;

    revisionPaidSourceDamage($payment, $damage);

    $expected = $damage === 'support missing'
        ? sprintf('O documento de suporte do enquadramento do pagamento #%d está ausente ou inválido: devolva a medição à etapa Pagamento para substituí-lo antes de revisá-la.', $payment->id)
        : sprintf('O comprovante do pagamento #%d está ausente ou não corresponde ao conteúdo aprovado: envie uma nova versão do comprovante antes de revisar a medição.', $payment->id);

    expect(revisionPaidSourceRefusal(fn () => Scenario::submit($scenario, $draft)))->toBe($expected)
        ->and($draft->fresh()->revisionStatus())->toBe(MeasurementRevisionStatus::Draft)
        ->and($draft->fresh()->uploaded_at)->toBeNull()
        ->and($source->fresh()->status)->toBe('approved')
        ->and($source->fresh()->workflow_revision)->toBe($stored)
        ->and($source->fresh()->isFrozenByRevision())->toBeFalse()
        ->and(Activity::query()->whereIn('description', ['measurement_revision_submitted', 'measurement_revision_source_suspended'])->exists())->toBeFalse();
})->with(['receipt missing', 'receipt altered', 'support missing'])->with([
    'draft created on the paid source' => false,
    'draft created before the payment' => true,
]);

it('refuses the Compliance approval that would supersede a paid source whose proof broke during the analysis, writing nothing', function (string $damage) {
    $scenario = Scenario::plan();
    $may = Scenario::awaitingPayment($scenario, '2026-05', 10);
    [$source, $payment] = revisionPaidSourceWithSupport($scenario, $may);
    $revision = Scenario::submit($scenario, Scenario::revise($scenario, $source));
    MeasurementPhysicalProgressScenario::approveEngineering($scenario, $revision, 12);
    app(MeasurementWorkflow::class)->approve($revision->fresh(), $scenario['actor']);
    $atCompliance = $revision->fresh();

    expect($atCompliance->current_stage)->toBe(3)
        ->and($atCompliance->revisionStatus())->toBe(MeasurementRevisionStatus::UnderReview);

    revisionPaidSourceDamage($payment, $damage);
    $activities = Activity::query()->count();
    $sourceRow = (array) DB::table('measurements')->where('id', $source->id)->first();
    $revisionRow = (array) DB::table('measurements')->where('id', $revision->id)->first();
    $reviews = DB::table('measurement_reviews')->orderBy('id')->get()->map(fn (object $review): array => (array) $review)->all();

    // A anterior está suspensa: não recebe comprovante nem volta à etapa
    // Pagamento. O texto aponta o único caminho, a recusa da revisão.
    $expected = $damage === 'support missing'
        ? sprintf('O documento de suporte do enquadramento do pagamento #%d está ausente ou inválido: a revisão não pode substituir a R0 assim. A R0 está suspensa por esta revisão e só volta ao fluxo, para substituir o documento na etapa Pagamento, com a recusa da revisão na Engenharia.', $payment->id)
        : sprintf('O comprovante do pagamento #%d está ausente ou não corresponde ao conteúdo aprovado: a revisão não pode substituir a R0 assim. A R0 está suspensa por esta revisão e só volta ao fluxo, para receber o comprovante novo, com a recusa da revisão na Engenharia.', $payment->id);

    expect(revisionPaidSourceRefusal(fn () => app(MeasurementWorkflow::class)->approve($atCompliance, $scenario['actor'])))->toBe($expected)
        ->and(Activity::query()->count())->toBe($activities)
        ->and((array) DB::table('measurements')->where('id', $source->id)->first())->toBe($sourceRow)
        ->and((array) DB::table('measurements')->where('id', $revision->id)->first())->toBe($revisionRow)
        ->and(DB::table('measurement_reviews')->orderBy('id')->get()->map(fn (object $review): array => (array) $review)->all())->toBe($reviews)
        ->and($source->fresh()->revisionStatus())->toBe(MeasurementRevisionStatus::Effective);
})->with(['receipt missing', 'receipt altered', 'support missing']);

it('lets only the version of the paid source whose proofs were verified outside the transaction be revised, submitted or superseded', function () {
    $scenario = Scenario::plan();
    $source = Scenario::paidAwaitingFinalization($scenario, '2026-05', 10);
    $unpaid = Scenario::awaitingPayment($scenario, '2026-06', 10);
    $service = app(MeasurementRevisionService::class);
    $guard = Closure::bind(fn (Measurement $measurement, ?int $verified) => $this->assertPaidSourceEvidenceVerified($measurement, $verified), $service, MeasurementRevisionService::class);
    $current = (int) $source->fresh()->workflow_revision;

    // A paga aguardando a Finalização sem a conferência, ou conferida em
    // outra versão do fluxo, ficou velha; a conferida nesta versão segue, e
    // a que não está paga aguardando a Finalização não depende dela.
    expect(revisionPaidSourceRefusal(fn () => $guard($source->fresh(), null)))->toBe(MeasurementRevisionService::STALE_REVISION_REFUSAL)
        ->and(revisionPaidSourceRefusal(fn () => $guard($source->fresh(), $current - 1)))->toBe(MeasurementRevisionService::STALE_REVISION_REFUSAL)
        ->and($guard($source->fresh(), $current))->toBeNull()
        ->and($guard($unpaid->fresh(), null))->toBeNull();
});

it('creates the revision of a paid measurement at Finalization as a draft that inherits its frozen context and preserves its payments', function () {
    $scenario = Scenario::plan();
    $may = Scenario::paidAwaitingFinalization($scenario, '2026-05', 10);
    $payment = $may->payments()->sole();
    $originalAsset = $may->assets()->with('planVersion')->sole();
    $before = $may->only(['status', 'current_stage', 'workflow_revision']);
    $snapshotHash = MeasurementRevisionService::canonicalSnapshotHash($may->engineering_snapshot);
    $revisions = app(MeasurementRevisionService::class);

    expect($may->status)->toBe('approved')
        ->and($revisions->creationBlockReason($may))->toBeNull()
        ->and($revisions->canCreate($may, $scenario['actor']))->toBeTrue();

    $revision = Scenario::revise($scenario, $may, 'Percentual de maio digitado errado; a medição já foi paga.');
    $inherited = $revision->assets()->sole();
    $created = revisionPaidSourceActivity($revision, 'measurement_revision_created');

    expect($revision->revisionNumber())->toBe(1)
        ->and($revision->revisionStatus())->toBe(MeasurementRevisionStatus::Draft)
        ->and($revision->status)->toBe('pending')
        ->and($revision->current_stage)->toBe(0)
        ->and($revision->previous_revision_id)->toBe($may->id)
        ->and($revision->reference_month->toDateString())->toBe($may->reference_month->toDateString())
        ->and($revision->previous_snapshot_sha256)->toBe($snapshotHash)
        ->and($revision->payments()->exists())->toBeFalse()
        ->and($inherited->inherited_from_asset_id)->toBe($originalAsset->id)
        ->and($inherited->plan_set_id)->toBe($originalAsset->plan_set_id)
        ->and($inherited->plan_line_id)->toBe($originalAsset->plan_line_id)
        ->and($inherited->plan_version_id)->toBe($originalAsset->plan_version_id)
        ->and($inherited->sha256)->toBe($originalAsset->sha256)
        ->and($inherited->line_claim_key)->toBeNull()
        ->and($created->properties['source_status'])->toBe('approved')
        ->and($created->properties['source_eligibility'])->toBe('paid_awaiting_finalization')
        ->and($created->properties['source_payments']['count'])->toBe(1)
        ->and($created->properties['source_payments']['total_amount'])->toBe('100000.00')
        ->and($created->properties['source_payments']['payment_ids'])->toBe([$payment->id])
        ->and($created->properties['plan_context'])->toHaveCount(1)
        ->and($created->properties['plan_context'][0]['plan_version_id'])->toBe($originalAsset->plan_version_id)
        ->and($created->properties['plan_context'][0]['plan_version_number'])->toBe($originalAsset->planVersion->version_number)
        ->and($created->properties['plan_context'][0]['plan_line_id'])->toBe($originalAsset->plan_line_id)
        ->and($created->properties['related_measurement_id'])->toBe($may->id);

    // A medição paga fica exatamente como estava: o rascunho não suspende
    // nada nem mexe nos pagamentos.
    $may->refresh();

    expect($may->only(['status', 'current_stage', 'workflow_revision']))->toBe($before)
        ->and($may->revisionStatus())->toBe(MeasurementRevisionStatus::Effective)
        ->and($may->isFrozenByRevision())->toBeFalse()
        ->and($may->reviews()->where('stage', MeasurementWorkflow::STAGE_FINALIZATION)->value('status'))->toBe('pending')
        ->and($originalAsset->fresh()->line_claim_key)->toBe($scenario['lines']['2026-05']->lineage_key)
        ->and(MeasurementPayment::query()->pluck('id')->all())->toBe([$payment->id]);
});

it('does not suspend the paid source while the revision is a draft: the source may still be finalized and the draft goes on as the revision of a finalized measurement', function () {
    $scenario = Scenario::plan();
    $may = Scenario::paidAwaitingFinalization($scenario, '2026-05', 10);
    $draft = Scenario::revise($scenario, $may);
    $workflow = app(MeasurementWorkflow::class);

    expect($may->fresh()->isFrozenByRevision())->toBeFalse()
        ->and(app(MeasurementRevisionService::class)->overview($may->fresh())['frozen_by'])->toBeNull()
        ->and($workflow->canFinalize($may->fresh(), $scenario['finalizer']))->toBeTrue()
        ->and($workflow->canReturnFromFinalization($may->fresh(), $scenario['finalizer']))->toBeTrue()
        ->and($workflow->pendingResponsibilities($may->fresh()))->toBe([MeasurementResponsibility::Finalizer]);

    $workflow->finalize($may->fresh(), $scenario['finalizer']);

    expect($may->fresh()->status)->toBe('finalized');

    $revision = Scenario::submit($scenario, $draft);
    $submitted = revisionPaidSourceActivity($revision, 'measurement_revision_submitted');

    expect($revision->revisionStatus())->toBe(MeasurementRevisionStatus::UnderReview)
        ->and($submitted->properties['source_eligibility'])->toBe('finalized')
        ->and($submitted->properties['suspends_measurement_id'])->toBeNull()
        ->and($submitted->properties['suspended_source_status'])->toBeNull()
        ->and($submitted->properties['suspended_source_stage'])->toBeNull()
        ->and(Activity::query()->where('description', 'measurement_revision_source_suspended')->exists())->toBeFalse()
        ->and($may->fresh()->isFrozenByRevision())->toBeFalse();

    MeasurementPhysicalProgressScenario::approveEngineering($scenario, $revision, 12);
    Scenario::approveManagementAndCompliance($scenario, $revision);
    $may->refresh();

    // Como a revisão de uma medição finalizada: nada a encerrar na anterior.
    expect($revision->fresh()->revisionStatus())->toBe(MeasurementRevisionStatus::Effective)
        ->and(app(MeasurementRevisionService::class)->overview($revision->fresh())['paid_source'])->toBe([
            'label' => 'R0',
            'finalized' => true,
            'paid_amount' => '100000.00',
            'payments_count' => 1,
        ])
        ->and($may->revisionStatus())->toBe(MeasurementRevisionStatus::Superseded)
        ->and($may->status)->toBe('finalized')
        ->and($may->reviews()->where('stage', MeasurementWorkflow::STAGE_FINALIZATION)->value('status'))->toBe('approved')
        ->and(Activity::query()->where('description', 'measurement_workflow_closed_by_revision')->exists())->toBeFalse()
        ->and(MeasurementPause::query()->where('measurement_id', $may->id)->exists())->toBeFalse();
});

it('suspends every action on the paid source while its revision is under review', function () {
    $scenario = Scenario::plan();
    $may = Scenario::paidAwaitingFinalization($scenario, '2026-05', 10);
    $payment = $may->payments()->sole();
    $evidence = $payment->currentReceiptEvidence;
    $revision = Scenario::submit($scenario, Scenario::revise($scenario, $may));
    $workflow = app(MeasurementWorkflow::class);
    $receipts = app(MeasurementReceiptEvidenceService::class);
    $actor = $scenario['actor'];
    $finalizer = $scenario['finalizer'];
    $frozen = sprintf(MeasurementRevisionService::FROZEN_WORKFLOW_REFUSAL, 'R1');
    $source = $may->fresh();
    $storedFiles = count(Storage::disk('local')->allFiles());
    $submitted = revisionPaidSourceActivity($revision, 'measurement_revision_submitted');

    $suspended = revisionPaidSourceActivity($may, 'measurement_revision_source_suspended');

    expect($submitted->properties['suspends_measurement_id'])->toBe($may->id)
        ->and($submitted->properties['suspended_source_status'])->toBe('approved')
        ->and($submitted->properties['suspended_source_stage'])->toBe(MeasurementWorkflow::STAGE_FINALIZATION)
        ->and($submitted->properties['source_eligibility'])->toBe('paid_awaiting_finalization')
        ->and($submitted->properties['source_payments']['payment_ids'])->toBe([$payment->id])
        // A trilha da própria anterior registra a suspensão, com a revisão que
        // a suspendeu, como registra a retomada e o encerramento.
        ->and($suspended->id)->toBeGreaterThan($submitted->id)
        // Quem envia a revisão do cenário é o Finalizador.
        ->and($suspended->causer_id)->toBe($finalizer->id)
        ->and($suspended->properties['stage'])->toBe(MeasurementWorkflow::STAGE_FINALIZATION)
        ->and($suspended->properties['status'])->toBe('approved')
        ->and($suspended->properties['suspended_by_revision_id'])->toBe($revision->id)
        ->and($suspended->properties['suspended_by_revision_number'])->toBe(1)
        ->and($suspended->properties['suspended_at'])->toBe($revision->fresh()->uploaded_at->toIso8601String())
        ->and($suspended->properties['source_eligibility'])->toBe('paid_awaiting_finalization')
        ->and($suspended->properties['source_payments'])->toBe($submitted->properties['source_payments'])
        ->and($suspended->properties['operation_id'])->toBe($may->operation_id)
        ->and($suspended->properties['revision_family_id'])->toBe($may->id)
        ->and($suspended->properties['revision_number'])->toBe(0)
        ->and($suspended->properties['related_measurement_id'])->toBe($revision->id)
        ->and($suspended->properties['related_revision_number'])->toBe(1)
        ->and($suspended->properties['workflow_revision'])->toBe((int) $source->workflow_revision)
        ->and($source->isFrozenByRevision())->toBeTrue()
        ->and($workflow->canFinalize($source, $finalizer))->toBeFalse()
        ->and($workflow->canReturnFromFinalization($source, $finalizer))->toBeFalse()
        ->and($workflow->canManageReceipts($source, $actor))->toBeFalse()
        ->and($workflow->canResume($source, $actor))->toBeFalse()
        ->and($receipts->canUpload($source, $actor))->toBeFalse()
        ->and($receipts->canReview($source, $finalizer))->toBeFalse()
        ->and($workflow->pendingResponsibilities($source))->toBe([])
        ->and(app(MeasurementSlaService::class)->evaluate($source)['status'])->toBe(MeasurementSlaService::STATUS_SUSPENDED_BY_REVISION)
        ->and(app(MeasurementRevisionService::class)->overview($source)['frozen_by'])->toBe('R1')
        ->and(Measurement::query()->withPendingWork()->pluck('id')->all())->toBe([$revision->id]);

    expect(revisionPaidSourceRefusal(fn () => $workflow->finalize($may->fresh(), $finalizer, acceptFinancialExceptions: true)))->toBe($frozen);

    foreach ([MeasurementWorkflow::STAGE_ENGINEERING, 2, 3, MeasurementWorkflow::STAGE_PAYMENT] as $target) {
        expect(revisionPaidSourceRefusal(fn () => $workflow->returnToStage($may->fresh(), $finalizer, $target, 'Devolver durante a revisão.')))->toBe($frozen);
    }

    expect(revisionPaidSourceRefusal(fn () => $workflow->approve($may->fresh(), $actor)))->toBe($frozen)
        ->and(revisionPaidSourceRefusal(fn () => $workflow->reject($may->fresh(), $actor, 'Recusar durante a revisão.')))->toBe($frozen)
        ->and(revisionPaidSourceRefusal(fn () => $workflow->pause($may->fresh(), $actor, 'Pausar durante a revisão.')))->toBe($frozen)
        ->and(revisionPaidSourceRefusal(fn () => $workflow->attachReceipt(
            $payment->fresh(),
            $actor,
            ReceiptScenario::file('nova versão.pdf', '%PDF-1.7 nova versão'),
            $evidence->id,
            'Nova versão durante a revisão.',
        )))->toBe($frozen)
        ->and(revisionPaidSourceRefusal(fn () => $receipts->correctFinalizedReceipt(
            $payment->fresh(),
            $actor,
            ReceiptScenario::file('correção.pdf', '%PDF-1.7 correção'),
            'Correção durante a revisão.',
            $evidence->id,
        )))->toBe($frozen)
        // A suspensão vem antes de "já decidida": o comprovante aprovado
        // também não é reconferido.
        ->and(revisionPaidSourceRefusal(fn () => $receipts->review($evidence->fresh(), $finalizer, MeasurementReceiptReviewStatus::Approved, true)))->toBe($frozen)
        // A Finalização não tem pausa: retomar é recusado antes de chegar à
        // suspensão (a guarda da suspensão na retomada é provada à parte).
        ->and(revisionPaidSourceRefusal(fn () => $workflow->resume($may->fresh(), $actor)))
        ->toBe('A etapa desta medição foi alterada por outra ação. Atualize a página.')
        // Outro pagamento e a reavaliação do enquadramento são da etapa
        // Pagamento, que a origem paga já deixou: os dois param na etapa.
        ->and(revisionPaidSourceRefusal(fn () => $workflow->registerPayment($may->fresh(), $actor, [
            'plan_set_id' => $scenario['planSet']->id,
            'pay_date' => '2026-07-20',
            'amount' => '1000.00',
            'method' => 'TED',
        ])))->toBe('A etapa desta medição foi alterada por outra ação. Atualize a página.')
        ->and(revisionPaidSourceRefusal(fn () => $workflow->reassessPayment($payment->fresh(), $actor, [
            'financial_justification' => 'Reavaliar durante a revisão.',
        ], (int) $may->fresh()->workflow_revision)))->toBe('A etapa desta medição foi alterada por outra ação. Atualize a página.');

    $may->refresh();

    expect($may->status)->toBe('approved')
        ->and($may->workflow_revision)->toBe($source->workflow_revision)
        ->and($may->reviews()->where('stage', MeasurementWorkflow::STAGE_FINALIZATION)->value('status'))->toBe('pending')
        ->and(MeasurementPayment::query()->pluck('id')->all())->toBe([$payment->id])
        ->and($payment->fresh()->financial_assessment)->toEqual($payment->financial_assessment)
        ->and($payment->receiptEvidences()->count())->toBe(1)
        ->and($evidence->fresh()->review_status)->toBe(MeasurementReceiptReviewStatus::Approved)
        ->and(count(Storage::disk('local')->allFiles()))->toBe($storedFiles)
        ->and(MeasurementPause::query()->where('measurement_id', $may->id)->exists())->toBeFalse();
});

it('refuses to resume a source suspended by its revision even from a forged paused state', function () {
    $scenario = Scenario::plan();
    $may = Scenario::paidAwaitingFinalization($scenario, '2026-05', 10);
    Scenario::submit($scenario, Scenario::revise($scenario, $may));

    // Pausa e revisão em análise nunca coexistem pelo fluxo -- a medição
    // suspensa não é pausada, e a pausada não é revisada --: o estado é
    // gravado à mão para alcançar a guarda da retomada.
    DB::table('measurements')->where('id', $may->id)->update(['status' => 'paused']);
    $pause = MeasurementPause::query()->create([
        'measurement_id' => $may->id,
        'stage' => MeasurementWorkflow::STAGE_FINALIZATION,
        'paused_by' => $scenario['actor']->id,
        'pause_reason' => 'Pausa gravada à mão.',
        'paused_operation_status' => 'approved',
        'paused_at' => now(),
    ]);

    expect($may->fresh()->isFrozenByRevision())->toBeTrue()
        ->and(revisionPaidSourceRefusal(fn () => app(MeasurementWorkflow::class)->resume($may->fresh(), $scenario['actor'])))
        ->toBe(sprintf(MeasurementRevisionService::FROZEN_WORKFLOW_REFUSAL, 'R1'))
        ->and($pause->fresh()->resumed_at)->toBeNull()
        ->and($may->fresh()->status)->toBe('paused');
});

it('resumes the Finalization of the paid source when Engineering rejects the revision, without counting the suspension against the stage deadline', function () {
    config(['measurements.sla.calendar_code' => null]);
    SlaConfiguration::factory()->forStage(MeasurementWorkflow::STAGE_FINALIZATION, 10)->create();

    // Segunda-feira: a Finalização abre.
    $this->travelTo(revisionPaidSourceBusinessNoon('2026-10-05'));
    $scenario = Scenario::plan();
    $may = Scenario::paidAwaitingFinalization($scenario, '2026-05', 10);
    $workflowRevision = $may->workflow_revision;

    // Terça-feira: a revisão é enviada e suspende a Finalização.
    $this->travelTo(revisionPaidSourceBusinessNoon('2026-10-06'));
    $revision = Scenario::submit($scenario, Scenario::revise($scenario, $may));

    expect(app(MeasurementSlaService::class)->evaluate($may->fresh())['status'])->toBe(MeasurementSlaService::STATUS_SUSPENDED_BY_REVISION);

    // Quinta-feira: a Engenharia recusa a revisão.
    $this->travelTo(revisionPaidSourceBusinessNoon('2026-10-08'));
    app(MeasurementWorkflow::class)->reject($revision->fresh(), $scenario['actor'], 'A correção proposta também está errada.');
    $may->refresh();
    $revision->refresh();
    $pause = MeasurementPause::query()->where('measurement_id', $may->id)->sole();
    $resumed = revisionPaidSourceActivity($may, 'measurement_revision_source_resumed');

    expect($revision->revisionStatus())->toBe(MeasurementRevisionStatus::Rejected)
        ->and($may->status)->toBe('approved')
        ->and($may->current_stage)->toBe(MeasurementWorkflow::STAGE_FINALIZATION)
        ->and($may->workflow_revision)->toBe($workflowRevision)
        ->and($may->revisionStatus())->toBe(MeasurementRevisionStatus::Effective)
        ->and($may->isFrozenByRevision())->toBeFalse()
        ->and($may->reviews()->where('stage', MeasurementWorkflow::STAGE_FINALIZATION)->value('status'))->toBe('pending')
        ->and($pause->stage)->toBe(MeasurementWorkflow::STAGE_FINALIZATION)
        ->and($pause->paused_operation_status)->toBe('approved')
        ->and($pause->paused_at->equalTo($revision->uploaded_at))->toBeTrue()
        ->and($pause->resumed_at->equalTo(now()))->toBeTrue()
        ->and($pause->resumed_by)->toBe($scenario['actor']->id)
        ->and($pause->pause_reason)->toBe('Fluxo suspenso pela revisão R1, em análise de 06/10/2026 a 08/10/2026; a revisão foi recusada.')
        ->and($resumed->properties['stage'])->toBe(MeasurementWorkflow::STAGE_FINALIZATION)
        ->and($resumed->properties['status'])->toBe('approved')
        ->and($resumed->properties['rejected_revision_id'])->toBe($revision->id)
        ->and($resumed->properties['suspension_pause_id'])->toBe($pause->id)
        ->and(app(MeasurementRevisionService::class)->overview($may)['frozen_by'])->toBeNull()
        ->and(app(MeasurementWorkflow::class)->canFinalize($may, $scenario['finalizer']))->toBeTrue();

    // Sexta-feira: quatro dias úteis desde que a Finalização abriu, dois deles
    // com a medição suspensa pela revisão.
    $this->travelTo(revisionPaidSourceBusinessNoon('2026-10-09'));
    $sla = app(MeasurementSlaService::class)->evaluate($may->fresh(), CarbonImmutable::instance(now()));

    expect($sla['status'])->toBe(MeasurementSlaService::STATUS_ON_TIME)
        ->and($sla['elapsed_business_seconds'])->toBe(2 * 86400);

    // O fluxo voltou de onde parou.
    app(MeasurementWorkflow::class)->finalize($may->fresh(), $scenario['finalizer']);

    expect($may->fresh()->status)->toBe('finalized');
});

it('closes the Finalization of the paid source without a decision when the revision becomes effective, and keeps its payments as the family history', function () {
    $this->travelTo(revisionPaidSourceBusinessNoon('2026-10-05'));
    $scenario = Scenario::plan();
    $may = Scenario::paidAwaitingFinalization($scenario, '2026-05', 10);
    $payment = $may->payments()->sole();
    $paymentBefore = $payment->only(['id', 'measurement_id', 'plan_set_id', 'amount', 'pay_date']);
    $snapshotHash = MeasurementRevisionService::canonicalSnapshotHash($may->engineering_snapshot);
    $finalizationReview = $may->reviews()->where('stage', MeasurementWorkflow::STAGE_FINALIZATION)->sole();
    $revision = Scenario::submit($scenario, Scenario::revise($scenario, $may, 'Percentual de maio subestimado.'));

    // A revisão passa a valer no dia seguinte ao envio.
    $this->travelTo(revisionPaidSourceBusinessNoon('2026-10-06'));
    MeasurementPhysicalProgressScenario::approveEngineering($scenario, $revision, 12);
    Scenario::approveManagementAndCompliance($scenario, $revision);
    $may->refresh();
    $revision->refresh();
    $closedReview = $finalizationReview->fresh();
    $closed = revisionPaidSourceActivity($may, 'measurement_workflow_closed_by_revision');
    $line = $scenario['lines']['2026-05']->fresh();
    $position = revisionPaidSourcePosition($revision);
    $workflow = app(MeasurementWorkflow::class);

    expect($revision->revisionStatus())->toBe(MeasurementRevisionStatus::Effective)
        ->and($revision->status)->toBe('awaiting_payment')
        ->and($revision->current_stage)->toBe(MeasurementWorkflow::STAGE_PAYMENT)
        ->and($may->revisionStatus())->toBe(MeasurementRevisionStatus::Superseded)
        ->and($may->status)->toBe('superseded')
        ->and($may->current_stage)->toBe(MeasurementWorkflow::STAGE_FINALIZATION)
        ->and($may->analyzed_by)->toBe($scenario['actor']->id)
        ->and($workflow->canFinalize($may, $scenario['finalizer']))->toBeFalse()
        ->and($workflow->canReturnFromFinalization($may, $scenario['finalizer']))->toBeFalse()
        ->and($workflow->pendingResponsibilities($may))->toBe([])
        // Encerrada, a R0 não recebe outro pagamento nem reavalia o
        // enquadramento do que pagou: a posição da família é a da R1.
        ->and(revisionPaidSourceRefusal(fn () => $workflow->registerPayment($may->fresh(), $scenario['actor'], [
            'plan_set_id' => $scenario['planSet']->id,
            'pay_date' => '2026-10-06',
            'amount' => '1000.00',
            'method' => 'TED',
        ])))->toBe('A etapa desta medição foi alterada por outra ação. Atualize a página.')
        ->and(revisionPaidSourceRefusal(fn () => $workflow->reassessPayment($payment->fresh(), $scenario['actor'], [
            'financial_justification' => 'Reavaliar depois da substituição.',
        ], (int) $may->fresh()->workflow_revision)))->toBe('A etapa desta medição foi alterada por outra ação. Atualize a página.')
        // O registro da Engenharia da R0 não muda, e a R1 herda a versão do
        // plano e a competência dele.
        ->and(MeasurementRevisionService::canonicalSnapshotHash($may->engineering_snapshot))->toBe($snapshotHash)
        ->and($revision->previous_snapshot_sha256)->toBe($snapshotHash)
        ->and($revision->engineering_snapshot['reference_month'])->toBe($may->engineering_snapshot['reference_month'])
        ->and($revision->engineering_snapshot['plan_sets'][0]['plan_version_id'])->toBe($may->engineering_snapshot['plan_sets'][0]['plan_version_id'])
        ->and($revision->engineering_snapshot['plan_sets'][0]['plan_line_id'])->toBe($may->engineering_snapshot['plan_sets'][0]['plan_line_id'])
        // A análise da Finalização fica encerrada sem decisão: nem aprovada,
        // nem recusada, sem revisor -- e nunca mais muda.
        ->and($closedReview->status)->toBe(MeasurementReview::STATUS_SUPERSEDED)
        ->and($closedReview->reviewer_user_id)->toBeNull()
        ->and($closedReview->reviewed_at->equalTo(now()))->toBeTrue()
        ->and($closedReview->notes)->toBe('Encerrada pela revisão R1, que passou a valer na aprovação da Compliance em 06/10/2026: a etapa não foi decidida nesta medição.')
        ->and(fn () => $closedReview->fresh()->forceFill(['status' => 'pending'])->save())->toThrow(MeasurementWorkflowException::class)
        ->and($may->reviews()->whereIn('stage', [1, 2, 3, 4])->pluck('status')->unique()->values()->all())->toBe(['approved'])
        // A ocupação da linha e o cronograma passam para a revisão.
        ->and($revision->assets()->sole()->line_claim_key)->toBe($line->lineage_key)
        ->and($may->assets()->sole()->line_claim_key)->toBeNull()
        ->and($line->measurement_id)->toBe($revision->id)
        ->and((string) $line->realized_monthly_percent)->toBe('12.00')
        // Os pagamentos da R0 ficam com ela, intactos, e viram o histórico da
        // R1: nada é copiado.
        ->and($payment->fresh()->only(['id', 'measurement_id', 'plan_set_id', 'amount', 'pay_date']))->toEqual($paymentBefore)
        ->and($payment->fresh()->amount)->toBe('100000.00')
        ->and(MeasurementPayment::query()->pluck('id')->all())->toBe([$payment->id])
        ->and($revision->payments()->exists())->toBeFalse()
        ->and($position->historicalPaidAmount)->toBe('100000.00')
        ->and($position->ownPaidAmount)->toBe('0.00')
        ->and($position->previousApprovedAmount)->toBe('100000.00')
        ->and($position->revisedApprovedAmount)->toBe('120000.00')
        ->and($position->financialDifferenceAmount)->toBe('20000.00')
        ->and($position->remainingPayableAmount)->toBe('20000.00')
        ->and($position->outstandingOverpaymentAmount)->toBe('0.00')
        ->and($position->settledMeasurementId)->toBeNull()
        ->and($position->adjustmentStatus)->toBe(MeasurementRevisionAdjustmentStatus::AwaitingSupplementaryPayment)
        // O encerramento na trilha protegida.
        ->and($closed->properties['stage'])->toBe(MeasurementWorkflow::STAGE_FINALIZATION)
        ->and($closed->properties['from_status'])->toBe('approved')
        ->and($closed->properties['to_status'])->toBe('superseded')
        ->and($closed->properties['superseding_measurement_id'])->toBe($revision->id)
        ->and($closed->properties['closed_reviews'])->toHaveCount(1)
        ->and($closed->properties['closed_reviews'][0]['review_id'])->toBe($finalizationReview->id)
        ->and($closed->properties['closed_reviews'][0]['stage'])->toBe(MeasurementWorkflow::STAGE_FINALIZATION)
        ->and($closed->properties['historical_payments']['count'])->toBe(1)
        ->and($closed->properties['historical_payments']['total_amount'])->toBe('100000.00')
        ->and($closed->properties['historical_payments']['payment_ids'])->toBe([$payment->id]);

    // O intervalo em que a Finalização ficou suspensa não é tempo ativo dela.
    $pause = MeasurementPause::query()->where('measurement_id', $may->id)->sole();

    expect($pause->stage)->toBe(MeasurementWorkflow::STAGE_FINALIZATION)
        ->and($pause->paused_operation_status)->toBe('approved')
        ->and($pause->paused_at->equalTo($revision->uploaded_at))->toBeTrue()
        ->and($pause->resumed_at->equalTo(now()))->toBeTrue();

    // O que as telas leem: a R1 parte de uma medição paga ainda não
    // finalizada, e a R0 aponta para a revisão que vale e a posição dela.
    $revisions = app(MeasurementRevisionService::class);
    $sourceOverview = $revisions->overview($may);

    expect($revisions->overview($revision)['paid_source'])->toBe([
        'label' => 'R0',
        'finalized' => false,
        'paid_amount' => '100000.00',
        'payments_count' => 1,
    ])
        ->and($sourceOverview['frozen_by'])->toBeNull()
        ->and($sourceOverview['superseded_by']['label'])->toBe('R1')
        ->and($sourceOverview['superseded_by']['at'])->toBe('06/10/2026')
        ->and($sourceOverview['family_position']['label'])->toBe('R1')
        ->and($sourceOverview['family_position']['positions'][0]->remainingPayableAmount)->toBe('20000.00');

    Notification::assertSentTo(
        $scenario['actor'],
        MeasurementWorkflowNotification::class,
        fn (MeasurementWorkflowNotification $notification): bool => $notification->event === 'paid_workflow_closed_by_revision'
            && (int) $notification->measurement->getKey() === $may->id,
    );
    Notification::assertNotSentTo(
        $scenario['actor'],
        MeasurementWorkflowNotification::class,
        fn (MeasurementWorkflowNotification $notification): bool => $notification->event === 'workflow_closed_by_revision',
    );
});

it('takes the family overpayment of the revision of a paid source to a decision at the Payment stage and to the Finalizer acceptance, without a new payment', function () {
    $scenario = Scenario::plan();
    $may = Scenario::paidAwaitingFinalization($scenario, '2026-05', 10);
    $revision = Scenario::effective($scenario, $may, 8, 'Percentual de maio superestimado.');
    $workflow = app(MeasurementWorkflow::class);
    $position = revisionPaidSourcePosition($revision);

    // A R0 nunca foi finalizada: nenhum reconhecimento anterior cobre o valor
    // pago a maior.
    expect($position->historicalPaidAmount)->toBe('100000.00')
        ->and($position->revisedApprovedAmount)->toBe('80000.00')
        ->and($position->outstandingOverpaymentAmount)->toBe('20000.00')
        ->and($position->previouslyAcknowledgedOverpaymentAmount)->toBe('0.00')
        ->and($position->unacknowledgedOverpaymentAmount)->toBe('20000.00')
        ->and($position->remainingPayableAmount)->toBe('0.00')
        ->and($position->settledMeasurementId)->toBeNull()
        ->and($position->adjustmentStatus)->toBe(MeasurementRevisionAdjustmentStatus::OverpaymentPendingDecision)
        ->and(revisionPaidSourceValidationMessage(fn () => Scenario::pay($scenario, $revision, '1000.00'), 'payments.0.amount'))
        ->toContain('nenhum novo pagamento é registrado enquanto houver valor pago a maior em aberto')
        ->and(revisionPaidSourceValidationMessage(fn () => $workflow->approve($revision->fresh(), $scenario['actor']), 'notes'))
        ->toContain('Registre a decisão financeira sobre o valor pago a maior');

    $decision = 'Valor pago a maior em maio será tratado com a construtora fora do sistema.';
    $workflow->approve($revision->fresh(), $scenario['actor'], $decision);

    expect($revision->fresh()->status)->toBe('approved')
        ->and($revision->payments()->exists())->toBeFalse()
        ->and(revisionPaidSourceValidationMessage(fn () => $workflow->finalize($revision->fresh(), $scenario['finalizer']), 'accept_financial_exceptions'))
        ->toContain('valor pago a maior desta revisão');

    $workflow->finalize($revision->fresh(), $scenario['finalizer'], acceptFinancialExceptions: true);
    $position = revisionPaidSourcePosition($revision);
    $acknowledged = revisionPaidSourceActivity($revision, 'measurement_revision_financial_difference_acknowledged');
    $unresolved = revisionPaidSourceActivity($revision, 'measurement_revision_financial_exception_unresolved');

    expect($revision->fresh()->status)->toBe('finalized')
        ->and($position->adjustmentStatus)->toBe(MeasurementRevisionAdjustmentStatus::OverpaymentAcceptedUnrecovered)
        ->and($position->adjustmentStatus->isAcknowledged())->toBeTrue()
        ->and($position->adjustmentStatus->isSettled())->toBeFalse()
        ->and($position->outstandingOverpaymentAmount)->toBe('20000.00')
        ->and($acknowledged->properties['positions'])->toHaveCount(1)
        ->and($acknowledged->properties['positions'][0]['acknowledged_now_overpayment_amount'])->toBe('20000.00')
        ->and($acknowledged->properties['decision'])->toBe($decision)
        ->and($acknowledged->properties['accepted_by'])->toBe($scenario['finalizer']->id)
        ->and($unresolved->properties['recovery_registered'])->toBeFalse()
        ->and($may->payments()->sole()->amount)->toBe('100000.00')
        ->and(MeasurementPayment::query()->count())->toBe(1);
});

it('pays only the remaining balance of the revision of a paid source and settles it only at the revision Finalization', function () {
    $scenario = Scenario::plan();
    $may = Scenario::paidAwaitingFinalization($scenario, '2026-05', 10);
    $snapshotHash = MeasurementRevisionService::canonicalSnapshotHash($may->engineering_snapshot);
    $revision = Scenario::effective($scenario, $may, 12);

    expect(revisionPaidSourceValidationMessage(fn () => Scenario::pay($scenario, $revision, '25000.00'), 'payments.0.amount'))
        ->toContain('passa do saldo em aberto da revisão')
        ->toContain('nem com regra financeira');

    $supplementary = Scenario::pay($scenario, $revision, '20000.00');
    $position = revisionPaidSourcePosition($revision);

    expect($position->ownPaidAmount)->toBe('20000.00')
        ->and($position->familyPaidAmount)->toBe('120000.00')
        ->and($position->remainingPayableAmount)->toBe('0.00')
        ->and($position->adjustmentStatus)->toBe(MeasurementRevisionAdjustmentStatus::SupplementaryPaymentRegistered)
        ->and($position->adjustmentStatus->isSettled())->toBeFalse();

    $finalized = Scenario::closePaymentAndFinalize($scenario, $revision);

    expect($finalized->status)->toBe('finalized')
        ->and(revisionPaidSourcePosition($finalized)->adjustmentStatus)->toBe(MeasurementRevisionAdjustmentStatus::SettledBySupplementaryPayment)
        ->and(MeasurementPayment::query()->where('measurement_id', $may->id)->pluck('amount')->all())->toBe(['100000.00'])
        ->and(MeasurementPayment::query()->where('measurement_id', $revision->id)->pluck('id')->all())->toBe([$supplementary->id])
        ->and($may->fresh()->status)->toBe('superseded')
        ->and(MeasurementRevisionService::canonicalSnapshotHash($may->fresh()->engineering_snapshot))->toBe($snapshotHash);
});

it('keeps a partial payment of the paid source as history and completes the balance of the revision in parts', function () {
    $scenario = Scenario::plan();
    $may = Scenario::paidAwaitingFinalization($scenario, '2026-05', 10, '60000.00', 'Retenção contratual de 40% até a vistoria final.');
    $payment = $may->payments()->sole();

    expect($may->status)->toBe('approved')
        ->and($payment->financial_assessment['requires_acceptance'])->toBeTrue()
        ->and(app(MeasurementRevisionService::class)->creationBlockReason($may))->toBeNull();

    $revision = Scenario::effective($scenario, $may, 8);
    $position = revisionPaidSourcePosition($revision);

    expect($position->historicalPaidAmount)->toBe('60000.00')
        ->and($position->revisedApprovedAmount)->toBe('80000.00')
        ->and($position->remainingPayableAmount)->toBe('20000.00')
        ->and($position->outstandingOverpaymentAmount)->toBe('0.00')
        ->and($position->adjustmentStatus)->toBe(MeasurementRevisionAdjustmentStatus::AwaitingSupplementaryPayment);

    Scenario::pay($scenario, $revision, '10000.00', justification: 'Primeira parcela do complemento.');
    $position = revisionPaidSourcePosition($revision);

    expect($position->remainingPayableAmount)->toBe('10000.00')
        ->and($position->adjustmentStatus)->toBe(MeasurementRevisionAdjustmentStatus::PartialSupplementaryPayment);

    Scenario::pay($scenario, $revision, '10000.00');
    $position = revisionPaidSourcePosition($revision);

    expect($position->remainingPayableAmount)->toBe('0.00')
        ->and($position->adjustmentStatus)->toBe(MeasurementRevisionAdjustmentStatus::SupplementaryPaymentRegistered);

    $finalized = Scenario::closePaymentAndFinalize($scenario, $revision, acceptFinancialExceptions: true);
    $position = revisionPaidSourcePosition($finalized);

    expect($finalized->status)->toBe('finalized')
        ->and($position->adjustmentStatus)->toBe(MeasurementRevisionAdjustmentStatus::SettledBySupplementaryPayment)
        ->and($position->familyPaidAmount)->toBe('80000.00')
        ->and($may->payments()->pluck('amount')->all())->toBe(['60000.00'])
        ->and($revision->payments()->orderBy('id')->pluck('amount')->all())->toBe(['10000.00', '10000.00'])
        ->and(MeasurementPayment::query()->count())->toBe(3);
});

it('counts every installment of the paid source as history of the revision', function () {
    $scenario = Scenario::plan();
    $may = Scenario::awaitingPayment($scenario, '2026-05', 10);
    $workflow = app(MeasurementWorkflow::class);
    $receipts = app(MeasurementReceiptEvidenceService::class);
    $revisions = app(MeasurementRevisionService::class);
    [$first, $second] = $workflow->registerPayments($may->fresh(), $scenario['actor'], [
        ['plan_set_id' => $scenario['planSet']->id, 'pay_date' => '2026-07-10', 'amount' => '40000.00', 'method' => 'TED', 'financial_justification' => 'Primeira parcela; o restante sai no dia 20.'],
        ['plan_set_id' => $scenario['planSet']->id, 'pay_date' => '2026-07-20', 'amount' => '60000.00', 'method' => 'TED'],
    ])->all();
    $workflow->approve($may->fresh(), $scenario['actor']);

    $evidence = $receipts->upload($first->fresh(), $scenario['actor'], ReceiptScenario::file('parcela-1.pdf', '%PDF-1.7 parcela 1'));
    $receipts->review($evidence, $scenario['finalizer'], MeasurementReceiptReviewStatus::Approved, true);

    // A segunda parcela ainda sem comprovante.
    expect($may->fresh()->status)->toBe('awaiting_receipt')
        ->and($revisions->creationBlockReason($may->fresh()))->toBe(MeasurementRevisionService::PAID_RECEIPTS_PENDING_REFUSAL);

    $evidence = $receipts->upload($second->fresh(), $scenario['actor'], ReceiptScenario::file('parcela-2.pdf', '%PDF-1.7 parcela 2'));
    $receipts->review($evidence, $scenario['finalizer'], MeasurementReceiptReviewStatus::Approved, true);

    expect($may->fresh()->status)->toBe('approved');

    $draft = Scenario::revise($scenario, $may);
    $created = revisionPaidSourceActivity($draft, 'measurement_revision_created');

    expect($created->properties['source_payments']['count'])->toBe(2)
        ->and($created->properties['source_payments']['total_amount'])->toBe('100000.00')
        ->and($created->properties['source_payments']['payment_ids'])->toBe([$first->id, $second->id]);

    MeasurementPhysicalProgressScenario::approveEngineering($scenario, Scenario::submit($scenario, $draft), 11);
    Scenario::approveManagementAndCompliance($scenario, $draft);
    $position = revisionPaidSourcePosition($draft);

    // As duas parcelas somam o histórico: o saldo da revisão é só o que passa
    // dos 100 mil já pagos.
    expect($position->historicalPaidAmount)->toBe('100000.00')
        ->and($position->revisedApprovedAmount)->toBe('110000.00')
        ->and($position->remainingPayableAmount)->toBe('10000.00')
        ->and($position->adjustmentStatus)->toBe(MeasurementRevisionAdjustmentStatus::AwaitingSupplementaryPayment)
        ->and($may->payments()->orderBy('id')->pluck('amount')->all())->toBe(['40000.00', '60000.00'])
        ->and($draft->payments()->exists())->toBeFalse();
});

it('accepts receipt corrections on the paid source closed by the revision and lists them as pending work', function () {
    $scenario = Scenario::plan();
    $may = Scenario::paidAwaitingFinalization($scenario, '2026-05', 10);
    $payment = $may->payments()->sole();
    $original = $payment->currentReceiptEvidence;
    $revision = Scenario::effective($scenario, $may, 12);
    $receipts = app(MeasurementReceiptEvidenceService::class);
    $workflow = app(MeasurementWorkflow::class);
    $workflowRevision = $may->fresh()->workflow_revision;

    expect($may->fresh()->status)->toBe('superseded')
        ->and($receipts->canUpload($may->fresh(), $scenario['actor']))->toBeTrue()
        ->and(Measurement::query()->withPendingWork()->whereKey($may->id)->exists())->toBeFalse()
        ->and(revisionPaidSourceRefusal(fn () => $receipts->upload($payment->fresh(), $scenario['actor'], ReceiptScenario::file(), $original->id, 'Nova versão.')))
        ->toBe('Use a ação Corrigir comprovante para uma medição finalizada ou encerrada por revisão.');

    $correction = $receipts->correctFinalizedReceipt(
        $payment->fresh(),
        $scenario['actor'],
        ReceiptScenario::file('comprovante reemitido.pdf', '%PDF-1.7 comprovante reemitido'),
        'O banco reemitiu o comprovante com o CNPJ correto.',
        $original->id,
    );
    $source = $may->fresh();

    expect($correction->version)->toBe(2)
        ->and($correction->supersedes_id)->toBe($original->id)
        ->and($correction->is_post_finalization)->toBeTrue()
        ->and($correction->review_status)->toBe(MeasurementReceiptReviewStatus::Pending)
        ->and($source->status)->toBe('superseded')
        ->and($source->workflow_revision)->toBe($workflowRevision)
        ->and(Measurement::query()->withPendingWork()->whereKey($may->id)->exists())->toBeTrue()
        ->and($workflow->pendingResponsibilities($source))->toBe([MeasurementResponsibility::Finalizer])
        ->and($receipts->documentaryStatus($source))->toBe('Correção documental pendente')
        ->and(Activity::query()
            ->where('log_name', 'measurement_evidence')
            ->where('description', 'receipt_evidence_corrected_after_finalization')
            ->where('subject_id', $correction->id)
            ->exists())->toBeTrue();

    $receipts->review($correction, $scenario['finalizer'], MeasurementReceiptReviewStatus::Approved, true);
    $source = $may->fresh();

    expect($source->status)->toBe('superseded')
        ->and(Measurement::query()->withPendingWork()->whereKey($may->id)->exists())->toBeFalse()
        ->and($workflow->pendingResponsibilities($source))->toBe([])
        ->and($receipts->documentaryStatus($source))->toBe('Correção documental regularizada')
        // O comprovante corrigido não muda o que a família pagou.
        ->and(revisionPaidSourcePosition($revision)->historicalPaidAmount)->toBe('100000.00')
        ->and($payment->fresh()->amount)->toBe('100000.00');
});

it('refuses to submit the draft while the receipt of the paid source is back under review, keeps the draft valid and submits it once the receipt is approved', function () {
    $scenario = Scenario::plan();
    $may = Scenario::paidAwaitingFinalization($scenario, '2026-05', 10);
    $payment = $may->payments()->sole();
    $draft = Scenario::revise($scenario, $may);
    $receipts = app(MeasurementReceiptEvidenceService::class);

    // O rascunho não suspende nada: a medição paga recebe uma nova versão do
    // comprovante e volta à conferência documental.
    $replacement = $receipts->upload(
        $payment->fresh(),
        $scenario['actor'],
        ReceiptScenario::file('comprovante legível.pdf', '%PDF-1.7 comprovante legível'),
        $payment->currentReceiptEvidence->id,
        'A primeira versão estava ilegível.',
    );

    expect($may->fresh()->status)->toBe('awaiting_receipt')
        ->and(revisionPaidSourceRefusal(fn () => Scenario::submit($scenario, $draft)))->toBe(sprintf(
            'A revisão R0 mudou desde a criação deste rascunho e não pode mais ser revisada assim: %s O rascunho continua válido: envie-o de novo quando a R0 puder ser revisada.',
            MeasurementRevisionService::PAID_RECEIPTS_PENDING_REFUSAL,
        ))
        ->and($draft->fresh()->revisionStatus())->toBe(MeasurementRevisionStatus::Draft)
        ->and($draft->fresh()->status)->toBe('pending')
        ->and($draft->fresh()->uploaded_at)->toBeNull()
        ->and($may->fresh()->isFrozenByRevision())->toBeFalse()
        ->and(Activity::query()->where('description', 'measurement_revision_submitted')->exists())->toBeFalse();

    $receipts->review($replacement, $scenario['finalizer'], MeasurementReceiptReviewStatus::Approved, true);
    $revision = Scenario::submit($scenario, $draft);

    expect($may->fresh()->status)->toBe('approved')
        ->and($revision->revisionStatus())->toBe(MeasurementRevisionStatus::UnderReview)
        ->and($revision->status)->toBe('in_review')
        ->and($may->fresh()->isFrozenByRevision())->toBeTrue()
        ->and(revisionPaidSourceActivity($revision, 'measurement_revision_submitted')->properties['source_eligibility'])->toBe('paid_awaiting_finalization');
});
