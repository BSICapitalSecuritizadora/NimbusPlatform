<?php

use App\Filament\Resources\Operations\OperationResource;
use App\Filament\Resources\Operations\Pages\ViewOperation;
use App\Filament\Resources\Operations\RelationManagers\PlanSetsRelationManager;
use App\Models\Construction;
use App\Models\Emission;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Models\Operation;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(makeAdminUser());
});

function makeOperationViewScenario(): Operation
{
    $emission = Emission::factory()->create(['name' => 'CRI Costa Azul']);
    $construction = Construction::factory()->create([
        'emission_id' => $emission->id,
        'development_name' => 'Residencial Costa Azul',
    ]);

    $responsibles = [
        'responsible_user_id' => User::factory()->create(['name' => 'Eng. Marina'])->id,
        'stage2_reviewer_user_id' => User::factory()->create(['name' => 'Ges. Rafael'])->id,
        'stage3_reviewer_user_id' => User::factory()->create(['name' => 'Jur. Paula'])->id,
        'payment_manager_user_id' => User::factory()->create(['name' => 'Pag. Tiago'])->id,
        'payment_receipt_uploader_user_id' => User::factory()->create(['name' => 'Comp. Lívia'])->id,
        'payment_finalizer_user_id' => User::factory()->create(['name' => 'Fin. Bruno'])->id,
        'assigned_user_id' => User::factory()->create(['name' => 'Coord. Helena'])->id,
    ];

    $operation = Operation::factory()->create([
        'emission_id' => $emission->id,
        'code' => 'OP-2026-0001',
        'title' => 'Costa Azul',
        'status' => 'active',
        'due_date' => '2027-03-15',
        ...$responsibles,
    ]);

    $planSet = MeasurementPlanSet::factory()->default()->create([
        'operation_id' => $operation->id,
        'construction_id' => $construction->id,
        'name' => 'Plano Costa Azul',
        'construction_fund_amount' => '200000.00',
        'initial_incurred_amount' => '50000.00',
    ]);

    MeasurementPlanLine::factory()->create([
        'operation_id' => $operation->id,
        'plan_set_id' => $planSet->id,
        'sequence_number' => 1,
        'measurement_date' => '2026-05-01',
        'realized_monthly_percent' => 0,
        'realized_cumulative_percent' => 0,
    ]);

    MeasurementPlanLine::factory()->create([
        'operation_id' => $operation->id,
        'plan_set_id' => $planSet->id,
        'sequence_number' => 2,
        'measurement_date' => '2026-06-01',
        'realized_monthly_percent' => 0,
        'realized_cumulative_percent' => 0,
    ]);

    return $operation->fresh();
}

it('summarizes the real operation in the header while keeping the lifecycle actions', function () {
    $operation = makeOperationViewScenario();

    $page = Livewire::test(ViewOperation::class, ['record' => $operation->getRouteKey()])
        ->assertOk()
        ->assertSee('Visualizar Costa Azul')
        ->assertSee('OP-2026-0001 · CRI Costa Azul · Em Andamento')
        ->assertActionVisible('edit')
        ->assertActionVisible('complete_operation')
        ->assertActionVisible('cancel_operation')
        ->assertActionHidden('activate_operation')
        ->assertActionHidden('reopen_operation');

    expect($page->instance()->getSubheading())->toBe('OP-2026-0001 · CRI Costa Azul · Em Andamento');
});

it('keeps every operation field in the executive grid order', function () {
    $operation = makeOperationViewScenario();

    Livewire::test(ViewOperation::class, ['record' => $operation->getRouteKey()])
        ->assertSeeInOrder([
            'Operação',
            'Código', 'OP-2026-0001',
            'Título', 'Costa Azul',
            'Emissão', 'CRI Costa Azul',
            'Situação', 'Em Andamento',
            'Vencimento', '15/03/2027',
            'Próxima Medição', '05/2026',
            'Empreendimentos', 'Residencial Costa Azul',
        ]);
});

it('keeps every responsibility label with its real assignee', function () {
    $operation = makeOperationViewScenario();

    Livewire::test(ViewOperation::class, ['record' => $operation->getRouteKey()])
        ->assertSeeInOrder([
            'Responsáveis',
            'Etapa 1 — Engenharia', 'Eng. Marina',
            'Etapa 2 — Gestão', 'Ges. Rafael',
            'Etapa 3 — Jurídico/Risco', 'Jur. Paula',
            'Pagamentos', 'Pag. Tiago',
            'Comprovantes', 'Comp. Lívia',
            'Finalizador', 'Fin. Bruno',
            'Responsável Geral', 'Coord. Helena',
        ]);
});

it('keeps the four area tabs with plans as the first one', function () {
    $operation = makeOperationViewScenario();

    Livewire::test(ViewOperation::class, ['record' => $operation->getRouteKey()])
        ->assertSee('Planos de Medição (Evolução da Obra)')
        ->assertSee('Cronograma (Acompanhamento)')
        ->assertSee('Medições')
        ->assertSee('Pagamentos')
        ->set('activeRelationManager', '2')
        ->assertSee('Medições');
});

it('groups the plan table without losing values, counts or actions', function () {
    $operation = makeOperationViewScenario();
    $planSet = $operation->planSets()->first();

    $relation = Livewire::test(PlanSetsRelationManager::class, [
        'ownerRecord' => $operation,
        'pageClass' => ViewOperation::class,
    ])
        ->assertOk()
        ->assertSee('Planos de Medição (Evolução da Obra)')
        ->assertSee('Buscar plano...')
        ->assertSee('Novo Plano')
        ->assertTableColumnExists('name')
        ->assertTableColumnExists('construction.development_name')
        ->assertTableColumnExists('lines_count')
        ->assertTableColumnExists('financials')
        ->assertTableColumnExists('used_percentage')
        ->assertSeeInOrder(['Plano', 'Empreendimento', 'Estrutura', 'Financeiro', '% Utilizada'])
        ->assertSee('R$')
        ->assertSeeInOrder([
            'Plano Costa Azul',
            'Residencial Costa Azul',
            '2 linhas',
            'Padrão',
            'Fundo de Obra', '200.000,00',
            'Custo Incorrido', '50.000,00',
            'Saldo Disponível', '150.000,00',
            '25,00%',
        ])
        ->assertTableActionVisible('edit', $planSet)
        ->assertTableActionVisible('addLines', $planSet)
        ->assertTableActionVisible('delete', $planSet);

    $table = $relation->instance()->getTable();

    expect($table->getColumn('lines_count')?->getLabel())->toBe('Estrutura')
        ->and($table->getColumn('financials')?->getLabel())->toBe('Financeiro')
        ->and($table->getColumn('used_percentage')?->getLabel())->toBe('% Utilizada')
        ->and($table->getColumn('construction_fund_amount'))->toBeNull()
        ->and($table->getColumn('is_default'))->toBeNull();
});

it('distinguishes a secondary plan with a single line', function () {
    $operation = makeOperationViewScenario();
    $construction = Construction::factory()->create([
        'emission_id' => $operation->emission_id,
        'development_name' => 'Torre Farol',
    ]);

    $secondary = MeasurementPlanSet::factory()->create([
        'operation_id' => $operation->id,
        'construction_id' => $construction->id,
        'name' => 'Plano Farol',
        'is_default' => false,
        'construction_fund_amount' => '100000.00',
        'initial_incurred_amount' => '0.00',
    ]);

    MeasurementPlanLine::factory()->create([
        'operation_id' => $operation->id,
        'plan_set_id' => $secondary->id,
        'sequence_number' => 1,
        'measurement_date' => '2026-07-01',
        'realized_monthly_percent' => 0,
        'realized_cumulative_percent' => 0,
    ]);

    Livewire::test(PlanSetsRelationManager::class, [
        'ownerRecord' => $operation,
        'pageClass' => ViewOperation::class,
    ])
        ->assertSeeInOrder(['Plano Farol', 'Torre Farol', '1 linha', 'Não padrão'])
        ->assertSee('100.000,00')
        ->assertSee('0,00%');
});

it('keeps searching and paging the grouped plan table', function () {
    $operation = makeOperationViewScenario();

    MeasurementPlanSet::factory()->count(26)->create([
        'operation_id' => $operation->id,
        'construction_id' => null,
        'construction_fund_amount' => '10000.00',
        'initial_incurred_amount' => '0.00',
    ]);

    $records = $operation->planSets()->orderBy('id')->get();

    $table = Livewire::test(PlanSetsRelationManager::class, [
        'ownerRecord' => $operation,
        'pageClass' => ViewOperation::class,
    ])
        ->set('tableSearch', 'Costa Azul')
        ->assertCanSeeTableRecords([$records->first()])
        ->set('tableSearch', null)
        ->set('tableRecordsPerPage', 25)
        ->assertCanSeeTableRecords($records->slice(0, 25))
        ->assertCanNotSeeTableRecords([$records->last()]);

    $table->call('gotoPage', 2, $table->instance()->getTablePaginationPageName())
        ->assertCanSeeTableRecords([$records->last()]);
});

it('preserves the resource permissions and hides management actions from a reader', function () {
    $operation = makeOperationViewScenario();
    $reader = User::factory()->withTwoFactor()->create();
    $role = Role::firstOrCreate(['name' => 'operation-view-reader']);
    $role->syncPermissions([Permission::firstOrCreate(['name' => 'operations.view'])]);
    $reader->syncRoles([$role]);
    $operation->update(['assigned_user_id' => $reader->id]);
    $this->actingAs($reader);

    expect(OperationResource::canView($operation))->toBeTrue()
        ->and(OperationResource::canEdit($operation))->toBeFalse();

    Livewire::test(ViewOperation::class, ['record' => $operation->getRouteKey()])
        ->assertOk()
        ->assertSee('OP-2026-0001 · CRI Costa Azul · Em Andamento')
        ->assertActionHidden('edit')
        ->assertActionHidden('complete_operation')
        ->assertActionHidden('cancel_operation');

    Livewire::test(PlanSetsRelationManager::class, [
        'ownerRecord' => $operation,
        'pageClass' => ViewOperation::class,
    ])
        ->assertOk()
        ->assertSee('200.000,00')
        ->assertTableActionHidden('edit', $operation->planSets()->first())
        ->assertTableActionHidden('addLines', $operation->planSets()->first())
        ->assertTableActionHidden('delete', $operation->planSets()->first());
});
