<?php

namespace App\Filament\Resources\EmissionMonthlyReportNotes\Pages;

use App\Filament\Resources\EmissionMonthlyReportNotes\EmissionMonthlyReportNoteResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditEmissionMonthlyReportNote extends EditRecord
{
    protected static string $resource = EmissionMonthlyReportNoteResource::class;

    protected static ?string $title = 'Editar Nota Explicativa';

    protected static ?string $breadcrumb = 'Editar';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page',
    ];

    public function getSubheading(): ?string
    {
        return 'Atualize a observação complementar vinculada à emissão e à competência do relatório.';
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Nota explicativa atualizada com sucesso.';
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
