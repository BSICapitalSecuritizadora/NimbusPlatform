<?php

namespace App\Filament\Resources\Measurements\Pages;

use App\Filament\Resources\Measurements\MeasurementResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMeasurements extends ListRecords
{
    protected static string $resource = MeasurementResource::class;

    protected static ?string $title = 'Medições';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-measurements-list-page',
    ];

    public function getSubheading(): ?string
    {
        return 'Acompanhamento das medições enviadas para as operações e empreendimentos vinculados às obras.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Enviar Medição')
                ->icon('heroicon-m-plus')
                ->color('primary'),
        ];
    }
}
