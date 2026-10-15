<?php

use App\Filament\Resources\BankApplications\BankApplicationResource;
use App\Filament\Resources\BankApplications\Pages\CreateBankApplication;
use App\Filament\Resources\BankApplications\Pages\EditBankApplication;
use App\Filament\Resources\BankApplications\Pages\ListBankApplications;
use App\Filament\Resources\BankApplications\Pages\ViewBankApplication;
use App\Models\BankAccount;
use App\Models\BankApplication;
use App\Models\Emission;
use App\Models\Fund;
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

it('renders the bank applications list with subheading and empty state', function () {
    $this->actingAs(makeBankApplicationAdminUser());

    Livewire::test(ListBankApplications::class)
        ->assertOk()
        ->assertSee('Produtos bancários de cada conta física')
        ->assertSee('Nenhuma aplicação bancária cadastrada')
        ->assertActionExists('create')
        ->assertActionHasLabel('create', 'Criar aplicação');
});

it('creates an application preserving leading zeros with active status by default', function () {
    $this->actingAs(makeBankApplicationAdminUser());

    $account = BankAccount::factory()->create();

    Livewire::test(CreateBankApplication::class)
        ->fillForm([
            'bank_account_id' => $account->id,
            'codigo_produto' => '00042',
            'nome_produto' => 'CDB DI',
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified('Aplicação bancária criada com sucesso.');

    $application = BankApplication::query()->first();

    expect($application)->not->toBeNull()
        ->and($application->codigo_produto)->toBe('00042')
        ->and($application->nome_produto)->toBe('CDB DI')
        ->and($application->status)->toBe(BankApplication::STATUS_ACTIVE)
        ->and($application->bank_account_id)->toBe($account->id);
});

it('requires the parent account and product code', function () {
    $this->actingAs(makeBankApplicationAdminUser());

    Livewire::test(CreateBankApplication::class)
        ->call('create')
        ->assertHasFormErrors([
            'bank_account_id' => 'required',
            'codigo_produto' => 'required',
        ]);
});

it('rejects duplicate product codes within the same account', function () {
    $this->actingAs(makeBankApplicationAdminUser());

    $account = BankAccount::factory()->create();

    BankApplication::factory()->create([
        'bank_account_id' => $account->id,
        'codigo_produto' => '00123',
    ]);

    Livewire::test(CreateBankApplication::class)
        ->fillForm([
            'bank_account_id' => $account->id,
            'codigo_produto' => '00123',
        ])
        ->call('create')
        ->assertHasFormErrors(['codigo_produto']);

    expect(BankApplication::query()->count())->toBe(1);
});

it('allows the same product code in different accounts', function () {
    $this->actingAs(makeBankApplicationAdminUser());

    $first = BankAccount::factory()->create();
    $second = BankAccount::factory()->create();

    BankApplication::factory()->create([
        'bank_account_id' => $first->id,
        'codigo_produto' => '00123',
    ]);

    Livewire::test(CreateBankApplication::class)
        ->fillForm([
            'bank_account_id' => $second->id,
            'codigo_produto' => '00123',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(BankApplication::query()->where('codigo_produto', '00123')->count())->toBe(2);
});

it('lists multiple applications of one account', function () {
    $this->actingAs(makeBankApplicationAdminUser());

    $account = BankAccount::factory()->create();

    BankApplication::factory()->create(['bank_account_id' => $account->id, 'codigo_produto' => '00001']);
    BankApplication::factory()->create(['bank_account_id' => $account->id, 'codigo_produto' => '00002']);
    BankApplication::factory()->create(['bank_account_id' => $account->id, 'codigo_produto' => '00003']);

    Livewire::test(ListBankApplications::class)
        ->assertSee('00001')
        ->assertSee('00002')
        ->assertSee('00003');
});

it('flags an application linked to funds from multiple emissions as shared', function () {
    $this->actingAs(makeBankApplicationAdminUser());

    $application = BankApplication::factory()->create(['codigo_produto' => '00777']);
    $firstEmission = Emission::factory()->create(['name' => 'Operação Alfa']);
    $secondEmission = Emission::factory()->create(['name' => 'Operação Beta']);

    Fund::factory()->create([
        'emission_id' => $firstEmission->id,
        'bank_account_id' => $application->bank_account_id,
        'bank_application_id' => $application->id,
    ]);
    Fund::factory()->create([
        'emission_id' => $secondEmission->id,
        'bank_account_id' => $application->bank_account_id,
        'bank_application_id' => $application->id,
    ]);

    expect($application->distinctEmissionCount())->toBe(2)
        ->and($application->isShared())->toBeTrue();

    Livewire::test(ListBankApplications::class)
        ->assertSee('00777')
        ->assertSee('Compartilhado');

    Livewire::test(ViewBankApplication::class, ['record' => $application->getRouteKey()])
        ->assertOk()
        ->assertSee('Compartilhado')
        ->assertSee('mais de uma operação')
        ->assertSee('Operação Alfa')
        ->assertSee('Operação Beta');
});

it('does not flag an application used by a single emission', function () {
    $this->actingAs(makeBankApplicationAdminUser());

    $application = BankApplication::factory()->create();
    $emission = Emission::factory()->create();

    Fund::factory()->count(2)->create([
        'emission_id' => $emission->id,
        'bank_account_id' => $application->bank_account_id,
        'bank_application_id' => $application->id,
    ]);

    expect($application->isShared())->toBeFalse();

    Livewire::test(ListBankApplications::class)
        ->assertDontSee('Compartilhado');
});

it('does not flag account sharing across emissions as a shared application', function () {
    $this->actingAs(makeBankApplicationAdminUser());

    $account = BankAccount::factory()->create();
    $firstApplication = BankApplication::factory()->create(['bank_account_id' => $account->id]);
    $secondApplication = BankApplication::factory()->create(['bank_account_id' => $account->id]);

    Fund::factory()->create([
        'emission_id' => Emission::factory()->create()->id,
        'bank_account_id' => $account->id,
        'bank_application_id' => $firstApplication->id,
    ]);
    Fund::factory()->create([
        'emission_id' => Emission::factory()->create()->id,
        'bank_account_id' => $account->id,
        'bank_application_id' => $secondApplication->id,
    ]);

    expect($firstApplication->isShared())->toBeFalse()
        ->and($secondApplication->isShared())->toBeFalse()
        ->and($account->linkedEmissions()->count())->toBe(2);

    Livewire::test(ListBankApplications::class)
        ->assertDontSee('Compartilhado');
});

it('hides emission names on the shared note without emissions.view', function () {
    $user = User::factory()->create();
    $user->givePermissionTo(['funds.view', 'funds.create', 'funds.update', 'funds.delete']);

    $this->actingAs($user);

    $application = BankApplication::factory()->create();
    $secretEmission = Emission::factory()->create(['name' => 'Operação Confidencial XPT']);

    Fund::factory()->create([
        'emission_id' => $secretEmission->id,
        'bank_account_id' => $application->bank_account_id,
        'bank_application_id' => $application->id,
    ]);
    Fund::factory()->create([
        'emission_id' => Emission::factory()->create()->id,
        'bank_account_id' => $application->bank_account_id,
        'bank_application_id' => $application->id,
    ]);

    Livewire::test(ViewBankApplication::class, ['record' => $application->getRouteKey()])
        ->assertOk()
        ->assertSee('Compartilhado')
        ->assertDontSee('Operação Confidencial XPT');
});

it('updates an application through the edit page', function () {
    $this->actingAs(makeBankApplicationAdminUser());

    $application = BankApplication::factory()->create(['nome_produto' => 'Antigo']);

    Livewire::test(EditBankApplication::class, ['record' => $application->getRouteKey()])
        ->fillForm(['nome_produto' => 'Novo produto'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified('Aplicação bancária atualizada com sucesso.');

    expect($application->fresh()->nome_produto)->toBe('Novo produto');
});

it('blocks deletion while funds are linked', function () {
    $this->actingAs(makeBankApplicationAdminUser());

    $linked = BankApplication::factory()->create();
    Fund::factory()->create([
        'bank_account_id' => $linked->bank_account_id,
        'bank_application_id' => $linked->id,
    ]);

    $orphan = BankApplication::factory()->create();

    expect(BankApplicationResource::canDelete($linked))->toBeFalse()
        ->and(BankApplicationResource::canDelete($orphan))->toBeTrue();

    $orphan->delete();

    expect(BankApplication::query()->whereKey($orphan->id)->exists())->toBeFalse();
});

it('denies access without funds permissions', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    expect(BankApplicationResource::canViewAny())->toBeFalse();

    Livewire::test(ListBankApplications::class)->assertForbidden();
});

function makeBankApplicationAdminUser(): User
{
    $user = User::factory()->withTwoFactor()->create([
        'email' => fake()->unique()->safeEmail(),
    ]);
    $user->assignRole('admin');

    return $user;
}
