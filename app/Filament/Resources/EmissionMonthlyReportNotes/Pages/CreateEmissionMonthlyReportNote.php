<?php

namespace App\Filament\Resources\EmissionMonthlyReportNotes\Pages;

use App\Filament\Resources\EmissionMonthlyReportNotes\EmissionMonthlyReportNoteResource;
use Filament\Resources\Pages\CreateRecord;

class CreateEmissionMonthlyReportNote extends CreateRecord
{
    protected static string $resource = EmissionMonthlyReportNoteResource::class;

    protected static ?string $title = 'Cadastrar Comentário do Relatório';

    protected static ?string $breadcrumb = 'Cadastrar';

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Comentário cadastrado com sucesso.';
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();
        $data['updated_by'] = auth()->id();

        return $data;
    }
}
