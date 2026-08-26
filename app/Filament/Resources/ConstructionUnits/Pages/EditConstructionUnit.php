<?php

namespace App\Filament\Resources\ConstructionUnits\Pages;

use App\Filament\Resources\ConstructionUnits\ConstructionUnitResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditConstructionUnit extends EditRecord
{
    protected static string $resource = ConstructionUnitResource::class;

    protected static ?string $title = 'Editar Unidade';

    protected static ?string $breadcrumb = 'Editar';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page',
    ];

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()->label('Visualizar'),
            DeleteAction::make()
                ->label('Excluir')
                ->modalHeading('Excluir unidade'),
        ];
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Unidade atualizada com sucesso.';
    }
}
