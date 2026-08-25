<?php

use App\Models\Measurement;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Models\Operation;
use App\Models\User;
use App\Services\MeasurementEngineeringService;
use App\Services\MeasurementWorkflow;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requer MySQL para validar lockForUpdate() com processos concorrentes reais.');
    }

    Artisan::call('migrate:fresh', ['--no-interaction' => true]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

function makeMysqlConcurrencyActor(): User
{
    $actor = User::factory()->withTwoFactor()->create();
    $actor->assignRole('admin');

    return $actor;
}

/**
 * @param  array{action: string, measurement_id: int, actor_id: int, stage: int, revision: int, notes?: string, payment?: array<string, mixed>, storage_root?: string}  $instruction
 */
function mysqlWorkflowTask(array $instruction): Closure
{
    return static function () use ($instruction): array {
        if (isset($instruction['storage_root'])) {
            config()->set('filesystems.disks.local.root', $instruction['storage_root']);
            Storage::forgetDisk('local');
        }

        $measurement = Measurement::query()->findOrFail($instruction['measurement_id']);
        $actor = User::query()->findOrFail($instruction['actor_id']);
        Notification::fake();

        try {
            match ($instruction['action']) {
                'approve' => app(MeasurementWorkflow::class)->approve(
                    $measurement,
                    $actor,
                    $instruction['notes'] ?? null,
                    expectedStage: $instruction['stage'],
                    expectedRevision: $instruction['revision'],
                ),
                'reject' => app(MeasurementWorkflow::class)->reject(
                    $measurement,
                    $actor,
                    $instruction['notes'] ?? 'Recusa concorrente',
                    expectedStage: $instruction['stage'],
                    expectedRevision: $instruction['revision'],
                ),
                'payment' => app(MeasurementWorkflow::class)->registerPayment(
                    $measurement,
                    $actor,
                    $instruction['payment'] ?? [],
                    expectedRevision: $instruction['revision'],
                ),
                'finalize' => app(MeasurementWorkflow::class)->finalize(
                    $measurement,
                    $actor,
                    expectedRevision: $instruction['revision'],
                    expectedStatus: 'approved',
                ),
            };

            return ['success' => true, 'exception' => null];
        } catch (Throwable $exception) {
            return ['success' => false, 'exception' => $exception::class];
        }
    };
}

/**
 * @return array{actor: User, measurement: Measurement}
 */
function createMysqlStageTwoScenario(): array
{
    $actor = makeMysqlConcurrencyActor();
    $operation = Operation::factory()->create([
        'assigned_user_id' => $actor->id,
        'stage2_reviewer_user_id' => $actor->id,
        'stage3_reviewer_user_id' => $actor->id,
        'payment_manager_user_id' => $actor->id,
        'payment_finalizer_user_id' => $actor->id,
    ]);
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->id,
        'status' => 'in_review',
        'current_stage' => 2,
        'workflow_revision' => 10,
    ]);
    $measurement->reviews()->create(['stage' => 1, 'status' => 'approved', 'reviewer_user_id' => $actor->id]);
    $measurement->reviews()->create(['stage' => 2, 'status' => 'pending']);

    return compact('actor', 'measurement');
}

it('serializes approve against approve on MySQL', function () {
    $scenario = createMysqlStageTwoScenario();
    $instruction = [
        'action' => 'approve',
        'measurement_id' => $scenario['measurement']->id,
        'actor_id' => $scenario['actor']->id,
        'stage' => 2,
        'revision' => 10,
    ];

    $results = Concurrency::driver('process')->run([
        mysqlWorkflowTask($instruction),
        mysqlWorkflowTask($instruction),
    ]);

    expect(collect($results)->where('success', true))->toHaveCount(1)
        ->and($scenario['measurement']->fresh()->current_stage)->toBe(3)
        ->and($scenario['measurement']->fresh()->reviewForStage(3)?->status)->toBe('pending');
})->group('mysql');

it('serializes approve against reject on MySQL', function () {
    $scenario = createMysqlStageTwoScenario();
    $base = [
        'measurement_id' => $scenario['measurement']->id,
        'actor_id' => $scenario['actor']->id,
        'stage' => 2,
        'revision' => 10,
    ];

    $results = Concurrency::driver('process')->run([
        mysqlWorkflowTask(['action' => 'approve'] + $base),
        mysqlWorkflowTask(['action' => 'reject'] + $base),
    ]);

    expect(collect($results)->where('success', true))->toHaveCount(1)
        ->and($scenario['measurement']->fresh()->workflow_revision)->toBe(11);
})->group('mysql');

it('serializes payment registration against payment approval on MySQL', function () {
    $actor = makeMysqlConcurrencyActor();
    $operation = Operation::factory()->create([
        'assigned_user_id' => $actor->id,
        'payment_manager_user_id' => $actor->id,
    ]);
    $planSet = MeasurementPlanSet::factory()->create(['operation_id' => $operation->id]);
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->id,
        'status' => 'awaiting_payment',
        'current_stage' => 4,
        'workflow_revision' => 20,
        'engineering_snapshot' => [
            'plan_sets' => [['plan_set_id' => $planSet->id]],
        ],
    ]);
    $measurement->reviews()->create(['stage' => 4, 'status' => 'pending']);
    $measurement->payments()->create([
        'operation_id' => $operation->id,
        'plan_set_id' => $planSet->id,
        'pay_date' => '2026-08-25',
        'amount' => 100,
    ]);
    $base = [
        'measurement_id' => $measurement->id,
        'actor_id' => $actor->id,
        'stage' => 4,
        'revision' => 20,
    ];

    $results = Concurrency::driver('process')->run([
        mysqlWorkflowTask([
            'action' => 'payment',
            'payment' => [
                'plan_set_id' => $planSet->id,
                'pay_date' => '2026-08-26',
                'amount' => 200,
            ],
        ] + $base),
        mysqlWorkflowTask(['action' => 'approve'] + $base),
    ]);

    expect(collect($results)->where('success', true))->toHaveCount(1)
        ->and($measurement->fresh()->workflow_revision)->toBe(21);
})->group('mysql');

it('serializes finalize against finalize on MySQL', function () {
    Storage::fake('local');
    $actor = makeMysqlConcurrencyActor();
    $operation = Operation::factory()->create([
        'assigned_user_id' => $actor->id,
        'payment_finalizer_user_id' => $actor->id,
    ]);
    $planSet = MeasurementPlanSet::factory()->create(['operation_id' => $operation->id]);
    $line = MeasurementPlanLine::factory()->create([
        'operation_id' => $operation->id,
        'plan_set_id' => $planSet->id,
        'measurement_date' => '2026-08-01',
    ]);
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->id,
        'reference_month' => '2026-08-01',
        'status' => 'approved',
        'current_stage' => 5,
        'workflow_revision' => 30,
        'storage_path' => null,
        'filename' => null,
    ]);
    Storage::disk('local')->put('nimbus_docs/measurements/assets/mysql.pdf', "%PDF-1.7\nasset\n%%EOF");
    $asset = $measurement->assets()->create([
        'plan_set_id' => $planSet->id,
        'plan_line_id' => $line->id,
        'storage_path' => 'nimbus_docs/measurements/assets/mysql.pdf',
    ]);
    $snapshot = app(MeasurementEngineeringService::class)->validateAndRecord(
        $measurement->fresh(),
        [$planSet->id => 5],
    );
    $measurement->forceFill(['engineering_snapshot' => $snapshot])->save();
    foreach ([1, 2, 3, 4] as $stage) {
        $measurement->reviews()->create(['stage' => $stage, 'status' => 'approved', 'reviewer_user_id' => $actor->id]);
    }
    Storage::disk('local')->put('nimbus_docs/measurements/receipts/mysql.pdf', "%PDF-1.7\nreceipt\n%%EOF");
    $measurement->payments()->create([
        'operation_id' => $operation->id,
        'plan_set_id' => $planSet->id,
        'pay_date' => '2026-08-25',
        'amount' => 100,
        'receipt_path' => 'nimbus_docs/measurements/receipts/mysql.pdf',
        'receipt_disk' => 'local',
    ]);
    $instruction = [
        'action' => 'finalize',
        'measurement_id' => $measurement->id,
        'actor_id' => $actor->id,
        'stage' => 5,
        'revision' => 30,
        'storage_root' => Storage::disk('local')->path(''),
    ];

    $results = Concurrency::driver('process')->run([
        mysqlWorkflowTask($instruction),
        mysqlWorkflowTask($instruction),
    ]);

    expect(collect($results)->where('success', true))->toHaveCount(1)
        ->and($measurement->fresh()->status)->toBe('finalized')
        ->and($measurement->fresh()->workflow_revision)->toBe(31);
})->group('mysql');
