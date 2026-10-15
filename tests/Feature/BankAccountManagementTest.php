<?php

use App\Filament\Resources\BankAccounts\BankAccountResource;
use App\Filament\Resources\BankAccounts\Pages\CreateBankAccount;
use App\Filament\Resources\BankAccounts\Pages\EditBankAccount;
use App\Filament\Resources\BankAccounts\Pages\ListBankAccounts;
use App\Filament\Resources\BankAccounts\Pages\ViewBankAccount;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\BankApplication;
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

it('renders the bank accounts list with subheading, empty state and create action', function () {
    $this->actingAs(makeBankAccountAdminUser());

    Livewire::test(ListBankAccounts::class)
        ->assertOk()
        ->assertSee('Contas físicas globais')
        ->assertSee('Nenhuma conta bancária cadastrada')
        ->assertSee('Criar primeira conta')
        ->assertActionExists('create')
        ->assertActionHasLabel('create', 'Criar conta');
});

it('carries the cockpit page structure on list, create and view pages', function () {
    $this->actingAs(makeBankAccountAdminUser());

    $account = BankAccount::factory()->create();

    expect(Livewire::test(ListBankAccounts::class)->instance()->getExtraBodyAttributes()['class'])
        ->toContain('bsi-cockpit-page')
        ->and(Livewire::test(CreateBankAccount::class)->instance()->getExtraBodyAttributes()['class'])
        ->toContain('bsi-cockpit-page')
        ->and(Livewire::test(ViewBankAccount::class, ['record' => $account->getRouteKey()])->instance()->getExtraBodyAttributes()['class'])
        ->toContain('bsi-cockpit-page');
});

it('creates a bank account preserving leading zeros and deriving display values', function () {
    $this->actingAs(makeBankAccountAdminUser());

    $bank = Bank::factory()->create(['name' => 'Banco Meridional']);

    Livewire::test(CreateBankAccount::class)
        ->fillForm([
            'bank_id' => $bank->id,
            'agency_number' => '0123',
            'agency_digit' => '4',
            'account_number' => '0012345',
            'account_digit' => '6',
            'holder_context' => 'Patrimônio Separado',
            'conta_azul_account_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'notes' => 'Conta principal da operação.',
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified('Conta bancária criada com sucesso.');

    $account = BankAccount::query()->first();

    expect($account)->not->toBeNull()
        ->and($account->agency_number)->toBe('0123')
        ->and($account->account_number)->toBe('0012345')
        ->and($account->display_agency)->toBe('0123-4')
        ->and($account->display_account)->toBe('0012345-6')
        ->and($account->formattedAgency())->toBe('0123-4')
        ->and($account->formattedAccount())->toBe('0012345-6')
        ->and($account->holder_context)->toBe('Patrimônio Separado')
        ->and($account->conta_azul_account_id)->toBe('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
});

it('requires bank, agency and account parts when creating an account', function () {
    $this->actingAs(makeBankAccountAdminUser());

    Livewire::test(CreateBankAccount::class)
        ->call('create')
        ->assertHasFormErrors([
            'bank_id' => 'required',
            'agency_number' => 'required',
            'agency_digit' => 'required',
            'account_number' => 'required',
            'account_digit' => 'required',
        ]);
});

it('rejects malformed agency and account identifiers', function () {
    $this->actingAs(makeBankAccountAdminUser());

    $bank = Bank::factory()->create();

    Livewire::test(CreateBankAccount::class)
        ->fillForm([
            'bank_id' => $bank->id,
            'agency_number' => '12A4',
            'agency_digit' => 'ZZ',
            'account_number' => '123',
            'account_digit' => '7',
        ])
        ->call('create')
        ->assertHasFormErrors([
            'agency_number' => 'regex',
            'agency_digit' => 'regex',
            'account_number' => 'regex',
        ]);

    expect(BankAccount::query()->count())->toBe(0);
});

it('identifies duplicate physical accounts instead of creating them', function () {
    $this->actingAs(makeBankAccountAdminUser());

    $bank = Bank::factory()->create();

    BankAccount::factory()->create([
        'bank_id' => $bank->id,
        'agency_number' => '1234',
        'agency_digit' => '5',
        'account_number' => '12345',
        'account_digit' => '6',
    ]);

    Livewire::test(CreateBankAccount::class)
        ->fillForm([
            'bank_id' => $bank->id,
            'agency_number' => '1234',
            'agency_digit' => '5',
            'account_number' => '12345',
            'account_digit' => '6',
        ])
        ->call('create')
        ->assertHasFormErrors(['account_number']);

    expect(BankAccount::query()->count())->toBe(1);
});

it('rejects a conta azul id already configured on another account', function () {
    $this->actingAs(makeBankAccountAdminUser());

    BankAccount::factory()->create(['conta_azul_account_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee']);

    Livewire::test(CreateBankAccount::class)
        ->fillForm([
            'bank_id' => Bank::factory()->create()->id,
            'agency_number' => '1111',
            'agency_digit' => '1',
            'account_number' => '22222',
            'account_digit' => '2',
            'conta_azul_account_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
        ])
        ->call('create')
        ->assertHasFormErrors(['conta_azul_account_id' => 'unique']);
});

it('shows the account on the view page with integration safeguards', function () {
    $this->actingAs(makeBankAccountAdminUser());

    $account = BankAccount::factory()->create([
        'agency_number' => '0123',
        'agency_digit' => '4',
        'account_number' => '0012345',
        'account_digit' => '6',
        'conta_azul_account_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
    ]);

    Livewire::test(ViewBankAccount::class, ['record' => $account->getRouteKey()])
        ->assertOk()
        ->assertSee('0123-4')
        ->assertSee('0012345-6')
        ->assertSee('Configuração conceitual')
        ->assertSee('exclusivamente pelos IDs cadastrados em cada fundo');
});

it('updates a bank account through the edit page', function () {
    $this->actingAs(makeBankAccountAdminUser());

    $account = BankAccount::factory()->create(['holder_context' => 'Antigo']);

    Livewire::test(EditBankAccount::class, ['record' => $account->getRouteKey()])
        ->fillForm(['holder_context' => 'Novo contexto'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified('Conta bancária atualizada com sucesso.');

    expect($account->fresh()->holder_context)->toBe('Novo contexto');
});

it('blocks deletion while applications or funds are linked', function () {
    $this->actingAs(makeBankAccountAdminUser());

    $withApplication = BankAccount::factory()->create();
    BankApplication::factory()->create(['bank_account_id' => $withApplication->id]);

    $withFund = BankAccount::factory()->create();
    Fund::factory()->create(['bank_account_id' => $withFund->id]);

    $orphan = BankAccount::factory()->create();

    expect(BankAccountResource::canDelete($withApplication))->toBeFalse()
        ->and(BankAccountResource::canDelete($withFund))->toBeFalse()
        ->and(BankAccountResource::canDelete($orphan))->toBeTrue();

    $orphan->delete();

    expect(BankAccount::query()->whereKey($orphan->id)->exists())->toBeFalse();
});

it('denies access without funds permissions', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    expect(BankAccountResource::canViewAny())->toBeFalse()
        ->and(BankAccountResource::canCreate())->toBeFalse();

    Livewire::test(ListBankAccounts::class)->assertForbidden();
});

it('denies deletion to editors without funds.delete', function () {
    $editor = User::factory()->create();
    $editor->assignRole('editor');

    $this->actingAs($editor);

    $account = BankAccount::factory()->create();

    expect(BankAccountResource::canViewAny())->toBeTrue()
        ->and(BankAccountResource::canCreate())->toBeTrue()
        ->and(BankAccountResource::canEdit($account))->toBeTrue()
        ->and(BankAccountResource::canDelete($account))->toBeFalse();
});

function makeBankAccountAdminUser(): User
{
    $user = User::factory()->withTwoFactor()->create([
        'email' => fake()->unique()->safeEmail(),
    ]);
    $user->assignRole('admin');

    return $user;
}
