<?php

use App\Actions\Expenses\LookupExpenseServiceProviderCnpj;
use App\Filament\Resources\Expenses\Pages\CreateExpense;
use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Filament\Resources\ExpenseServiceProviders\Pages\CreateExpenseServiceProvider;
use App\Models\Emission;
use App\Models\Expense;
use App\Models\ExpenseServiceProvider;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('shows the create expense action on the expenses list page', function () {
    $this->actingAs(makeExpenseAdminUser());

    Livewire::test(ListExpenses::class)
        ->assertActionExists('create')
        ->assertActionHasLabel('create', 'Criar despesa');
});

it('creates an expense linked to the selected operation and service provider', function () {
    $this->actingAs(makeExpenseAdminUser());

    $emission = Emission::factory()->create([
        'name' => 'Operação Teste',
    ]);
    $serviceProvider = ExpenseServiceProvider::factory()->create([
        'name' => 'Prestador Alpha',
    ]);

    Livewire::test(CreateExpense::class)
        ->fillForm([
            'emission_id' => $emission->id,
            'category' => 'Servicer',
            'expense_service_provider_id' => $serviceProvider->id,
            'period' => Expense::PERIOD_SINGLE,
            'start_date' => '2026-04-16',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $expense = Expense::query()->first();

    expect($expense)->not->toBeNull()
        ->and($expense?->emission_id)->toBe($emission->id)
        ->and($expense?->expense_service_provider_id)->toBe($serviceProvider->id)
        ->and($expense?->category)->toBe('Servicer')
        ->and($expense?->period)->toBe(Expense::PERIOD_SINGLE)
        ->and($expense?->start_date?->toDateString())->toBe('2026-04-16')
        ->and($expense?->end_date)->toBeNull();
});

it('requires an end date for recurring expense periods and hides it for single expenses', function () {
    $this->actingAs(makeExpenseAdminUser());

    $emission = Emission::factory()->create();
    $serviceProvider = ExpenseServiceProvider::factory()->create();

    Livewire::test(CreateExpense::class)
        ->fillForm([
            'period' => Expense::PERIOD_SINGLE,
        ])
        ->assertFormFieldHidden('end_date')
        ->fillForm([
            'period' => Expense::PERIOD_MONTHLY,
        ])
        ->assertFormFieldVisible('end_date')
        ->fillForm([
            'emission_id' => $emission->id,
            'category' => 'Cartório',
            'expense_service_provider_id' => $serviceProvider->id,
            'period' => Expense::PERIOD_MONTHLY,
            'start_date' => '2026-04-16',
            'end_date' => null,
        ])
        ->call('create')
        ->assertHasFormErrors(['end_date' => 'required']);
});

it('creates a service provider inline from the expense form', function () {
    $this->actingAs(makeExpenseAdminUser());

    Http::fake([
        'https://publica.cnpj.ws/cnpj/*' => Http::response([
            'razao_social' => 'Razão Social do Prestador Ltda',
            'estabelecimento' => [
                'nome_fantasia' => 'Prestador Inline',
            ],
        ]),
    ]);

    Livewire::test(CreateExpense::class)
        ->assertFormComponentActionExists('expense_service_provider_id', 'createOption')
        ->assertFormComponentActionHasLabel('expense_service_provider_id', 'createOption', 'Cadastrar prestador')
        ->mountFormComponentAction('expense_service_provider_id', 'createOption')
        ->fillForm([
            'cnpj' => '12.345.678/0001-90',
        ])
        ->assertFormComponentActionDataSet([
            'name' => 'Prestador Inline',
        ])
        ->callMountedAction()
        ->assertHasNoFormErrors();

    expect(ExpenseServiceProvider::query()->where('cnpj', '12345678000190')->first())
        ->not->toBeNull()
        ->name->toBe('Prestador Inline');
});

it('prevents duplicate service provider cnpj registrations', function () {
    $this->actingAs(makeExpenseAdminUser());

    ExpenseServiceProvider::factory()->create([
        'cnpj' => '12345678000190',
    ]);

    Livewire::test(CreateExpenseServiceProvider::class)
        ->fillForm([
            'cnpj' => '12.345.678/0001-90',
            'name' => 'Prestador Duplicado',
        ])
        ->call('create')
        ->assertHasFormErrors(['cnpj']);
});

it('uses the trade name first and falls back to the legal name during cnpj lookup', function (array $payload, string $expectedName) {
    Http::fake([
        'https://publica.cnpj.ws/cnpj/*' => Http::response($payload),
    ]);

    $result = app(LookupExpenseServiceProviderCnpj::class)->handle('12.345.678/0001-90');

    expect($result['status'])->toBe(200)
        ->and(data_get($result, 'payload.data.name'))->toBe($expectedName);
})->with([
    'trade name' => [[
        'razao_social' => 'Fornecedor Legal S/A',
        'estabelecimento' => [
            'nome_fantasia' => 'Fornecedor Fantasia',
        ],
    ], 'Fornecedor Fantasia'],
    'legal name fallback' => [[
        'razao_social' => 'Fornecedor Sem Fantasia S/A',
        'estabelecimento' => [
            'nome_fantasia' => '',
        ],
    ], 'Fornecedor Sem Fantasia S/A'],
]);

function makeExpenseAdminUser(): User
{
    $user = User::factory()->withTwoFactor()->create([
        'email' => fake()->unique()->safeEmail(),
    ]);
    $user->assignRole('admin');

    return $user;
}
