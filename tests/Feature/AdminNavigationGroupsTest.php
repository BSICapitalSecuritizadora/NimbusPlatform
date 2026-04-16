<?php

use App\Filament\Resources\Emissions\EmissionResource;
use App\Filament\Resources\Payments\PaymentResource;
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

    expect(PaymentResource::getNavigationGroup())->toBe('Gestão')
        ->and(PaymentResource::getNavigationParentItem())->toBe('Despesas')
        ->and(PaymentResource::getNavigationLabel())->toBe('Pagamentos')
        ->and($expenseItem)->not->toBeNull()
        ->and($expenseItem->getUrl())->toBe(PaymentResource::getUrl(panel: 'admin'));
});
