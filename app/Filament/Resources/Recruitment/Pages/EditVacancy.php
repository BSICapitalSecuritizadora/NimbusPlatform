<?php

namespace App\Filament\Resources\Recruitment\Pages;

use App\Enums\VacancyStatus;
use App\Filament\Resources\Recruitment\VacancyResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;

class EditVacancy extends EditRecord
{
    protected static string $resource = VacancyResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    protected static ?string $title = 'Editar vaga';

    protected static ?string $breadcrumb = 'Editar';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-vacancy-form-page',
    ];

    public function getSubheading(): ?string
    {
        return 'Atualize os dados da oportunidade, publicação, remuneração e conteúdo exibido aos candidatos.';
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make()
                ->label('Excluir Vaga')
                ->modalHeading('Excluir Vaga'),
        ];
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()
            ->label('Salvar alterações')
            ->color('primary');
    }

    protected function getCancelFormAction(): Action
    {
        return parent::getCancelFormAction()
            ->label('Cancelar')
            ->color('gray');
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $originalStatus = $this->record->status instanceof VacancyStatus ? $this->record->status->value : (string) $this->record->status;
        $newStatus = $data['status'] ?? $originalStatus;

        if ($newStatus === VacancyStatus::Published->value && $originalStatus !== VacancyStatus::Published->value && blank($data['published_at'] ?? null)) {
            $data['published_at'] = now();
        }

        if ($newStatus === VacancyStatus::Closed->value && blank($data['closed_at'] ?? null)) {
            $data['closed_at'] = now();
        }

        if (in_array($newStatus, [VacancyStatus::Published->value, VacancyStatus::Paused->value], true)) {
            // keep closed_at null when reopening/pausing
            if ($newStatus !== VacancyStatus::Closed->value) {
                // do not clear if already closed and reopening — handled above
            }
        }

        return $data;
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Vaga atualizada com sucesso.';
    }

    protected function getRedirectUrl(): string
    {
        try {
            $record = $this->record;
        } catch (\Error) {
            return $this->getResource()::getUrl('index');
        }

        if (! $record || ! $record->exists) {
            return $this->getResource()::getUrl('index');
        }

        return $this->getResource()::getUrl('view', ['record' => $record]);
    }
}
