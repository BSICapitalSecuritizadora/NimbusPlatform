<?php

use App\Enums\MeasurementResponsibility;
use App\Filament\Resources\Measurements\Pages\ListMeasurements;
use App\Filament\Resources\Measurements\Tables\MeasurementsTable;
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
use Illuminate\Database\Eloquent\Factories\Sequence;
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

/** @param array<string, mixed> $attributes */
function p2CockpitOperation(User $responsible, array $attributes = []): Operation
{
    return Operation::factory()->create(array_merge([
        'payment_manager_user_id' => $responsible->getKey(),
    ], $attributes));
}

/**
 * @param  array<string, mixed>  $measurementAttributes
 */
function p2CockpitMeasurement(User $responsible, array $measurementAttributes = []): Measurement
{
    return p2CockpitMeasurementForOperation(p2CockpitOperation($responsible), $measurementAttributes);
}

/**
 * @param  array<string, mixed>  $measurementAttributes
 */
function p2CockpitMeasurementForOperation(Operation $operation, array $measurementAttributes = []): Measurement
{
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

/** @return array<string, mixed> */
function p2CockpitQueryParameters(string $url): array
{
    $query = parse_url($url, PHP_URL_QUERY);
    $parameters = [];
    parse_str(is_string($query) ? $query : '', $parameters);

    return $parameters;
}

/**
 * @param  list<Measurement>  $visibleMeasurements
 * @param  list<Measurement>  $hiddenMeasurements
 */
function p2CockpitAssertDestinationPopulation(
    string $url,
    int $expectedCount,
    array $visibleMeasurements,
    array $hiddenMeasurements = [],
): void {
    $queryParameters = p2CockpitQueryParameters($url);

    expect($queryParameters)
        ->toHaveKey('filters')
        ->not->toHaveKey('tableFilters');

    $destination = Livewire::withQueryParams($queryParameters)
        ->test(ListMeasurements::class)
        ->assertCountTableRecords($expectedCount)
        ->assertCanSeeTableRecords($visibleMeasurements);

    foreach ($queryParameters['filters'] as $filter => $state) {
        $hydratedState = $destination->get("tableFilters.{$filter}");

        if ($filter === 'competence_period') {
            $hydratedState = collect($hydratedState)
                ->map(fn (mixed $date): ?string => filled($date) ? CarbonImmutable::parse($date)->toDateString() : null)
                ->all();
        }

        expect($hydratedState)->toBe($state);
    }

    if ($hiddenMeasurements !== []) {
        $destination->assertCanNotSeeTableRecords($hiddenMeasurements);
    }
}

/**
 * @return array{
 *     viewer: User,
 *     operationA: Operation,
 *     matchingOverdue: list<Measurement>,
 *     outsideCompetence: Measurement,
 *     outsideOperation: Measurement,
 *     onTime: Measurement
 * }
 */
function p2CockpitComposedFiltersFixture(): array
{
    $viewer = p2CockpitUser();
    $operationA = p2CockpitOperation($viewer);
    $operationB = p2CockpitOperation($viewer);
    $matchingOverdue = collect(range(1, 2))->map(function () use ($operationA): Measurement {
        $measurement = p2CockpitMeasurementForOperation($operationA);
        $measurement->reviews()->update(['created_at' => '2026-08-17 12:00:00']);

        return $measurement;
    });
    $outsideCompetence = p2CockpitMeasurementForOperation($operationA, ['reference_month' => '2026-07-01']);
    $outsideCompetence->reviews()->update(['created_at' => '2026-08-17 12:00:00']);
    $outsideOperation = p2CockpitMeasurementForOperation($operationB);
    $outsideOperation->reviews()->update(['created_at' => '2026-08-17 12:00:00']);

    return [
        'viewer' => $viewer,
        'operationA' => $operationA,
        'matchingOverdue' => $matchingOverdue->all(),
        'outsideCompetence' => $outsideCompetence,
        'outsideOperation' => $outsideOperation,
        'onTime' => p2CockpitMeasurementForOperation($operationA),
    ];
}

it('translates cockpit domain filters into the Filament measurement table state', function () {
    expect(MeasurementsTable::cockpitFiltersToTableState([
        'competence_from' => '2026-08-01',
        'competence_to' => '2026-08-31',
        'operation_id' => 10,
        'emission_id' => 20,
        'responsible_user_id' => 30,
        'stage' => 2,
        'status' => 'approved',
        'sla_status' => MeasurementSlaService::STATUS_OVERDUE,
        'assignment' => 'delegated',
    ]))->toBe([
        'competence_period' => [
            'from' => '2026-08-01',
            'to' => '2026-08-31',
        ],
        'operation_id' => ['value' => 10],
        'emission_id' => ['value' => 20],
        'responsible_user_id' => ['value' => 30],
        'stage' => ['value' => 2],
        'status' => ['value' => 'approved'],
        'sla_status' => ['value' => MeasurementSlaService::STATUS_OVERDUE],
        'assignment' => ['value' => 'delegated'],
    ]);
});

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

it('keeps an unfiltered overdue KPI equal to the measurement destination population', function () {
    $viewer = p2CockpitUser();
    $operation = p2CockpitOperation($viewer);
    $overdueMeasurements = collect(range(1, 7))
        ->map(fn (): Measurement => p2CockpitMeasurementForOperation($operation));
    $overdueMeasurements->each(fn (Measurement $measurement): int => $measurement->reviews()
        ->update(['created_at' => '2026-08-17 12:00:00']));
    $onTime = p2CockpitMeasurementForOperation($operation);
    $this->actingAs($viewer);

    $component = Livewire::test(MeasurementCockpit::class);
    $overdueSignal = collect($component->viewData('signals'))->firstWhere('label', 'SLA vencido');

    expect($overdueSignal['count'])->toBe(7)
        ->and($overdueSignal['url'])->toBeString();

    p2CockpitAssertDestinationPopulation(
        $overdueSignal['url'],
        7,
        $overdueMeasurements->all(),
        [$onTime],
    );
});

it('preserves an operation base filter in the overdue drill-down population', function () {
    $viewer = p2CockpitUser();
    $operationA = p2CockpitOperation($viewer);
    $operationB = p2CockpitOperation($viewer);
    $operationAOverdue = collect(range(1, 3))
        ->map(fn (): Measurement => p2CockpitMeasurementForOperation($operationA));
    $operationBOverdue = collect(range(1, 4))
        ->map(fn (): Measurement => p2CockpitMeasurementForOperation($operationB));
    $operationAOverdue
        ->concat($operationBOverdue)
        ->each(fn (Measurement $measurement): int => $measurement->reviews()
            ->update(['created_at' => '2026-08-17 12:00:00']));
    $this->actingAs($viewer);

    $component = Livewire::test(MeasurementCockpit::class, [
        'pageFilters' => ['operation_id' => $operationA->getKey()],
    ]);
    $overdueSignal = collect($component->viewData('signals'))->firstWhere('label', 'SLA vencido');
    $queryParameters = p2CockpitQueryParameters($overdueSignal['url']);

    expect($overdueSignal['count'])->toBe(3)
        ->and($overdueSignal['url'])->toBeString()
        ->and((int) $queryParameters['filters']['operation_id']['value'])->toBe($operationA->getKey())
        ->and($queryParameters['filters']['sla_status']['value'])->toBe(MeasurementSlaService::STATUS_OVERDUE);

    p2CockpitAssertDestinationPopulation(
        $overdueSignal['url'],
        3,
        $operationAOverdue->all(),
        $operationBOverdue->all(),
    );
});

it('removes the overdue drill-down when the base SLA filter is on time', function () {
    $viewer = p2CockpitUser();
    p2CockpitMeasurement($viewer);
    $overdue = p2CockpitMeasurement($viewer);
    $overdue->reviews()->update(['created_at' => '2026-08-17 12:00:00']);
    $pageFilters = ['sla_status' => MeasurementSlaService::STATUS_ON_TIME];
    $this->actingAs($viewer);

    $component = Livewire::test(MeasurementCockpit::class, ['pageFilters' => $pageFilters])
        ->assertSet('pageFilters', $pageFilters)
        ->assertSee('aria-disabled="true"', false);
    $overdueSignal = collect($component->viewData('signals'))->firstWhere('label', 'SLA vencido');

    expect($overdueSignal['count'])->toBe(0)
        ->and($overdueSignal['url'])->toBeNull();
});

it('removes a stage drill-down when the base stage is different', function () {
    $viewer = p2CockpitUser();
    p2CockpitMeasurement($viewer, [
        'status' => 'pending',
        'current_stage' => 2,
    ]);
    p2CockpitMeasurement($viewer, [
        'status' => 'pending',
        'current_stage' => 4,
    ]);
    $this->actingAs($viewer);

    $component = Livewire::test(MeasurementCockpit::class, [
        'pageFilters' => ['stage' => 2],
    ])->assertSee('aria-disabled="true"', false);
    $stageFour = $component->viewData('stages')->firstWhere('label', MeasurementWorkflow::STAGE_LABELS[4]);

    expect($stageFour['count'])->toBe(0)
        ->and($stageFour['url'])->toBeNull();
});

it('removes a status drill-down when the base status is different', function () {
    $viewer = p2CockpitUser();
    p2CockpitMeasurement($viewer, [
        'status' => 'approved',
        'current_stage' => MeasurementWorkflow::STAGE_FINALIZATION,
    ]);
    p2CockpitMeasurement($viewer, ['status' => 'paused']);
    $this->actingAs($viewer);

    $component = Livewire::test(MeasurementCockpit::class, [
        'pageFilters' => ['status' => 'approved'],
    ])->assertSee('aria-disabled="true"', false);
    $pausedSignal = collect($component->viewData('signals'))->firstWhere('label', 'Pausadas');

    expect($pausedSignal['count'])->toBe(0)
        ->and($pausedSignal['url'])->toBeNull();
});

it('removes the delegated drill-down when the base assignment is direct', function () {
    $viewer = p2CockpitUser();
    $manager = p2CockpitUser();
    p2CockpitMeasurement($viewer);
    $delegatedMeasurement = p2CockpitMeasurement($manager);
    ResponsibilityDelegation::factory()->active()->forStage(
        MeasurementWorkflow::STAGE_PAYMENT,
        $delegatedMeasurement->operation,
        MeasurementResponsibility::PaymentManager,
    )->create([
        'delegator_user_id' => $manager->getKey(),
        'delegate_user_id' => $viewer->getKey(),
    ]);
    $this->actingAs($viewer);

    $component = Livewire::test(MeasurementCockpit::class, [
        'pageFilters' => ['assignment' => 'direct'],
    ])->assertSee('aria-disabled="true"', false);
    $delegatedSignal = collect($component->viewData('signals'))->firstWhere('label', 'Delegadas no meu escopo');

    expect($delegatedSignal['count'])->toBe(0)
        ->and($delegatedSignal['url'])->toBeNull();
});

it('keeps the overdue drill-down when the base SLA filter has the same value', function () {
    $viewer = p2CockpitUser();
    $operation = p2CockpitOperation($viewer);
    $overdueMeasurements = collect(range(1, 2))
        ->map(fn (): Measurement => p2CockpitMeasurementForOperation($operation));
    $overdueMeasurements->each(fn (Measurement $measurement): int => $measurement->reviews()
        ->update(['created_at' => '2026-08-17 12:00:00']));
    $onTime = p2CockpitMeasurementForOperation($operation);
    $pageFilters = ['sla_status' => MeasurementSlaService::STATUS_OVERDUE];
    $this->actingAs($viewer);

    $component = Livewire::test(MeasurementCockpit::class, ['pageFilters' => $pageFilters]);
    $overdueSignal = collect($component->viewData('signals'))->firstWhere('label', 'SLA vencido');
    $queryParameters = p2CockpitQueryParameters($overdueSignal['url']);

    expect($overdueSignal['count'])->toBe(2)
        ->and($overdueSignal['url'])->toBeString()
        ->and($queryParameters['filters']['sla_status']['value'])->toBe(MeasurementSlaService::STATUS_OVERDUE);

    p2CockpitAssertDestinationPopulation(
        $overdueSignal['url'],
        2,
        $overdueMeasurements->all(),
        [$onTime],
    );
});

it('keeps a stage drill-down when the base stage has the same value', function () {
    $viewer = p2CockpitUser();
    $operation = p2CockpitOperation($viewer);
    $stageTwoMeasurements = collect(range(1, 2))
        ->map(fn (): Measurement => p2CockpitMeasurementForOperation($operation, [
            'status' => 'pending',
            'current_stage' => 2,
        ]));
    $stageFour = p2CockpitMeasurementForOperation($operation, [
        'status' => 'pending',
        'current_stage' => 4,
    ]);
    $this->actingAs($viewer);

    $component = Livewire::test(MeasurementCockpit::class, [
        'pageFilters' => ['stage' => 2],
    ]);
    $stageTwo = $component->viewData('stages')->firstWhere('label', MeasurementWorkflow::STAGE_LABELS[2]);
    $queryParameters = p2CockpitQueryParameters($stageTwo['url']);

    expect($stageTwo['count'])->toBe(2)
        ->and($stageTwo['url'])->toBeString()
        ->and((int) $queryParameters['filters']['stage']['value'])->toBe(2);

    p2CockpitAssertDestinationPopulation(
        $stageTwo['url'],
        2,
        $stageTwoMeasurements->all(),
        [$stageFour],
    );
});

it('keeps a status drill-down when the base status has the same value', function () {
    $viewer = p2CockpitUser();
    $operation = p2CockpitOperation($viewer);
    $pausedMeasurements = collect(range(1, 2))
        ->map(fn (): Measurement => p2CockpitMeasurementForOperation($operation, ['status' => 'paused']));
    $approved = p2CockpitMeasurementForOperation($operation, [
        'status' => 'approved',
        'current_stage' => MeasurementWorkflow::STAGE_FINALIZATION,
    ]);
    $this->actingAs($viewer);

    $component = Livewire::test(MeasurementCockpit::class, [
        'pageFilters' => ['status' => 'paused'],
    ]);
    $pausedSignal = collect($component->viewData('signals'))->firstWhere('label', 'Pausadas');
    $queryParameters = p2CockpitQueryParameters($pausedSignal['url']);

    expect($pausedSignal['count'])->toBe(2)
        ->and($pausedSignal['url'])->toBeString()
        ->and($queryParameters['filters']['status']['value'])->toBe('paused');

    p2CockpitAssertDestinationPopulation(
        $pausedSignal['url'],
        2,
        $pausedMeasurements->all(),
        [$approved],
    );
});

it('keeps the delegated drill-down when the base assignment has the same value', function () {
    $viewer = p2CockpitUser();
    $manager = p2CockpitUser();
    $operation = p2CockpitOperation($manager);
    $delegatedMeasurements = collect(range(1, 2))
        ->map(fn (): Measurement => p2CockpitMeasurementForOperation($operation));
    ResponsibilityDelegation::factory()->active()->forStage(
        MeasurementWorkflow::STAGE_PAYMENT,
        $operation,
        MeasurementResponsibility::PaymentManager,
    )->create([
        'delegator_user_id' => $manager->getKey(),
        'delegate_user_id' => $viewer->getKey(),
    ]);
    $directMeasurement = p2CockpitMeasurement($viewer);
    $this->actingAs($viewer);

    $component = Livewire::test(MeasurementCockpit::class, [
        'pageFilters' => ['assignment' => 'delegated'],
    ]);
    $delegatedSignal = collect($component->viewData('signals'))->firstWhere('label', 'Delegadas no meu escopo');
    $queryParameters = p2CockpitQueryParameters($delegatedSignal['url']);

    expect($delegatedSignal['count'])->toBe(2)
        ->and($delegatedSignal['url'])->toBeString()
        ->and($queryParameters['filters']['assignment']['value'])->toBe('delegated');

    p2CockpitAssertDestinationPopulation(
        $delegatedSignal['url'],
        2,
        $delegatedMeasurements->all(),
        [$directMeasurement],
    );
});

it('keeps operation and overdue composition equal to its destination population', function () {
    $fixture = p2CockpitComposedFiltersFixture();
    $this->actingAs($fixture['viewer']);

    $component = Livewire::test(MeasurementCockpit::class, [
        'pageFilters' => ['operation_id' => $fixture['operationA']->getKey()],
    ]);
    $overdueSignal = collect($component->viewData('signals'))->firstWhere('label', 'SLA vencido');

    expect($overdueSignal['count'])->toBe(3);

    p2CockpitAssertDestinationPopulation(
        $overdueSignal['url'],
        3,
        [...$fixture['matchingOverdue'], $fixture['outsideCompetence']],
        [$fixture['outsideOperation'], $fixture['onTime']],
    );
});

it('keeps operation competence and overdue composition equal to its destination population', function () {
    $fixture = p2CockpitComposedFiltersFixture();
    $this->actingAs($fixture['viewer']);

    $component = Livewire::test(MeasurementCockpit::class, [
        'pageFilters' => [
            'competence_from' => '2026-08-01',
            'competence_to' => '2026-08-31',
            'operation_id' => $fixture['operationA']->getKey(),
        ],
    ]);
    $overdueSignal = collect($component->viewData('signals'))->firstWhere('label', 'SLA vencido');

    expect($overdueSignal['count'])->toBe(2);

    p2CockpitAssertDestinationPopulation(
        $overdueSignal['url'],
        2,
        $fixture['matchingOverdue'],
        [$fixture['outsideCompetence'], $fixture['outsideOperation'], $fixture['onTime']],
    );
});

it('keeps operation responsible and overdue composition equal to its destination population', function () {
    $fixture = p2CockpitComposedFiltersFixture();
    $this->actingAs($fixture['viewer']);

    $component = Livewire::test(MeasurementCockpit::class, [
        'pageFilters' => [
            'operation_id' => $fixture['operationA']->getKey(),
            'responsible_user_id' => $fixture['viewer']->getKey(),
        ],
    ]);
    $overdueSignal = collect($component->viewData('signals'))->firstWhere('label', 'SLA vencido');

    expect($overdueSignal['count'])->toBe(3);

    p2CockpitAssertDestinationPopulation(
        $overdueSignal['url'],
        3,
        [...$fixture['matchingOverdue'], $fixture['outsideCompetence']],
        [$fixture['outsideOperation'], $fixture['onTime']],
    );
});

it('keeps competence responsible and overdue composition equal to its destination population', function () {
    $fixture = p2CockpitComposedFiltersFixture();
    $this->actingAs($fixture['viewer']);

    $component = Livewire::test(MeasurementCockpit::class, [
        'pageFilters' => [
            'competence_from' => '2026-08-01',
            'competence_to' => '2026-08-31',
            'responsible_user_id' => $fixture['viewer']->getKey(),
        ],
    ]);
    $overdueSignal = collect($component->viewData('signals'))->firstWhere('label', 'SLA vencido');

    expect($overdueSignal['count'])->toBe(3);

    p2CockpitAssertDestinationPopulation(
        $overdueSignal['url'],
        3,
        [...$fixture['matchingOverdue'], $fixture['outsideOperation']],
        [$fixture['outsideCompetence'], $fixture['onTime']],
    );
});

it('preserves multiple base filters in the overdue population without mutating page filters', function () {
    $fixture = p2CockpitComposedFiltersFixture();
    $pageFilters = [
        'competence_from' => '2026-08-01',
        'competence_to' => '2026-08-31',
        'operation_id' => $fixture['operationA']->getKey(),
        'responsible_user_id' => $fixture['viewer']->getKey(),
    ];
    $this->actingAs($fixture['viewer']);

    $component = Livewire::test(MeasurementCockpit::class, ['pageFilters' => $pageFilters])
        ->assertSet('pageFilters', $pageFilters);
    $overdueSignal = collect($component->viewData('signals'))->firstWhere('label', 'SLA vencido');
    $queryParameters = p2CockpitQueryParameters($overdueSignal['url']);

    expect($overdueSignal['count'])->toBe(2)
        ->and($overdueSignal['url'])->toBeString()
        ->and($queryParameters['filters']['competence_period'])->toBe([
            'from' => '2026-08-01',
            'to' => '2026-08-31',
        ])
        ->and((int) $queryParameters['filters']['operation_id']['value'])->toBe($fixture['operationA']->getKey())
        ->and((int) $queryParameters['filters']['responsible_user_id']['value'])->toBe($fixture['viewer']->getKey())
        ->and($queryParameters['filters']['sla_status']['value'])->toBe(MeasurementSlaService::STATUS_OVERDUE);

    p2CockpitAssertDestinationPopulation(
        $overdueSignal['url'],
        2,
        $fixture['matchingOverdue'],
        [$fixture['outsideCompetence'], $fixture['outsideOperation'], $fixture['onTime']],
    );

    $component->call('$refresh')->assertSet('pageFilters', $pageFilters);
    $refreshedSignal = collect($component->viewData('signals'))->firstWhere('label', 'SLA vencido');

    expect($refreshedSignal['url'])->toBe($overdueSignal['url']);
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

it('processes more than one cockpit chunk and counts records after position 100', function () {
    $viewer = p2CockpitUser();
    $operation = p2CockpitOperation($viewer);
    $measurements = Measurement::factory()
        ->count(205)
        ->state(new Sequence(
            fn (Sequence $sequence): array => [
                'operation_id' => $operation->getKey(),
                'status' => 'pending',
                'current_stage' => match ($sequence->index) {
                    0 => 1,
                    100 => 2,
                    204 => 4,
                    default => 3,
                },
                'reference_month' => '2026-08-01',
            ],
        ))
        ->create()
        ->values();
    $measurements->each(function (Measurement $measurement): void {
        $measurement->reviews()->create([
            'stage' => $measurement->current_stage,
            'status' => 'pending',
            'created_at' => now(),
        ]);
    });

    $firstMeasurement = $measurements->get(0);
    $measurementAfterFirstChunk = $measurements->get(100);
    $lastMeasurement = $measurements->get(204);
    $firstMeasurement->reviews()->update(['created_at' => '2026-08-17 12:00:00']);
    $measurementAfterFirstChunk->reviews()->update(['created_at' => '2026-08-24 12:00:00']);
    $lastMeasurement->reviews()->update(['created_at' => '2026-08-17 12:00:00']);

    $otherViewer = p2CockpitUser();
    $invisibleMeasurement = p2CockpitMeasurement($otherViewer, [
        'status' => 'pending',
        'current_stage' => 5,
    ]);
    $invisibleMeasurement->reviews()->update(['created_at' => '2026-08-17 12:00:00']);

    $summary = null;
    expect(function () use ($viewer, &$summary): void {
        $summary = app(MeasurementCockpitService::class)->summaryFor($viewer);
    })->not->toThrow(Throwable::class);

    expect($measurementAfterFirstChunk->getKey())->toBe($firstMeasurement->getKey() + 100)
        ->and($lastMeasurement->getKey())->toBe($firstMeasurement->getKey() + 204)
        ->and($firstMeasurement->current_stage)->toBe(1)
        ->and($measurementAfterFirstChunk->current_stage)->toBe(2)
        ->and($lastMeasurement->current_stage)->toBe(4)
        ->and($invisibleMeasurement->current_stage)->toBe(5)
        ->and($summary)->toBeArray()
        ->and($summary['total'])->toBe(205)
        ->and($summary['stages'][1])->toBe(1)
        ->and($summary['stages'][2])->toBe(1)
        ->and($summary['stages'][3])->toBe(202)
        ->and($summary['stages'][4])->toBe(1)
        ->and($summary['stages'][5])->toBe(0)
        ->and($summary['overdue'])->toBe(2)
        ->and($summary['approaching'])->toBe(1);
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
