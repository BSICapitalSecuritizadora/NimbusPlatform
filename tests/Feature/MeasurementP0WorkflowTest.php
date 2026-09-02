<?php

use App\Enums\OperationStatus;
use App\Exceptions\MeasurementWorkflowException;
use App\Models\Measurement;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Models\Operation;
use App\Models\User;
use App\Services\MeasurementWorkflow;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    Storage::fake('public');
    Notification::fake();
});

function createP0Actor(): User
{
    $user = User::factory()->withTwoFactor()->create();
    $user->assignRole('editor');

    return $user;
}

/**
 * @return array{
 *     measurement: Measurement,
 *     operation: Operation,
 *     planSet: MeasurementPlanSet,
 *     line: MeasurementPlanLine,
 *     assigned: User,
 *     engineering: User,
 *     management: User,
 *     compliance: User,
 *     payment: User,
 *     receipt: User,
 *     finalizer: User
 * }
 */
function createP0Scenario(): array
{
    $actors = [
        'assigned' => createP0Actor(),
        'engineering' => createP0Actor(),
        'management' => createP0Actor(),
        'compliance' => createP0Actor(),
        'payment' => createP0Actor(),
        'receipt' => createP0Actor(),
        'finalizer' => createP0Actor(),
    ];

    $operation = Operation::factory()->create([
        'status' => 'active',
        'assigned_user_id' => $actors['assigned']->id,
        'responsible_user_id' => $actors['engineering']->id,
        'stage2_reviewer_user_id' => $actors['management']->id,
        'stage3_reviewer_user_id' => $actors['compliance']->id,
        'payment_manager_user_id' => $actors['payment']->id,
        'payment_receipt_uploader_user_id' => $actors['receipt']->id,
        'payment_finalizer_user_id' => $actors['finalizer']->id,
    ]);
    $planSet = MeasurementPlanSet::factory()->default()->create(['operation_id' => $operation->id]);
    $line = MeasurementPlanLine::factory()->create([
        'operation_id' => $operation->id,
        'plan_set_id' => $planSet->id,
        'sequence_number' => 1,
        'measurement_date' => '2026-08-01',
        'initial_realized_cumulative_percent' => 0,
        'realized_monthly_percent' => 0,
        'realized_cumulative_percent' => 0,
    ]);
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->id,
        'reference_month' => '2026-08-01',
        'storage_path' => null,
        'filename' => null,
        'status' => 'pending',
        'current_stage' => MeasurementWorkflow::STAGE_ENGINEERING,
        'uploaded_by' => $actors['assigned']->id,
    ]);

    $assetPath = "nimbus_docs/measurements/assets/{$measurement->id}.pdf";
    Storage::disk('local')->put($assetPath, '%PDF-1.7 measurement-file-content');
    $measurement->assets()->create([
        'plan_set_id' => $planSet->id,
        'plan_line_id' => $line->id,
        'storage_path' => $assetPath,
        'storage_disk' => 'local',
    ]);

    app(MeasurementWorkflow::class)->startReview($measurement, $actors['assigned']);

    return array_merge($actors, compact('measurement', 'operation', 'planSet', 'line'));
}

/**
 * @param  array<string, mixed>  $scenario
 */
function advanceP0ToStage(array $scenario, int $targetStage): void
{
    $workflow = app(MeasurementWorkflow::class);
    $measurement = $scenario['measurement'];

    if ($targetStage > 1 && (int) $measurement->fresh()->current_stage === 1) {
        $workflow->approve($measurement->fresh(), $scenario['engineering'], 'Engenharia aprovada', [
            $scenario['planSet']->id => 10,
        ]);
    }

    if ($targetStage > 2 && (int) $measurement->fresh()->current_stage === 2) {
        $workflow->approve($measurement->fresh(), $scenario['management'], 'Gestão aprovada');
    }

    if ($targetStage > 3 && (int) $measurement->fresh()->current_stage === 3) {
        $workflow->approve($measurement->fresh(), $scenario['compliance'], 'Compliance aprovada');
    }
}

it('executes the formal five-stage workflow without treating payment registration as approval', function () {
    $scenario = createP0Scenario();
    $measurement = $scenario['measurement'];
    $workflow = app(MeasurementWorkflow::class);

    expect($measurement->fresh()->status)->toBe('in_review')
        ->and($measurement->fresh()->current_stage)->toBe(1)
        ->and($measurement->fresh()->reviewForStage(1)?->status)->toBe('pending');

    $workflow->approve($measurement->fresh(), $scenario['engineering'], null, [$scenario['planSet']->id => 10]);
    expect($measurement->fresh()->current_stage)->toBe(2)
        ->and($measurement->fresh()->reviewForStage(1)?->status)->toBe('approved');

    $workflow->approve($measurement->fresh(), $scenario['management']);
    expect($measurement->fresh()->current_stage)->toBe(3);

    $workflow->approve($measurement->fresh(), $scenario['compliance']);
    expect($measurement->fresh()->status)->toBe('awaiting_payment')
        ->and($measurement->fresh()->current_stage)->toBe(4)
        ->and($measurement->fresh()->reviewForStage(4)?->status)->toBe('pending');

    $payment = $workflow->registerPayment($measurement->fresh(), $scenario['payment'], [
        'plan_set_id' => $scenario['planSet']->id,
        'pay_date' => '2026-08-20',
        'amount' => 150000.50,
        'method' => 'PIX',
    ]);

    expect($measurement->fresh()->status)->toBe('awaiting_payment')
        ->and($measurement->fresh()->reviewForStage(4)?->status)->toBe('pending')
        ->and($payment->created_by)->toBe($scenario['payment']->id);

    expect(fn () => $workflow->finalize($measurement->fresh(), $scenario['finalizer']))
        ->toThrow(MeasurementWorkflowException::class);

    $workflow->approve($measurement->fresh(), $scenario['payment'], 'Pagamento conferido');
    expect($measurement->fresh()->status)->toBe('awaiting_receipt')
        ->and($measurement->fresh()->current_stage)->toBe(5)
        ->and($measurement->fresh()->reviewForStage(4)?->status)->toBe('approved');

    $receiptPath = 'nimbus_docs/measurements/receipts/payment.pdf';
    Storage::disk('local')->put($receiptPath, '%PDF-1.7 receipt-real-content');
    $workflow->attachReceipt($payment, $scenario['receipt'], $receiptPath, 'local');

    expect($measurement->fresh()->status)->toBe('approved')
        ->and($payment->fresh()->receipt_uploaded_by)->toBe($scenario['receipt']->id)
        ->and($payment->fresh()->receipt_sha256)->toBe(hash('sha256', '%PDF-1.7 receipt-real-content'));

    $workflow->finalize($measurement->fresh(), $scenario['finalizer']);

    expect($measurement->fresh()->status)->toBe('finalized')
        ->and($measurement->fresh()->reviewForStage(5)?->status)->toBe('approved')
        ->and($measurement->fresh()->analyzed_by)->toBe($scenario['finalizer']->id)
        ->and($scenario['operation']->fresh()->status)->toBe(OperationStatus::Active)
        ->and(Activity::query()->where('description', 'measurement_finalized')->exists())->toBeTrue();
});

it('blocks payment approval until at least one valid payment exists', function () {
    $scenario = createP0Scenario();
    advanceP0ToStage($scenario, 4);

    expect(fn () => app(MeasurementWorkflow::class)->approve(
        $scenario['measurement']->fresh(),
        $scenario['payment'],
    ))->toThrow(ValidationException::class);

    expect($scenario['measurement']->fresh()->status)->toBe('awaiting_payment')
        ->and($scenario['measurement']->fresh()->reviewForStage(4)?->status)->toBe('pending');
});

it('enforces engineering progress, document, plan-line and cumulative prerequisites in the domain', function () {
    $scenario = createP0Scenario();
    $workflow = app(MeasurementWorkflow::class);

    expect(fn () => $workflow->approve($scenario['measurement']->fresh(), $scenario['engineering'], null, [
        $scenario['planSet']->id => 0,
    ]))->toThrow(ValidationException::class);

    Storage::disk('local')->delete($scenario['measurement']->assets()->value('storage_path'));

    expect(fn () => $workflow->approve($scenario['measurement']->fresh(), $scenario['engineering'], null, [
        $scenario['planSet']->id => 10,
    ]))->toThrow(ValidationException::class);

    expect($scenario['measurement']->fresh()->reviewForStage(1)?->status)->toBe('pending')
        ->and($scenario['line']->fresh()->measurement_id)->toBeNull();
});

it('returns each rejected post-engineering stage to its immediate predecessor', function () {
    foreach ([2, 3, 4] as $stage) {
        $scenario = createP0Scenario();
        advanceP0ToStage($scenario, $stage);
        $actor = match ($stage) {
            2 => $scenario['management'],
            3 => $scenario['compliance'],
            4 => $scenario['payment'],
        };

        app(MeasurementWorkflow::class)->reject($scenario['measurement']->fresh(), $actor, "Recusa da etapa {$stage}");

        expect($scenario['measurement']->fresh()->current_stage)->toBe($stage - 1)
            ->and($scenario['measurement']->fresh()->reviewForStage($stage)?->status)->toBe('rejected')
            ->and($scenario['measurement']->fresh()->reviewForStage($stage - 1)?->status)->toBe('pending');
    }
});

it('terminates an engineering rejection and records its reason', function () {
    $scenario = createP0Scenario();

    app(MeasurementWorkflow::class)->reject(
        $scenario['measurement']->fresh(),
        $scenario['engineering'],
        'Arquivo técnico inconsistente',
    );

    expect($scenario['measurement']->fresh()->status)->toBe('rejected')
        ->and($scenario['measurement']->fresh()->reviewForStage(1)?->notes)->toBe('Arquivo técnico inconsistente');
});

it('allows finalization to return to an earlier stage only with persisted evidence', function () {
    $scenario = createP0Scenario();
    advanceP0ToStage($scenario, 4);
    $workflow = app(MeasurementWorkflow::class);
    $payment = $workflow->registerPayment($scenario['measurement']->fresh(), $scenario['payment'], [
        'pay_date' => now(),
        'amount' => 1000,
    ]);
    $workflow->approve($scenario['measurement']->fresh(), $scenario['payment']);

    expect(fn () => $workflow->returnToStage(
        $scenario['measurement']->fresh(),
        $scenario['finalizer'],
        2,
        '',
    ))->toThrow(ValidationException::class);

    $workflow->returnToStage($scenario['measurement']->fresh(), $scenario['finalizer'], 2, 'Revalidar custos');

    expect($scenario['measurement']->fresh()->current_stage)->toBe(2)
        ->and($scenario['measurement']->fresh()->status)->toBe('in_review')
        ->and($scenario['measurement']->fresh()->reviewForStage(5)?->status)->toBe('rejected')
        ->and($scenario['measurement']->fresh()->reviewForStage(5)?->notes)->toBe('Revalidar custos')
        ->and($payment->fresh())->not->toBeNull();
});

it('keeps batch and per-development payments, records authors and rejects invalid values', function () {
    $scenario = createP0Scenario();
    advanceP0ToStage($scenario, 4);
    $workflow = app(MeasurementWorkflow::class);

    $payments = $workflow->registerPayments($scenario['measurement']->fresh(), $scenario['payment'], [
        ['plan_set_id' => $scenario['planSet']->id, 'amount' => 100, 'pay_date' => '2026-08-20'],
        ['plan_set_id' => $scenario['planSet']->id, 'amount' => 200, 'pay_date' => '2026-08-21'],
        ['plan_set_id' => $scenario['planSet']->id, 'amount' => null, 'pay_date' => '2026-08-21'],
    ]);

    expect($payments)->toHaveCount(2)
        ->and($payments->pluck('plan_set_id')->all())->toBe([$scenario['planSet']->id, $scenario['planSet']->id])
        ->and($payments->every(fn ($payment): bool => $payment->created_by === $scenario['payment']->id))->toBeTrue();

    expect(fn () => $workflow->registerPayment($scenario['measurement']->fresh(), $scenario['payment'], [
        'amount' => -1,
        'pay_date' => now(),
    ]))->toThrow(ValidationException::class);
});

it('revalidates locked state so a stale second decision fails without corruption', function () {
    $scenario = createP0Scenario();
    $firstRequest = $scenario['measurement']->fresh();
    $staleSecondRequest = $scenario['measurement']->fresh();
    $workflow = app(MeasurementWorkflow::class);

    $workflow->approve($firstRequest, $scenario['engineering'], null, [$scenario['planSet']->id => 10]);

    expect(fn () => $workflow->reject($staleSecondRequest, $scenario['engineering'], 'Decisão concorrente'))
        ->toThrow(MeasurementWorkflowException::class, 'A etapa desta medição foi alterada por outra ação. Atualize a página.');

    expect($scenario['measurement']->fresh()->current_stage)->toBe(2)
        ->and($scenario['measurement']->fresh()->reviews()->where('stage', 1)->count())->toBe(1)
        ->and($scenario['measurement']->fresh()->reviewForStage(1)?->status)->toBe('approved');
});

it('pauses engineering, blocks decisions and resumes the exact pending flow', function () {
    $scenario = createP0Scenario();
    $workflow = app(MeasurementWorkflow::class);

    $workflow->pause($scenario['measurement']->fresh(), $scenario['engineering'], 'Aguardar vistoria');

    expect($scenario['measurement']->fresh()->status)->toBe('paused')
        ->and($scenario['measurement']->fresh()->pauses()->whereNull('resumed_at')->count())->toBe(1)
        ->and(fn () => $workflow->approve(
            $scenario['measurement']->fresh(),
            $scenario['engineering'],
            null,
            [$scenario['planSet']->id => 10],
        ))->toThrow(MeasurementWorkflowException::class);

    $workflow->resume($scenario['measurement']->fresh(), $scenario['engineering']);

    expect($scenario['measurement']->fresh()->status)->toBe('in_review')
        ->and($scenario['measurement']->fresh()->current_stage)->toBe(1)
        ->and($scenario['measurement']->fresh()->pauses()->whereNull('resumed_at')->count())->toBe(0);
});

test('example', function () {
    $response = $this->get('/');

    $response->assertStatus(200);
});
