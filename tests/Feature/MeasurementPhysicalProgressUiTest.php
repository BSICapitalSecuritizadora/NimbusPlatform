<?php

use App\Filament\Resources\Measurements\Pages\ViewMeasurement;
use App\Filament\Resources\Operations\Pages\CreateOperation;
use App\Filament\Resources\Operations\Pages\EditOperation;
use App\Filament\Resources\Operations\Pages\ViewOperation;
use App\Filament\Resources\Operations\RelationManagers\PlanLinesRelationManager;
use App\Filament\Resources\Operations\RelationManagers\PlanSetsRelationManager;
use App\Models\Construction;
use App\Models\Emission;
use App\Models\MeasurementPlanSet;
use App\Models\Operation;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Forms\Components\Placeholder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\MeasurementPhysicalProgressScenario as Scenario;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    Notification::fake();
});

/** @return array{emission: Emission, construction: Construction, operation: Operation} */
function physicalProgressPlanScenario(): array
{
    $emission = Emission::factory()->create();
    $construction = Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => 'Residencial Baseline']);
    $operation = Operation::factory()->create(['emission_id' => $emission->id]);

    return compact('emission', 'construction', 'operation');
}

function physicalProgressPlanSets(Operation $operation)
{
    return Livewire::test(PlanSetsRelationManager::class, [
        'ownerRecord' => $operation,
        'pageClass' => ViewOperation::class,
    ]);
}

function physicalProgressSchedule(Operation $operation)
{
    return Livewire::test(PlanLinesRelationManager::class, [
        'ownerRecord' => $operation,
        'pageClass' => ViewOperation::class,
    ]);
}

/**
 * Conteúdo do bloco de contexto do modal montado. O modal vai na resposta como
 * JSON escapado, então o HTML não serve para procurar texto acentuado.
 */
function physicalProgressModalContent(Testable $component, int $planSetId): string
{
    $name = $component->instance()->getMountedActionSchemaName();

    foreach ($component->instance()->{$name}->getFlatComponents(withHidden: true) as $key => $item) {
        if (str_ends_with($key, "physical_progress_context.{$planSetId}") && $item instanceof Placeholder) {
            return (string) $item->getContent();
        }
    }

    return '';
}

// ── Modal do plano ───────────────────────────────────────────────────────────

it('creates a plan with its initial physical progress through the plan modal', function () {
    $this->actingAs(makeAdminUser());
    ['operation' => $operation, 'construction' => $construction] = physicalProgressPlanScenario();

    physicalProgressPlanSets($operation)
        ->callTableAction('create', data: [
            'name' => 'Plano em andamento',
            'construction_id' => $construction->id,
            'initial_physical_progress_percent' => 35.5,
            'initial_physical_progress_reference_date' => '2026-04-30',
        ])
        ->assertHasNoTableActionErrors();

    $planSet = MeasurementPlanSet::query()->where('operation_id', $operation->id)->sole();

    expect($planSet->initial_physical_progress_percent)->toBe('35.50')
        ->and($planSet->initial_physical_progress_reference_date->toDateString())->toBe('2026-04-30');
});

it('explains an invalid initial physical progress in the plan modal', function (array $data, string $field, string $message) {
    $this->actingAs(makeAdminUser());
    ['operation' => $operation, 'construction' => $construction] = physicalProgressPlanScenario();

    physicalProgressPlanSets($operation)
        ->callTableAction('create', data: ['name' => 'Plano inválido', 'construction_id' => $construction->id, ...$data])
        ->assertHasTableActionErrors([$field => $message]);

    expect(MeasurementPlanSet::query()->where('operation_id', $operation->id)->exists())->toBeFalse();
})->with([
    'above 100%' => [['initial_physical_progress_percent' => 120, 'initial_physical_progress_reference_date' => '2026-04-30'], 'initial_physical_progress_percent', 'O avanço físico inicial não pode passar de 100%.'],
    'negative' => [['initial_physical_progress_percent' => -1, 'initial_physical_progress_reference_date' => '2026-04-30'], 'initial_physical_progress_percent', 'O avanço físico inicial não pode ser negativo.'],
    'three decimals' => [['initial_physical_progress_percent' => '35.555', 'initial_physical_progress_reference_date' => '2026-04-30'], 'initial_physical_progress_percent', 'Informe o avanço físico inicial com no máximo duas casas decimais.'],
    'positive without date' => [['initial_physical_progress_percent' => 35], 'initial_physical_progress_reference_date', 'Informe a data de referência do avanço físico inicial.'],
    'future reference date' => [['initial_physical_progress_percent' => 35, 'initial_physical_progress_reference_date' => '2099-12-31'], 'initial_physical_progress_reference_date', 'A data de referência do avanço físico inicial não pode ser futura.'],
    'absurdly large' => [['initial_physical_progress_percent' => '99999999999999999', 'initial_physical_progress_reference_date' => '2026-04-30'], 'initial_physical_progress_percent', 'O avanço físico inicial não pode passar de 100%.'],
]);

it('shows the initial physical progress locked when editing a plan and never changes it', function (?string $referenceDate) {
    $this->actingAs(makeAdminUser());
    ['operation' => $operation, 'construction' => $construction] = physicalProgressPlanScenario();
    $planSet = MeasurementPlanSet::factory()->withInitialPhysicalProgress('35.00')->create([
        'operation_id' => $operation->id,
        'construction_id' => $construction->id,
        'name' => 'Plano original',
    ]);
    DB::table('measurement_plan_sets')->where('id', $planSet->id)->update(['initial_physical_progress_reference_date' => $referenceDate]);

    physicalProgressPlanSets($operation)
        ->mountTableAction('edit', $planSet)
        ->assertFormFieldDisabled('initial_physical_progress_percent')
        ->assertFormFieldDisabled('initial_physical_progress_reference_date')
        ->setTableActionData([
            'name' => 'Plano renomeado',
            'initial_physical_progress_percent' => 70,
            'initial_physical_progress_reference_date' => '2026-09-30',
        ])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    $planSet->refresh();

    expect($planSet->name)->toBe('Plano renomeado')
        ->and($planSet->initial_physical_progress_percent)->toBe('35.00')
        ->and($planSet->initial_physical_progress_reference_date?->toDateString())->toBe($referenceDate);
})->with([
    'with reference date' => ['2026-04-30'],
    'copied from the first schedule line, without date' => [null],
]);

it('shows the current physical progress of each plan with its initial and measured parts', function () {
    $scenario = Scenario::plan(initialPercent: '30.00', referenceDate: '2026-04-30');
    Scenario::measured($scenario, '2026-05', 10);
    Scenario::measured($scenario, '2026-06', 5);
    $this->actingAs(makeAdminUser());

    physicalProgressPlanSets($scenario['operation'])
        ->assertTableColumnStateSet('physical_progress', '45,00%', $scenario['planSet'])
        ->assertTableColumnHasDescription('physical_progress', 'Inicial 30,00% em 30/04/2026 · Medido 15,00%', $scenario['planSet']);
});

it('shows the initial physical progress copied without reference date in the plans table', function () {
    $scenario = Scenario::plan();
    Scenario::copiedInitialProgress($scenario, '35.00');
    Scenario::measured($scenario, '2026-05', 5);
    $this->actingAs(makeAdminUser());

    // Plano copiado pela migration: o percentual entra no atual e a descrição
    // não inventa data de referência.
    physicalProgressPlanSets($scenario['operation'])
        ->assertTableColumnStateSet('physical_progress', '40,00%', $scenario['planSet'])
        ->assertTableColumnHasDescription('physical_progress', 'Inicial 35,00% · Medido 5,00%', $scenario['planSet']);
});

// ── Formulário da operação ───────────────────────────────────────────────────

it('creates the development plans with the initial physical progress informed on the operation form', function () {
    $this->actingAs(makeAdminUser());
    $emission = Emission::factory()->create();
    $construction = Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => 'Torre em andamento']);

    Livewire::test(CreateOperation::class)
        ->fillForm(['emission_id' => $emission->id])
        ->fillForm([
            'developments' => [
                [
                    'construction_id' => $construction->id,
                    'construction_fund_amount' => '1.000.000,00',
                    'initial_physical_progress_percent' => 42.25,
                    'initial_physical_progress_reference_date' => '2026-09-30',
                ],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $planSet = Operation::query()->where('emission_id', $emission->id)->sole()->planSets()->sole();

    expect($planSet->initial_physical_progress_percent)->toBe('42.25')
        ->and($planSet->initial_physical_progress_reference_date->toDateString())->toBe('2026-09-30');
});

it('requires the reference date of a positive initial physical progress on the operation form', function () {
    $this->actingAs(makeAdminUser());
    $emission = Emission::factory()->create();
    $construction = Construction::factory()->create(['emission_id' => $emission->id]);

    $component = Livewire::test(CreateOperation::class)
        ->fillForm(['emission_id' => $emission->id])
        ->fillForm([
            'developments' => [
                ['construction_id' => $construction->id, 'construction_fund_amount' => '1.000,00', 'initial_physical_progress_percent' => 10],
            ],
        ])
        ->call('create');

    $item = 'developments.'.array_key_first($component->get('data.developments'));

    $component->assertHasFormErrors([$item.'.initial_physical_progress_reference_date' => 'Informe a data de referência do avanço físico inicial.']);

    expect(Operation::query()->where('emission_id', $emission->id)->exists())->toBeFalse();
});

it('keeps the initial physical progress of an existing plan locked on the operation edit form', function () {
    $admin = makeAdminUser();
    $this->actingAs($admin);
    $emission = Emission::factory()->create();
    $construction = Construction::factory()->create(['emission_id' => $emission->id]);
    $operation = Operation::factory()->create(['emission_id' => $emission->id]);
    $planSet = MeasurementPlanSet::factory()->default()->withInitialPhysicalProgress('20.00', '2026-03-31')->create([
        'operation_id' => $operation->id,
        'construction_id' => $construction->id,
        'construction_fund_amount' => '500000.00',
    ]);

    $component = Livewire::test(EditOperation::class, ['record' => $operation->getRouteKey()]);
    $row = 'developments.'.array_key_first($component->get('data.developments'));
    $item = 'data.'.$row;

    // O fundo chega no formato da máscara: cru, o ponto viraria milhar no navegador.
    expect($component->get($item.'.construction_fund_amount'))->toBe('500.000,00')
        ->and($component->get($item.'.initial_physical_progress_percent'))->toBe('20.00')
        ->and($component->get($item.'.has_plan'))->toBeTrue();

    // O sync ignora o campo na edição: o valor intacto depois de salvar não
    // provaria, sozinho, que o campo está travado.
    $component
        ->assertFormFieldDisabled($row.'.initial_physical_progress_percent')
        ->assertFormFieldDisabled($row.'.initial_physical_progress_reference_date')
        ->set($item.'.initial_physical_progress_percent', 70)
        ->call('save')
        ->assertHasNoFormErrors();

    expect($planSet->fresh()->initial_physical_progress_percent)->toBe('20.00')
        ->and($planSet->fresh()->initial_physical_progress_reference_date->toDateString())->toBe('2026-03-31')
        ->and($planSet->fresh()->construction_fund_amount)->toBe('500000.00');
});

it('keeps the initial physical progress of an existing plan locked after switching the emission and back on the operation edit form', function () {
    $this->actingAs(makeAdminUser());
    $emission = Emission::factory()->create();
    $construction = Construction::factory()->create(['emission_id' => $emission->id]);
    $otherEmission = Emission::factory()->create();
    $otherConstruction = Construction::factory()->create(['emission_id' => $otherEmission->id]);
    $operation = Operation::factory()->create(['emission_id' => $emission->id]);
    MeasurementPlanSet::factory()->default()->withInitialPhysicalProgress('20.00', '2026-03-31')->create([
        'operation_id' => $operation->id,
        'construction_id' => $construction->id,
    ]);

    $component = Livewire::test(EditOperation::class, ['record' => $operation->getRouteKey()])
        ->fillForm(['emission_id' => $otherEmission->id]);
    $otherRow = 'developments.'.array_key_first($component->get('data.developments'));

    // A troca recarrega os empreendimentos; sem isso, a volta não provaria nada.
    // E o plano que ainda vai nascer aceita o avanço: a trava vem do plano existente.
    expect(array_column($component->get('data.developments'), 'has_plan', 'construction_id'))->toBe([$otherConstruction->id => false]);

    $component->assertFormFieldEnabled($otherRow.'.initial_physical_progress_percent');

    $component->fillForm(['emission_id' => $emission->id]);
    $row = 'developments.'.array_key_first($component->get('data.developments'));

    // De volta à emissão original, o empreendimento reencontra o plano da operação.
    expect(array_column($component->get('data.developments'), 'has_plan', 'construction_id'))->toBe([$construction->id => true])
        ->and($component->get("data.{$row}.initial_physical_progress_percent"))->toBe('20.00')
        ->and($component->get("data.{$row}.initial_physical_progress_reference_date"))->toBe('2026-03-31');

    $component
        ->assertFormFieldDisabled($row.'.initial_physical_progress_percent')
        ->assertFormFieldDisabled($row.'.initial_physical_progress_reference_date');
});

// ── Modal de aprovação da Engenharia ─────────────────────────────────────────

it('shows where the development stands before the Engineering enters the month', function () {
    $scenario = Scenario::plan(initialPercent: '30.00', referenceDate: '2026-04-30');
    Scenario::measured($scenario, '2026-05', 10);
    $june = Scenario::measurement($scenario, '2026-06');
    $this->actingAs($scenario['actor']);

    $component = Livewire::test(ViewMeasurement::class, ['record' => $june->getRouteKey()])
        ->mountAction('approve');

    expect(strip_tags(physicalProgressModalContent($component, $scenario['planSet']->id)))
        ->toBe('Plano padrãoAvanço físico inicial30,00% em 30/04/2026Medido no sistema10,00%Avanço físico atual40,00%Máximo restante60,00%');
});

it('explains the 100% limit under the development field and keeps the measurement in Engineering', function () {
    $scenario = Scenario::plan(initialPercent: '90.00');
    $measurement = Scenario::measurement($scenario, '2026-05');
    $this->actingAs($scenario['actor']);

    $component = Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->callAction('approve', data: ['realized' => [$scenario['planSet']->id => 11]]);

    // A mensagem tem ':' -- o assertHasErrors do Livewire a leria como "regra:parâmetros".
    expect($component->errors()->get("mountedActions.0.data.realized.{$scenario['planSet']->id}"))->toBe([
        'O percentual físico informado para Plano padrão ultrapassaria o limite de 100% do empreendimento. Progresso atual: 90,00%. Percentual informado: 11,00%. Máximo restante: 10,00%.',
    ])
        ->and($measurement->fresh()->current_stage)->toBe(1)
        ->and($measurement->fresh()->engineering_snapshot)->toBeNull();
});

it('answers an absurdly large percentage in the approval modal with a validation error', function () {
    $scenario = Scenario::plan();
    $measurement = Scenario::measurement($scenario, '2026-05');
    $this->actingAs($scenario['actor']);

    $component = Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->callAction('approve', data: ['realized' => [$scenario['planSet']->id => '99999999999999999']]);

    expect($component->errors()->has("mountedActions.0.data.realized.{$scenario['planSet']->id}"))->toBeTrue()
        ->and($measurement->fresh()->current_stage)->toBe(1);
});

it('accepts a month without progress in the approval modal of a construction already at 100%', function () {
    $scenario = Scenario::plan(initialPercent: '100.00');
    $measurement = Scenario::measurement($scenario, '2026-05');
    $this->actingAs($scenario['actor']);

    Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->callAction('approve', data: ['realized' => [$scenario['planSet']->id => 0]])
        ->assertHasNoActionErrors();

    expect($measurement->fresh()->current_stage)->toBe(2)
        ->and($scenario['lines']['2026-05']->fresh()->realized_cumulative_percent)->toBe('100.00');
});

it('opens the Engineering field empty instead of repeating a value from the schedule line', function () {
    $scenario = Scenario::plan();
    $june = Scenario::measured($scenario, '2026-06', 5);
    Scenario::returnToEngineering($scenario, $june);
    $this->actingAs($scenario['actor']);

    $component = Livewire::test(ViewMeasurement::class, ['record' => $june->getRouteKey()])
        ->mountAction('approve');

    expect($component->get("mountedActions.0.data.realized.{$scenario['planSet']->id}"))->toBeNull();
});

// ── Cronograma (Acompanhamento) ──────────────────────────────────────────────

it('suggests the planned cumulative of the first line from the initial physical progress', function () {
    $scenario = Scenario::plan(initialPercent: '35.00');
    $this->actingAs(makeAdminUser());

    physicalProgressSchedule($scenario['operation'])
        ->mountTableAction('editPlanned', $scenario['lines']['2026-05'])
        ->setTableActionData(['planned_monthly_percent' => 5])
        ->assertTableActionDataSet(['planned_cumulative_percent' => 40.0]);
});

it('shows a month without measurement as not measured, carrying the last cumulative', function () {
    $this->travelTo(now()->setDate(2026, 7, 20));
    $scenario = Scenario::plan(months: ['2026-05', '2026-06', '2026-07', '2026-08'], initialPercent: '25.00');
    Scenario::measured($scenario, '2026-05', 10);
    Scenario::measured($scenario, '2026-07', 5);
    $this->actingAs(makeAdminUser());
    $lines = $scenario['lines'];

    physicalProgressSchedule($scenario['operation'])
        ->assertTableColumnStateSet('realized_monthly_percent', '10.00', $lines['2026-05'])
        ->assertTableColumnStateSet('realized_cumulative_percent', '35.00', $lines['2026-05'])
        ->assertTableColumnStateSet('evolution_diff_percent', '25.00', $lines['2026-05'])
        ->assertTableColumnStateSet('evolution_trend', 'Acima', $lines['2026-05'])
        ->assertTableColumnStateSet('realized_monthly_percent', null, $lines['2026-06'])
        ->assertTableColumnStateSet('realized_cumulative_percent', '35.00', $lines['2026-06'])
        ->assertTableColumnStateSet('evolution_diff_percent', null, $lines['2026-06'])
        ->assertTableColumnStateSet('status', 'Pendente', $lines['2026-06'])
        ->assertTableColumnStateSet('realized_cumulative_percent', '40.00', $lines['2026-07'])
        ->assertTableColumnStateSet('status', 'Realizado informado', $lines['2026-07'])
        ->assertTableColumnStateSet('realized_cumulative_percent', null, $lines['2026-08'])
        ->assertTableColumnStateSet('evolution_trend', null, $lines['2026-08']);
});

it('shows a month approved with 0% as measured, not as pending', function () {
    $scenario = Scenario::plan();
    Scenario::measured($scenario, '2026-05', 10);
    Scenario::measured($scenario, '2026-06', 0);
    $this->actingAs(makeAdminUser());
    $june = $scenario['lines']['2026-06'];

    // Zero é um mês medido sem avanço, não um mês sem medição.
    physicalProgressSchedule($scenario['operation'])
        ->assertTableColumnStateSet('realized_monthly_percent', '0.00', $june)
        ->assertTableColumnStateSet('realized_cumulative_percent', '10.00', $june)
        ->assertTableColumnStateSet('evolution_diff_percent', '-10.00', $june)
        ->assertTableColumnStateSet('status', 'Realizado informado', $june);
});

it('accumulates the schedule by competence when a later month is approved first', function () {
    // Hoje em junho: julho ainda não começou, e só o intervalo entre a primeira
    // e a última medição vigente explica o acumulado dele. Abril já passou, mas
    // fica antes da primeira medição e não há avanço inicial: em branco.
    $this->travelTo(now()->setDate(2026, 6, 15));
    $scenario = Scenario::plan(months: ['2026-04', '2026-05', '2026-06', '2026-07']);
    Scenario::measured($scenario, '2026-07', 5);
    Scenario::measured($scenario, '2026-05', 10);
    $this->actingAs(makeAdminUser());
    $lines = $scenario['lines'];

    physicalProgressSchedule($scenario['operation'])
        ->assertTableColumnStateSet('realized_cumulative_percent', null, $lines['2026-04'])
        ->assertTableColumnStateSet('realized_cumulative_percent', '10.00', $lines['2026-05'])
        ->assertTableColumnStateSet('realized_cumulative_percent', '10.00', $lines['2026-06'])
        ->assertTableColumnStateSet('realized_cumulative_percent', '15.00', $lines['2026-07']);
});

it('stops showing a measurement returned to Engineering in the schedule', function () {
    $scenario = Scenario::plan();
    Scenario::measured($scenario, '2026-05', 10);
    $june = Scenario::measured($scenario, '2026-06', 5);
    Scenario::returnToEngineering($scenario, $june);
    $this->actingAs(makeAdminUser());

    physicalProgressSchedule($scenario['operation'])
        ->assertTableColumnStateSet('realized_monthly_percent', null, $scenario['lines']['2026-06'])
        ->assertTableColumnStateSet('realized_cumulative_percent', '10.00', $scenario['lines']['2026-06'])
        ->assertTableColumnStateSet('evolution_diff_percent', null, $scenario['lines']['2026-06'])
        ->assertTableColumnStateSet('status', 'Pendente', $scenario['lines']['2026-06'])
        ->assertTableColumnStateSet('realized_cumulative_percent', '10.00', $scenario['lines']['2026-05']);
});

it('lists the schedule in competence order even when a line was registered later', function () {
    $scenario = Scenario::plan(months: ['2026-05', '2026-07', '2026-06']);
    $this->actingAs(makeAdminUser());
    $lines = $scenario['lines'];

    physicalProgressSchedule($scenario['operation'])
        ->assertCanSeeTableRecords([$lines['2026-05'], $lines['2026-06'], $lines['2026-07']], inOrder: true);
});
