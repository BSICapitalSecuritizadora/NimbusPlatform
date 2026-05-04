<?php

use App\Filament\Pages\Nimbus\NimbusDashboard;
use App\Filament\Pages\Nimbus\NotificationSettings;
use App\Filament\Resources\Banks\BankResource;
use App\Filament\Resources\Constructions\ConstructionResource;
use App\Filament\Resources\Documents\DocumentResource;
use App\Filament\Resources\Emissions\EmissionResource;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\ExpenseServiceProviders\ExpenseServiceProviderResource;
use App\Filament\Resources\ExpenseServiceProviderTypes\ExpenseServiceProviderTypeResource;
use App\Filament\Resources\FundApplications\FundApplicationResource;
use App\Filament\Resources\FundNames\FundNameResource;
use App\Filament\Resources\Funds\FundResource;
use App\Filament\Resources\FundTypes\FundTypeResource;
use App\Filament\Resources\ProposalRepresentatives\ProposalRepresentativeResource;
use App\Filament\Resources\SalesBoards\SalesBoardResource;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('registers the Gestão section between Cadastro and Gestão de Acesso', function () {
    $navigationGroups = collect(Filament::getPanel('admin')->getNavigationGroups())
        ->map(fn (NavigationGroup|string $group): string => $group instanceof NavigationGroup ? $group->getLabel() ?? '' : $group)
        ->values()
        ->all();

    expect(EmissionResource::getNavigationGroup())->toBe(ExpenseResource::getNavigationGroup())
        ->and($navigationGroups)->toBe([
            'Gestão Documental Externa',
            'Auditoria',
            'Comercial',
            'Cadastro',
            'Gestão',
            'Gestão de Acesso',
            'Recrutamento',
            'Relatórios',
            'Configurações',
        ]);
});

it('registers the Despesas subsection inside Gestão', function () {
    $this->actingAs(makeNavigationAdminUser());

    $expensesGroup = collect(Filament::getPanel('admin')->getNavigation())
        ->first(fn (NavigationGroup $group) => $group->getLabel() === 'Gestão');
    $expenseItem = collect($expensesGroup?->getItems() ?? [])
        ->first(fn ($item) => $item->getLabel() === 'Despesas');
    $salesBoardItem = collect($expensesGroup?->getItems() ?? [])
        ->first(fn ($item) => $item->getLabel() === 'Quadro de Vendas');
    $serviceProviderTypeItem = collect($expenseItem?->getChildItems() ?? [])
        ->first(fn ($item) => $item->getLabel() === 'Tipos de prestador de serviço');
    $serviceProviderItem = collect($expenseItem?->getChildItems() ?? [])
        ->first(fn ($item) => $item->getLabel() === 'Prestadores de serviço');

    expect(ExpenseResource::getNavigationGroup())->toBe(ExpenseServiceProviderTypeResource::getNavigationGroup())
        ->and(ExpenseResource::getNavigationGroup())->toBe(ExpenseServiceProviderResource::getNavigationGroup())
        ->and(ExpenseResource::shouldRegisterNavigation())->toBeFalse()
        ->and(SalesBoardResource::getNavigationGroup())->toBe('Gestão')
        ->and(SalesBoardResource::getNavigationLabel())->toBe('Quadro de Vendas')
        ->and(ExpenseServiceProviderTypeResource::getNavigationParentItem())->toBe('Despesas')
        ->and(ExpenseServiceProviderTypeResource::getNavigationLabel())->toBe('Tipos de prestador de serviço')
        ->and(ExpenseServiceProviderResource::getNavigationParentItem())->toBe('Despesas')
        ->and(ExpenseServiceProviderResource::getNavigationLabel())->toBe('Prestadores de serviço')
        ->and($expensesGroup)->not->toBeNull()
        ->and($expenseItem)->not->toBeNull()
        ->and($expenseItem->getUrl())->toBe(ExpenseResource::getUrl(panel: 'admin'))
        ->and($salesBoardItem)->not->toBeNull()
        ->and($salesBoardItem->getUrl())->toBe(SalesBoardResource::getUrl(panel: 'admin'))
        ->and($serviceProviderTypeItem)->not->toBeNull()
        ->and($serviceProviderItem)->not->toBeNull();
});

it('registers the Fundos subsection inside Cadastro', function () {
    $this->actingAs(makeNavigationAdminUser());

    $registrationGroup = collect(Filament::getPanel('admin')->getNavigation())
        ->first(fn (NavigationGroup $group) => $group->getLabel() === 'Cadastro');
    $fundItem = collect(Filament::getPanel('admin')->getNavigationItems())
        ->first(fn ($item) => $item->getLabel() === 'Fundos');
    $constructionItem = collect($registrationGroup?->getItems() ?? [])
        ->first(fn ($item) => $item->getLabel() === 'Obras');

    expect(FundResource::getNavigationGroup())->toBe('Cadastro')
        ->and(FundResource::shouldRegisterNavigation())->toBeFalse()
        ->and(ConstructionResource::getNavigationGroup())->toBe('Cadastro')
        ->and(ConstructionResource::getNavigationLabel())->toBe('Obras')
        ->and(FundTypeResource::getNavigationGroup())->toBe('Cadastro')
        ->and(FundTypeResource::getNavigationParentItem())->toBe('Fundos')
        ->and(FundNameResource::getNavigationParentItem())->toBe('Fundos')
        ->and(FundApplicationResource::getNavigationParentItem())->toBe('Fundos')
        ->and(BankResource::getNavigationParentItem())->toBe('Fundos')
        ->and($fundItem)->not->toBeNull()
        ->and($fundItem->getUrl())->toBe(FundResource::getUrl(panel: 'admin'))
        ->and($constructionItem)->not->toBeNull()
        ->and($constructionItem->getUrl())->toBe(ConstructionResource::getUrl(panel: 'admin'));
});

it('uses pt-BR labels and translations for admin resources', function () {
    app()->setLocale('pt_BR');

    expect(FundApplicationResource::getNavigationLabel())->toBe('Aplicações')
        ->and(FundApplicationResource::getModelLabel())->toBe('Aplicação')
        ->and(DocumentResource::getNavigationLabel())->toBe('Documentos')
        ->and(DocumentResource::getModelLabel())->toBe('Documento')
        ->and(ProposalRepresentativeResource::getNavigationLabel())->toBe('Representantes comerciais')
        ->and(NimbusDashboard::getNavigationLabel())->toBe('Visão Geral')
        ->and(NotificationSettings::getNavigationLabel())->toBe('Configurações de notificações')
        ->and(__('Go to page :page', ['page' => 2]))->toBe('Ir para a página 2')
        ->and(trans('pagination.next'))->toBe('Próxima')
        ->and(trans('proposals.status.em_analise'))->toBe('Em Análise Técnica');
});

function makeNavigationAdminUser(): User
{
    $user = User::factory()->withTwoFactor()->create([
        'email' => fake()->unique()->safeEmail(),
    ]);
    $user->assignRole('admin');

    return $user;
}
