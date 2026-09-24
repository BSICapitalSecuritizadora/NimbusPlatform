<?php

use App\Filament\Resources\Banks\BankResource;
use App\Filament\Resources\Banks\Pages\CreateBank;
use App\Filament\Resources\Banks\Pages\EditBank;
use App\Filament\Resources\Banks\Pages\ListBanks;
use App\Filament\Resources\FundApplications\FundApplicationResource;
use App\Filament\Resources\FundApplications\Pages\CreateFundApplication;
use App\Filament\Resources\FundApplications\Pages\EditFundApplication;
use App\Filament\Resources\FundApplications\Pages\ListFundApplications;
use App\Filament\Resources\FundNames\Pages\CreateFundName;
use App\Filament\Resources\FundNames\Pages\ListFundNames;
use App\Filament\Resources\Funds\Pages\CreateFund;
use App\Filament\Resources\Funds\Pages\ListFunds;
use App\Filament\Resources\FundTypes\Pages\CreateFundType;
use App\Filament\Resources\FundTypes\Pages\ListFundTypes;
use App\Models\Bank;
use App\Models\Emission;
use App\Models\Fund;
use App\Models\FundApplication;
use App\Models\FundName;
use App\Models\FundType;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\TextInput;
use Filament\Support\RawJs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
        ->assertTableFilterExists('bank_id')
        ->assertTableFilterExists('account');
});

it('filters funds by current account', function () {
    $this->actingAs(makeFundAdminUser());

    $selectedFund = Fund::factory()->create([
        'account' => '12345-6',
    ]);
    $otherFund = Fund::factory()->create([
        'account' => '65432-1',
    ]);

    Livewire::test(ListFunds::class)
        ->assertCanSeeTableRecords([$selectedFund, $otherFund])
        ->filterTable('account', '12345-6')
        ->assertCanSeeTableRecords([$selectedFund])
        ->assertCanNotSeeTableRecords([$otherFund]);
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
            'trade_name' => 'Fundo Atlas Capital',
            'bank_id' => $bank->id,
            'agency' => '1234-5',
            'account' => '12345-6',
            'balance' => '150.000,25',
            'minimum_balance' => '125.000,00',
        ])
        ->call('create');

    $fund = Fund::query()->first();

    expect($fund)->not->toBeNull()
        ->and($fund?->emission_id)->toBe($emission->id)
        ->and($fund?->fund_type_id)->toBe($fundType->id)
        ->and($fund?->fund_name_id)->toBe($fundName->id)
        ->and($fund?->fund_application_id)->toBe($fundApplication->id)
        ->and($fund?->bank_id)->toBe($bank->id)
        ->and($fund?->trade_name)->toBe('Fundo Atlas Capital')
        ->and($fund?->agency)->toBe('1234-5')
        ->and($fund?->account)->toBe('12345-6')
        ->and($fund?->balance)->toBe('150000.25')
        ->and($fund?->minimum_balance)->toBe('125000.00')
        ->and($fund?->balance_updated_at)->not->toBeNull();
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
            'agency' => 'required',
            'account' => 'required',
            'balance' => 'required',
        ]);
});

it('shows the expected masks for agency, current account, balance and minimum balance fields', function () {
    $this->actingAs(makeFundAdminUser());

    Livewire::test(CreateFund::class)
        ->assertFormFieldExists('agency', function (TextInput $field): bool {
            return $field->getMask() === '9999-9';
        })
        ->assertFormFieldExists('account', function (TextInput $field): bool {
            $mask = $field->getMask();

            return ($mask instanceof RawJs)
                && str_contains((string) $mask, '99999-9')
                && str_contains((string) $mask, '999999999-9');
        })
        ->assertFormFieldExists('balance', function (TextInput $field): bool {
            $mask = $field->getMask();

            return ($mask instanceof RawJs)
                && str_contains((string) $mask, '$money($input');
        })
        ->assertFormFieldExists('minimum_balance', function (TextInput $field): bool {
            $mask = $field->getMask();

            return ($mask instanceof RawJs)
                && str_contains((string) $mask, '$money($input');
        });
});

it('validates the agency and current account formats', function () {
    $this->actingAs(makeFundAdminUser());

    $emission = Emission::factory()->create();
    $fundType = FundType::factory()->create();
    $fundName = FundName::factory()->create([
        'fund_type_id' => $fundType->id,
    ]);
    $fundApplication = FundApplication::factory()->create();
    $bank = Bank::factory()->create();

    Livewire::test(CreateFund::class)
        ->fillForm([
            'emission_id' => $emission->id,
            'fund_type_id' => $fundType->id,
            'fund_name_id' => $fundName->id,
            'fund_application_id' => $fundApplication->id,
            'bank_id' => $bank->id,
            'agency' => '123-4',
            'account' => '1234-5',
            'balance' => '1.500,00',
        ])
        ->call('create')
        ->assertHasFormErrors([
            'agency' => 'regex',
            'account' => 'regex',
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
        'agency' => '1111-1',
        'account' => '98765-4',
    ]);

    Livewire::test(CreateFund::class)
        ->fillForm([
            'emission_id' => $emission->id,
            'fund_type_id' => $fundType->id,
            'fund_name_id' => $fundName->id,
            'fund_application_id' => $fundApplication->id,
            'bank_id' => $bank->id,
            'agency' => '2222-2',
            'account' => '98765-4',
            'balance' => '98.765,43',
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
        'agency' => '3333-3',
        'account' => '45678-9',
    ]);

    Livewire::test(CreateFund::class)
        ->fillForm([
            'emission_id' => $emission->id,
            'fund_type_id' => $fundType->id,
            'fund_name_id' => $fundName->id,
            'fund_application_id' => $secondaryApplication->id,
            'bank_id' => $bank->id,
            'agency' => '4444-4',
            'account' => '45678-9',
            'balance' => '45.000,00',
        ])
        ->call('create');

    expect(Fund::query()->count())->toBe(2);
});

it('allows creating a fund type inline from the fund form', function () {
    $this->actingAs(makeFundAdminUser());
    $createFundTypeAction = TestAction::make('createOption')
        ->schemaComponent('fund_type_id');

    Livewire::test(CreateFund::class)
        ->assertActionExists($createFundTypeAction)
        ->mountAction($createFundTypeAction)
        ->fillForm([
            'name' => 'Fundo Multimercado',
        ])
        ->callMountedAction()
        ->assertHasNoFormErrors();

    expect(FundType::query()->where('name', 'Fundo Multimercado')->exists())->toBeTrue();
});

it('allows creating a bank inline from the fund form and selects it', function () {
    Storage::fake('public');
    $this->actingAs(makeFundAdminUser());
    $createBankAction = TestAction::make('createOption')
        ->schemaComponent('bank_id');

    $component = Livewire::test(CreateFund::class)
        ->assertActionExists($createBankAction)
        ->mountAction($createBankAction)
        ->fillForm([
            'name' => 'Banco Cooperativo de Teste S.A.',
            'logo_path' => UploadedFile::fake()->image('logo.png'),
        ])
        ->callMountedAction()
        ->assertHasNoFormErrors();

    $bank = Bank::query()->where('name', 'Banco Cooperativo de Teste S.A.')->first();

    expect($bank)->not->toBeNull();

    $component->assertSchemaStateSet(['bank_id' => $bank->id]);
});

it('shows the required fields on the auxiliary bank resource form', function () {
    $this->actingAs(makeFundAdminUser());

    Livewire::test(CreateBank::class)
        ->assertFormExists()
        ->assertFormFieldExists('name')
        ->assertFormFieldExists('logo_path');
});

it('renders the funds list page with custom subheading and empty state', function () {
    $this->actingAs(makeFundAdminUser());

    Livewire::test(ListFunds::class)
        ->assertOk()
        ->assertSee('Acompanhamento dos fundos vinculados às operações, com identificação, aplicação e dados bancários.')
        ->assertSee('Nenhum fundo cadastrado')
        ->assertSee('Criar primeiro fundo')
        ->assertDontSee('Nenhum fundo corresponde aos filtros selecionados');
});

it('shows contextual empty state when filtered and allows clearing filters', function () {
    $this->actingAs(makeFundAdminUser());
    Fund::factory()->create();

    Livewire::test(ListFunds::class)
        ->set('tableSearch', 'termo-inexistente')
        ->assertSee('Nenhum fundo corresponde aos filtros selecionados')
        ->assertSee('Limpar filtros')
        ->assertDontSee('Nenhum fundo cadastrado');
});

it('renders formatted currency and status badges in the funds table', function () {
    $this->actingAs(makeFundAdminUser());

    $emission = Emission::factory()->create(['name' => 'CRI Alpha Residencial']);
    $fundName = FundName::factory()->create(['name' => 'Fundo Reserva Obra']);
    $bank = Bank::factory()->create(['name' => 'Banco Bradesco']);

    $fund = Fund::factory()->create([
        'emission_id' => $emission->id,
        'fund_name_id' => $fundName->id,
        'bank_id' => $bank->id,
        'balance' => 250000.50,
        'minimum_balance' => 100000.00,
        'balance_updated_at' => now(),
    ]);

    Livewire::test(ListFunds::class)
        ->assertSee('CRI Alpha Residencial')
        ->assertSee('Fundo Reserva Obra')
        ->assertSee('Banco Bradesco')
        ->assertSee('250.000,50')
        ->assertSee('Em dia');
});

it('renders the fund types list page with custom subheading and empty state', function () {
    $this->actingAs(makeFundAdminUser());

    Livewire::test(ListFundTypes::class)
        ->assertOk()
        ->assertSee('Gerencie as classificações utilizadas no cadastro e organização dos fundos das operações.')
        ->assertSee('Nenhum tipo de fundo cadastrado')
        ->assertSee('Criar primeiro tipo de fundo')
        ->assertDontSee('Nenhum tipo de fundo corresponde à busca aplicada');
});

it('shows contextual empty state on the fund types list when the search has no results', function () {
    $this->actingAs(makeFundAdminUser());
    FundType::factory()->create();

    Livewire::test(ListFundTypes::class)
        ->set('tableSearch', 'termo-inexistente')
        ->assertSee('Nenhum tipo de fundo corresponde à busca aplicada')
        ->assertSee('Limpar busca')
        ->assertDontSee('Nenhum tipo de fundo cadastrado');
});

it('renders humanized linked names and funds counters on the fund types table', function () {
    $this->actingAs(makeFundAdminUser());

    $typeWithNames = FundType::factory()->create(['name' => 'Tipo Reserva Teste']);
    FundName::factory()->count(2)->create(['fund_type_id' => $typeWithNames->id]);

    $typeWithFund = FundType::factory()->create(['name' => 'Tipo Juros Teste']);
    Fund::factory()->create(['fund_type_id' => $typeWithFund->id]);

    Livewire::test(ListFundTypes::class)
        ->assertSee('Tipo Reserva Teste')
        ->assertSee('2 nomes')
        ->assertSee('Tipo Juros Teste')
        ->assertSee('1 fundo')
        ->assertSee('1 nome');
});

it('renders the create fund form page with custom subheading, sections and fields', function () {
    $this->actingAs(makeFundAdminUser());

    Livewire::test(CreateFund::class)
        ->assertOk()
        ->assertSee('Cadastre a classificação, os dados bancários e os limites financeiros do fundo.')
        ->assertSee('Classificação')
        ->assertSee('Dados Bancários')
        ->assertSee('Saldos e Limites')
        ->assertSee('Criar fundo')
        ->assertSee('Salvar e criar outro')
        ->assertSee('Cancelar')
        ->assertFormFieldExists('emission_id')
        ->assertFormFieldExists('fund_type_id')
        ->assertFormFieldExists('fund_name_id')
        ->assertFormFieldExists('fund_application_id')
        ->assertFormFieldExists('bank_id')
        ->assertFormFieldExists('agency')
        ->assertFormFieldExists('account')
        ->assertFormFieldExists('balance')
        ->assertFormFieldExists('minimum_balance');
});

it('renders the create fund type page with subheading, section description and refined actions', function () {
    $this->actingAs(makeFundAdminUser());

    Livewire::test(CreateFundType::class)
        ->assertOk()
        ->assertSee('Cadastre uma classificação para organizar os fundos das operações.')
        ->assertSee('Defina o nome utilizado para classificar os fundos cadastrados.')
        ->assertSee('Criar tipo de fundo')
        ->assertSee('Salvar e criar outro')
        ->assertSee('Cancelar');
});

it('creates a fund type through the create page with a success notification', function () {
    $this->actingAs(makeFundAdminUser());

    Livewire::test(CreateFundType::class)
        ->fillForm(['name' => 'Fundo de Reserva'])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified('Tipo de fundo cadastrado com sucesso.');

    expect(FundType::query()->where('name', 'Fundo de Reserva')->exists())->toBeTrue();
});

it('renders the fund names list page with custom subheading, empty state and create action', function () {
    $this->actingAs(makeFundAdminUser());

    Livewire::test(ListFundNames::class)
        ->assertOk()
        ->assertSee('Gerencie as denominações disponíveis para cada tipo de fundo.')
        ->assertSee('Nenhum nome de fundo cadastrado')
        ->assertSee('Criar primeiro nome de fundo')
        ->assertActionExists('create')
        ->assertActionHasLabel('create', 'Criar nome de fundo');
});

it('shows contextual empty state on the fund names list when search has no results', function () {
    $this->actingAs(makeFundAdminUser());
    FundName::factory()->create();

    Livewire::test(ListFundNames::class)
        ->set('tableSearch', 'termo-inexistente')
        ->assertSee('Nenhum nome de fundo encontrado')
        ->assertSee('Limpar busca')
        ->assertDontSee('Nenhum nome de fundo cadastrado');
});

it('renders humanized fund counter and type relation on fund names table', function () {
    $this->actingAs(makeFundAdminUser());

    $type = FundType::factory()->create(['name' => 'Crédito Estruturado']);
    $fundNameWithFund = FundName::factory()->create([
        'name' => 'Fundo Reserva Obra',
        'fund_type_id' => $type->id,
    ]);
    Fund::factory()->create(['fund_name_id' => $fundNameWithFund->id]);

    $fundNameWithoutFund = FundName::factory()->create([
        'name' => 'Fundo Despesas Futuras',
        'fund_type_id' => $type->id,
    ]);

    Livewire::test(ListFundNames::class)
        ->assertSee('Fundo Reserva Obra')
        ->assertSee('Fundo Despesas Futuras')
        ->assertSee('Crédito Estruturado')
        ->assertSee('1 fundo');
});

it('renders the create fund name form page with custom subheading and actions', function () {
    $this->actingAs(makeFundAdminUser());

    Livewire::test(CreateFundName::class)
        ->assertOk()
        ->assertSee('Cadastre uma nova denominação para vinculá-la a um tipo de fundo.')
        ->assertSee('Criar nome de fundo')
        ->assertSee('Salvar e criar outro')
        ->assertSee('Cancelar')
        ->assertFormFieldExists('fund_type_id')
        ->assertFormFieldExists('name');
});

it('renders the fund applications list page with custom subheading and empty state', function () {
    $this->actingAs(makeFundAdminUser());

    Livewire::test(ListFundApplications::class)
        ->assertOk()
        ->assertSee('Gerencie as aplicações utilizadas na classificação financeira dos fundos.')
        ->assertSee('Nenhuma aplicação cadastrada')
        ->assertSee('Criar primeira aplicação')
        ->assertDontSee('Nenhuma aplicação corresponde à busca aplicada');
});

it('shows contextual empty state on the fund applications list when the search has no results', function () {
    $this->actingAs(makeFundAdminUser());
    FundApplication::factory()->create();

    Livewire::test(ListFundApplications::class)
        ->set('tableSearch', 'termo-inexistente')
        ->assertSee('Nenhuma aplicação corresponde à busca aplicada')
        ->assertSee('Limpar busca')
        ->assertDontSee('Nenhuma aplicação cadastrada');
});

it('renders humanized linked funds counter on the fund applications table', function () {
    $this->actingAs(makeFundAdminUser());

    $application = FundApplication::factory()->create(['name' => 'Renda Fixa Teste']);
    Fund::factory()->create(['fund_application_id' => $application->id]);

    Livewire::test(ListFundApplications::class)
        ->assertSee('Renda Fixa Teste')
        ->assertSee('1 fundo');
});

it('renders the create fund application page with subheading, section description and refined actions', function () {
    $this->actingAs(makeFundAdminUser());

    Livewire::test(CreateFundApplication::class)
        ->assertOk()
        ->assertSee('Cadastre uma aplicação para utilizá-la na configuração financeira dos fundos.')
        ->assertSee('Defina o nome utilizado para identificar a aplicação nos fundos cadastrados.')
        ->assertSee('Criar aplicação')
        ->assertSee('Salvar e criar outro')
        ->assertSee('Cancelar');
});

it('creates a fund application through the create page with a success notification', function () {
    $this->actingAs(makeFundAdminUser());

    Livewire::test(CreateFundApplication::class)
        ->fillForm(['name' => 'Renda Fixa Ativa'])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified('Aplicação cadastrada com sucesso.');

    expect(FundApplication::query()->where('name', 'Renda Fixa Ativa')->exists())->toBeTrue();
});

it('renders the edit fund application page with contextual title, subheading, full-width attributes and actions', function () {
    $this->actingAs(makeFundAdminUser());

    $application = FundApplication::factory()->create(['name' => 'Aplicação de Oliveira-Assunção']);

    $test = Livewire::test(EditFundApplication::class, [
        'record' => $application->getRouteKey(),
    ]);

    $test->assertOk()
        ->assertSee('Editar Aplicação de Oliveira-Assunção')
        ->assertSee('Atualize a identificação utilizada nos fundos vinculados.')
        ->assertSee('Dados da aplicação')
        ->assertSee('Defina o nome utilizado para identificar a aplicação nos fundos cadastrados.')
        ->assertSee('Salvar alterações')
        ->assertSee('Cancelar')
        ->assertFormFieldExists('name');

    $page = $test->instance();
    expect($page->getExtraBodyAttributes()['class'])->toContain('bsi-fund-application-form-page')
        ->and($page->getExtraBodyAttributes()['class'])->toContain('bsi-fund-form-page')
        ->and(invade($page)->hasUnsavedDataChangesAlert())->toBeTrue();
});

it('tucks the destructive delete action into a secondary ellipsis menu with confirmation on edit fund application', function () {
    $this->actingAs(makeFundAdminUser());

    $application = FundApplication::factory()->create();

    $page = Livewire::test(EditFundApplication::class, [
        'record' => $application->getRouteKey(),
    ])->instance();

    $headerActions = invade($page)->getHeaderActions();

    expect($headerActions)->toHaveCount(1)
        ->and($headerActions[0])->toBeInstanceOf(ActionGroup::class);

    $deleteAction = collect($headerActions[0]->getFlatActions())
        ->first(fn ($action): bool => $action instanceof DeleteAction);

    expect($deleteAction)->not->toBeNull()
        ->and($deleteAction->getLabel())->toBe('Excluir aplicação')
        ->and($deleteAction->isConfirmationRequired())->toBeTrue();
});

it('updates a fund application through the edit page with a success notification', function () {
    $this->actingAs(makeFundAdminUser());

    $application = FundApplication::factory()->create(['name' => 'Nome Antigo']);

    Livewire::test(EditFundApplication::class, [
        'record' => $application->getRouteKey(),
    ])
        ->fillForm(['name' => 'Nome Atualizado'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified('Aplicação atualizada com sucesso.');

    expect($application->fresh()->name)->toBe('Nome Atualizado');
});

it('hides the delete action when funds are linked to the application', function () {
    $this->actingAs(makeFundAdminUser());

    $application = FundApplication::factory()->create();
    Fund::factory()->create(['fund_application_id' => $application->id]);

    $page = Livewire::test(EditFundApplication::class, [
        'record' => $application->getRouteKey(),
    ])->instance();

    $headerActions = invade($page)->getHeaderActions();
    $deleteAction = collect($headerActions[0]->getFlatActions())
        ->first(fn ($action): bool => $action instanceof DeleteAction);

    expect($deleteAction->record($application)->isVisible())->toBeFalse()
        ->and(FundApplicationResource::canDelete($application))->toBeFalse();
});

it('renders the banks list page with custom subheading and empty state', function () {
    $this->actingAs(makeFundAdminUser());

    Livewire::test(ListBanks::class)
        ->assertOk()
        ->assertSee('Gerencie as instituições bancárias utilizadas nos fundos e contas financeiras das operações.')
        ->assertSee('Nenhum banco cadastrado')
        ->assertSee('Criar primeiro banco');
});

it('renders the banks search empty state with clear search action', function () {
    $this->actingAs(makeFundAdminUser());
    Bank::factory()->create(['name' => 'Banco Bradesco']);

    Livewire::test(ListBanks::class)
        ->set('tableSearch', 'termo-inexistente')
        ->assertSee('Nenhum banco encontrado')
        ->assertSee('Limpar busca')
        ->assertDontSee('Nenhum banco cadastrado');
});

it('renders bank name, initials avatar fallback, and humanized linked funds counter on banks table', function () {
    $this->actingAs(makeFundAdminUser());

    $bankWithLogo = Bank::factory()->create([
        'name' => 'Banco Bradesco',
        'logo_path' => 'banks/logos/bradesco.png',
    ]);
    Fund::factory()->create(['bank_id' => $bankWithLogo->id]);

    $bankWithoutLogo = Bank::factory()->create([
        'name' => 'Banco Alfa',
        'logo_path' => '',
    ]);

    Livewire::test(ListBanks::class)
        ->assertSee('Banco Bradesco')
        ->assertSee('Banco Alfa')
        ->assertSee('1 fundo')
        ->assertSee('banks/logos/bradesco.png')
        ->assertSee('BA');
});

it('renders the create bank form page with custom subheading and fields', function () {
    $this->actingAs(makeFundAdminUser());

    Livewire::test(CreateBank::class)
        ->assertOk()
        ->assertSee('Cadastre uma nova instituição bancária com nome e logotipo institucional.')
        ->assertSee('Criar banco')
        ->assertSee('Salvar e criar outro')
        ->assertSee('Cancelar')
        ->assertFormFieldExists('name')
        ->assertFormFieldExists('logo_path');
});

it('renders the edit bank form page with contextual title, subheading, full-width attributes, logo preview and actions', function () {
    $this->actingAs(makeFundAdminUser());

    $bank = Bank::factory()->create([
        'name' => 'Banco Bradesco S.A.',
        'logo_path' => 'banks/logos/bradesco.png',
    ]);

    $test = Livewire::test(EditBank::class, [
        'record' => $bank->getRouteKey(),
    ]);

    $test->assertOk()
        ->assertSee('Editar Banco Bradesco S.A.')
        ->assertSee('Atualize os dados cadastrais e o logotipo institucional da instituição bancária.')
        ->assertSee('Dados da Instituição Bancária')
        ->assertSee('Informe a denominação e envie o logotipo oficial do banco.')
        ->assertSee('Logotipo atual')
        ->assertSee('Substituir logotipo')
        ->assertSee('Salvar alterações')
        ->assertSee('Cancelar')
        ->assertFormFieldExists('name')
        ->assertFormFieldExists('logo_path');

    $page = $test->instance();
    expect($page->getExtraBodyAttributes()['class'])->toContain('bsi-bank-form-page')
        ->and($page->getExtraBodyAttributes()['class'])->toContain('bsi-fund-form-page')
        ->and(invade($page)->hasUnsavedDataChangesAlert())->toBeTrue();
});

it('tucks the destructive delete action into an ellipsis menu with confirmation on edit bank', function () {
    $this->actingAs(makeFundAdminUser());

    $bank = Bank::factory()->create();

    $page = Livewire::test(EditBank::class, [
        'record' => $bank->getRouteKey(),
    ])->instance();

    $headerActions = invade($page)->getHeaderActions();

    expect($headerActions)->toHaveCount(1)
        ->and($headerActions[0])->toBeInstanceOf(ActionGroup::class);

    $deleteAction = collect($headerActions[0]->getFlatActions())
        ->first(fn ($action): bool => $action instanceof DeleteAction);

    expect($deleteAction)->not->toBeNull()
        ->and($deleteAction->getLabel())->toBe('Excluir banco')
        ->and($deleteAction->isConfirmationRequired())->toBeTrue();
});

it('updates a bank through the edit page with a success notification', function () {
    $this->actingAs(makeFundAdminUser());

    $bank = Bank::factory()->create(['name' => 'Banco Antigo']);

    Livewire::test(EditBank::class, [
        'record' => $bank->getRouteKey(),
    ])
        ->fillForm(['name' => 'Banco Atualizado'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified('Banco atualizado com sucesso.');

    expect($bank->fresh()->name)->toBe('Banco Atualizado');
});

it('hides the delete action when funds are linked to the bank', function () {
    $this->actingAs(makeFundAdminUser());

    $bank = Bank::factory()->create();
    Fund::factory()->create(['bank_id' => $bank->id]);

    $page = Livewire::test(EditBank::class, [
        'record' => $bank->getRouteKey(),
    ])->instance();

    $headerActions = invade($page)->getHeaderActions();
    $deleteAction = collect($headerActions[0]->getFlatActions())
        ->first(fn ($action): bool => $action instanceof DeleteAction);

    expect($deleteAction->record($bank)->isVisible())->toBeFalse()
        ->and(BankResource::canDelete($bank))->toBeFalse();
});

function makeFundAdminUser(): User
{
    $user = User::factory()->withTwoFactor()->create([
        'email' => fake()->unique()->safeEmail(),
    ]);
    $user->assignRole('admin');

    return $user;
}
