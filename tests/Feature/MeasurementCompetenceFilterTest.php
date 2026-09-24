<?php

use App\Filament\Forms\Components\MonthPicker;
use App\Filament\Resources\Measurements\Pages\ListMeasurements;
use App\Models\Measurement;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);

    $admin = User::factory()->withTwoFactor()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);
});

/**
 * Uma medição por competência do cenário. O dia gravado em `reference_month` varia de
 * propósito: vem da medição prevista no cronograma e não é sempre o dia 1.
 *
 * @return array<string, Measurement>
 */
function competenceFilterMeasurements(): array
{
    return collect([
        '03/2026' => '2026-03-10',
        '04/2026' => '2026-04-15',
        '05/2026' => '2026-05-01',
        '09/2026' => '2026-09-30',
        '10/2026' => '2026-10-01',
    ])->map(fn (string $referenceMonth): Measurement => Measurement::factory()->create([
        'reference_month' => $referenceMonth,
        'status' => 'in_review',
    ]))->all();
}

/**
 * @param  array<string, Measurement>  $measurements
 * @param  list<string>  $visibleCompetences
 */
function assertOnlyCompetencesVisible(Testable $component, array $measurements, array $visibleCompetences): void
{
    $component
        ->assertCountTableRecords(count($visibleCompetences))
        ->assertCanSeeTableRecords(collect($measurements)->only($visibleCompetences)->values())
        ->assertCanNotSeeTableRecords(collect($measurements)->except($visibleCompetences)->values());
}

it('lists every competence when neither bound is filled', function () {
    $measurements = competenceFilterMeasurements();

    $component = Livewire::test(ListMeasurements::class)
        ->filterTable('competence_period', ['from' => null, 'to' => null]);

    assertOnlyCompetencesVisible($component, $measurements, ['03/2026', '04/2026', '05/2026', '09/2026', '10/2026']);
});

it('filters the competence period as an inclusive monthly range', function (?string $from, ?string $to, array $expected) {
    $measurements = competenceFilterMeasurements();

    $component = Livewire::test(ListMeasurements::class)
        ->filterTable('competence_period', ['from' => $from, 'to' => $to]);

    assertOnlyCompetencesVisible($component, $measurements, $expected);
})->with([
    'somente inicial' => ['2026-04', null, ['04/2026', '05/2026', '09/2026', '10/2026']],
    'somente final' => [null, '2026-09', ['03/2026', '04/2026', '05/2026', '09/2026']],
    'inicial e final' => ['2026-04', '2026-09', ['04/2026', '05/2026', '09/2026']],
    'mesma competência' => ['2026-09', '2026-09', ['09/2026']],
    'competência sem medição' => ['2026-06', '2026-08', []],
]);

it('reads legacy day-precision state by the month it belongs to', function () {
    $measurements = competenceFilterMeasurements();

    $component = Livewire::test(ListMeasurements::class)
        ->filterTable('competence_period', ['from' => '2026-04-20', 'to' => '2026-09-01']);

    assertOnlyCompetencesVisible($component, $measurements, ['04/2026', '05/2026', '09/2026']);
});

it('fails closed when a bound is unreadable', function () {
    competenceFilterMeasurements();

    Livewire::test(ListMeasurements::class)
        ->filterTable('competence_period', ['from' => 'abril', 'to' => null])
        ->assertCountTableRecords(0);
});

it('applies the range from the filters panel and resets pagination', function () {
    $measurements = competenceFilterMeasurements();
    Measurement::factory()->count(12)->create(['reference_month' => '2026-03-05', 'status' => 'in_review']);

    $component = Livewire::test(ListMeasurements::class)
        ->call('gotoPage', 2);

    expect($component->instance()->getPage())->toBe(2);

    $component
        ->set('tableDeferredFilters.competence_period', ['from' => '2026-04', 'to' => '2026-09'])
        ->call('applyTableFilters')
        ->assertSet('tableFilters.competence_period', ['from' => '2026-04', 'to' => '2026-09']);

    expect($component->instance()->getPage())->toBe(1);

    assertOnlyCompetencesVisible($component, $measurements, ['04/2026', '05/2026', '09/2026']);
});

it('refuses to apply a final competence earlier than the initial one', function () {
    $measurements = competenceFilterMeasurements();

    $component = Livewire::test(ListMeasurements::class)
        ->set('tableDeferredFilters.competence_period', ['from' => '2026-09', 'to' => '2026-04'])
        ->call('applyTableFilters')
        ->assertNotified('Intervalo de competência inválido')
        ->assertSet('tableDeferredFilters.competence_period', ['from' => '2026-09', 'to' => '2026-04']);

    expect($component->get('tableFilters.competence_period'))->toBe(['from' => null, 'to' => null]);

    assertOnlyCompetencesVisible($component, $measurements, ['03/2026', '04/2026', '05/2026', '09/2026', '10/2026']);
});

it('clears both bounds when the filters are reset', function () {
    $measurements = competenceFilterMeasurements();

    $component = Livewire::test(ListMeasurements::class)
        ->filterTable('competence_period', ['from' => '2026-04', 'to' => '2026-05'])
        ->resetTableFilters()
        ->assertSet('tableFilters.competence_period', ['from' => null, 'to' => null])
        ->assertSet('tableDeferredFilters.competence_period', ['from' => null, 'to' => null]);

    assertOnlyCompetencesVisible($component, $measurements, ['03/2026', '04/2026', '05/2026', '09/2026', '10/2026']);
});

it('clears both bounds when its indicator is removed', function () {
    $measurements = competenceFilterMeasurements();

    $component = Livewire::test(ListMeasurements::class)
        ->filterTable('competence_period', ['from' => '2026-04', 'to' => '2026-05'])
        ->removeTableFilter('competence_period')
        ->assertSet('tableFilters.competence_period', ['from' => null, 'to' => null]);

    assertOnlyCompetencesVisible($component, $measurements, ['03/2026', '04/2026', '05/2026', '09/2026', '10/2026']);
});

it('combines the competence range with the status filter', function () {
    $measurements = competenceFilterMeasurements();
    $approvedInRange = Measurement::factory()->create(['reference_month' => '2026-05-20', 'status' => 'approved']);
    $approvedOutOfRange = Measurement::factory()->create(['reference_month' => '2026-10-20', 'status' => 'approved']);

    Livewire::test(ListMeasurements::class)
        ->filterTable('competence_period', ['from' => '2026-04', 'to' => '2026-09'])
        ->filterTable('status', 'approved')
        ->assertCountTableRecords(1)
        ->assertCanSeeTableRecords([$approvedInRange])
        ->assertCanNotSeeTableRecords([$approvedOutOfRange, ...array_values($measurements)]);
});

it('describes the applied range in a single indicator', function (array $state, string $expected) {
    Measurement::factory()->create();

    $component = Livewire::test(ListMeasurements::class)
        ->filterTable('competence_period', $state);

    $indicators = $component->instance()->getTable()->getFilter('competence_period')->getIndicators();

    expect($indicators)->toHaveCount(1)
        ->and($indicators[0]->getLabel())->toBe($expected);
})->with([
    'intervalo' => [['from' => '2026-04', 'to' => '2026-09'], 'Competência: 04/2026 até 09/2026'],
    'mesma competência' => [['from' => '2026-09', 'to' => '2026-09'], 'Competência: 09/2026'],
    'somente inicial' => [['from' => '2026-04', 'to' => null], 'Competência a partir de 04/2026'],
    'somente final' => [['from' => null, 'to' => '2026-09'], 'Competência até 09/2026'],
]);

it('renders both bounds as month pickers wired to each other', function () {
    Measurement::factory()->create();

    Livewire::test(ListMeasurements::class)
        ->assertTableFilterExists('competence_period')
        ->assertSee('Competência inicial')
        ->assertSee('Competência final')
        ->assertSeeHtml('placeholder="mm/aaaa"')
        ->assertDontSeeHtml('placeholder="dd/mm/aaaa"')
        ->assertSeeHtml("notAfterStatePath: 'tableDeferredFilters.competence_period.to'")
        ->assertSeeHtml("notBeforeStatePath: 'tableDeferredFilters.competence_period.from'");
});

it('parses the month picker state by competence month', function (mixed $value, ?string $expected) {
    expect(MonthPicker::parseMonth($value)?->toDateString())->toBe($expected);
})->with([
    'mês/ano' => ['2026-09', '2026-09-01'],
    'data legada no meio do mês' => ['2026-09-17', '2026-09-01'],
    'vazio' => ['', null],
    'nulo' => [null, null],
    'mês inexistente' => ['2026-13', null],
    'dia inexistente' => ['2026-02-30', null],
    'formato de exibição' => ['09/2026', null],
]);
