<?php

use App\Filament\Resources\Operations\Pages\CreateOperation;
use App\Filament\Resources\Operations\Pages\ListOperations;
use App\Models\Construction;
use App\Models\Emission;
use App\Models\MeasurementPlanSet;
use App\Models\Operation;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('shows the create operation action and subheading on the operations list page', function () {
    $this->actingAs(makeOperationAdminUser());

    Operation::factory()->create();

    Livewire::test(ListOperations::class)
        ->assertOk()
        ->assertActionExists('create')
        ->assertActionHasLabel('create', 'Criar Operação de Obra')
        ->assertSee('Acompanhamento das operações, valores e próximas medições vinculadas às obras.')
        ->assertTableFilterExists('status')
        ->assertTableFilterExists('emission_id');
});

it('shows a contextual empty state when there are no operations', function () {
    $this->actingAs(makeOperationAdminUser());

    Livewire::test(ListOperations::class)
        ->assertOk()
        ->assertSee('Acompanhamento das operações, valores e próximas medições vinculadas às obras.')
        ->assertSee('Nenhuma operação de obra cadastrada')
        ->assertSee('Cadastre a primeira operação para acompanhar valores, situação e próximas medições.')
        ->assertSee('Criar primeira operação')
        ->assertDontSee('Nenhuma operação de obra corresponde aos filtros selecionados');
});

it('distinguishes no results from an empty base and offers to clear filters', function () {
    $this->actingAs(makeOperationAdminUser());

    $emission = Emission::factory()->create(['name' => 'CRI Residencial Jardins']);
    Operation::factory()->create([
        'emission_id' => $emission->id,
        'title' => 'Operação Torre Alpha',
        'code' => 'OP-2026-0001',
    ]);

    Livewire::test(ListOperations::class)
        ->assertSee('Buscar por código, título ou emissão...')
        ->set('tableSearch', 'termo-inexistente')
        ->assertSee('Nenhuma operação de obra corresponde aos filtros selecionados')
        ->assertSee('Ajuste a busca ou limpe os filtros para visualizar as operações cadastradas.')
        ->assertSee('Limpar filtros')
        ->assertDontSee('Nenhuma operação de obra cadastrada');
});

it('renders operations with formatted values and developments counter', function () {
    $this->actingAs(makeOperationAdminUser());

    $emission = Emission::factory()->create(['name' => 'CRI Residencial Parque']);
    $construction1 = Construction::factory()->create([
        'emission_id' => $emission->id,
        'development_name' => 'Residencial Alto Bellevue',
    ]);
    $construction2 = Construction::factory()->create([
        'emission_id' => $emission->id,
        'development_name' => 'Torre Horizon',
    ]);

    $operation = Operation::factory()->create([
        'emission_id' => $emission->id,
        'code' => 'OP-001',
        'title' => 'Acompanhamento Obra Bellevue',
        'status' => 'active',
        'amount' => 18940068.86,
        'next_measurement_at' => '2026-09-15',
    ]);

    MeasurementPlanSet::create([
        'operation_id' => $operation->id,
        'construction_id' => $construction1->id,
        'name' => 'Plano Bellevue',
        'is_default' => true,
    ]);

    MeasurementPlanSet::create([
        'operation_id' => $operation->id,
        'construction_id' => $construction2->id,
        'name' => 'Plano Horizon',
        'is_default' => false,
    ]);

    Livewire::test(ListOperations::class)
        ->assertOk()
        ->assertSee('OP-001')
        ->assertSee('Acompanhamento Obra Bellevue')
        ->assertSee('CRI Residencial Parque')
        ->assertSee('Residencial Alto Bellevue +1')
        ->assertSee('Em Andamento')
        ->assertSee('18.940.068,86')
        ->assertSee('15/09/2026');
});

it('filters operations by status', function () {
    $this->actingAs(makeOperationAdminUser());

    $activeOp = Operation::factory()->create([
        'title' => 'Operação Em Andamento',
        'status' => 'active',
    ]);

    $draftOp = Operation::factory()->create([
        'title' => 'Operação Em Rascunho',
        'status' => 'draft',
    ]);

    Livewire::test(ListOperations::class)
        ->assertSee('Operação Em Andamento')
        ->assertSee('Operação Em Rascunho')
        ->set('tableFilters.status.value', 'active')
        ->assertSee('Operação Em Andamento')
        ->assertDontSee('Operação Em Rascunho');
});

it('renders the create operation page with custom subheading and sections', function () {
    $this->actingAs(makeOperationAdminUser());

    Livewire::test(CreateOperation::class)
        ->assertOk()
        ->assertSee('Configure a operação, os empreendimentos envolvidos e os responsáveis por cada etapa do fluxo de medição.')
        ->assertSee('Dados da Operação')
        ->assertSee('Empreendimentos e Fundo de Obra')
        ->assertSee('Responsáveis pelo Fluxo de Medição')
        ->assertSee('Sequência da Esteira:')
        ->assertFormFieldExists('emission_id')
        ->assertFormFieldExists('status')
        ->assertFormFieldExists('due_date')
        ->assertFormFieldExists('responsible_user_id')
        ->assertFormFieldExists('stage2_reviewer_user_id')
        ->assertFormFieldExists('stage3_reviewer_user_id')
        ->assertFormFieldExists('payment_manager_user_id')
        ->assertFormFieldExists('payment_finalizer_user_id')
        ->assertFormFieldExists('assigned_user_id')
        ->assertFormFieldExists('rejectionNotifyUsers');
});

it('creates an operation with developments from the selected emission', function () {
    $this->actingAs(makeOperationAdminUser());

    $emission = Emission::factory()->create(['name' => 'CRI Alpha Real Estate']);
    $construction = Construction::factory()->create([
        'emission_id' => $emission->id,
        'development_name' => 'Edifício Solar do Parque',
    ]);

    $engineer = User::factory()->create(['name' => 'Eng. Ricardo']);

    Livewire::test(CreateOperation::class)
        ->fillForm([
            'emission_id' => $emission->id,
            'status' => 'draft',
            'responsible_user_id' => $engineer->id,
            'developments' => [
                [
                    'construction_id' => $construction->id,
                    'construction_fund_amount' => 500000.00,
                ],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $operation = Operation::query()->where('emission_id', $emission->id)->first();
    expect($operation)->not->toBeNull()
        ->and($operation->responsible_user_id)->toBe($engineer->id)
        ->and($operation->planSets()->count())->toBe(1)
        ->and($operation->planSets()->first()->construction_id)->toBe($construction->id);
});

function makeOperationAdminUser(): User
{
    $user = User::factory()->withTwoFactor()->create([
        'email' => fake()->unique()->safeEmail(),
    ]);
    $user->assignRole('admin');

    return $user;
}
