<?php

use App\Exceptions\MeasurementWorkflowException;
use App\Filament\Resources\Measurements\Pages\ListMeasurements;
use App\Filament\Resources\Measurements\Schemas\MeasurementForm;
use App\Filament\Resources\Operations\Pages\EditOperation;
use App\Filament\Resources\Operations\Pages\ViewOperation;
use App\Filament\Resources\Operations\RelationManagers\PlanLinesRelationManager;
use App\Models\Construction;
use App\Models\Measurement;
use App\Models\MeasurementAsset;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Models\Operation;
use App\Models\User;
use App\Services\MeasurementAuthorizationService;
use App\Services\MeasurementFileValidationService;
use App\Services\MeasurementWorkflow;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\MeasurementReceiptEvidenceScenario;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    Storage::fake('public');
    Notification::fake();
});

function makeP01Editor(): User
{
    $user = User::factory()->withTwoFactor()->create();
    $user->assignRole('editor');

    return $user;
}

function makeP01Admin(): User
{
    $user = User::factory()->withTwoFactor()->create();
    $user->assignRole('admin');

    return $user;
}

function putP01Pdf(string $path, string $disk = 'local', string $suffix = ''): void
{
    Storage::disk($disk)->put($path, "%PDF-1.7\n{$suffix}\n%%EOF");
}

it('grants responsibility management only to the explicit administrative roles by default', function () {
    $customRole = Role::query()->create(['name' => 'custom-operations', 'guard_name' => 'web']);

    expect(Role::findByName('super-admin')->hasPermissionTo('operations.manage-responsibilities'))->toBeTrue()
        ->and(Role::findByName('admin')->hasPermissionTo('operations.manage-responsibilities'))->toBeTrue()
        ->and(Role::findByName('editor')->hasPermissionTo('operations.manage-responsibilities'))->toBeFalse()
        ->and($customRole->hasPermissionTo('operations.manage-responsibilities'))->toBeFalse();
});

/**
 * @return array{actor: User, operation: Operation, measurement: Measurement, planSets: Collection<int, MeasurementPlanSet>, assets: Collection<int, MeasurementAsset>}
 */
function createP01Scenario(int $planSetCount = 1, ?User $actor = null): array
{
    $actor ??= makeP01Admin();
    test()->actingAs($actor);

    $operation = Operation::factory()->create([
        'status' => 'active',
        'assigned_user_id' => $actor->id,
        'responsible_user_id' => $actor->id,
        'stage2_reviewer_user_id' => $actor->id,
        'stage3_reviewer_user_id' => $actor->id,
        'payment_manager_user_id' => $actor->id,
        'payment_receipt_uploader_user_id' => $actor->id,
        'payment_finalizer_user_id' => $actor->id,
    ]);
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->id,
        'reference_month' => '2026-08-01',
        'status' => 'pending',
        'current_stage' => 1,
        'storage_path' => null,
        'filename' => null,
    ]);
    $planSets = collect();
    $assets = collect();

    foreach (range(1, $planSetCount) as $sequence) {
        $planSet = MeasurementPlanSet::factory()->create([
            'operation_id' => $operation->id,
            'name' => "Plano {$sequence}",
        ]);
        $line = MeasurementPlanLine::factory()->create([
            'operation_id' => $operation->id,
            'plan_set_id' => $planSet->id,
            'sequence_number' => 1,
            'measurement_date' => '2026-08-01',
        ]);
        $path = "nimbus_docs/measurements/assets/p01-{$measurement->id}-{$sequence}.pdf";
        putP01Pdf($path, suffix: "asset-{$sequence}");
        $asset = $measurement->assets()->create([
            'plan_set_id' => $planSet->id,
            'plan_line_id' => $line->id,
            'storage_path' => $path,
            'storage_disk' => 'local',
        ]);
        $planSets->push($planSet);
        $assets->push($asset);
    }

    app(MeasurementWorkflow::class)->startReview($measurement->fresh(), $actor);

    return compact('actor', 'operation', 'measurement', 'planSets', 'assets');
}

/**
 * @param  array{actor: User, operation: Operation, measurement: Measurement, planSets: Collection<int, MeasurementPlanSet>, assets: Collection<int, MeasurementAsset>}  $scenario
 */
function advanceP01ToDocumentedFinalization(array $scenario): void
{
    $workflow = app(MeasurementWorkflow::class);
    $progress = $scenario['planSets']->mapWithKeys(fn (MeasurementPlanSet $planSet): array => [$planSet->id => 10])->all();

    $workflow->approve($scenario['measurement']->fresh(), $scenario['actor'], engineeringProgress: $progress);
    $workflow->approve($scenario['measurement']->fresh(), $scenario['actor']);
    $workflow->approve($scenario['measurement']->fresh(), $scenario['actor']);
    $payment = $workflow->registerPayment($scenario['measurement']->fresh(), $scenario['actor'], [
        'pay_date' => '2026-08-25',
        'amount' => 1000,
        'plan_set_id' => $scenario['planSets']->first()->id,
    ]);
    $workflow->approve($scenario['measurement']->fresh(), $scenario['actor']);
    $workflow->attachReceipt($payment->fresh(), $scenario['actor'], MeasurementReceiptEvidenceScenario::file());
    MeasurementReceiptEvidenceScenario::approveCurrentReceipt($payment, $scenario['actor']);
}

it('blocks participant self-assignment and payment responsibility changes without the granular permission', function () {
    $editor = makeP01Editor();
    $other = makeP01Editor();
    $operation = Operation::factory()->create([
        'assigned_user_id' => $editor->id,
        'payment_manager_user_id' => $other->id,
        'payment_finalizer_user_id' => $other->id,
    ]);
    $this->actingAs($editor);

    expect(fn () => $operation->update(['payment_finalizer_user_id' => $editor->id]))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => $operation->update(['payment_manager_user_id' => $editor->id]))
        ->toThrow(AuthorizationException::class);

    expect($operation->fresh()->payment_finalizer_user_id)->toBe($other->id)
        ->and($operation->fresh()->payment_manager_user_id)->toBe($other->id);
});

it('allows responsibility management only with the granular permission or administrator override', function () {
    $manager = makeP01Editor();
    $admin = makeP01Admin();
    $replacement = makeP01Editor();
    $operation = Operation::factory()->create(['assigned_user_id' => $manager->id]);
    $manager->givePermissionTo('operations.manage-responsibilities');

    $this->actingAs($manager);
    $operation->update(['payment_manager_user_id' => $replacement->id]);

    $this->actingAs($admin);
    $operation->fresh()->update(['payment_finalizer_user_id' => $admin->id]);

    expect($operation->fresh()->payment_manager_user_id)->toBe($replacement->id)
        ->and($operation->fresh()->payment_finalizer_user_id)->toBe($admin->id);
});

it('blocks a forged Filament responsibility payload on the backend', function () {
    $editor = makeP01Editor();
    $replacement = makeP01Editor();
    $operation = Operation::factory()->create(['assigned_user_id' => $editor->id]);
    $construction = Construction::factory()->create(['emission_id' => $operation->emission_id]);
    MeasurementPlanSet::factory()->create([
        'operation_id' => $operation->id,
        'construction_id' => $construction->id,
    ]);
    $this->actingAs($editor);

    Livewire::test(EditOperation::class, ['record' => $operation->getRouteKey()])
        ->fillForm(['payment_finalizer_user_id' => $replacement->id])
        ->call('save');

    expect($operation->fresh()->payment_finalizer_user_id)->toBeNull();
});

it('rejects custom plan mutations for a read-only participant', function () {
    $reader = User::factory()->withTwoFactor()->create();
    $reader->givePermissionTo('operations.view');
    $operation = Operation::factory()->create(['assigned_user_id' => $reader->id]);
    $planSet = MeasurementPlanSet::factory()->create(['operation_id' => $operation->id]);
    $line = MeasurementPlanLine::factory()->create([
        'operation_id' => $operation->id,
        'plan_set_id' => $planSet->id,
    ]);
    $this->actingAs($reader);

    Livewire::test(PlanLinesRelationManager::class, [
        'ownerRecord' => $operation,
        'pageClass' => ViewOperation::class,
    ])->assertTableActionHidden('editPlanned', $line);

    expect($line->fresh()->planned_monthly_percent)->not->toBe('20.00');
});

it('keeps operation immutable after measurement creation', function () {
    $measurement = Measurement::factory()->create();
    $otherOperation = Operation::factory()->create();

    expect(fn () => $measurement->update(['operation_id' => $otherOperation->id]))
        ->toThrow(MeasurementWorkflowException::class);
});

it('freezes approved engineering evidence while allowing changes before approval', function () {
    $scenario = createP01Scenario();
    $asset = $scenario['assets']->first();
    $replacementPath = 'nimbus_docs/measurements/assets/pre-approval.pdf';
    putP01Pdf($replacementPath, suffix: 'before');
    $asset->update(['storage_path' => $replacementPath]);

    app(MeasurementWorkflow::class)->approve(
        $scenario['measurement']->fresh(),
        $scenario['actor'],
        engineeringProgress: [$scenario['planSets']->first()->id => 10],
    );

    $afterApprovalPath = 'nimbus_docs/measurements/assets/after-approval.pdf';
    putP01Pdf($afterApprovalPath, suffix: 'after');

    expect(fn () => $asset->fresh()->update(['storage_path' => $afterApprovalPath]))
        ->toThrow(MeasurementWorkflowException::class)
        ->and(fn () => $asset->fresh()->delete())
        ->toThrow(MeasurementWorkflowException::class)
        ->and(fn () => $scenario['measurement']->fresh()->update(['reference_month' => '2026-09-01']))
        ->toThrow(MeasurementWorkflowException::class);
});

it('finalizes with complete three-plan coverage and records the effective actor', function () {
    $scenario = createP01Scenario(3);
    advanceP01ToDocumentedFinalization($scenario);

    app(MeasurementWorkflow::class)->finalize($scenario['measurement']->fresh(), $scenario['actor']);

    expect($scenario['measurement']->fresh()->status)->toBe('finalized')
        ->and($scenario['measurement']->fresh()->engineering_snapshot['plan_sets'])->toHaveCount(3)
        ->and($scenario['measurement']->fresh()->reviewForStage(5)?->reviewer_user_id)->toBe($scenario['actor']->id);
});

it('blocks finalization when snapshot coverage is missing or points to the wrong plan set', function (string $tamper) {
    $scenario = createP01Scenario(3);
    advanceP01ToDocumentedFinalization($scenario);
    $asset = $scenario['assets']->first();

    if ($tamper === 'missing') {
        DB::table('measurement_assets')->where('id', $asset->id)->delete();
    } else {
        $foreignPlanSet = MeasurementPlanSet::factory()->create();
        DB::table('measurement_assets')->where('id', $asset->id)->update(['plan_set_id' => $foreignPlanSet->id]);
    }

    expect(fn () => app(MeasurementWorkflow::class)->finalize($scenario['measurement']->fresh(), $scenario['actor']))
        ->toThrow(MeasurementWorkflowException::class);
})->with(['missing', 'wrong plan set']);

it('blocks finalization when an engineering file is missing or has a divergent hash', function (string $tamper) {
    $scenario = createP01Scenario(3);
    advanceP01ToDocumentedFinalization($scenario);
    $asset = $scenario['assets']->first();

    if ($tamper === 'missing') {
        Storage::disk('local')->delete($asset->storage_path);
    } else {
        putP01Pdf($asset->storage_path, suffix: 'tampered-content');
    }

    expect(fn () => app(MeasurementWorkflow::class)->finalize($scenario['measurement']->fresh(), $scenario['actor']))
        ->toThrow(MeasurementWorkflowException::class);
})->with(['missing', 'divergent hash']);

it('does not let a repeated stage-two approval reach stage three for a consecutive responsible', function () {
    $scenario = createP01Scenario();
    $workflow = app(MeasurementWorkflow::class);
    $workflow->approve(
        $scenario['measurement']->fresh(),
        $scenario['actor'],
        engineeringProgress: [$scenario['planSets']->first()->id => 10],
    );
    $firstRequest = $scenario['measurement']->fresh();
    $staleRequest = $scenario['measurement']->fresh();

    $workflow->approve($firstRequest, $scenario['actor']);

    expect(fn () => $workflow->approve($staleRequest, $scenario['actor']))
        ->toThrow(MeasurementWorkflowException::class, 'A etapa desta medição foi alterada por outra ação. Atualize a página.');

    expect($scenario['measurement']->fresh()->current_stage)->toBe(3)
        ->and($scenario['measurement']->fresh()->reviewForStage(3)?->status)->toBe('pending');
});

it('protects admin approve-reject and pause-approve intents with the same revision contract', function () {
    $scenario = createP01Scenario();
    $workflow = app(MeasurementWorkflow::class);
    $approveRequest = $scenario['measurement']->fresh();
    $rejectRequest = $scenario['measurement']->fresh();
    $pauseRequest = $scenario['measurement']->fresh();

    $workflow->approve(
        $approveRequest,
        $scenario['actor'],
        engineeringProgress: [$scenario['planSets']->first()->id => 10],
    );

    expect(fn () => $workflow->reject($rejectRequest, $scenario['actor'], 'stale reject'))
        ->toThrow(MeasurementWorkflowException::class)
        ->and(fn () => $workflow->pause($pauseRequest, $scenario['actor'], 'stale pause'))
        ->toThrow(MeasurementWorkflowException::class);

    expect($scenario['measurement']->fresh()->current_stage)->toBe(2);
});

it('rejects a pre-pause decision after pause and resume changed the revision', function () {
    $scenario = createP01Scenario();
    $workflow = app(MeasurementWorkflow::class);
    $staleReject = $scenario['measurement']->fresh();

    $workflow->pause($scenario['measurement']->fresh(), $scenario['actor'], 'Aguardar documento');
    $workflow->resume($scenario['measurement']->fresh(), $scenario['actor']);

    expect(fn () => $workflow->reject($staleReject, $scenario['actor'], 'Decisão antiga'))
        ->toThrow(MeasurementWorkflowException::class);
});

it('does not reveal operation, plan set, schedule or autocomplete metadata to an outsider', function () {
    $participant = makeP01Editor();
    $outsider = makeP01Editor();
    $operation = Operation::factory()->create([
        'title' => 'Operação Ultra Secreta',
        'assigned_user_id' => $participant->id,
    ]);
    $planSet = MeasurementPlanSet::factory()->create(['operation_id' => $operation->id, 'name' => 'Plano Sigiloso']);
    $line = MeasurementPlanLine::factory()->create([
        'operation_id' => $operation->id,
        'plan_set_id' => $planSet->id,
    ]);
    Measurement::factory()->create(['operation_id' => $operation->id]);
    $this->actingAs($outsider);

    $probe = new class extends MeasurementForm
    {
        public static function assets(mixed $operationId): array
        {
            return parent::assetsForOperation($operationId);
        }

        public static function planSets(mixed $operationId): array
        {
            return parent::planSetOptions($operationId);
        }

        public static function schedules(mixed $planSetId): array
        {
            return parent::scheduleOptionsForPlanSet($planSetId);
        }

        public static function label(mixed $planSetId): ?string
        {
            return parent::planSetLabel($planSetId);
        }
    };

    expect($probe::assets($operation->id))->toBe([])
        ->and($probe::planSets($operation->id))->toBe([])
        ->and($probe::schedules($planSet->id))->toBe([])
        ->and($probe::label($planSet->id))->toBeNull();

    Livewire::test(ListMeasurements::class)
        ->assertSuccessful()
        ->assertDontSee($operation->title)
        ->assertDontSee($planSet->name);
});

it('applies backend size, MIME, extension and non-Filament validation to assets and receipts', function () {
    $measurement = Measurement::factory()->create(['storage_path' => null, 'filename' => null]);
    Storage::disk('local')->put('nimbus_docs/measurements/assets/invalid.exe', 'not allowed');

    expect(fn () => $measurement->assets()->create([
        'storage_path' => 'nimbus_docs/measurements/assets/invalid.exe',
        'storage_disk' => 'local',
    ]))->toThrow(ValidationException::class);

    Storage::disk('local')->put('nimbus_docs/measurements/assets/spoofed.pdf', 'plain text posing as PDF');

    expect(fn () => $measurement->assets()->create([
        'storage_path' => 'nimbus_docs/measurements/assets/spoofed.pdf',
        'storage_disk' => 'local',
    ]))->toThrow(ValidationException::class);

    Storage::disk('local')->put(
        'nimbus_docs/measurements/receipts/oversized.pdf',
        str_repeat('x', (int) config('uploads.measurement_receipt.max_bytes') + 1),
    );

    expect(fn () => app(MeasurementFileValidationService::class)->validateReceipt(
        'nimbus_docs/measurements/receipts/oversized.pdf',
        'local',
    ))->toThrow(ValidationException::class);
});

it('records effective administrative reviewer separately from expected responsibility', function () {
    $admin = makeP01Admin();
    $expectedReviewer = makeP01Editor();
    $operation = Operation::factory()->create([
        'assigned_user_id' => $admin->id,
        'stage2_reviewer_user_id' => $expectedReviewer->id,
    ]);
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->id,
        'status' => 'in_review',
        'current_stage' => 2,
    ]);
    $review = $measurement->reviews()->create([
        'stage' => 2,
        'reviewer_user_id' => $expectedReviewer->id,
        'status' => 'pending',
    ]);
    $this->actingAs($admin);

    app(MeasurementWorkflow::class)->approve($measurement->fresh(), $admin);
    $activity = Activity::query()->where('description', 'measurement_stage_approved')->latest('id')->firstOrFail();

    expect($review->fresh()->reviewer_user_id)->toBe($admin->id)
        ->and($activity->properties->get('expected_responsible_user_id'))->toBe($expectedReviewer->id)
        ->and($activity->properties->get('actual_actor_user_id'))->toBe($admin->id);
});

it('audits asset creation replacement and removal without binary content', function () {
    $actor = makeP01Editor();
    $operation = Operation::factory()->create(['assigned_user_id' => $actor->id]);
    $measurement = Measurement::factory()->create(['operation_id' => $operation->id, 'storage_path' => null, 'filename' => null]);
    $planSet = MeasurementPlanSet::factory()->create(['operation_id' => $operation->id]);
    $this->actingAs($actor);
    putP01Pdf('nimbus_docs/measurements/assets/audit-a.pdf', suffix: 'a');
    putP01Pdf('nimbus_docs/measurements/assets/audit-b.pdf', suffix: 'b');

    $asset = $measurement->assets()->create([
        'plan_set_id' => $planSet->id,
        'storage_path' => 'nimbus_docs/measurements/assets/audit-a.pdf',
    ]);
    $oldHash = $asset->sha256;
    $asset->update(['storage_path' => 'nimbus_docs/measurements/assets/audit-b.pdf']);
    $newHash = $asset->fresh()->sha256;
    $asset->delete();

    $activities = Activity::query()
        ->where('log_name', 'measurement_assets')
        ->orderBy('id')
        ->get();

    expect($activities->pluck('description')->all())->toBe([
        'measurement_asset_created',
        'measurement_asset_replaced',
        'measurement_asset_removed',
    ])->and($activities[1]->properties->get('old_sha256'))->toBe($oldHash)
        ->and($activities[1]->properties->get('new_sha256'))->toBe($newHash)
        ->and($activities[1]->properties->has('content'))->toBeFalse();
});

it('preserves the separated receipt role when no uploader is configured', function () {
    $manager = makeP01Editor();
    $admin = makeP01Admin();
    $operation = Operation::factory()->create([
        'assigned_user_id' => $manager->id,
        'payment_manager_user_id' => $manager->id,
        'payment_receipt_uploader_user_id' => null,
    ]);
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->id,
        'status' => 'awaiting_receipt',
        'current_stage' => 5,
    ]);

    expect(app(MeasurementAuthorizationService::class)->canManageReceipts($manager, $measurement))->toBeFalse()
        ->and(app(MeasurementAuthorizationService::class)->canManageReceipts($admin, $measurement))->toBeTrue();
});

it('does not start measurements for terminal operations', function (string $status) {
    $admin = makeP01Admin();
    $operation = Operation::factory()->create([
        'status' => $status,
        'assigned_user_id' => $admin->id,
    ]);
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->id,
        'status' => 'pending',
        'current_stage' => 1,
    ]);

    expect(fn () => app(MeasurementWorkflow::class)->startReview($measurement, $admin))
        ->toThrow(AuthorizationException::class);
})->with(['canceled', 'completed']);
