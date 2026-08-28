<?php

use App\Enums\MeasurementResponsibility;
use App\Filament\Resources\Measurements\MeasurementResource;
use App\Filament\Widgets\Dashboard\MeasurementCockpit;
use App\Models\Measurement;
use App\Models\MeasurementPayment;
use App\Models\Operation;
use App\Models\ResponsibilityDelegation;
use App\Models\SlaConfiguration;
use App\Models\User;
use App\Services\MeasurementCockpitService;
use App\Services\MeasurementSlaService;
use App\Services\MeasurementWorkflow;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-08-27 12:00:00');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    config(['measurements.sla.calendar_code' => null]);

    foreach (range(1, 5) as $stage) {
        SlaConfiguration::factory()->forStage($stage, 4)->create();
    }
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/** @param list<string> $permissions */
function p2CockpitUser(array $permissions = ['measurements.pay']): User
{
    $user = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $user->givePermissionTo([
        'operations.view',
        'measurements.view',
        ...$permissions,
    ]);

    return $user;
}

/**
 * @param  array<string, mixed>  $measurementAttributes
 */
function p2CockpitMeasurement(User $responsible, array $measurementAttributes = []): Measurement
{
    $operation = Operation::factory()->create([
        'payment_manager_user_id' => $responsible->getKey(),
    ]);
    $measurement = Measurement::factory()->create(array_merge([
        'operation_id' => $operation->getKey(),
        'status' => 'awaiting_payment',
        'current_stage' => MeasurementWorkflow::STAGE_PAYMENT,
        'reference_month' => '2026-08-01',
    ], $measurementAttributes));
    $measurement->reviews()->create([
        'stage' => $measurement->current_stage,
        'status' => 'pending',
        'created_at' => now(),
    ]);

    return $measurement->fresh(['operation', 'reviews', 'pauses']);
}

it('scopes every KPI to the viewer portfolio', function () {
    $viewer = p2CockpitUser();
    $other = p2CockpitUser();
    p2CockpitMeasurement($viewer);
    p2CockpitMeasurement($other);

    $summary = app(MeasurementCockpitService::class)->summaryFor($viewer);

    expect($summary['total'])->toBe(1)
        ->and($summary['stages'][MeasurementWorkflow::STAGE_PAYMENT])->toBe(1);
});

it('preserves the existing global admin override without labeling it delegated', function () {
    $first = p2CockpitUser();
    $second = p2CockpitUser();
    p2CockpitMeasurement($first);
    p2CockpitMeasurement($second);
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $summary = app(MeasurementCockpitService::class)->summaryFor($admin);

    expect($summary['total'])->toBe(2)
        ->and($summary['delegated'])->toBe(0);
});

it('counts direct work without converting it into personal pendings', function () {
    $viewer = p2CockpitUser();
    p2CockpitMeasurement($viewer);

    $summary = app(MeasurementCockpitService::class)->summaryFor($viewer, ['assignment' => 'direct']);

    expect($summary['total'])->toBe(1)
        ->and($summary['delegated'])->toBe(0);
});

it('counts only effective delegated measurements', function () {
    $manager = p2CockpitUser();
    $delegate = p2CockpitUser();
    $measurement = p2CockpitMeasurement($manager);
    ResponsibilityDelegation::factory()->active()->forStage(
        MeasurementWorkflow::STAGE_PAYMENT,
        $measurement->operation,
        MeasurementResponsibility::PaymentManager,
    )->create([
        'delegator_user_id' => $manager->getKey(),
        'delegate_user_id' => $delegate->getKey(),
    ]);

    $summary = app(MeasurementCockpitService::class)->summaryFor($delegate);

    expect($summary['total'])->toBe(1)
        ->and($summary['delegated'])->toBe(1);
});

it('separates overdue approaching and paused SLA signals', function () {
    $viewer = p2CockpitUser();
    $overdue = p2CockpitMeasurement($viewer);
    $approaching = p2CockpitMeasurement($viewer);
    $paused = p2CockpitMeasurement($viewer, ['status' => 'paused']);
    $overdue->reviews()->update(['created_at' => '2026-08-17 12:00:00']);
    $approaching->reviews()->update(['created_at' => '2026-08-24 12:00:00']);
    $paused->reviews()->update(['created_at' => '2026-08-26 12:00:00']);
    $paused->pauses()->create([
        'stage' => MeasurementWorkflow::STAGE_PAYMENT,
        'paused_by' => $viewer->getKey(),
        'pause_reason' => 'Aguardando validação externa',
        'paused_operation_status' => 'awaiting_payment',
        'paused_at' => '2026-08-26 18:00:00',
    ]);

    $summary = app(MeasurementCockpitService::class)->summaryFor($viewer);

    expect($summary['overdue'])->toBe(1)
        ->and($summary['approaching'])->toBe(1)
        ->and($summary['paused'])->toBe(1);
});

it('consolidates payment and receipt counters with an explicitly non-accounting total', function () {
    $viewer = p2CockpitUser();
    $measurement = p2CockpitMeasurement($viewer, [
        'status' => 'awaiting_receipt',
        'current_stage' => MeasurementWorkflow::STAGE_FINALIZATION,
    ]);
    MeasurementPayment::factory()->create([
        'operation_id' => $measurement->operation_id,
        'measurement_id' => $measurement->getKey(),
        'amount' => 1500.25,
        'created_by' => $viewer->getKey(),
    ]);

    $summary = app(MeasurementCockpitService::class)->summaryFor($viewer);

    expect($summary['payment_count'])->toBe(1)
        ->and($summary['pending_receipt_count'])->toBe(1)
        ->and($summary['recorded_amount'])->toBe(1500.25)
        ->and($summary['awaiting_receipt'])->toBe(1);
});

it('preserves cockpit filters in KPI drill-down links', function () {
    $viewer = p2CockpitUser();
    $measurement = p2CockpitMeasurement($viewer);
    $pageFilters = [
        'competence_from' => '2026-08-01',
        'competence_to' => '2026-08-31',
        'operation_id' => $measurement->operation_id,
        'emission_id' => $measurement->operation->emission_id,
        'responsible_user_id' => $viewer->getKey(),
        'stage' => MeasurementWorkflow::STAGE_PAYMENT,
        'status' => 'awaiting_payment',
        'sla_status' => MeasurementSlaService::STATUS_ON_TIME,
        'assignment' => 'direct',
    ];
    $expectedUrl = MeasurementResource::getUrl('index', [
        'tableFilters' => [
            'competence_period' => [
                'from' => '2026-08-01',
                'to' => '2026-08-31',
            ],
            'operation_id' => ['value' => $measurement->operation_id],
            'emission_id' => ['value' => $measurement->operation->emission_id],
            'responsible_user_id' => ['value' => $viewer->getKey()],
            'stage' => ['value' => MeasurementWorkflow::STAGE_PAYMENT],
            'status' => ['value' => 'awaiting_payment'],
            'sla_status' => ['value' => MeasurementSlaService::STATUS_OVERDUE],
            'assignment' => ['value' => 'direct'],
        ],
    ]);
    $this->actingAs($viewer);

    Livewire::test(MeasurementCockpit::class, ['pageFilters' => $pageFilters])
        ->assertSet('pageFilters', $pageFilters)
        ->assertSee($expectedUrl)
        ->call('$refresh')
        ->assertSet('pageFilters', $pageFilters)
        ->assertSee($expectedUrl);
});

it('keeps My Pendings outside the cockpit widget implementation', function () {
    $viewer = p2CockpitUser();
    p2CockpitMeasurement($viewer);
    $this->actingAs($viewer);

    Livewire::test(MeasurementCockpit::class)
        ->assertSuccessful()
        ->assertSee('Medições e pagamentos')
        ->assertDontSee('Minhas pendências');
});

it('returns curated aggregates without technical metadata', function () {
    $viewer = p2CockpitUser();
    p2CockpitMeasurement($viewer);
    $summary = app(MeasurementCockpitService::class)->summaryFor($viewer);

    expect($summary)->not->toHaveKeys([
        'storage_path',
        'storage_disk',
        'sha256',
        'engineering_snapshot',
        'receipt_path',
        'receipt_sha256',
    ]);
});

it('uses grouped and eager-loaded queries with bounded growth', function () {
    $viewer = p2CockpitUser();

    foreach (range(1, 40) as $index) {
        p2CockpitMeasurement($viewer, ['reference_month' => now()->subMonths($index % 6)->startOfMonth()]);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $summary = app(MeasurementCockpitService::class)->summaryFor($viewer);
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($summary['total'])->toBe(40)
        ->and($queryCount)->toBeLessThan(20);
});

it('renders a clear empty state for an authorized empty scope', function () {
    $viewer = p2CockpitUser();
    $this->actingAs($viewer);

    Livewire::test(MeasurementCockpit::class)
        ->assertSee('Nenhuma medição corresponde ao recorte operacional no seu escopo.');
});
