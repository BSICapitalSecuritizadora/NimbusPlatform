<?php

use App\Filament\Resources\Emissions\EmissionResource;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\ExpenseServiceProviders\ExpenseServiceProviderResource;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;

it('registers the Gestão section between Cadastro and Gestão de Acesso', function () {
    $navigationGroups = collect(Filament::getPanel('admin')->getNavigationGroups())
        ->map(fn (NavigationGroup|string $group): string => $group instanceof NavigationGroup ? $group->getLabel() ?? '' : $group)
        ->values()
        ->all();

    expect(EmissionResource::getNavigationGroup())->toBe('Gestão')
        ->and($navigationGroups)->toBe([
            'NimbusDocs',
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
    $expenseItem = collect(Filament::getPanel('admin')->getNavigationItems())
        ->first(fn ($item) => $item->getLabel() === 'Despesas');

    expect(ExpenseResource::getNavigationGroup())->toBe('Gestão')
        ->and(ExpenseResource::shouldRegisterNavigation())->toBeFalse()
        ->and(ExpenseServiceProviderResource::getNavigationGroup())->toBe('Gestão')
        ->and(ExpenseServiceProviderResource::getNavigationParentItem())->toBe('Despesas')
        ->and(ExpenseServiceProviderResource::getNavigationLabel())->toBe('Prestadores de serviço')
        ->and($expenseItem)->not->toBeNull()
        ->and($expenseItem->getUrl())->toBe(ExpenseResource::getUrl(panel: 'admin'));
});
