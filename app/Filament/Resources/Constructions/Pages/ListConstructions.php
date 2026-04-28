<?php

namespace App\Filament\Resources\Constructions\Pages;

use App\Filament\Resources\Constructions\ConstructionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListConstructions extends ListRecords
{
    protected static string $resource = ConstructionResource::class;

    protected static ?string $title = 'Obras';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Cadastrar obra'),
        ];
    }
}
