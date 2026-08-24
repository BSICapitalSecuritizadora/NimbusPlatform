<?php

namespace App\Filament\Resources\Constructions\Pages;

use App\Filament\Resources\Constructions\ConstructionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListConstructions extends ListRecords
{
    protected static string $resource = ConstructionResource::class;

    protected static ?string $title = 'Obras';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-constructions-list-page',
    ];

    public function getSubheading(): ?string
    {
        return 'Acompanhamento cadastral e financeiro das obras vinculadas às emissões.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Nova Obra')
                ->icon('heroicon-m-plus')
                ->color('primary'),
        ];
    }
}
