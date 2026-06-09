<?php

use App\Filament\Resources\Measurements\Pages\CreateMeasurement;
use App\Filament\Resources\Measurements\Pages\ListMeasurements;
use App\Filament\Resources\Measurements\Pages\ViewMeasurement;
use App\Filament\Resources\Operations\Pages\CreateOperation;
use App\Filament\Resources\Operations\Pages\ListOperations;
use App\Models\Construction;
use App\Models\Emission;
use App\Models\Measurement;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Models\Operation;
use App\Models\User;
use App\Services\MeasurementWorkflow;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

function makeMeasurementAdminUser(): User
{
    $user = User::factory()->withTwoFactor()->create([
        'email' => fake()->unique()->safeEmail(),
    ]);
    $user->assignRole('admin');

    return $user;
}

it('renders the operations list and create pages', function () {
    $this->actingAs(makeMeasurementAdminUser());

    Livewire::test(ListOperations::class)->assertSuccessful();
    Livewire::test(CreateOperation::class)->assertSuccessful();
});

it('creates an operation and derives its title from the development', function () {
    $this->actingAs(makeMeasurementAdminUser());
    $emission = Emission::factory()->create();
    $construction = Construction::factory()->create([
        'emission_id' => $emission->id,
        'development_name' => 'Residencial Teste',
    ]);

    Livewire::test(CreateOperation::class)
        ->fillForm(['emission_id' => $emission->id, 'status' => 'active'])
        ->fillForm([
            'developments' => [
                ['construction_id' => $construction->id, 'construction_fund_amount' => '1.000.000,00'],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $operation = Operation::query()->first();

    expect($operation)->not->toBeNull()
        ->and($operation->emission_id)->toBe($emission->id)
        ->and($operation->title)->toBe('Residencial Teste')
        ->and($operation->code)->toStartWith('OP-')
        ->and($operation->planSets()->where('construction_id', $construction->id)->value('construction_fund_amount'))
        ->toBe('1000000.00');
});

it('allows selecting more than one rejection-notify user', function () {
    $this->actingAs(makeMeasurementAdminUser());
    $emission = Emission::factory()->create();
    $construction = Construction::factory()->create(['emission_id' => $emission->id]);
    $watcherA = User::factory()->create();
    $watcherB = User::factory()->create();

    Livewire::test(CreateOperation::class)
        ->fillForm([
            'emission_id' => $emission->id,
            'status' => 'active',
            'rejectionNotifyUsers' => [$watcherA->id, $watcherB->id],
        ])
        ->fillForm([
            'developments' => [
                ['construction_id' => $construction->id, 'construction_fund_amount' => '100.000,00'],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $operation = Operation::query()->latest('id')->first();

    expect($operation->rejectionNotifyUsers()->pluck('users.id')->sort()->values()->all())
        ->toBe(collect([$watcherA->id, $watcherB->id])->sort()->values()->all());
});

it('requires at least one development', function () {
    $this->actingAs(makeMeasurementAdminUser());
    $emission = Emission::factory()->create();

    Livewire::test(CreateOperation::class)
        ->fillForm([
            'emission_id' => $emission->id,
            'status' => 'active',
        ])
        ->call('create')
        ->assertHasFormErrors(['developments']);
});

it('auto-fills the due date from the selected emission', function () {
    $this->actingAs(makeMeasurementAdminUser());
    $emission = Emission::factory()->create(['maturity_date' => '2030-12-31']);

    Livewire::test(CreateOperation::class)
        ->fillForm(['emission_id' => $emission->id])
        ->assertFormSet(['due_date' => '2030-12-31']);
});

it('creates a plan set per selected development when the emission has many', function () {
    $this->actingAs(makeMeasurementAdminUser());
    $emission = Emission::factory()->create();
    $conviva1 = Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => 'Conviva I']);
    $conviva2 = Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => 'Conviva II']);

    Livewire::test(CreateOperation::class)
        ->fillForm(['emission_id' => $emission->id, 'status' => 'active'])
        ->fillForm([
            'developments' => [
                ['construction_id' => $conviva1->id, 'construction_fund_amount' => '500.000,00'],
                ['construction_id' => $conviva2->id, 'construction_fund_amount' => '750.000,00'],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $operation = Operation::query()->latest('id')->first();

    expect($operation->planSets()->count())->toBe(2)
        ->and($operation->planSets()->pluck('construction_id')->sort()->values()->all())
        ->toBe(collect([$conviva1->id, $conviva2->id])->sort()->values()->all())
        ->and($operation->planSets()->where('is_default', true)->count())->toBe(1)
        ->and($operation->title)->toBe('Conviva I, Conviva II')
        ->and($operation->planSets()->where('construction_id', $conviva1->id)->value('construction_fund_amount'))->toBe('500000.00')
        ->and($operation->planSets()->where('construction_id', $conviva2->id)->value('construction_fund_amount'))->toBe('750000.00');
});

it('renders the measurement plan editor (schedule) relation manager', function () {
    $this->actingAs(makeMeasurementAdminUser());
    $operation = Operation::factory()->create();

    Livewire::test(\App\Filament\Resources\Operations\RelationManagers\PlanSetsRelationManager::class, [
        'ownerRecord' => $operation,
        'pageClass' => \App\Filament\Resources\Operations\Pages\EditOperation::class,
    ])->assertSuccessful();
});

it('renders the read-only schedule monitoring with the plan lines', function () {
    $this->actingAs(makeMeasurementAdminUser());
    $operation = Operation::factory()->create();
    $planSet = MeasurementPlanSet::factory()->create(['operation_id' => $operation->id]);
    $line = MeasurementPlanLine::factory()->create([
        'plan_set_id' => $planSet->id,
        'operation_id' => $operation->id,
        'planned_cumulative_percent' => 40,
        'realized_cumulative_percent' => 55,
    ]);

    Livewire::test(\App\Filament\Resources\Operations\RelationManagers\PlanLinesRelationManager::class, [
        'ownerRecord' => $operation,
        'pageClass' => \App\Filament\Resources\Operations\Pages\ViewOperation::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$line]);

    expect($line->fresh()->evolution_trend)->toBe(MeasurementPlanLine::TREND_AHEAD);
});

it('edits the planned monthly and cumulative progress from the monitoring grid', function () {
    $this->actingAs(makeMeasurementAdminUser());
    $operation = Operation::factory()->create();
    $planSet = MeasurementPlanSet::factory()->create(['operation_id' => $operation->id]);
    $line = MeasurementPlanLine::factory()->create([
        'plan_set_id' => $planSet->id,
        'operation_id' => $operation->id,
        'sequence_number' => 1,
        'planned_monthly_percent' => 0,
        'planned_cumulative_percent' => 0,
    ]);

    Livewire::test(\App\Filament\Resources\Operations\RelationManagers\PlanLinesRelationManager::class, [
        'ownerRecord' => $operation,
        'pageClass' => \App\Filament\Resources\Operations\Pages\ViewOperation::class,
    ])
        ->callTableAction('editPlanned', $line, data: [
            'planned_monthly_percent' => 15,
            'planned_cumulative_percent' => 15,
        ])
        ->assertHasNoTableActionErrors();

    $line->refresh();

    expect($line->planned_monthly_percent)->toBe('15.00')
        ->and($line->planned_cumulative_percent)->toBe('15.00');
});

it('no longer exposes manual realized entry in the monitoring grid', function () {
    $this->actingAs(makeMeasurementAdminUser());
    $operation = Operation::factory()->create();
    $planSet = MeasurementPlanSet::factory()->create(['operation_id' => $operation->id]);
    $line = MeasurementPlanLine::factory()->create([
        'plan_set_id' => $planSet->id,
        'operation_id' => $operation->id,
    ]);

    Livewire::test(\App\Filament\Resources\Operations\RelationManagers\PlanLinesRelationManager::class, [
        'ownerRecord' => $operation,
        'pageClass' => \App\Filament\Resources\Operations\Pages\ViewOperation::class,
    ])
        ->assertTableActionExists('editPlanned')
        ->assertTableActionDoesNotExist('registerActual');
});

it('renders the measurements list page', function () {
    $this->actingAs(makeMeasurementAdminUser());

    Livewire::test(ListMeasurements::class)->assertSuccessful();
});

it('offers a per-development measurement slot when the operation is selected', function () {
    $this->actingAs(makeMeasurementAdminUser());

    $emission = Emission::factory()->create();
    $construction = Construction::factory()->create(['emission_id' => $emission->id]);
    $operation = Operation::factory()->forEmission($emission)->create();
    $planSet = MeasurementPlanSet::factory()->create([
        'operation_id' => $operation->id,
        'construction_id' => $construction->id,
        'is_default' => true,
    ]);
    MeasurementPlanLine::factory()->create([
        'plan_set_id' => $planSet->id,
        'operation_id' => $operation->id,
        'sequence_number' => 3,
        'measurement_date' => '2026-07-01',
    ]);

    $component = Livewire::test(CreateMeasurement::class)
        ->fillForm(['operation_id' => $operation->id]);

    $assets = collect($component->get('data.assets'))->values();

    expect($assets)->toHaveCount(1)
        ->and($assets->first())->toHaveKey('plan_line_id')
        ->and((int) $assets->first()['plan_set_id'])->toBe($planSet->id);
});

it('pre-fills one file slot per development when the operation is selected', function () {
    $this->actingAs(makeMeasurementAdminUser());

    $emission = Emission::factory()->create();
    $constructionA = Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => 'Obra A']);
    $constructionB = Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => 'Obra B']);
    $operation = Operation::factory()->forEmission($emission)->create();
    $planA = MeasurementPlanSet::factory()->create(['operation_id' => $operation->id, 'construction_id' => $constructionA->id]);
    $planB = MeasurementPlanSet::factory()->create(['operation_id' => $operation->id, 'construction_id' => $constructionB->id]);

    $component = Livewire::test(CreateMeasurement::class)
        ->fillForm(['operation_id' => $operation->id]);

    $assets = collect($component->get('data.assets'))->values();

    expect($assets)->toHaveCount(2)
        ->and($assets->pluck('plan_set_id')->map(fn ($id): int => (int) $id)->sort()->values()->all())
        ->toBe(collect([$planA->id, $planB->id])->sort()->values()->all());
});

it('persists one asset per development with its file and starts the review', function () {
    $this->actingAs($admin = makeMeasurementAdminUser());

    $emission = Emission::factory()->create();
    $constructionA = Construction::factory()->create(['emission_id' => $emission->id]);
    $constructionB = Construction::factory()->create(['emission_id' => $emission->id]);
    $operation = Operation::factory()->forEmission($emission)->create(['responsible_user_id' => $admin->id]);
    $planA = MeasurementPlanSet::factory()->create(['operation_id' => $operation->id, 'construction_id' => $constructionA->id]);
    $planB = MeasurementPlanSet::factory()->create(['operation_id' => $operation->id, 'construction_id' => $constructionB->id]);

    $measurement = Measurement::create([
        'operation_id' => $operation->id,
        'status' => 'pending',
        'current_stage' => 1,
        'uploaded_by' => $admin->id,
        'uploaded_at' => now(),
    ]);
    $measurement->assets()->createMany([
        ['plan_set_id' => $planA->id, 'storage_path' => 'measurements/a.pdf', 'filename' => 'a.pdf'],
        ['plan_set_id' => $planB->id, 'storage_path' => 'measurements/b.pdf', 'filename' => 'b.pdf'],
    ]);
    app(MeasurementWorkflow::class)->startReview($measurement);

    expect($measurement->fresh()->assets()->count())->toBe(2)
        ->and($measurement->fresh()->assets()->pluck('plan_set_id')->sort()->values()->all())
        ->toBe(collect([$planA->id, $planB->id])->sort()->values()->all())
        ->and($measurement->fresh()->status)->toBe('in_review');
});

it('requires an operation to send a measurement', function () {
    $this->actingAs(makeMeasurementAdminUser());

    Livewire::test(CreateMeasurement::class)
        ->call('create')
        ->assertHasFormErrors(['operation_id']);
});

it('exposes the review actions to the stage reviewer and approves a stage', function () {
    $reviewer = makeMeasurementAdminUser();
    $this->actingAs($reviewer);

    $operation = Operation::factory()->create(['responsible_user_id' => $reviewer->id]);
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->id,
        'status' => 'pending',
        'current_stage' => 1,
    ]);
    app(MeasurementWorkflow::class)->startReview($measurement);

    Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->assertSuccessful()
        ->assertActionVisible('approve')
        ->assertActionVisible('reject')
        ->assertActionHidden('pause')
        ->assertActionHidden('resume')
        ->callAction('approve', data: ['notes' => 'Ok']);

    expect($measurement->fresh()->status)->toBe('awaiting_payment');
});

it('hides the validation from users who are not responsible for the stage', function () {
    $reviewer = makeMeasurementAdminUser();
    $bystander = makeMeasurementAdminUser();
    $this->actingAs($bystander);

    $operation = Operation::factory()->create(['responsible_user_id' => $reviewer->id]);
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->id,
        'status' => 'pending',
        'current_stage' => 1,
    ]);
    app(MeasurementWorkflow::class)->startReview($measurement);

    Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->assertSuccessful()
        ->assertActionHidden('approve')
        ->assertActionHidden('reject')
        ->assertActionHidden('pause');
});

it('lets a super admin validate any stage', function () {
    $superAdmin = User::factory()->withTwoFactor()->create();
    $superAdmin->assignRole('super-admin');
    $this->actingAs($superAdmin);

    $reviewer = makeMeasurementAdminUser();
    $operation = Operation::factory()->create(['responsible_user_id' => $reviewer->id]);
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->id,
        'status' => 'pending',
        'current_stage' => 1,
    ]);
    app(MeasurementWorkflow::class)->startReview($measurement);

    Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->assertSuccessful()
        ->assertActionVisible('approve');
});

it('shows payment to the payment manager and finalize to the finalizer', function () {
    $manager = makeMeasurementAdminUser();
    $this->actingAs($manager);

    $operation = Operation::factory()->create([
        'payment_manager_user_id' => $manager->id,
        'payment_finalizer_user_id' => $manager->id,
    ]);
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->id,
        'status' => 'awaiting_payment',
        'current_stage' => 1,
    ]);

    Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->assertActionVisible('registerPayment')
        ->assertActionVisible('finalize')
        ->callAction('finalize');

    expect($measurement->fresh()->status)->toBe('finalized');
});
