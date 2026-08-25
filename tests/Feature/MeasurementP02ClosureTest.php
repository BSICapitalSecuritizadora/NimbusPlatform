<?php

use App\Exceptions\MeasurementWorkflowException;
use App\Filament\Resources\Operations\RelationManagers\PlanSetsRelationManager;
use App\Filament\Resources\Operations\Schemas\OperationForm;
use App\Models\Construction;
use App\Models\Emission;
use App\Models\Measurement;
use App\Models\MeasurementAsset;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Models\Operation;
use App\Models\User;
use App\Services\MeasurementEngineeringService;
use App\Services\MeasurementWorkflow;
use App\Services\OperationContextVisibilityService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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

function makeP02User(string $role = 'editor'): User
{
    $user = User::factory()->withTwoFactor()->create();
    $user->assignRole($role);

    return $user;
}

function putP02Pdf(string $path, string $suffix): void
{
    Storage::disk('local')->put($path, "%PDF-1.7\n{$suffix}\n%%EOF");
}

/**
 * @return array{
 *     actor: User,
 *     emission: Emission,
 *     operation: Operation,
 *     measurement: Measurement,
 *     planSets: Collection<int, MeasurementPlanSet>,
 *     lines: Collection<int, MeasurementPlanLine>,
 *     assets: Collection<int, MeasurementAsset>
 * }
 */
function createP02EngineeringScenario(int $planSetCount = 1): array
{
    $actor = makeP02User('admin');
    test()->actingAs($actor);
    $emission = Emission::factory()->create(['name' => 'Emissão P0.2']);
    $operation = Operation::factory()->forEmission($emission)->create([
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
        'current_stage' => MeasurementWorkflow::STAGE_ENGINEERING,
        'storage_path' => null,
        'filename' => null,
    ]);
    $planSets = collect();
    $lines = collect();
    $assets = collect();

    foreach (range(1, $planSetCount) as $sequence) {
        $construction = Construction::factory()->create([
            'emission_id' => $emission->id,
            'development_name' => "Empreendimento {$sequence}",
            'development_cnpj' => str_pad((string) $sequence, 14, '0', STR_PAD_LEFT),
        ]);
        $planSet = MeasurementPlanSet::factory()->create([
            'operation_id' => $operation->id,
            'construction_id' => $construction->id,
            'name' => "Plano {$sequence}",
            'is_default' => $sequence === 1,
            'construction_fund_amount' => 100000 * $sequence,
            'initial_incurred_amount' => 1000 * $sequence,
        ]);
        $line = MeasurementPlanLine::factory()->create([
            'operation_id' => $operation->id,
            'plan_set_id' => $planSet->id,
            'sequence_number' => 1,
            'measurement_date' => '2026-08-01',
            'planned_monthly_percent' => 12.5,
            'planned_cumulative_percent' => 25,
            'initial_realized_cumulative_percent' => 2,
            'realized_monthly_percent' => 0,
            'realized_cumulative_percent' => 0,
        ]);
        $path = "nimbus_docs/measurements/assets/p02-{$measurement->id}-{$sequence}.pdf";
        putP02Pdf($path, "asset-{$sequence}");
        $asset = $measurement->assets()->create([
            'plan_set_id' => $planSet->id,
            'plan_line_id' => $line->id,
            'storage_path' => $path,
            'storage_disk' => 'local',
        ]);
        $planSets->push($planSet);
        $lines->push($line);
        $assets->push($asset);
    }

    app(MeasurementWorkflow::class)->startReview($measurement->fresh(), $actor);

    return compact('actor', 'emission', 'operation', 'measurement', 'planSets', 'lines', 'assets');
}

/**
 * @param  array{actor: User, measurement: Measurement, planSets: Collection<int, MeasurementPlanSet>}  $scenario
 */
function approveP02Engineering(array $scenario, float $monthly = 10): void
{
    $progress = $scenario['planSets']->mapWithKeys(
        fn (MeasurementPlanSet $planSet): array => [$planSet->id => $monthly],
    )->all();

    app(MeasurementWorkflow::class)->approve(
        $scenario['measurement']->fresh(),
        $scenario['actor'],
        engineeringProgress: $progress,
    );
}

/**
 * @param  array{actor: User, measurement: Measurement, planSets: Collection<int, MeasurementPlanSet>}  $scenario
 */
function advanceP02ToReadyForFinalization(array $scenario): void
{
    $workflow = app(MeasurementWorkflow::class);
    approveP02Engineering($scenario);
    $workflow->approve($scenario['measurement']->fresh(), $scenario['actor']);
    $workflow->approve($scenario['measurement']->fresh(), $scenario['actor']);
    $payment = $workflow->registerPayment($scenario['measurement']->fresh(), $scenario['actor'], [
        'plan_set_id' => $scenario['planSets']->first()->id,
        'pay_date' => '2026-08-25',
        'amount' => 1000,
    ]);
    $workflow->approve($scenario['measurement']->fresh(), $scenario['actor']);
    $receiptPath = "nimbus_docs/measurements/receipts/p02-{$scenario['measurement']->id}.pdf";
    putP02Pdf($receiptPath, 'receipt');
    $workflow->attachReceipt($payment->fresh(), $scenario['actor'], $receiptPath, 'local');
}

it('persists the complete versioned Engineering context and approved evidence', function () {
    $scenario = createP02EngineeringScenario();
    approveP02Engineering($scenario, 10);
    $snapshot = $scenario['measurement']->fresh()->engineering_snapshot;
    $planSet = $scenario['planSets']->first()->fresh();
    $line = $scenario['lines']->first()->fresh();
    $asset = $scenario['assets']->first()->fresh();
    $requirement = $snapshot['plan_sets'][0];

    expect($snapshot)->toMatchArray([
        'schema_version' => MeasurementEngineeringService::SNAPSHOT_SCHEMA_VERSION,
        'measurement_id' => $scenario['measurement']->id,
        'operation_id' => $scenario['operation']->id,
        'emission_id' => $scenario['emission']->id,
        'reference_month' => '2026-08-01',
    ])->and($requirement)->toMatchArray([
        'plan_set_id' => $planSet->id,
        'construction_id' => $planSet->construction_id,
        'construction_emission_id' => $scenario['emission']->id,
        'construction_cnpj' => $planSet->construction->development_cnpj,
        'plan_set_name' => $planSet->name,
        'construction_name' => $planSet->construction->development_name,
        'is_default' => true,
        'construction_fund_amount' => '100000.00',
        'initial_incurred_amount' => '1000.00',
        'plan_line_id' => $line->id,
        'measurement_date' => '2026-08-01',
        'sequence_number' => 1,
        'planned_monthly_percent' => '12.50',
        'planned_cumulative_percent' => '25.00',
        'initial_realized_cumulative_percent' => '2.00',
        'realized_monthly_percent' => '10.00',
        'realized_cumulative_percent' => '12.00',
        'asset_id' => $asset->id,
        'storage_path' => $asset->storage_path,
        'storage_disk' => 'local',
        'sha256' => $asset->sha256,
        'mime_type' => 'application/pdf',
        'file_size' => $asset->size,
    ]);
});

it('blocks material model mutations after Engineering while allowing them before approval', function () {
    $scenario = createP02EngineeringScenario();
    $planSet = $scenario['planSets']->first();
    $line = $scenario['lines']->first();
    $construction = $planSet->construction;

    $planSet->update(['construction_fund_amount' => 120000]);
    $line->update(['planned_monthly_percent' => 13]);
    approveP02Engineering($scenario);

    expect(fn () => $planSet->fresh()->update(['construction_fund_amount' => 130000]))
        ->toThrow(MeasurementWorkflowException::class)
        ->and(fn () => $planSet->fresh()->update(['construction_id' => Construction::factory()->create(['emission_id' => $scenario['emission']->id])->id]))
        ->toThrow(MeasurementWorkflowException::class)
        ->and(fn () => $line->fresh()->update(['realized_monthly_percent' => 20]))
        ->toThrow(MeasurementWorkflowException::class)
        ->and(fn () => $construction->fresh()->update(['development_cnpj' => '99999999999999']))
        ->toThrow(MeasurementWorkflowException::class)
        ->and(fn () => $scenario['measurement']->fresh()->update(['reference_month' => '2026-09-01']))
        ->toThrow(MeasurementWorkflowException::class)
        ->and(fn () => $scenario['measurement']->fresh()->forceFill(['engineering_snapshot' => ['forged' => true]])->save())
        ->toThrow(MeasurementWorkflowException::class)
        ->and(fn () => $scenario['measurement']->fresh()->delete())
        ->toThrow(MeasurementWorkflowException::class)
        ->and(fn () => $scenario['operation']->fresh()->delete())
        ->toThrow(MeasurementWorkflowException::class);
});

it('blocks finalization after direct database divergence of any approved material invariant', function (string $tamper) {
    $scenario = createP02EngineeringScenario();
    advanceP02ToReadyForFinalization($scenario);

    match ($tamper) {
        'construction' => DB::table('measurement_plan_sets')->where('id', $scenario['planSets']->first()->id)->update([
            'construction_id' => Construction::factory()->create(['emission_id' => $scenario['emission']->id])->id,
        ]),
        'realized' => DB::table('measurement_plan_lines')->where('id', $scenario['lines']->first()->id)->update([
            'realized_monthly_percent' => 20,
        ]),
        'reference month' => DB::table('measurements')->where('id', $scenario['measurement']->id)->update([
            'reference_month' => '2026-09-01',
        ]),
        'asset metadata' => DB::table('measurement_assets')->where('id', $scenario['assets']->first()->id)->update([
            'mime_type' => 'image/png',
        ]),
    };

    expect(fn () => app(MeasurementWorkflow::class)->finalize(
        $scenario['measurement']->fresh(),
        $scenario['actor'],
    ))->toThrow(MeasurementWorkflowException::class);
})->with(['construction', 'realized', 'reference month', 'asset metadata']);

it('archives the invalidated snapshot and creates a different snapshot after Engineering reapproval', function () {
    $scenario = createP02EngineeringScenario();
    $workflow = app(MeasurementWorkflow::class);
    advanceP02ToReadyForFinalization($scenario);
    $oldSnapshot = $scenario['measurement']->fresh()->engineering_snapshot;
    $revisionBeforeReturn = (int) $scenario['measurement']->fresh()->workflow_revision;

    $workflow->returnToStage(
        $scenario['measurement']->fresh(),
        $scenario['actor'],
        MeasurementWorkflow::STAGE_ENGINEERING,
        'Nova vistoria necessária',
    );

    $reopened = $scenario['measurement']->fresh();
    $history = Activity::query()
        ->where('description', 'measurement_engineering_snapshot_invalidated')
        ->latest('id')
        ->firstOrFail();

    expect($reopened->reviewForStage(1)?->status)->toBe('pending')
        ->and($reopened->engineering_snapshot)->toBeNull()
        ->and($reopened->workflow_revision)->toBe($revisionBeforeReturn + 1)
        ->and($history->properties->get('engineering_snapshot'))->toBe($oldSnapshot);

    approveP02Engineering($scenario, 20);
    $newSnapshot = $scenario['measurement']->fresh()->engineering_snapshot;

    expect($newSnapshot)->not->toBe($oldSnapshot)
        ->and($newSnapshot['plan_sets'][0]['realized_monthly_percent'])->toBe('20.00')
        ->and(Activity::query()->where('description', 'measurement_engineering_snapshot_created')->count())->toBe(2);
});

it('keeps old Measurements on A B C while new Measurements can approve D and payments cannot retroact', function () {
    $scenario = createP02EngineeringScenario(3);
    $workflow = app(MeasurementWorkflow::class);
    approveP02Engineering($scenario);
    $constructionD = Construction::factory()->create(['emission_id' => $scenario['emission']->id]);
    $planSetD = MeasurementPlanSet::factory()->create([
        'operation_id' => $scenario['operation']->id,
        'construction_id' => $constructionD->id,
        'name' => 'Plano D',
    ]);

    $workflow->approve($scenario['measurement']->fresh(), $scenario['actor']);
    $workflow->approve($scenario['measurement']->fresh(), $scenario['actor']);

    expect(fn () => $workflow->registerPayment($scenario['measurement']->fresh(), $scenario['actor'], [
        'plan_set_id' => $planSetD->id,
        'pay_date' => '2026-08-25',
        'amount' => 1000,
    ]))->toThrow(ValidationException::class);

    $payment = $workflow->registerPayment($scenario['measurement']->fresh(), $scenario['actor'], [
        'plan_set_id' => $scenario['planSets']->first()->id,
        'pay_date' => '2026-08-25',
        'amount' => 1000,
    ]);
    $workflow->approve($scenario['measurement']->fresh(), $scenario['actor']);
    putP02Pdf('nimbus_docs/measurements/receipts/old-measurement.pdf', 'old-receipt');
    $workflow->attachReceipt($payment, $scenario['actor'], 'nimbus_docs/measurements/receipts/old-measurement.pdf', 'local');
    $workflow->finalize($scenario['measurement']->fresh(), $scenario['actor']);

    expect($scenario['measurement']->fresh()->status)->toBe('finalized')
        ->and($scenario['measurement']->fresh()->engineering_snapshot['plan_sets'])->toHaveCount(3);

    $newMeasurement = Measurement::factory()->create([
        'operation_id' => $scenario['operation']->id,
        'reference_month' => '2026-09-01',
        'status' => 'pending',
        'current_stage' => 1,
        'storage_path' => null,
        'filename' => null,
    ]);
    $allPlanSets = $scenario['operation']->planSets()->orderBy('id')->get();

    foreach ($allPlanSets as $index => $planSet) {
        $line = $planSet->lines()->create([
            'operation_id' => $scenario['operation']->id,
            'sequence_number' => 2,
            'measurement_date' => '2026-09-01',
            'planned_monthly_percent' => 10,
            'planned_cumulative_percent' => 35,
            'initial_realized_cumulative_percent' => 0,
        ]);
        $path = "nimbus_docs/measurements/assets/new-{$newMeasurement->id}-{$planSet->id}.pdf";
        putP02Pdf($path, "new-{$index}");
        $newMeasurement->assets()->create([
            'plan_set_id' => $planSet->id,
            'plan_line_id' => $line->id,
            'storage_path' => $path,
            'storage_disk' => 'local',
        ]);
    }

    $workflow->startReview($newMeasurement->fresh(), $scenario['actor']);
    $workflow->approve(
        $newMeasurement->fresh(),
        $scenario['actor'],
        engineeringProgress: $allPlanSets->mapWithKeys(fn (MeasurementPlanSet $planSet): array => [$planSet->id => 5])->all(),
    );

    expect($newMeasurement->fresh()->engineering_snapshot['plan_sets'])->toHaveCount(4)
        ->and(collect($newMeasurement->fresh()->engineering_snapshot['plan_sets'])->pluck('plan_set_id'))->toContain($planSetD->id);
});

it('scopes Operation emission and construction preload search labels and arbitrary IDs', function () {
    $participant = makeP02User();
    $outsider = makeP02User();
    $emissionA = Emission::factory()->create(['name' => 'Emissão Visível', 'maturity_date' => '2030-01-01']);
    $emissionB = Emission::factory()->create(['name' => 'Emissão Ultra Secreta', 'maturity_date' => '2040-01-01']);
    $constructionA1 = Construction::factory()->create(['emission_id' => $emissionA->id, 'development_name' => 'A1 Visível']);
    $constructionA2 = Construction::factory()->create(['emission_id' => $emissionA->id, 'development_name' => 'A2 Sigiloso']);
    $constructionB1 = Construction::factory()->create(['emission_id' => $emissionB->id, 'development_name' => 'B1 Ultra Secreto']);
    $operationA1 = Operation::factory()->forEmission($emissionA)->create(['assigned_user_id' => $participant->id]);
    $operationA2 = Operation::factory()->forEmission($emissionA)->create(['assigned_user_id' => $outsider->id]);
    $operationB1 = Operation::factory()->forEmission($emissionB)->create(['assigned_user_id' => $outsider->id]);
    MeasurementPlanSet::withoutEvents(fn () => MeasurementPlanSet::factory()->create([
        'operation_id' => $operationA1->id,
        'construction_id' => $constructionA1->id,
    ]));
    MeasurementPlanSet::withoutEvents(fn () => MeasurementPlanSet::factory()->create([
        'operation_id' => $operationA2->id,
        'construction_id' => $constructionA2->id,
    ]));
    MeasurementPlanSet::withoutEvents(fn () => MeasurementPlanSet::factory()->create([
        'operation_id' => $operationB1->id,
        'construction_id' => $constructionB1->id,
    ]));
    $this->actingAs($participant);

    $formProbe = new class extends OperationForm
    {
        public static function emissions(?string $search = null): array
        {
            return parent::emissionOptions($search);
        }

        public static function emissionName(mixed $id): ?string
        {
            return parent::emissionLabel($id);
        }

        public static function maturity(mixed $id): ?string
        {
            return parent::emissionMaturityDate($id);
        }

        public static function constructions(mixed $emissionId, ?string $search = null): array
        {
            return parent::constructionOptionsForEmission($emissionId, $search);
        }

        public static function constructionName(mixed $id): ?string
        {
            return parent::constructionLabel($id);
        }

        public static function developments(mixed $emissionId): array
        {
            return parent::developmentsForEmission($emissionId);
        }
    };

    expect($formProbe::emissions())->toBe([$emissionA->id => 'Emissão Visível'])
        ->and($formProbe::emissions('Ultra Secreta'))->toBe([])
        ->and($formProbe::emissionName($emissionB->id))->toBeNull()
        ->and($formProbe::maturity($emissionB->id))->toBeNull()
        ->and($formProbe::constructions($emissionA->id))->toBe([$constructionA1->id => 'A1 Visível'])
        ->and($formProbe::constructions($emissionA->id, 'Sigiloso'))->toBe([])
        ->and($formProbe::constructionName($constructionA2->id))->toBeNull()
        ->and($formProbe::constructionName($constructionB1->id))->toBeNull()
        ->and($formProbe::developments($emissionB->id))->toBe([]);

    $service = app(OperationContextVisibilityService::class);

    try {
        $operationA1->syncDevelopmentPlans([
            ['construction_id' => $constructionA2->id, 'construction_fund_amount' => 100],
        ], $participant);
        $this->fail('O payload forjado deveria ser rejeitado.');
    } catch (ValidationException $exception) {
        $serializedErrors = json_encode($exception->errors(), JSON_THROW_ON_ERROR);
        expect($serializedErrors)->not->toContain('A2 Sigiloso')
            ->and($serializedErrors)->not->toContain('Emissão Ultra Secreta');
    }

    expect(fn () => $operationA1->planSets()->create([
        'construction_id' => $constructionA2->id,
        'name' => 'Payload forjado',
    ]))->toThrow(ValidationException::class)
        ->and($service->findVisibleConstruction($participant, $constructionA2->id))->toBeNull();

    $relationProbe = new class extends PlanSetsRelationManager
    {
        public function options(mixed $emissionId, ?string $search = null): array
        {
            return parent::constructionOptions($emissionId, $search);
        }

        public function label(mixed $constructionId, mixed $emissionId): ?string
        {
            return parent::constructionLabel($constructionId, $emissionId);
        }
    };

    expect($relationProbe->options($emissionA->id))->toBe([$constructionA1->id => 'A1 Visível'])
        ->and($relationProbe->label($constructionA2->id, $emissionA->id))->toBeNull();
});

it('keeps global contextual visibility for admin and super-admin', function (string $role) {
    $actor = makeP02User($role);
    $emission = Emission::factory()->create();
    $construction = Construction::factory()->create(['emission_id' => $emission->id]);
    $this->actingAs($actor);
    $visibility = app(OperationContextVisibilityService::class);

    expect($visibility->findVisibleEmission($actor, $emission->id)?->id)->toBe($emission->id)
        ->and($visibility->findVisibleConstruction($actor, $construction->id)?->id)->toBe($construction->id);
})->with(['admin', 'super-admin']);
