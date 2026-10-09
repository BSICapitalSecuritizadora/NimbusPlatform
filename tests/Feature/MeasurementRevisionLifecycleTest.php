<?php

use App\Enums\MeasurementRevisionStatus;
use App\Exceptions\MeasurementWorkflowException;
use App\Models\Measurement;
use App\Models\MeasurementAsset;
use App\Models\MeasurementPause;
use App\Models\MeasurementPlanLine;
use App\Notifications\MeasurementWorkflowNotification;
use App\Services\MeasurementRevisionService;
use App\Services\MeasurementWorkflow;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\MeasurementPhysicalProgressScenario;
use Tests\Support\MeasurementRevisionScenario as Scenario;

uses(RefreshDatabase::class);
pest()->group('parity');

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    Notification::fake();
});

/**
 * Os eventos de revisão da trilha protegida, na ordem em que foram gravados.
 *
 * @return list<string>
 */
function revisionEvents(Measurement $measurement): array
{
    return Activity::query()
        ->where('log_name', 'measurement_workflow')
        ->where('subject_type', $measurement->getMorphClass())
        ->where('subject_id', $measurement->getKey())
        ->where('description', 'like', 'measurement_revision_%')
        ->orderBy('id')
        ->pluck('description')
        ->all();
}

it('makes every measurement the original R0 of its own family', function () {
    $scenario = Scenario::plan();
    $may = Scenario::awaitingPayment($scenario, '2026-05', 10);

    expect($may->revision_family_id)->toBe($may->id)
        ->and($may->revisionNumber())->toBe(0)
        ->and($may->revisionStatus())->toBe(MeasurementRevisionStatus::Effective)
        ->and($may->revision_root_id)->toBeNull()
        ->and($may->previous_revision_id)->toBeNull()
        ->and($may->revisionLabel())->toBe('R0');
});

it('creates R1 of a finalized measurement as a draft that inherits the frozen context without touching the original', function () {
    $scenario = Scenario::plan();
    $may = Scenario::finalized($scenario, '2026-05', 10);
    $originalAsset = $may->assets()->sole();
    $paymentsBefore = $may->payments()->get(['id', 'amount', 'pay_date'])->toArray();
    $snapshotBefore = $may->engineering_snapshot;

    $revision = Scenario::revise($scenario, $may, 'Percentual de maio digitado errado.');
    $inherited = $revision->assets()->sole();

    expect($revision->revisionNumber())->toBe(1)
        ->and($revision->revision_family_id)->toBe($may->id)
        ->and($revision->revision_root_id)->toBe($may->id)
        ->and($revision->previous_revision_id)->toBe($may->id)
        ->and($revision->previous_revision_number)->toBe(0)
        ->and($revision->revisionStatus())->toBe(MeasurementRevisionStatus::Draft)
        ->and($revision->status)->toBe('pending')
        ->and($revision->current_stage)->toBe(0)
        ->and($revision->revision_reason)->toBe('Percentual de maio digitado errado.')
        ->and($revision->revision_created_by)->toBe($scenario['actor']->id)
        ->and($revision->reference_month->toDateString())->toBe($may->reference_month->toDateString())
        ->and($revision->reviews()->exists())->toBeFalse()
        ->and($inherited->inherited_from_asset_id)->toBe($originalAsset->id)
        ->and($inherited->plan_set_id)->toBe($originalAsset->plan_set_id)
        ->and($inherited->plan_line_id)->toBe($originalAsset->plan_line_id)
        ->and($inherited->plan_version_id)->toBe($originalAsset->plan_version_id)
        ->and($inherited->storage_path)->toBe($originalAsset->storage_path)
        ->and($inherited->sha256)->toBe($originalAsset->sha256)
        ->and($inherited->line_claim_key)->toBeNull();

    $may->refresh();

    expect($may->status)->toBe('finalized')
        ->and($may->revisionStatus())->toBe(MeasurementRevisionStatus::Effective)
        ->and($may->engineering_snapshot)->toBe($snapshotBefore)
        ->and($may->payments()->get(['id', 'amount', 'pay_date'])->toArray())->toBe($paymentsBefore)
        ->and($originalAsset->fresh()->line_claim_key)->toBe($scenario['lines']['2026-05']->lineage_key)
        ->and(revisionEvents($revision))->toBe(['measurement_revision_created']);
});

it('refuses to revise a measurement that is still under analysis or already paid without finalization', function () {
    $scenario = Scenario::plan();
    $inAnalysis = MeasurementPhysicalProgressScenario::measured($scenario, '2026-05', 10);

    expect(fn () => Scenario::revise($scenario, $inAnalysis))
        ->toThrow(MeasurementWorkflowException::class, MeasurementRevisionService::ELIGIBILITY_REFUSAL);

    $june = Scenario::awaitingPayment($scenario, '2026-06', 10);
    Scenario::pay($scenario, $june, Scenario::expected(10));

    expect(fn () => Scenario::revise($scenario, $june))
        ->toThrow(MeasurementWorkflowException::class, MeasurementRevisionService::ELIGIBILITY_REFUSAL);

    expect(Measurement::query()->where('revision_number', '>', 0)->count())->toBe(0);
});

it('requires a reason to create a revision', function () {
    $scenario = Scenario::plan();
    $may = Scenario::finalized($scenario, '2026-05', 10);

    expect(fn () => Scenario::revise($scenario, $may, '   '))->toThrow(ValidationException::class);
    expect(Measurement::query()->where('revision_number', '>', 0)->exists())->toBeFalse();
});

it('keeps a single pending revision per measurement and never reuses a revision number', function () {
    $scenario = Scenario::plan();
    $may = Scenario::finalized($scenario, '2026-05', 10);
    $first = Scenario::revise($scenario, $may);

    expect(fn () => Scenario::revise($scenario, $may))
        ->toThrow(MeasurementWorkflowException::class, 'já tem a revisão R1 em andamento');

    app(MeasurementRevisionService::class)->cancel($first->fresh(), $scenario['actor'], 'Aberta por engano.');
    $second = Scenario::revise($scenario, $may);

    expect($first->fresh()->revisionStatus())->toBe(MeasurementRevisionStatus::Cancelled)
        ->and($first->fresh()->status)->toBe('cancelled')
        ->and($first->fresh()->revision_closed_reason)->toBe('Aberta por engano.')
        ->and($second->revisionNumber())->toBe(2)
        ->and($second->previous_revision_id)->toBe($may->id)
        ->and(revisionEvents($first))->toBe(['measurement_revision_created', 'measurement_revision_cancelled']);
});

it('enforces one effective and one pending revision per family in the database', function () {
    $scenario = Scenario::plan();
    $may = Scenario::finalized($scenario, '2026-05', 10);
    $revision = Scenario::revise($scenario, $may);

    expect(fn () => DB::table('measurements')->where('id', $revision->id)->update(['revision_status' => 'effective']))
        ->toThrow(QueryException::class);

    expect(fn () => DB::table('measurements')->insert(array_merge(
        collect(DB::table('measurements')->where('id', $revision->id)->first())->except(['id', 'effective_revision_family_id', 'pending_revision_family_id'])->all(),
        ['revision_number' => 2],
    )))->toThrow(QueryException::class);
});

it('submits the draft to Engineering and notifies the Engineering reviewer', function () {
    $scenario = Scenario::plan();
    $may = Scenario::finalized($scenario, '2026-05', 10);
    $revision = Scenario::submit($scenario, Scenario::revise($scenario, $may));

    expect($revision->revisionStatus())->toBe(MeasurementRevisionStatus::UnderReview)
        ->and($revision->status)->toBe('in_review')
        ->and($revision->current_stage)->toBe(MeasurementWorkflow::STAGE_ENGINEERING)
        ->and($revision->uploaded_at)->not->toBeNull()
        ->and(revisionEvents($revision))->toBe(['measurement_revision_created', 'measurement_revision_submitted']);

    Notification::assertSentTo($scenario['actor'], MeasurementWorkflowNotification::class, fn (MeasurementWorkflowNotification $notification): bool => $notification->event === 'revision_submitted');
});

it('makes the revision effective at the Compliance approval and moves the line claim and the schedule line to it', function () {
    $scenario = Scenario::plan();
    $may = Scenario::finalized($scenario, '2026-05', 10);
    $revision = Scenario::engineeringApproved($scenario, $may, 12);
    $line = $scenario['lines']['2026-05']->fresh();

    // Aprovada pela Engenharia, mas ainda não vigente: a linha e a ocupação
    // continuam com a R0.
    expect($line->measurement_id)->toBe($may->id)
        ->and((string) $line->realized_monthly_percent)->toBe('10.00')
        ->and($revision->assets()->sole()->line_claim_key)->toBeNull()
        ->and($may->assets()->sole()->line_claim_key)->toBe($line->lineage_key);

    Scenario::approveManagementAndCompliance($scenario, $revision);
    $revision->refresh();
    $may->refresh();
    $line->refresh();

    expect($revision->revisionStatus())->toBe(MeasurementRevisionStatus::Effective)
        ->and($revision->status)->toBe('awaiting_payment')
        ->and($revision->revision_effective_at)->not->toBeNull()
        ->and($may->revisionStatus())->toBe(MeasurementRevisionStatus::Superseded)
        ->and($may->status)->toBe('finalized')
        ->and($may->revision_superseded_at)->not->toBeNull()
        ->and($line->measurement_id)->toBe($revision->id)
        ->and((string) $line->realized_monthly_percent)->toBe('12.00')
        ->and($revision->assets()->sole()->line_claim_key)->toBe($line->lineage_key)
        ->and($may->assets()->sole()->line_claim_key)->toBeNull()
        ->and(revisionEvents($revision))->toBe([
            'measurement_revision_created',
            'measurement_revision_submitted',
            'measurement_revision_approved',
            'measurement_revision_became_effective',
            'measurement_revision_financial_difference_identified',
        ])
        ->and(revisionEvents($may))->toBe(['measurement_revision_superseded']);

    $effective = Activity::query()->where('description', 'measurement_revision_became_effective')->sole();

    expect($effective->properties['measurement_id'])->toBe($revision->id)
        ->and($effective->properties['superseded_measurement_id'])->toBe($may->id)
        ->and($effective->properties['line_claims_transferred'][0]['from_asset_id'])->toBe($may->assets()->sole()->id);
});

it('closes a pending revision rejected by Engineering and keeps the effective revision untouched', function () {
    $scenario = Scenario::plan();
    $may = Scenario::finalized($scenario, '2026-05', 10);
    $revision = Scenario::submit($scenario, Scenario::revise($scenario, $may));

    app(MeasurementWorkflow::class)->reject($revision->fresh(), $scenario['actor'], 'O percentual corrigido também está errado.');
    $revision->refresh();
    $may->refresh();

    expect($revision->revisionStatus())->toBe(MeasurementRevisionStatus::Rejected)
        ->and($revision->status)->toBe('rejected')
        ->and($revision->revision_closed_reason)->toBe('O percentual corrigido também está errado.')
        ->and($revision->revision_closed_by)->toBe($scenario['actor']->id)
        ->and($may->revisionStatus())->toBe(MeasurementRevisionStatus::Effective)
        ->and($may->assets()->sole()->line_claim_key)->toBe($scenario['lines']['2026-05']->lineage_key)
        ->and($scenario['lines']['2026-05']->fresh()->measurement_id)->toBe($may->id)
        ->and(revisionEvents($revision))->toContain('measurement_revision_rejected');

    Notification::assertSentTo($scenario['actor'], MeasurementWorkflowNotification::class, fn (MeasurementWorkflowNotification $notification): bool => $notification->event === 'revision_rejected');

    // A família aceita uma nova revisão, com o número seguinte.
    expect(Scenario::revise($scenario, $may)->revisionNumber())->toBe(2);
});

it('refuses the terminal rejection of an effective revision returned to Engineering', function () {
    $scenario = Scenario::plan();
    $may = Scenario::finalized($scenario, '2026-05', 10);
    $revision = Scenario::effective($scenario, $may, 12);
    $workflow = app(MeasurementWorkflow::class);

    // Pagamento devolve à Compliance, Compliance à Gestão, Gestão à Engenharia.
    $workflow->reject($revision->fresh(), $scenario['actor'], 'Rever.');
    $workflow->reject($revision->fresh(), $scenario['actor'], 'Rever.');
    $workflow->reject($revision->fresh(), $scenario['actor'], 'Rever.');

    expect($workflow->terminalRejectionBlockReason($revision->fresh()))->toBe(MeasurementWorkflow::TERMINAL_REJECTION_OF_EFFECTIVE_REVISION)
        ->and(fn () => $workflow->reject($revision->fresh(), $scenario['actor'], 'Encerrar.'))
        ->toThrow(MeasurementWorkflowException::class, MeasurementWorkflow::TERMINAL_REJECTION_OF_EFFECTIVE_REVISION);

    expect($revision->fresh()->revisionStatus())->toBe(MeasurementRevisionStatus::Effective);
});

it('suspends the workflow of an unpaid measurement while its revision is under review and records the suspension on rejection', function () {
    $scenario = Scenario::plan();
    $may = Scenario::awaitingPayment($scenario, '2026-05', 10);
    $workflow = app(MeasurementWorkflow::class);
    $draft = Scenario::revise($scenario, $may);

    // O rascunho não suspende nada.
    expect($may->fresh()->isFrozenByRevision())->toBeFalse()
        ->and($workflow->pendingResponsibilities($may->fresh()))->not->toBe([]);

    $revision = Scenario::submit($scenario, $draft);

    expect($may->fresh()->isFrozenByRevision())->toBeTrue()
        ->and($workflow->pendingResponsibilities($may->fresh()))->toBe([])
        ->and($workflow->canRegisterPayment($may->fresh(), $scenario['actor']))->toBeFalse()
        ->and(fn () => Scenario::pay($scenario, $may, Scenario::expected(10)))
        ->toThrow(MeasurementWorkflowException::class, 'revisão R1 em análise')
        ->and(fn () => $workflow->pause($may->fresh(), $scenario['actor'], 'Aguardar.'))
        ->toThrow(MeasurementWorkflowException::class, 'revisão R1 em análise');

    $workflow->reject($revision->fresh(), $scenario['actor'], 'Revisão desnecessária.');
    $pause = MeasurementPause::query()->where('measurement_id', $may->id)->sole();

    expect($may->fresh()->isFrozenByRevision())->toBeFalse()
        ->and($pause->stage)->toBe(MeasurementWorkflow::STAGE_PAYMENT)
        ->and($pause->resumed_at)->not->toBeNull()
        ->and($pause->paused_at->equalTo($revision->uploaded_at))->toBeTrue()
        ->and($may->fresh()->status)->toBe('awaiting_payment');

    // O fluxo volta: o pagamento da R0 é aceito.
    Scenario::pay($scenario, $may, Scenario::expected(10));
    expect($may->payments()->count())->toBe(1);
});

it('closes the open payment stage of the unpaid measurement when its revision becomes effective', function () {
    $scenario = Scenario::plan();
    $may = Scenario::awaitingPayment($scenario, '2026-05', 10);
    $revision = Scenario::effective($scenario, $may, 8);
    $may->refresh();

    expect($may->status)->toBe('superseded')
        ->and($may->revisionStatus())->toBe(MeasurementRevisionStatus::Superseded)
        ->and(app(MeasurementWorkflow::class)->pendingResponsibilities($may))->toBe([])
        ->and($revision->status)->toBe('awaiting_payment')
        ->and(Activity::query()->where('description', 'measurement_workflow_closed_by_revision')->where('subject_id', $may->id)->exists())->toBeTrue();

    Notification::assertSentTo($scenario['actor'], MeasurementWorkflowNotification::class, fn (MeasurementWorkflowNotification $notification): bool => $notification->event === 'workflow_closed_by_revision');
});

it('keeps revision identity, milestones and closed revisions immutable', function () {
    $scenario = Scenario::plan();
    $may = Scenario::finalized($scenario, '2026-05', 10);
    $revision = Scenario::effective($scenario, $may, 12);

    expect(fn () => $revision->fresh()->forceFill(['revision_number' => 5])->save())
        ->toThrow(MeasurementWorkflowException::class)
        ->and(fn () => $revision->fresh()->forceFill(['previous_revision_id' => null])->save())
        ->toThrow(MeasurementWorkflowException::class)
        ->and(fn () => $revision->fresh()->forceFill(['revision_reason' => 'Outro motivo'])->save())
        ->toThrow(MeasurementWorkflowException::class)
        ->and(fn () => $revision->fresh()->forceFill(['revision_effective_at' => now()->addDay()])->save())
        ->toThrow(MeasurementWorkflowException::class)
        ->and(fn () => $may->fresh()->forceFill(['revision_status' => MeasurementRevisionStatus::Effective])->save())
        ->toThrow(MeasurementWorkflowException::class)
        ->and(fn () => $may->fresh()->forceFill(['notes' => 'Reescrever o histórico'])->save())
        ->toThrow(MeasurementWorkflowException::class)
        ->and(fn () => $revision->fresh()->delete())
        ->toThrow(MeasurementWorkflowException::class)
        ->and(fn () => $may->fresh()->delete())
        ->toThrow(MeasurementWorkflowException::class)
        ->and(fn () => $revision->assets()->sole()->delete())
        ->toThrow(MeasurementWorkflowException::class);
});

it('refuses to add, remove or move the files of a revision', function () {
    $scenario = Scenario::plan();
    $may = Scenario::finalized($scenario, '2026-05', 10);
    $draft = Scenario::revise($scenario, $may);
    $inherited = $draft->assets()->sole();

    expect(fn () => $inherited->fresh()->forceFill(['plan_line_id' => $scenario['lines']['2026-06']->id])->save())
        ->toThrow(MeasurementWorkflowException::class, MeasurementAsset::REVISION_CONTEXT_REFUSAL)
        ->and(fn () => $draft->assets()->create([
            'plan_set_id' => $scenario['planSet']->id,
            'plan_line_id' => $scenario['lines']['2026-06']->id,
            'storage_path' => $inherited->storage_path,
            'storage_disk' => 'local',
        ]))->toThrow(MeasurementWorkflowException::class, MeasurementAsset::REVISION_ASSET_SET_REFUSAL)
        ->and(fn () => $inherited->fresh()->delete())
        ->toThrow(MeasurementWorkflowException::class, MeasurementAsset::REVISION_ASSET_SET_REFUSAL);

    expect(MeasurementPlanLine::query()->whereKey($scenario['lines']['2026-06']->id)->value('measurement_id'))->toBeNull();
});

it('replaces the file of a draft revision without touching the file of the revision it corrects', function () {
    $scenario = Scenario::plan();
    $may = Scenario::finalized($scenario, '2026-05', 10);
    $draft = Scenario::revise($scenario, $may);
    $inherited = $draft->assets()->sole();
    $original = $may->assets()->sole();
    $path = 'nimbus_docs/measurements/assets/laudo-corrigido.pdf';
    Storage::disk('local')->put($path, '%PDF-1.7 laudo corrigido');

    $inherited->fresh()->forceFill(['storage_path' => $path, 'storage_disk' => 'local'])->save();
    $replaced = $inherited->fresh();

    expect($replaced->storage_path)->toBe($path)
        ->and($replaced->inherited_from_asset_id)->toBe($original->id)
        ->and($replaced->plan_line_id)->toBe($original->plan_line_id)
        ->and($replaced->sha256)->toBe(hash('sha256', '%PDF-1.7 laudo corrigido'))
        ->and($replaced->line_claim_key)->toBeNull()
        ->and($original->fresh()->storage_path)->toBe($original->storage_path)
        ->and($original->fresh()->sha256)->toBe($original->sha256)
        ->and(Storage::disk('local')->exists($original->storage_path))->toBeTrue();

    app(MeasurementRevisionService::class)->cancel($draft->fresh(), $scenario['actor'], 'Desnecessária.');

    expect(fn () => $replaced->fresh()->forceFill(['storage_path' => $original->storage_path])->save())
        ->toThrow(MeasurementWorkflowException::class, MeasurementAsset::CLOSED_REVISION_FILE_REFUSAL);
});

it('refuses to submit a draft created over an Engineering record that was later replaced', function () {
    $scenario = Scenario::plan();
    $may = Scenario::awaitingPayment($scenario, '2026-05', 10);
    $draft = Scenario::revise($scenario, $may);
    $workflow = app(MeasurementWorkflow::class);

    // A vigente volta à Engenharia pelo caminho de sempre e é aprovada de novo
    // com outro percentual: o rascunho herdou um registro que deixou de valer.
    $workflow->reject($may->fresh(), $scenario['actor'], 'Pagamento devolve.');
    $workflow->reject($may->fresh(), $scenario['actor'], 'Compliance devolve.');
    $workflow->reject($may->fresh(), $scenario['actor'], 'Gestão devolve.');
    MeasurementPhysicalProgressScenario::approveEngineering($scenario, $may, 9);
    Scenario::approveManagementAndCompliance($scenario, $may);

    expect(fn () => app(MeasurementRevisionService::class)->submit($draft->fresh(), $scenario['actor']))
        ->toThrow(MeasurementWorkflowException::class, 'aprovou outro registro depois da criação desta revisão');

    expect($draft->fresh()->revisionStatus())->toBe(MeasurementRevisionStatus::Draft);
});

it('explains why the file a revision inherited cannot move or leave instead of failing in the database', function () {
    $scenario = Scenario::plan();
    $may = Scenario::awaitingPayment($scenario, '2026-05', 10);
    $draft = Scenario::revise($scenario, $may);
    app(MeasurementRevisionService::class)->cancel($draft->fresh(), $scenario['actor'], 'Desnecessária.');
    $workflow = app(MeasurementWorkflow::class);
    $workflow->reject($may->fresh(), $scenario['actor'], 'Pagamento devolve.');
    $workflow->reject($may->fresh(), $scenario['actor'], 'Compliance devolve.');
    $workflow->reject($may->fresh(), $scenario['actor'], 'Gestão devolve.');
    $source = $may->assets()->sole();

    expect(fn () => $source->fresh()->forceFill(['plan_line_id' => $scenario['lines']['2026-06']->id])->save())
        ->toThrow(MeasurementWorkflowException::class, 'foi herdado pela revisão R1')
        ->and(fn () => $source->fresh()->delete())
        ->toThrow(MeasurementWorkflowException::class, 'foi herdado pela revisão R1');

    // O arquivo continua podendo ser trocado.
    $path = 'nimbus_docs/measurements/assets/laudo-maio-corrigido.pdf';
    Storage::disk('local')->put($path, '%PDF-1.7 laudo de maio corrigido');
    $source->fresh()->forceFill(['storage_path' => $path, 'storage_disk' => 'local'])->save();

    expect($source->fresh()->storage_path)->toBe($path);
});
