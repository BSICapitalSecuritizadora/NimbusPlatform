<?php

use App\Filament\Resources\Operations\OperationResource;
use App\Filament\Resources\Operations\Pages\ViewOperation;
use App\Filament\Resources\Operations\RelationManagers\MeasurementsRelationManager;
use App\Filament\Resources\Operations\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\Operations\RelationManagers\PlanLinesRelationManager;
use App\Filament\Resources\Operations\RelationManagers\PlanSetsRelationManager;
use App\Filament\Resources\Operations\RelationManagers\PlanVersionsRelationManager;
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
use Tests\Support\MeasurementPlanVersionFixture;

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

    // O Fundo de Obra é da versão do plano: nasce na V1.
    $planSet = MeasurementPlanSet::factory()->default()->withConstructionFund('200000.00')->create([
        'operation_id' => $operation->id,
        'construction_id' => $construction->id,
        'name' => 'Plano Costa Azul',
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

    // Plano em vigor, como o de uma operação em andamento: é o cronograma
    // vigente que dá a próxima medição e que admite revisão.
    MeasurementPlanVersionFixture::activate($planSet);

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

it('keeps the area tabs with plans first and the plan versions right after them', function () {
    $operation = makeOperationViewScenario();

    // A aba Versões dos Planos entrou logo depois dos planos: as seguintes
    // andaram uma posição, e Medições agora é a aba '3'.
    $page = Livewire::test(ViewOperation::class, ['record' => $operation->getRouteKey()])
        ->assertSeeInOrder([
            'Planos de Medição (Evolução da Obra)',
            'Versões dos Planos',
            'Cronograma (Acompanhamento)',
            'Medições',
            'Pagamentos',
        ])
        ->assertSeeHtml('wire:name="'.PlanSetsRelationManager::class.'"')
        ->set('activeRelationManager', '3')
        ->assertSeeHtml('wire:name="'.MeasurementsRelationManager::class.'"')
        ->assertDontSeeHtml('wire:name="'.PlanSetsRelationManager::class.'"');

    expect($page->instance()->getCachedRelationManagers())->toBe([
        PlanSetsRelationManager::class,
        PlanVersionsRelationManager::class,
        PlanLinesRelationManager::class,
        MeasurementsRelationManager::class,
        PaymentsRelationManager::class,
    ]);
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
        ->assertTableColumnExists('version')
        ->assertTableColumnExists('lines_count')
        ->assertTableColumnExists('financials')
        ->assertTableColumnExists('used_percentage')
        ->assertSeeInOrder(['Plano', 'Empreendimento', 'Versão', 'Estrutura', 'Financeiro', '% Utilizada'])
        ->assertSee('R$')
        ->assertSeeInOrder([
            'Plano Costa Azul',
            'Residencial Costa Azul',
            'V1 · Vigente',
            '2 linhas',
            'Padrão',
            'Fundo de Obra', '200.000,00',
            'Custo Incorrido', '50.000,00',
            'Saldo Disponível', '150.000,00',
            '25,00%',
        ])
        // O cronograma é da versão: medição prevista nova entra pela revisão
        // do plano (aba Versões dos Planos), não mais por "Adicionar medições".
        ->assertTableActionVisible('edit', $planSet)
        ->assertTableActionVisible('createPlanRevision', $planSet)
        ->assertTableActionVisible('delete', $planSet)
        ->assertTableActionDoesNotExist('addLines');

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

    $secondary = MeasurementPlanSet::factory()->withConstructionFund('100000.00')->create([
        'operation_id' => $operation->id,
        'construction_id' => $construction->id,
        'name' => 'Plano Farol',
        'is_default' => false,
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

    // Ainda não ativado: o plano responde pelo rascunho da V1 -- o cronograma
    // e o Fundo de Obra dela.
    Livewire::test(PlanSetsRelationManager::class, [
        'ownerRecord' => $operation,
        'pageClass' => ViewOperation::class,
    ])
        ->assertSeeInOrder(['Plano Farol', 'Torre Farol', 'V1 · Rascunho', '1 linha', 'Não padrão'])
        ->assertSee('100.000,00')
        ->assertSee('0,00%');
});

it('keeps searching and paging the grouped plan table', function () {
    $operation = makeOperationViewScenario();

    MeasurementPlanSet::factory()->count(26)->withConstructionFund('10000.00')->create([
        'operation_id' => $operation->id,
        'construction_id' => null,
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
        ->assertTableActionHidden('createPlanRevision', $operation->planSets()->first())
        ->assertTableActionHidden('delete', $operation->planSets()->first());
});
