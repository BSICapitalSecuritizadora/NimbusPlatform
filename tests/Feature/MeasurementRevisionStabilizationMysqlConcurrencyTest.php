<?php

use App\Enums\MeasurementReceiptReviewStatus;
use App\Enums\MeasurementRevisionStatus;
use App\Exceptions\MeasurementWorkflowException;
use App\Models\Measurement;
use App\Models\MeasurementPause;
use App\Models\MeasurementPayment;
use App\Models\MeasurementPaymentReceiptEvidence;
use App\Models\MeasurementReview;
use App\Services\MeasurementReceiptEvidenceService;
use App\Services\MeasurementRevisionService;
use App\Services\MeasurementWorkflow;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\CommittedRowsSweeper;
use Tests\Support\MeasurementReceiptEvidenceScenario;
use Tests\Support\MeasurementRevisionRaceTasks as RaceTasks;
use Tests\Support\MeasurementRevisionScenario as Scenario;

/*
 * A revisão da medição paga (caso C), a suspensão da vigente com o fluxo
 * aberto, a recusa que a devolve ao fluxo e a vigência que o encerra, contra o
 * MySQL de verdade, com transações em processos próprios.
 *
 * Quem cria, envia, recusa ou aprova a revisão, e quem finaliza, trava a
 * Operation primeiro e depois a medição; quem envia ou confere comprovante, ou
 * decide as etapas 2 a 4 da vigente, trava só a medição. Entre dois caminhos
 * que travam a Operation a corrida é nela; entre um de cada tipo, é na linha da
 * medição -- e a fotografia (REPEATABLE READ) de quem trava a Operation nasce
 * antes de esperar a medição: o envio confere a vigente travada contra a
 * leitura comum dela e recusa, em vez de decidir sobre a fotografia velha.
 * Nenhuma ordem deixa a vigente suspensa receber comprovante ou ser
 * finalizada, nem uma vigente substituída sem a revisão vigente. O SQLite
 * serializa escritores e nunca mostra nada disso.
 */
pest()->group('mysql');

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
    Storage::fake('local');
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
 * Roda as duas ações em processos próprios: a primeira segura por 750 ms o
 * primeiro lock que pedir em `$table` -- a tabela em que as duas disputam --,
 * e a segunda só começa depois disso.
 *
 * @param  array<string, mixed>  $first
 * @param  array<string, mixed>  $second
 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
 */
function p31Race(array $scenario, array $first, array $second, string $table): array
{
    $marker = temporaryTestFilePath('p31-race-lock', 'lock');
    @unlink($marker);
    $base = [
        'actor_id' => $scenario['actor']->id,
        'storage_root' => Storage::disk('local')->path(''),
        'lock_table' => $table,
    ];

    $results = Concurrency::driver('process')->run([
        p31RaceTask(['lock_marker' => $marker] + $first + $base),
        p31RaceTask(['wait_for_marker' => $marker] + $second + $base),
    ]);
    @unlink($marker);

    return $results;
}

/**
 * @param  array<string, mixed>  $instruction  só escalares: a closure vai serializada para o processo filho
 */
function p31RaceTask(array $instruction): Closure
{
    return static function () use ($instruction): array {
        return RaceTasks::run($instruction);
    };
}

/**
 * O motivo da recusa de um processo filho, para a falha dizer o que houve.
 *
 * @param  array<string, mixed>  $result
 */
function p31RaceReason(array $result): string
{
    return "{$result['exception']}: {$result['message']}";
}

/**
 * @return array<string, mixed>
 */
function p31RaceCreate(Measurement $source): array
{
    return ['action' => 'create_revision', 'measurement_id' => $source->id];
}

/**
 * O envio do rascunho por quem o criou -- o Finalizador, nos rascunhos de
 * {@see Scenario::revise()} --: a Compliance do ator fica independente da
 * revisão (Fase 4B-1).
 *
 * @param  array<string, mixed>  $scenario
 * @return array<string, mixed>
 */
function p31RaceSubmit(array $scenario, Measurement $draft): array
{
    return ['action' => 'submit_revision', 'actor_id' => $scenario['finalizer']->id, 'measurement_id' => $draft->id];
}

/**
 * A decisão da etapa pendente, com a etapa e a versão que a tela leu.
 *
 * @return array<string, mixed>
 */
function p31RaceDecision(string $action, Measurement $measurement): array
{
    $measurement = $measurement->fresh();

    return [
        'action' => $action,
        'measurement_id' => $measurement->id,
        'stage' => app(MeasurementWorkflow::class)->unifiedStage($measurement),
        'revision' => (int) $measurement->workflow_revision,
    ];
}

/**
 * A Finalização pelo Finalizador (Fase 4B-1: o ator não finaliza o que fez).
 *
 * @param  array<string, mixed>  $scenario
 * @return array<string, mixed>
 */
function p31RaceFinalize(array $scenario, Measurement $measurement): array
{
    $measurement = $measurement->fresh();

    return [
        'action' => 'finalize',
        'actor_id' => $scenario['finalizer']->id,
        'measurement_id' => $measurement->id,
        'revision' => (int) $measurement->workflow_revision,
        'status' => $measurement->status,
    ];
}

/**
 * Nova versão do comprovante do pagamento, sobre a versão atual que a tela leu.
 *
 * @return array<string, mixed>
 */
function p31RaceReceiptUpload(MeasurementPayment $payment): array
{
    return [
        'action' => 'upload_receipt',
        'payment_id' => $payment->id,
        'evidence_id' => $payment->fresh()->currentReceiptEvidence?->id,
    ];
}

/**
 * A aprovação do comprovante pelo Finalizador: quem o enviou -- o ator -- não o
 * aprova (Fase 4B-1).
 *
 * @param  array<string, mixed>  $scenario
 * @return array<string, mixed>
 */
function p31RaceReceiptApproval(array $scenario, MeasurementPaymentReceiptEvidence $evidence): array
{
    return ['action' => 'approve_receipt', 'actor_id' => $scenario['finalizer']->id, 'evidence_id' => $evidence->id];
}

/**
 * Os eventos da trilha protegida da medição com esta descrição, na ordem.
 *
 * @return Collection<int, Activity>
 */
function p31RaceEvents(Measurement $measurement, string $description): Collection
{
    return Activity::query()
        ->where('log_name', 'measurement_workflow')
        ->where('subject_type', $measurement->getMorphClass())
        ->where('subject_id', $measurement->getKey())
        ->where('description', $description)
        ->orderBy('id')
        ->get();
}

/**
 * Os comprovantes gravados no disco falso, pelo pai ou pelos filhos.
 *
 * @return list<string>
 */
function p31RaceReceiptFiles(): array
{
    return Storage::disk('local')->allFiles('nimbus_docs/measurements/receipts');
}

it('creates a single R1 when two people revise the same paid measurement awaiting finalization at the same time on MySQL', function () {
    $scenario = Scenario::plan();
    $may = Scenario::paidAwaitingFinalization($scenario, '2026-05', 10);
    $create = p31RaceCreate($may);

    [$winner, $loser] = p31Race($scenario, $create, $create, RaceTasks::OPERATIONS);

    expect($winner['success'])->toBeTrue(p31RaceReason($winner))
        ->and($loser['success'])->toBeFalse()
        ->and($loser['exception'])->toBe(MeasurementWorkflowException::class)
        ->and($loser['message'])->toContain('já tem a revisão R1 em andamento')
        ->and($loser['lock_wait_ms'])->toBeGreaterThan(250);

    $revision = Measurement::query()->findOrFail($winner['result']);

    expect(Measurement::query()->where('revision_family_id', $may->id)->orderBy('revision_number')->pluck('revision_number')->all())->toBe([0, 1])
        ->and($revision->revisionStatus())->toBe(MeasurementRevisionStatus::Draft)
        ->and($revision->previous_revision_id)->toBe($may->id)
        ->and(p31RaceEvents($revision, 'measurement_revision_created')->sole()->properties['source_eligibility'])->toBe('paid_awaiting_finalization')
        // O rascunho não suspende a vigente paga, e os pagamentos ficam com ela.
        ->and($may->fresh()->status)->toBe('approved')
        ->and($may->fresh()->isFrozenByRevision())->toBeFalse()
        ->and($may->payments()->count())->toBe(1)
        ->and($revision->payments()->count())->toBe(0);
});

it('refuses the submission of a paid revision behind a receipt correction of the source and keeps the draft valid on MySQL', function () {
    $scenario = Scenario::plan();
    $may = Scenario::paidAwaitingFinalization($scenario, '2026-05', 10);
    $draft = Scenario::revise($scenario, $may);
    $payment = $may->payments()->sole();
    $approvedReceipt = $payment->currentReceiptEvidence;

    [$upload, $submission] = p31Race($scenario, p31RaceReceiptUpload($payment), p31RaceSubmit($scenario, $draft), RaceTasks::MEASUREMENTS);

    expect($upload['success'])->toBeTrue(p31RaceReason($upload))
        ->and($submission['success'])->toBeFalse()
        ->and($submission['exception'])->toBe(MeasurementWorkflowException::class)
        // A fotografia do envio nasceu antes do commit da correção, enquanto ele
        // esperava a linha da vigente: a vigente travada não confere com a
        // leitura comum dela, e o envio é recusado em vez de decidido sobre ela.
        ->and($submission['message'])->toBe(MeasurementRevisionService::STALE_REVISION_REFUSAL)
        ->and($submission['lock_wait_ms'])->toBeGreaterThan(250);

    $current = $payment->fresh()->currentReceiptEvidence;

    expect($draft->fresh()->revisionStatus())->toBe(MeasurementRevisionStatus::Draft)
        ->and(p31RaceEvents($draft, 'measurement_revision_submitted'))->toBeEmpty()
        ->and($may->fresh()->status)->toBe('awaiting_receipt')
        ->and($may->fresh()->isFrozenByRevision())->toBeFalse()
        ->and($current->id)->toBe($upload['result'])
        ->and($current->version)->toBe(2)
        ->and($current->review_status)->toBe(MeasurementReceiptReviewStatus::Pending)
        ->and($approvedReceipt->fresh()->review_status)->toBe(MeasurementReceiptReviewStatus::Approved);

    // Enviado de novo, já sem corrida: a vigente com o comprovante em
    // conferência não é revisada assim, e o rascunho continua de pé.
    expect(fn () => Scenario::submit($scenario, $draft))->toThrow(function (MeasurementWorkflowException $exception) {
        expect($exception->getMessage())->toContain(MeasurementRevisionService::PAID_RECEIPTS_PENDING_REFUSAL)
            ->and($exception->getMessage())->toContain('O rascunho continua válido');
    });

    // Fase 4B-1: o ator enviou a correção; quem a aprova é o Finalizador.
    MeasurementReceiptEvidenceScenario::approveCurrentReceipt($payment, $scenario['finalizer']);

    expect(Scenario::submit($scenario, $draft)->revisionStatus())->toBe(MeasurementRevisionStatus::UnderReview)
        ->and($may->fresh()->isFrozenByRevision())->toBeTrue();
});

it('refuses a receipt correction of the paid source while its revision is submitted, without leaving the file behind, on MySQL', function () {
    $scenario = Scenario::plan();
    $may = Scenario::paidAwaitingFinalization($scenario, '2026-05', 10);
    $draft = Scenario::revise($scenario, $may);
    $payment = $may->payments()->sole();
    $filesBefore = p31RaceReceiptFiles();

    [$submission, $upload] = p31Race($scenario, p31RaceSubmit($scenario, $draft), p31RaceReceiptUpload($payment), RaceTasks::MEASUREMENTS);

    expect($submission['success'])->toBeTrue(p31RaceReason($submission))
        ->and($upload['success'])->toBeFalse()
        ->and($upload['exception'])->toBe(MeasurementWorkflowException::class)
        ->and($upload['message'])->toBe(sprintf(MeasurementRevisionService::FROZEN_WORKFLOW_REFUSAL, 'R1'))
        ->and($upload['lock_wait_ms'])->toBeGreaterThan(250)
        ->and($draft->fresh()->revisionStatus())->toBe(MeasurementRevisionStatus::UnderReview)
        ->and($may->fresh()->status)->toBe('approved')
        ->and($may->fresh()->isFrozenByRevision())->toBeTrue()
        ->and($payment->receiptEvidences()->count())->toBe(1)
        ->and($payment->fresh()->currentReceiptEvidence->review_status)->toBe(MeasurementReceiptReviewStatus::Approved)
        // A suspensão é conferida sob o lock, antes de gravar o arquivo.
        ->and($filesBefore)->toHaveCount(1)
        ->and(p31RaceReceiptFiles())->toBe($filesBefore);
});

it('lets a receipt correction waiting behind the rejection of the revision see the paid source back in its workflow on MySQL', function () {
    $scenario = Scenario::plan();
    $may = Scenario::paidAwaitingFinalization($scenario, '2026-05', 10);
    $revision = Scenario::submit($scenario, Scenario::revise($scenario, $may));
    $payment = $may->payments()->sole();

    [$rejection, $upload] = p31Race($scenario, p31RaceDecision('reject', $revision), p31RaceReceiptUpload($payment), RaceTasks::MEASUREMENTS);

    expect($rejection['success'])->toBeTrue(p31RaceReason($rejection))
        ->and($upload['success'])->toBeTrue(p31RaceReason($upload))
        ->and($upload['lock_wait_ms'])->toBeGreaterThan(250);

    $rejected = $revision->fresh();
    $pause = MeasurementPause::query()->where('measurement_id', $may->id)->sole();
    $current = $payment->fresh()->currentReceiptEvidence;

    expect($rejected->revisionStatus())->toBe(MeasurementRevisionStatus::Rejected)
        ->and($may->fresh()->revisionStatus())->toBe(MeasurementRevisionStatus::Effective)
        // A correção encontrou a vigente de volta ao fluxo, sem suspensão.
        ->and($current->id)->toBe($upload['result'])
        ->and($current->version)->toBe(2)
        ->and($current->review_status)->toBe(MeasurementReceiptReviewStatus::Pending)
        ->and($may->fresh()->status)->toBe('awaiting_receipt')
        ->and($pause->stage)->toBe(MeasurementWorkflow::STAGE_FINALIZATION)
        ->and($pause->paused_operation_status)->toBe('approved')
        ->and($pause->paused_at->equalTo($rejected->uploaded_at))->toBeTrue()
        ->and($pause->resumed_at)->not->toBeNull();
});

it('refuses a receipt correction of the frozen paid source and lets the waiting rejection of the revision resume it on MySQL', function () {
    $scenario = Scenario::plan();
    $may = Scenario::paidAwaitingFinalization($scenario, '2026-05', 10);
    $revision = Scenario::submit($scenario, Scenario::revise($scenario, $may));
    $payment = $may->payments()->sole();

    [$upload, $rejection] = p31Race($scenario, p31RaceReceiptUpload($payment), p31RaceDecision('reject', $revision), RaceTasks::MEASUREMENTS);

    expect($upload['success'])->toBeFalse()
        ->and($upload['exception'])->toBe(MeasurementWorkflowException::class)
        ->and($upload['message'])->toBe(sprintf(MeasurementRevisionService::FROZEN_WORKFLOW_REFUSAL, 'R1'))
        ->and($rejection['success'])->toBeTrue(p31RaceReason($rejection))
        ->and($rejection['lock_wait_ms'])->toBeGreaterThan(250)
        ->and($revision->fresh()->revisionStatus())->toBe(MeasurementRevisionStatus::Rejected)
        ->and($may->fresh()->status)->toBe('approved')
        ->and($may->fresh()->isFrozenByRevision())->toBeFalse()
        ->and($payment->receiptEvidences()->count())->toBe(1)
        ->and(p31RaceReceiptFiles())->toHaveCount(1)
        ->and(MeasurementPause::query()->where('measurement_id', $may->id)->where('stage', MeasurementWorkflow::STAGE_FINALIZATION)->whereNotNull('resumed_at')->count())->toBe(1)
        ->and(p31RaceEvents($may, 'measurement_revision_source_resumed'))->toHaveCount(1);
});

it('refuses the submission of a paid revision while the last receipt of the source is still in review and accepts it right after on MySQL', function () {
    $scenario = Scenario::plan();
    $may = Scenario::paidAwaitingFinalization($scenario, '2026-05', 10);
    $draft = Scenario::revise($scenario, $may);
    $payment = $may->payments()->sole();
    $pending = app(MeasurementReceiptEvidenceService::class)->upload(
        $payment->fresh(),
        $scenario['actor'],
        MeasurementReceiptEvidenceScenario::file('comprovante-reemitido.pdf', '%PDF-1.7 comprovante reemitido'),
        $payment->currentReceiptEvidence->id,
        'Comprovante reemitido pelo banco.',
    );

    [$submission, $approval] = p31Race($scenario, p31RaceSubmit($scenario, $draft), p31RaceReceiptApproval($scenario, $pending), RaceTasks::MEASUREMENTS);

    expect($submission['success'])->toBeFalse()
        ->and($submission['exception'])->toBe(MeasurementWorkflowException::class)
        ->and($submission['message'])->toContain(MeasurementRevisionService::PAID_RECEIPTS_PENDING_REFUSAL)
        ->and($submission['message'])->toContain('O rascunho continua válido')
        ->and($approval['success'])->toBeTrue(p31RaceReason($approval))
        ->and($approval['lock_wait_ms'])->toBeGreaterThan(250)
        ->and($draft->fresh()->revisionStatus())->toBe(MeasurementRevisionStatus::Draft)
        ->and($pending->fresh()->review_status)->toBe(MeasurementReceiptReviewStatus::Approved)
        ->and($may->fresh()->status)->toBe('approved');

    expect(Scenario::submit($scenario, $draft)->revisionStatus())->toBe(MeasurementRevisionStatus::UnderReview)
        ->and($may->fresh()->isFrozenByRevision())->toBeTrue();
});

it('refuses the submission of a paid revision that waited behind the approval of the last receipt of the source on MySQL', function () {
    $scenario = Scenario::plan();
    $may = Scenario::paidAwaitingFinalization($scenario, '2026-05', 10);
    $draft = Scenario::revise($scenario, $may);
    $payment = $may->payments()->sole();
    $pending = app(MeasurementReceiptEvidenceService::class)->upload(
        $payment->fresh(),
        $scenario['actor'],
        MeasurementReceiptEvidenceScenario::file('comprovante-reemitido.pdf', '%PDF-1.7 comprovante reemitido'),
        $payment->currentReceiptEvidence->id,
        'Comprovante reemitido pelo banco.',
    );

    [$approval, $submission] = p31Race($scenario, p31RaceReceiptApproval($scenario, $pending), p31RaceSubmit($scenario, $draft), RaceTasks::MEASUREMENTS);

    expect($approval['success'])->toBeTrue(p31RaceReason($approval))
        ->and($submission['success'])->toBeFalse()
        ->and($submission['exception'])->toBe(MeasurementWorkflowException::class)
        // O envio nunca decide sobre a fotografia de antes da aprovação: nem
        // aceita a vigente com o comprovante em conferência, nem a recusa por isso.
        ->and($submission['message'])->toBe(MeasurementRevisionService::STALE_REVISION_REFUSAL)
        ->and($submission['lock_wait_ms'])->toBeGreaterThan(250)
        ->and($draft->fresh()->revisionStatus())->toBe(MeasurementRevisionStatus::Draft)
        ->and($pending->fresh()->review_status)->toBe(MeasurementReceiptReviewStatus::Approved)
        ->and($may->fresh()->status)->toBe('approved');

    expect(Scenario::submit($scenario, $draft)->revisionStatus())->toBe(MeasurementRevisionStatus::UnderReview)
        ->and($may->fresh()->isFrozenByRevision())->toBeTrue();
});

it('submits the revision of a paid source finalized while the submission waited, as the revision of a finalized measurement, on MySQL', function () {
    $scenario = Scenario::plan();
    $may = Scenario::paidAwaitingFinalization($scenario, '2026-05', 10);
    $draft = Scenario::revise($scenario, $may);

    [$finalization, $submission] = p31Race($scenario, p31RaceFinalize($scenario, $may), p31RaceSubmit($scenario, $draft), RaceTasks::OPERATIONS);

    expect($finalization['success'])->toBeTrue(p31RaceReason($finalization))
        ->and($submission['success'])->toBeTrue(p31RaceReason($submission))
        ->and($submission['lock_wait_ms'])->toBeGreaterThan(250);

    $submitted = p31RaceEvents($draft, 'measurement_revision_submitted')->sole();

    expect($may->fresh()->status)->toBe('finalized')
        ->and($may->fresh()->isFrozenByRevision())->toBeFalse()
        ->and($draft->fresh()->revisionStatus())->toBe(MeasurementRevisionStatus::UnderReview)
        // Revisada como finalizada (caso A): não havia fluxo a suspender.
        ->and($submitted->properties['source_eligibility'])->toBe('finalized')
        ->and($submitted->properties['suspends_measurement_id'])->toBeNull()
        ->and($submitted->properties['suspended_source_status'])->toBeNull();
});

it('refuses to finalize the paid source once its revision was submitted first on MySQL', function () {
    $scenario = Scenario::plan();
    $may = Scenario::paidAwaitingFinalization($scenario, '2026-05', 10);
    $draft = Scenario::revise($scenario, $may);

    [$submission, $finalization] = p31Race($scenario, p31RaceSubmit($scenario, $draft), p31RaceFinalize($scenario, $may), RaceTasks::OPERATIONS);

    expect($submission['success'])->toBeTrue(p31RaceReason($submission))
        ->and($finalization['success'])->toBeFalse()
        ->and($finalization['exception'])->toBe(MeasurementWorkflowException::class)
        ->and($finalization['message'])->toBe(sprintf(MeasurementRevisionService::FROZEN_WORKFLOW_REFUSAL, 'R1'))
        ->and($finalization['lock_wait_ms'])->toBeGreaterThan(250);

    $submitted = p31RaceEvents($draft, 'measurement_revision_submitted')->sole();

    expect($may->fresh()->status)->toBe('approved')
        ->and($may->fresh()->isFrozenByRevision())->toBeTrue()
        ->and($may->reviews()->where('stage', MeasurementWorkflow::STAGE_FINALIZATION)->value('status'))->toBe('pending')
        ->and(p31RaceEvents($may, 'measurement_finalized'))->toBeEmpty()
        ->and($submitted->properties['source_eligibility'])->toBe('paid_awaiting_finalization')
        ->and($submitted->properties['suspends_measurement_id'])->toBe($may->id)
        ->and($submitted->properties['suspended_source_status'])->toBe('approved')
        ->and($submitted->properties['suspended_source_stage'])->toBe(MeasurementWorkflow::STAGE_FINALIZATION);
});

it('finalizes the paid source after the rejection of its revision, with the suspension recorded as a closed pause, on MySQL', function () {
    $scenario = Scenario::plan();
    $may = Scenario::paidAwaitingFinalization($scenario, '2026-05', 10);
    $revision = Scenario::submit($scenario, Scenario::revise($scenario, $may));

    [$rejection, $finalization] = p31Race($scenario, p31RaceDecision('reject', $revision), p31RaceFinalize($scenario, $may), RaceTasks::OPERATIONS);

    expect($rejection['success'])->toBeTrue(p31RaceReason($rejection))
        ->and($finalization['success'])->toBeTrue(p31RaceReason($finalization))
        ->and($finalization['lock_wait_ms'])->toBeGreaterThan(250);

    $rejected = $revision->fresh();
    $finalized = $may->fresh();
    $pause = MeasurementPause::query()->where('measurement_id', $may->id)->sole();

    expect($rejected->revisionStatus())->toBe(MeasurementRevisionStatus::Rejected)
        ->and($finalized->status)->toBe('finalized')
        ->and($finalized->revisionStatus())->toBe(MeasurementRevisionStatus::Effective)
        // A pausa cobre a suspensão: do envio da revisão até a recusa, antes da
        // Finalização que esperou por ela.
        ->and($pause->stage)->toBe(MeasurementWorkflow::STAGE_FINALIZATION)
        ->and($pause->paused_operation_status)->toBe('approved')
        ->and($pause->paused_at->equalTo($rejected->uploaded_at))->toBeTrue()
        ->and($pause->resumed_at->greaterThanOrEqualTo($rejected->revision_closed_at))->toBeTrue()
        ->and($pause->resumed_at->diffInSeconds($rejected->revision_closed_at, true))->toBeLessThanOrEqual(1)
        ->and($pause->resumed_at->lessThanOrEqualTo($finalized->analyzed_at))->toBeTrue();
});

it('refuses to finalize the frozen paid source and lets the waiting rejection of its revision resume it on MySQL', function () {
    $scenario = Scenario::plan();
    $may = Scenario::paidAwaitingFinalization($scenario, '2026-05', 10);
    $revision = Scenario::submit($scenario, Scenario::revise($scenario, $may));

    [$finalization, $rejection] = p31Race($scenario, p31RaceFinalize($scenario, $may), p31RaceDecision('reject', $revision), RaceTasks::OPERATIONS);

    expect($finalization['success'])->toBeFalse()
        ->and($finalization['exception'])->toBe(MeasurementWorkflowException::class)
        ->and($finalization['message'])->toBe(sprintf(MeasurementRevisionService::FROZEN_WORKFLOW_REFUSAL, 'R1'))
        ->and($rejection['success'])->toBeTrue(p31RaceReason($rejection))
        ->and($rejection['lock_wait_ms'])->toBeGreaterThan(250)
        ->and($revision->fresh()->revisionStatus())->toBe(MeasurementRevisionStatus::Rejected)
        ->and($may->fresh()->status)->toBe('approved')
        ->and($may->fresh()->isFrozenByRevision())->toBeFalse()
        ->and($may->reviews()->where('stage', MeasurementWorkflow::STAGE_FINALIZATION)->value('status'))->toBe('pending')
        ->and(p31RaceEvents($may, 'measurement_finalized'))->toBeEmpty()
        ->and(MeasurementPause::query()->where('measurement_id', $may->id)->where('stage', MeasurementWorkflow::STAGE_FINALIZATION)->whereNotNull('resumed_at')->count())->toBe(1);
});

it('never approves the Payment stage of the unpaid source while its revision becomes effective on MySQL', function (bool $effectivenessFirst) {
    $scenario = Scenario::plan();
    $may = Scenario::finalized($scenario, '2026-05', 10);
    // A R1 vigente parada na etapa Pagamento sem pagamento próprio (caso B):
    // a família já pagou o que ela aprova, e a etapa dela passaria sem
    // pagamento novo se a R2 não a suspendesse.
    $source = Scenario::effective($scenario, $may, 10);
    $revision = Scenario::engineeringApproved($scenario, $source, 9);
    app(MeasurementWorkflow::class)->approve($revision->fresh(), $scenario['actor']);
    $effectiveness = p31RaceDecision('approve', $revision);
    $sourceApproval = p31RaceDecision('approve', $source);

    $results = $effectivenessFirst
        ? p31Race($scenario, $effectiveness, $sourceApproval, RaceTasks::MEASUREMENTS)
        : p31Race($scenario, $sourceApproval, $effectiveness, RaceTasks::MEASUREMENTS);
    [$compliance, $paymentStage] = $effectivenessFirst ? $results : array_reverse($results);

    expect($compliance['success'])->toBeTrue(p31RaceReason($compliance))
        ->and($paymentStage['success'])->toBeFalse()
        ->and($paymentStage['exception'])->toBe(MeasurementWorkflowException::class)
        ->and($paymentStage['message'])->toBe($effectivenessFirst
            ? 'A etapa desta medição foi alterada por outra ação. Atualize a página.'
            : sprintf(MeasurementRevisionService::FROZEN_WORKFLOW_REFUSAL, 'R2'))
        ->and($results[1]['lock_wait_ms'])->toBeGreaterThan(250);

    $closedReview = $source->reviews()->where('stage', MeasurementWorkflow::STAGE_PAYMENT)->sole();

    // Nenhuma transação deixa a vigente substituída sem a revisão vigente, nem
    // a análise da etapa Pagamento decidida.
    expect($revision->fresh()->revisionStatus())->toBe(MeasurementRevisionStatus::Effective)
        ->and($source->fresh()->revisionStatus())->toBe(MeasurementRevisionStatus::Superseded)
        ->and($source->fresh()->status)->toBe('superseded')
        ->and($closedReview->status)->toBe(MeasurementReview::STATUS_SUPERSEDED)
        ->and($closedReview->reviewer_user_id)->toBeNull()
        ->and(p31RaceEvents($source, 'measurement_stage_approved')->filter(
            fn (Activity $event): bool => (int) $event->properties['stage'] === MeasurementWorkflow::STAGE_PAYMENT,
        ))->toBeEmpty()
        ->and(p31RaceEvents($source, 'measurement_workflow_closed_by_revision')->sole()->properties['stage'])->toBe(MeasurementWorkflow::STAGE_PAYMENT)
        ->and($source->payments()->count())->toBe(0);
})->with([
    'effectiveness first' => [true],
    'source approval first' => [false],
]);

it('never finalizes the paid source while its revision becomes effective on MySQL', function (bool $effectivenessFirst) {
    $scenario = Scenario::plan();
    $may = Scenario::paidAwaitingFinalization($scenario, '2026-05', 10);
    $revision = Scenario::engineeringApproved($scenario, $may, 8);
    app(MeasurementWorkflow::class)->approve($revision->fresh(), $scenario['actor']);
    $effectiveness = p31RaceDecision('approve', $revision);
    $finalization = p31RaceFinalize($scenario, $may);

    $results = $effectivenessFirst
        ? p31Race($scenario, $effectiveness, $finalization, RaceTasks::OPERATIONS)
        : p31Race($scenario, $finalization, $effectiveness, RaceTasks::OPERATIONS);
    [$compliance, $finalize] = $effectivenessFirst ? $results : array_reverse($results);

    expect($compliance['success'])->toBeTrue(p31RaceReason($compliance))
        ->and($finalize['success'])->toBeFalse()
        ->and($finalize['exception'])->toBe(MeasurementWorkflowException::class)
        ->and($finalize['message'])->toBe($effectivenessFirst
            ? 'A etapa desta medição foi alterada por outra ação. Atualize a página.'
            : sprintf(MeasurementRevisionService::FROZEN_WORKFLOW_REFUSAL, 'R1'))
        ->and($results[1]['lock_wait_ms'])->toBeGreaterThan(250);

    $closedReview = $may->reviews()->where('stage', MeasurementWorkflow::STAGE_FINALIZATION)->sole();
    $closure = p31RaceEvents($may, 'measurement_workflow_closed_by_revision')->sole();

    // Nenhuma transação deixa a vigente substituída sem a revisão vigente, nem
    // a análise da Finalização decidida.
    expect($revision->fresh()->revisionStatus())->toBe(MeasurementRevisionStatus::Effective)
        ->and($may->fresh()->revisionStatus())->toBe(MeasurementRevisionStatus::Superseded)
        ->and($may->fresh()->status)->toBe('superseded')
        ->and($closedReview->status)->toBe(MeasurementReview::STATUS_SUPERSEDED)
        ->and($closedReview->reviewer_user_id)->toBeNull()
        ->and(p31RaceEvents($may, 'measurement_finalized'))->toBeEmpty()
        ->and($closure->properties['stage'])->toBe(MeasurementWorkflow::STAGE_FINALIZATION)
        ->and($closure->properties['from_status'])->toBe('approved')
        // Os pagamentos ficam na vigente substituída, como histórico da revisão.
        ->and($may->payments()->count())->toBe(1)
        ->and($revision->payments()->count())->toBe(0);
})->with([
    'effectiveness first' => [true],
    'finalization first' => [false],
]);

it('keeps the measurement awaiting receipts when a receipt correction and the approval of another payment receipt race on MySQL', function (bool $correctionFirst) {
    $scenario = Scenario::plan();
    $may = Scenario::awaitingPayment($scenario, '2026-05', 10);
    $firstPayment = Scenario::pay($scenario, $may, '60000.00', justification: 'Primeira parcela do pagamento de maio.');
    $secondPayment = Scenario::pay($scenario, $may, '40000.00');
    app(MeasurementWorkflow::class)->approve($may->fresh(), $scenario['actor']);
    $receipts = app(MeasurementReceiptEvidenceService::class);
    $pending = $receipts->upload($firstPayment->fresh(), $scenario['actor'], MeasurementReceiptEvidenceScenario::file('parcela-1.pdf', '%PDF-1.7 parcela 1'));
    $receipts->upload($secondPayment->fresh(), $scenario['actor'], MeasurementReceiptEvidenceScenario::file('parcela-2.pdf', '%PDF-1.7 parcela 2'));
    // Fase 4B-1: o ator enviou os comprovantes; quem os aprova é o Finalizador.
    MeasurementReceiptEvidenceScenario::approveCurrentReceipt($secondPayment, $scenario['finalizer']);
    $correction = p31RaceReceiptUpload($secondPayment);
    $approval = p31RaceReceiptApproval($scenario, $pending);

    $results = $correctionFirst
        ? p31Race($scenario, $correction, $approval, RaceTasks::MEASUREMENTS)
        : p31Race($scenario, $approval, $correction, RaceTasks::MEASUREMENTS);
    [$corrected, $approved] = $correctionFirst ? $results : array_reverse($results);

    expect($corrected['success'])->toBeTrue(p31RaceReason($corrected))
        ->and($approved['success'])->toBeTrue(p31RaceReason($approved))
        ->and($results[1]['lock_wait_ms'])->toBeGreaterThan(250);

    $current = $secondPayment->fresh()->currentReceiptEvidence;

    expect($pending->fresh()->review_status)->toBe(MeasurementReceiptReviewStatus::Approved)
        ->and($current->id)->toBe($corrected['result'])
        ->and($current->version)->toBe(2)
        ->and($current->review_status)->toBe(MeasurementReceiptReviewStatus::Pending)
        // Nenhuma das duas decidiu a situação sobre uma leitura velha: a
        // correção do segundo comprovante ainda espera conferência.
        ->and($may->fresh()->status)->toBe('awaiting_receipt');
})->with([
    'correction holds the lock' => [true],
    'approval holds the lock' => [false],
]);
