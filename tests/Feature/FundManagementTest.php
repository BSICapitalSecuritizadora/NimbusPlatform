<?php

use App\Filament\Resources\Banks\Pages\CreateBank;
use App\Filament\Resources\Funds\Pages\CreateFund;
use App\Filament\Resources\Funds\Pages\ListFunds;
use App\Models\Bank;
use App\Models\Emission;
use App\Models\Fund;
use App\Models\FundApplication;
use App\Models\FundName;
use App\Models\FundType;
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

it('shows the create fund action on the funds list page', function () {
    $this->actingAs(makeFundAdminUser());

    Livewire::test(ListFunds::class)
        ->assertActionExists('create')
        ->assertActionHasLabel('create', 'Criar fundo');
});

it('shows filters for operation, type, application and bank on the funds list page', function () {
    $this->actingAs(makeFundAdminUser());

    Livewire::test(ListFunds::class)
        ->assertTableFilterExists('emission_id')
        ->assertTableFilterExists('fund_type_id')
        ->assertTableFilterExists('fund_application_id')
        ->assertTableFilterExists('bank_id');
});

it('creates a fund linked to the selected auxiliary records', function () {
    $this->actingAs(makeFundAdminUser());

    $emission = Emission::factory()->create([
        'name' => 'Operacao Fundo Teste',
    ]);
    $fundType = FundType::factory()->create([
        'name' => 'Credito Estruturado',
    ]);
    $fundName = FundName::factory()->create([
        'fund_type_id' => $fundType->id,
        'name' => 'Fundo Atlas',
    ]);
    $fundApplication = FundApplication::factory()->create([
        'name' => 'Aplicacao Principal',
    ]);
    $bank = Bank::factory()->create([
        'name' => 'Banco Atlas',
    ]);

    Livewire::test(CreateFund::class)
        ->fillForm([
            'emission_id' => $emission->id,
            'fund_type_id' => $fundType->id,
            'fund_name_id' => $fundName->id,
            'fund_application_id' => $fundApplication->id,
            'bank_id' => $bank->id,
            'account' => '12345-6',
        ])
        ->call('create');

    $fund = Fund::query()->first();

    expect($fund)->not->toBeNull()
        ->and($fund?->emission_id)->toBe($emission->id)
        ->and($fund?->fund_type_id)->toBe($fundType->id)
        ->and($fund?->fund_name_id)->toBe($fundName->id)
        ->and($fund?->fund_application_id)->toBe($fundApplication->id)
        ->and($fund?->bank_id)->toBe($bank->id)
        ->and($fund?->account)->toBe('12345-6');
});

it('requires all mandatory fields when creating a fund', function () {
    $this->actingAs(makeFundAdminUser());

    Livewire::test(CreateFund::class)
        ->call('create')
        ->assertHasFormErrors([
            'emission_id' => 'required',
            'fund_type_id' => 'required',
            'fund_name_id' => 'required',
            'fund_application_id' => 'required',
            'bank_id' => 'required',
            'account' => 'required',
        ]);
});

it('prevents duplicate account registrations within the same operation and application', function () {
    $this->actingAs(makeFundAdminUser());

    $emission = Emission::factory()->create();
    $fundType = FundType::factory()->create();
    $fundName = FundName::factory()->create([
        'fund_type_id' => $fundType->id,
    ]);
    $fundApplication = FundApplication::factory()->create();
    $bank = Bank::factory()->create();

    Fund::factory()->create([
        'emission_id' => $emission->id,
        'fund_type_id' => $fundType->id,
        'fund_name_id' => $fundName->id,
        'fund_application_id' => $fundApplication->id,
        'bank_id' => $bank->id,
        'account' => '98765-4',
    ]);

    Livewire::test(CreateFund::class)
        ->fillForm([
            'emission_id' => $emission->id,
            'fund_type_id' => $fundType->id,
            'fund_name_id' => $fundName->id,
            'fund_application_id' => $fundApplication->id,
            'bank_id' => $bank->id,
            'account' => '98765-4',
        ])
        ->call('create')
        ->assertHasFormErrors(['account']);
});

it('allows reusing the same account in another application', function () {
    $this->actingAs(makeFundAdminUser());

    $emission = Emission::factory()->create();
    $fundType = FundType::factory()->create();
    $fundName = FundName::factory()->create([
        'fund_type_id' => $fundType->id,
    ]);
    $primaryApplication = FundApplication::factory()->create();
    $secondaryApplication = FundApplication::factory()->create();
    $bank = Bank::factory()->create();

    Fund::factory()->create([
        'emission_id' => $emission->id,
        'fund_type_id' => $fundType->id,
        'fund_name_id' => $fundName->id,
        'fund_application_id' => $primaryApplication->id,
        'bank_id' => $bank->id,
        'account' => '45678-9',
    ]);

    Livewire::test(CreateFund::class)
        ->fillForm([
            'emission_id' => $emission->id,
            'fund_type_id' => $fundType->id,
            'fund_name_id' => $fundName->id,
            'fund_application_id' => $secondaryApplication->id,
            'bank_id' => $bank->id,
            'account' => '45678-9',
        ])
        ->call('create');

    expect(Fund::query()->count())->toBe(2);
});

it('allows creating a fund type inline from the fund form', function () {
    $this->actingAs(makeFundAdminUser());

    Livewire::test(CreateFund::class)
        ->assertFormComponentActionExists('fund_type_id', 'createOption')
        ->mountFormComponentAction('fund_type_id', 'createOption')
        ->fillForm([
            'name' => 'Fundo Multimercado',
        ])
        ->callMountedAction()
        ->assertHasNoFormErrors();

    expect(FundType::query()->where('name', 'Fundo Multimercado')->exists())->toBeTrue();
});

it('shows the required fields on the auxiliary bank resource form', function () {
    $this->actingAs(makeFundAdminUser());

    Livewire::test(CreateBank::class)
        ->assertFormExists()
        ->assertFormFieldExists('name')
        ->assertFormFieldExists('logo_path');
});

function makeFundAdminUser(): User
{
    $user = User::factory()->withTwoFactor()->create([
        'email' => fake()->unique()->safeEmail(),
    ]);
    $user->assignRole('admin');

    return $user;
}
