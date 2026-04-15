<?php

use App\Filament\Resources\Emissions\EmissionResource;
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
