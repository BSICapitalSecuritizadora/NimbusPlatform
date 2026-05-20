<?php

namespace App\Filament\Resources\Recruitment\Pages;

use App\Filament\Resources\Recruitment\VacancyResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditVacancy extends EditRecord
{
    protected static string $resource = VacancyResource::class;

    protected static ?string $title = 'Editar Vaga';

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->label('Excluir Vaga')
                ->modalHeading('Excluir Vaga'),
        ];
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Vaga atualizada com sucesso.';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
