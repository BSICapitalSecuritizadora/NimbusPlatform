<?php

namespace App\Filament\Resources\Operations\Pages;

use App\Filament\Resources\Operations\OperationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListOperations extends ListRecords
{
    protected static string $resource = OperationResource::class;

    protected static ?string $title = 'Operações de Obra';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-operations-list-page',
    ];

    public function getSubheading(): ?string
    {
        return 'Acompanhamento das operações, valores e próximas medições vinculadas às obras.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Criar Operação de Obra')
                ->icon('heroicon-m-plus')
                ->color('primary'),
        ];
    }
}
