<?php

namespace App\Filament\Resources\Activities\Pages;

use App\Filament\Exports\ActivityExporter;
use App\Filament\Resources\Activities\ActivityResource;
use Filament\Actions\ExportAction;
use Filament\Resources\Pages\ManageRecords;

class ManageActivities extends ManageRecords
{
    protected static string $resource = ActivityResource::class;

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-activities-list-page',
    ];

    public function getTitle(): string
    {
        return 'Registros de Auditoria';
    }

    public function getSubheading(): ?string
    {
        return 'Acompanhe ações executadas no sistema, responsáveis e entidades afetadas.';
    }

    protected function getHeaderActions(): array
    {
        return [
            ExportAction::make()
                ->label('Exportar registros')
                ->icon('heroicon-m-arrow-down-tray')
                ->color('gray')
                ->exporter(ActivityExporter::class),
        ];
    }
}
