<?php

namespace App\Filament\Resources\EmissionMonthlyReportNotes\Pages;

use App\Filament\Resources\EmissionMonthlyReportNotes\EmissionMonthlyReportNoteResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditEmissionMonthlyReportNote extends EditRecord
{
    protected static string $resource = EmissionMonthlyReportNoteResource::class;

    protected static ?string $title = 'Editar Comentário do Relatório';

    protected static ?string $breadcrumb = 'Editar';

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Comentário atualizado com sucesso.';
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['updated_by'] = auth()->id();

        return $data;
    }
}
