<?php

use App\Actions\Expenses\LookupExpenseServiceProviderCnpj;
use App\Filament\Resources\Expenses\Pages\CreateExpense;
use App\Filament\Resources\Expenses\Pages\EditExpense;
use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Filament\Resources\ExpenseServiceProviders\Pages\CreateExpenseServiceProvider;
use App\Models\Emission;
use App\Models\Expense;
use App\Models\ExpenseServiceProvider;
use App\Models\ExpenseServiceProviderType;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Forms\Components\Select;
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

it('shows filters for operation and category on the expenses list page', function () {
    $this->actingAs(makeExpenseAdminUser());

    Livewire::test(ListExpenses::class)
        ->assertTableFilterExists('emission_id')
        ->assertTableFilterExists('category');
});

it('shows the new expense categories on the create form', function () {
    $this->actingAs(makeExpenseAdminUser());

    Livewire::test(CreateExpense::class)
        ->assertFormFieldExists('category', function (Select $field): bool {
            $options = $field->getOptions();

            return isset($options['Horas complementares'], $options['Auditoria'], $options['IPTU']);
        });
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
            'amount' => '1.250,50',
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
        ->and($expense?->amount)->toBe('1250.50')
        ->and($expense?->period)->toBe(Expense::PERIOD_SINGLE)
        ->and($expense?->start_date?->toDateString())->toBe('2026-04-16')
        ->and($expense?->end_date)->toBeNull();
});

it('creates an expense without a service provider', function () {
    $this->actingAs(makeExpenseAdminUser());

    $emission = Emission::factory()->create([
        'name' => 'Operação Sem Prestador',
    ]);

    Livewire::test(CreateExpense::class)
        ->fillForm([
            'emission_id' => $emission->id,
            'category' => 'Servicer',
            'amount' => '980,00',
            'period' => Expense::PERIOD_SINGLE,
            'start_date' => '2026-04-18',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $expense = Expense::query()->first();

    expect($expense)->not->toBeNull()
        ->and($expense?->emission_id)->toBe($emission->id)
        ->and($expense?->expense_service_provider_id)->toBeNull()
        ->and($expense?->category)->toBe('Servicer')
        ->and($expense?->amount)->toBe('980.00')
        ->and($expense?->period)->toBe(Expense::PERIOD_SINGLE)
        ->and($expense?->start_date?->toDateString())->toBe('2026-04-18')
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
            'amount' => '350,00',
            'period' => Expense::PERIOD_MONTHLY,
            'start_date' => '2026-04-16',
            'end_date' => null,
        ])
        ->call('create')
        ->assertHasFormErrors(['end_date' => 'required']);
});

it('requires the expense amount', function () {
    $this->actingAs(makeExpenseAdminUser());

    $emission = Emission::factory()->create();
    $serviceProvider = ExpenseServiceProvider::factory()->create();

    Livewire::test(CreateExpense::class)
        ->fillForm([
            'emission_id' => $emission->id,
            'category' => 'Servicer',
            'expense_service_provider_id' => $serviceProvider->id,
            'period' => Expense::PERIOD_SINGLE,
            'start_date' => '2026-04-16',
        ])
        ->call('create')
        ->assertHasFormErrors(['amount' => 'required']);
});

it('filters expenses by operation', function () {
    $this->actingAs(makeExpenseAdminUser());

    $selectedEmission = Emission::factory()->create([
        'name' => 'CRI Conviva',
    ]);
    $otherEmission = Emission::factory()->create([
        'name' => 'CRI Atlas',
    ]);
    $serviceProvider = ExpenseServiceProvider::factory()->create();

    $selectedExpense = Expense::factory()->create([
        'emission_id' => $selectedEmission->id,
        'expense_service_provider_id' => $serviceProvider->id,
        'category' => 'Servicer',
    ]);
    $otherExpense = Expense::factory()->create([
        'emission_id' => $otherEmission->id,
        'expense_service_provider_id' => $serviceProvider->id,
        'category' => 'Cartório',
    ]);

    Livewire::test(ListExpenses::class)
        ->assertCanSeeTableRecords([$selectedExpense, $otherExpense])
        ->filterTable('emission_id', $selectedEmission->id)
        ->assertCanSeeTableRecords([$selectedExpense])
        ->assertCanNotSeeTableRecords([$otherExpense]);
});

it('filters expenses by category', function () {
    $this->actingAs(makeExpenseAdminUser());

    $emission = Emission::factory()->create();
    $serviceProvider = ExpenseServiceProvider::factory()->create();

    $selectedExpense = Expense::factory()->create([
        'emission_id' => $emission->id,
        'expense_service_provider_id' => $serviceProvider->id,
        'category' => 'Engenharia',
    ]);
    $otherExpense = Expense::factory()->create([
        'emission_id' => $emission->id,
        'expense_service_provider_id' => $serviceProvider->id,
        'category' => 'Cartório',
    ]);

    Livewire::test(ListExpenses::class)
        ->assertCanSeeTableRecords([$selectedExpense, $otherExpense])
        ->filterTable('category', 'Engenharia')
        ->assertCanSeeTableRecords([$selectedExpense])
        ->assertCanNotSeeTableRecords([$otherExpense]);
});

it('formats the saved amount correctly when reopening an expense for editing', function () {
    $this->actingAs(makeExpenseAdminUser());

    $expense = Expense::factory()->create([
        'amount' => 750.00,
    ]);

    Livewire::test(EditExpense::class, [
        'record' => $expense->getRouteKey(),
    ])
        ->assertFormSet([
            'amount' => '750,00',
        ]);
});

it('updates an existing expense without a service provider', function () {
    $this->actingAs(makeExpenseAdminUser());

    $emission = Emission::factory()->create([
        'name' => 'OperaÃ§Ã£o Editada',
    ]);
    $serviceProvider = ExpenseServiceProvider::factory()->create([
        'name' => 'Prestador Inicial',
    ]);
    $expense = Expense::factory()->create([
        'emission_id' => $emission->id,
        'expense_service_provider_id' => $serviceProvider->id,
        'category' => 'Servicer',
        'amount' => 570.50,
        'period' => Expense::PERIOD_SINGLE,
        'start_date' => '2026-04-07',
        'end_date' => null,
    ]);

    Livewire::test(EditExpense::class, [
        'record' => $expense->getRouteKey(),
    ])
        ->fillForm([
            'emission_id' => $emission->id,
            'category' => 'Servicer',
            'expense_service_provider_id' => null,
            'amount' => '570,50',
            'period' => Expense::PERIOD_SINGLE,
            'start_date' => '2026-04-07',
            'end_date' => null,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($expense->fresh())
        ->expense_service_provider_id->toBeNull()
        ->amount->toBe('570.50');
});

it('creates a service provider inline from the expense form', function () {
    $this->actingAs(makeExpenseAdminUser());
    $serviceProviderType = ExpenseServiceProviderType::factory()->create([
        'name' => 'Administrador',
    ]);

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
            'expense_service_provider_type_id' => $serviceProviderType->id,
        ])
        ->assertFormComponentActionDataSet([
            'name' => 'Prestador Inline',
        ])
        ->callMountedAction()
        ->assertHasNoFormErrors();

    expect(ExpenseServiceProvider::query()->where('cnpj', '12345678000190')->first())
        ->not->toBeNull()
        ->name->toBe('Prestador Inline')
        ->expense_service_provider_type_id->toBe($serviceProviderType->id);
});

it('fills the service provider name automatically from cnpj on the direct create page', function () {
    $this->actingAs(makeExpenseAdminUser());

    Http::fake([
        'https://publica.cnpj.ws/cnpj/*' => Http::response([
            'razao_social' => 'Razão Social Direta Ltda',
            'estabelecimento' => [
                'nome_fantasia' => 'Prestador Direto',
            ],
        ]),
    ]);

    Livewire::test(CreateExpenseServiceProvider::class)
        ->fillForm([
            'cnpj' => '12.345.678/0001-90',
        ])
        ->assertFormSet([
            'name' => 'Prestador Direto',
        ]);
});

it('creates a service provider type inline from the direct create page', function () {
    $this->actingAs(makeExpenseAdminUser());

    Livewire::test(CreateExpenseServiceProvider::class)
        ->assertFormComponentActionExists('expense_service_provider_type_id', 'createOption')
        ->mountFormComponentAction('expense_service_provider_type_id', 'createOption')
        ->fillForm([
            'name' => 'Tipo criado inline',
        ])
        ->callMountedAction()
        ->assertHasNoFormErrors()
        ->assertFormSet([
            'expense_service_provider_type_id' => (string) ExpenseServiceProviderType::query()
                ->where('name', 'Tipo criado inline')
                ->value('id'),
        ]);

    expect(ExpenseServiceProviderType::query()->where('name', 'Tipo criado inline')->exists())
        ->toBeTrue();
});

it('prevents duplicate service provider cnpj registrations', function () {
    $this->actingAs(makeExpenseAdminUser());
    $serviceProviderType = ExpenseServiceProviderType::factory()->create();

    ExpenseServiceProvider::factory()->create([
        'cnpj' => '12345678000190',
    ]);

    Livewire::test(CreateExpenseServiceProvider::class)
        ->fillForm([
            'cnpj' => '12.345.678/0001-90',
            'name' => 'Prestador Duplicado',
            'expense_service_provider_type_id' => $serviceProviderType->id,
        ])
        ->call('create')
        ->assertHasFormErrors(['cnpj']);
});

it('requires the service provider type when creating a service provider', function () {
    $this->actingAs(makeExpenseAdminUser());

    Livewire::test(CreateExpenseServiceProvider::class)
        ->fillForm([
            'cnpj' => '12.345.678/0001-90',
            'name' => 'Prestador Sem Tipo',
        ])
        ->call('create')
        ->assertHasFormErrors(['expense_service_provider_type_id' => 'required']);
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
