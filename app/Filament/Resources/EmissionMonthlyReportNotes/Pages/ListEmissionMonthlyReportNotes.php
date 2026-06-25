<?php

namespace App\Filament\Resources\EmissionMonthlyReportNotes\Pages;

use App\Filament\Resources\EmissionMonthlyReportNotes\EmissionMonthlyReportNoteResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEmissionMonthlyReportNotes extends ListRecords
{
    protected static string $resource = EmissionMonthlyReportNoteResource::class;

    protected static ?string $title = 'Comentários do Relatório';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Cadastrar Comentário')
                ->icon('heroicon-o-plus-circle'),
        ];
    }
}
