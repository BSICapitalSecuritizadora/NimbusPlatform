<?php

namespace App\Filament\Resources\ConstructionUnits\Pages;

use App\Filament\Resources\ConstructionUnits\ConstructionUnitResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewConstructionUnit extends ViewRecord
{
    protected static string $resource = ConstructionUnitResource::class;

    protected static ?string $title = 'Visualizar Unidade';

    protected static ?string $breadcrumb = 'Visualizar';

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->label('Editar'),
        ];
    }
}
