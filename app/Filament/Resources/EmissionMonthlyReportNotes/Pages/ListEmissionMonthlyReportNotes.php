<?php

namespace App\Filament\Resources\EmissionMonthlyReportNotes\Pages;

use App\Filament\Resources\EmissionMonthlyReportNotes\EmissionMonthlyReportNoteResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEmissionMonthlyReportNotes extends ListRecords
{
    protected static string $resource = EmissionMonthlyReportNoteResource::class;

    protected static ?string $title = 'Notas Explicativas';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-report-notes-list-page',
    ];

    public function getSubheading(): ?string
    {
        return 'Gestão das observações complementares utilizadas na composição dos relatórios das emissões.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Nova Nota Explicativa')
                ->icon('heroicon-m-plus')
                ->color('primary'),
        ];
    }
}
