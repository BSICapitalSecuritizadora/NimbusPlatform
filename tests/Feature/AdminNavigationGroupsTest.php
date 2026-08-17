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
use App\Filament\Resources\Invitations\InvitationResource;
use App\Filament\Resources\Nimbus\GeneralDocuments\GeneralDocumentResource;
use App\Filament\Resources\ProposalRepresentatives\ProposalRepresentativeResource;
use App\Filament\Resources\Receivables\ReceivableResource;
use App\Filament\Resources\ReminderLogs\ReminderLogResource;
use App\Filament\Resources\SalesBoards\SalesBoardResource;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('registers the current admin navigation groups in the intended order', function () {
    $navigationGroups = collect(Filament::getPanel('admin')->getNavigationGroups())
        ->map(fn (NavigationGroup|string $group): string => $group instanceof NavigationGroup ? $group->getLabel() ?? '' : $group)
        ->values()
        ->all();

    expect($navigationGroups)->toBe([
        'Comercial',
        'Operações',
        'Financeiro',
        'Governança & Risco',
        'Gestão Documental Externa',
        'Dados de Mercado',
        'Site Institucional',
        'Administração',
    ]);
});

it('resolves every navigation parent item inside its own group', function () {
    $entries = declaredNavigationEntries();
    $parented = $entries->filter(fn (array $entry): bool => filled($entry['parent']));

    $orphans = $parented
        ->reject(function (array $entry) use ($entries): bool {
            return $entries->contains(
                fn (array $candidate): bool => $candidate['group'] === $entry['group']
                    && ($candidate['key'] === $entry['parent'] || $candidate['label'] === $entry['parent']),
            );
        })
        ->map(fn (array $entry): string => sprintf('%s -> %s (%s)', $entry['label'], $entry['parent'], $entry['group'] ?? 'sem grupo'))
        ->values()
        ->all();

    expect($orphans)->toBe([])
        ->and($parented)->not->toBeEmpty();
});

it('registers every declared navigation group in the panel', function () {
    $registeredGroups = collect(Filament::getPanel('admin')->getNavigationGroups())
        ->map(fn (NavigationGroup|string $group): string => $group instanceof NavigationGroup ? $group->getLabel() ?? '' : $group)
        ->all();

    $declaredGroups = declaredNavigationEntries()
        ->map(fn (array $entry) => $entry['group'])
        ->filter()
        ->unique()
        ->values()
        ->all();

    expect($declaredGroups)->not->toBeEmpty()
        ->and(array_diff($declaredGroups, $registeredGroups))->toBe([]);
});

it('groups the proposal pipeline inside Comercial', function () {
    $this->actingAs(makeNavigationAdminUser());

    $representativeItem = navigationChild('Comercial', 'Propostas', 'Representantes Comerciais');

    expect(ProposalRepresentativeResource::getNavigationGroup())->toBe('Comercial')
        ->and(ProposalRepresentativeResource::getNavigationParentItem())->toBe('Propostas')
        ->and(navigationItem('Comercial', 'Painel de Propostas'))->not->toBeNull()
        ->and(navigationItem('Comercial', 'Investidores'))->not->toBeNull()
        ->and($representativeItem)->not->toBeNull()
        ->and($representativeItem->getUrl())->toBe(ProposalRepresentativeResource::getUrl(panel: 'admin'));
});

it('groups the emission monthly report resources under Emissões', function () {
    $this->actingAs(makeNavigationAdminUser());

    $children = navigationChildLabels('Operações', 'Emissões');

    expect(EmissionResource::getNavigationGroup())->toBe('Operações')
        ->and(SalesBoardResource::getNavigationGroup())->toBe('Operações')
        ->and(SalesBoardResource::getNavigationParentItem())->toBe('Emissões')
        ->and($children)->toBe([
            'Quadro de Vendas',
            'Negociações',
            'Relatório Mensal',
            'Comentários do Relatório',
        ]);
});

it('groups the construction resources under Obras', function () {
    $this->actingAs(makeNavigationAdminUser());

    expect(ConstructionResource::getNavigationGroup())->toBe('Operações')
        ->and(navigationChildLabels('Operações', 'Obras'))->toBe([
            'Operações de Obra',
            'Medições',
        ]);
});

it('groups the fund registries under Fundos inside Financeiro', function () {
    $this->actingAs(makeNavigationAdminUser());

    expect(FundResource::getNavigationGroup())->toBe('Financeiro')
        ->and(FundResource::shouldRegisterNavigation())->toBeTrue()
        ->and(FundTypeResource::getNavigationGroup())->toBe('Financeiro')
        ->and(FundNameResource::getNavigationGroup())->toBe('Financeiro')
        ->and(FundApplicationResource::getNavigationGroup())->toBe('Financeiro')
        ->and(BankResource::getNavigationGroup())->toBe('Financeiro')
        ->and(navigationChildLabels('Financeiro', 'Fundos'))->toBe([
            'Tipos de fundo',
            'Nomes de fundo',
            'Aplicações',
            'Bancos',
        ]);
});

it('groups the service providers under Despesas inside Financeiro', function () {
    $this->actingAs(makeNavigationAdminUser());

    expect(ExpenseResource::getNavigationGroup())->toBe('Financeiro')
        ->and(ExpenseResource::shouldRegisterNavigation())->toBeTrue()
        ->and(ExpenseServiceProviderResource::getNavigationParentItem())->toBe('Despesas')
        ->and(ExpenseServiceProviderTypeResource::shouldRegisterNavigation())->toBeFalse()
        ->and(navigationChildLabels('Financeiro', 'Despesas'))->toBe([
            'Prestadores de serviço',
        ]);
});

it('keeps the risk resources inside Governança & Risco', function () {
    $this->actingAs(makeNavigationAdminUser());

    expect(ReceivableResource::getNavigationGroup())->toBe('Governança & Risco')
        ->and(DocumentResource::getNavigationGroup())->toBe('Governança & Risco')
        ->and(navigationItemLabels('Governança & Risco'))->toBe([
            'Painel de Obrigações',
            'Documentos',
            'Recebíveis',
        ]);
});

it('consolidates the external portal into a single section', function () {
    $this->actingAs(makeNavigationAdminUser());

    expect(NimbusDashboard::getNavigationGroup())->toBe('Gestão Documental Externa')
        ->and(NotificationSettings::getNavigationGroup())->toBe('Gestão Documental Externa')
        ->and(navigationItemLabels('Gestão Documental Externa'))->toBe([
            'Visão Geral',
            'Gestão Documental',
            'Comunicação',
            'Acessos e Usuários',
        ])
        ->and(navigationChildLabels('Gestão Documental Externa', 'Visão Geral'))->toBe([
            'Envios e Solicitações',
        ])
        ->and(navigationChildLabels('Gestão Documental Externa', 'Gestão Documental'))->toBe([
            'Categorias de Documentos',
            'Biblioteca Geral',
            'Documentos por Usuário',
        ])
        ->and(navigationChildLabels('Gestão Documental Externa', 'Comunicação'))->toBe([
            'Avisos Gerais',
            'Auditoria de Envios',
            'Configurações de notificações',
        ])
        ->and(navigationChildLabels('Gestão Documental Externa', 'Acessos e Usuários'))->toBe([
            'Usuários do Portal',
            'Chaves de Acesso',
        ]);
});

it('groups the market reference data inside Dados de Mercado', function () {
    $this->actingAs(makeNavigationAdminUser());

    expect(navigationItemLabels('Dados de Mercado'))->toBe([
        'Índices (CDI/IPCA)',
        'Feriados (Calendário B3)',
    ])
        ->and(navigationChildLabels('Dados de Mercado', 'Índices (CDI/IPCA)'))->toBe([
            'Séries Projetadas IPCA',
        ]);
});

it('groups the public website resources inside Site Institucional', function () {
    $this->actingAs(makeNavigationAdminUser());

    expect(navigationItemLabels('Site Institucional'))->toBe([
        'Mensagens de Contato',
        'Vagas',
    ])
        ->and(navigationChildLabels('Site Institucional', 'Vagas'))->toBe([
            'Candidaturas',
        ]);
});

it('consolidates the audit trails and internal access inside Administração', function () {
    $this->actingAs(makeNavigationSuperAdminUser());

    expect(InvitationResource::getNavigationGroup())->toBe('Administração')
        ->and(InvitationResource::getNavigationParentItem())->toBeNull()
        ->and(navigationItemLabels('Administração'))->toBe([
            'Convites de Acesso',
            'Auditoria',
            'Configurações',
        ])
        ->and(navigationChildLabels('Administração', 'Auditoria'))->toBe([
            'Logs de Auditoria',
            'Auditoria de Lembretes',
            'Histórico de Downloads',
        ])
        ->and(navigationChildLabels('Administração', 'Configurações'))->toBe([
            'Usuários',
            'Perfis de acesso',
        ]);
});

it('points every parent navigation item at a destination the user may open', function () {
    $user = makeNavigationRestrictedUser(['nimbus.general-documents.view', 'reminder-logs.view']);
    $this->actingAs($user);

    expect(navigationItem('Gestão Documental Externa', 'Gestão Documental')?->getUrl())
        ->toBe(GeneralDocumentResource::getUrl(panel: 'admin'))
        ->and(navigationItem('Administração', 'Auditoria')?->getUrl())
        ->toBe(ReminderLogResource::getUrl(panel: 'admin'));
});

it('uses pt-BR labels and translations for admin resources', function () {
    app()->setLocale('pt_BR');

    expect(FundApplicationResource::getNavigationLabel())->toBe('Aplicações')
        ->and(FundApplicationResource::getModelLabel())->toBe('Aplicação')
        ->and(DocumentResource::getNavigationLabel())->toBe('Documentos')
        ->and(DocumentResource::getModelLabel())->toBe('Documento')
        ->and(ProposalRepresentativeResource::getNavigationLabel())->toBe('Representantes Comerciais')
        ->and(NimbusDashboard::getNavigationLabel())->toBe('Visão Geral')
        ->and(NotificationSettings::getNavigationLabel())->toBe('Configurações de notificações')
        ->and(__('Go to page :page', ['page' => 2]))->toBe('Ir para a página 2')
        ->and(trans('pagination.next'))->toBe('Próxima')
        ->and(trans('proposals.status.em_analise'))->toBe('Em Análise Técnica');
});

/**
 * Flatten every navigation entry the panel declares, regardless of the current user's visibility.
 *
 * Resources and pages register themselves on the navigation manager rather than on the panel,
 * so they are collected from their classes to keep this structural check independent of auth.
 *
 * @return Collection<int, array{label: ?string, group: mixed, parent: ?string, key: ?string}>
 */
function declaredNavigationEntries(): Collection
{
    $panel = Filament::getPanel('admin');

    $fromClasses = collect([...$panel->getPages(), ...$panel->getResources()])
        ->map(fn (string $class): array => [
            'label' => $class::getNavigationLabel(),
            'group' => $class::getNavigationGroup(),
            'parent' => $class::getNavigationParentItem(),
            'key' => $class,
        ]);

    $fromItems = collect($panel->getNavigationItems())
        ->map(fn (NavigationItem $item): array => [
            'label' => $item->getLabel(),
            'group' => $item->getGroup(),
            'parent' => $item->getParentItem(),
            'key' => $item->getKey(),
        ]);

    return $fromClasses->merge($fromItems)->values();
}

function navigationGroupItems(string $group): Collection
{
    $navigationGroup = collect(Filament::getPanel('admin')->getNavigation())
        ->first(fn (NavigationGroup $candidate): bool => $candidate->getLabel() === $group);

    return collect($navigationGroup?->getItems() ?? []);
}

function navigationItem(string $group, string $label): ?NavigationItem
{
    return navigationGroupItems($group)
        ->first(fn (NavigationItem $item): bool => $item->getLabel() === $label);
}

/**
 * @return array<int, string>
 */
function navigationItemLabels(string $group): array
{
    return navigationGroupItems($group)
        ->map(fn (NavigationItem $item): string => $item->getLabel())
        ->values()
        ->all();
}

function navigationChild(string $group, string $parent, string $label): ?NavigationItem
{
    return collect(navigationItem($group, $parent)?->getChildItems() ?? [])
        ->first(fn (NavigationItem $item): bool => $item->getLabel() === $label);
}

/**
 * @return array<int, string>
 */
function navigationChildLabels(string $group, string $parent): array
{
    return collect(navigationItem($group, $parent)?->getChildItems() ?? [])
        ->map(fn (NavigationItem $item): string => $item->getLabel())
        ->values()
        ->all();
}

function makeNavigationAdminUser(array $permissions = []): User
{
    $user = User::factory()->withTwoFactor()->create([
        'email' => fake()->unique()->safeEmail(),
    ]);
    $user->assignRole('admin');

    if ($permissions !== []) {
        $user->givePermissionTo($permissions);
    }

    return $user;
}

function makeNavigationSuperAdminUser(): User
{
    $user = User::factory()->withTwoFactor()->create([
        'email' => fake()->unique()->safeEmail(),
    ]);
    $user->assignRole('super-admin');
    $user->givePermissionTo(Permission::all());

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->fresh();
}

/**
 * @param  array<int, string>  $permissions
 */
function makeNavigationRestrictedUser(array $permissions): User
{
    $user = User::factory()->withTwoFactor()->create([
        'email' => fake()->unique()->safeEmail(),
    ]);
    $user->givePermissionTo($permissions);

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->fresh();
}
