<?php

namespace App\Filament\Resources\FundApplications\Pages;

use App\Filament\Resources\FundApplications\FundApplicationResource;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;

class EditFundApplication extends EditRecord
{
    protected static string $resource = FundApplicationResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    protected static ?string $breadcrumb = 'Editar';

    protected ?string $subheading = 'Atualize a identificação utilizada nos fundos vinculados.';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-fund-form-page bsi-fund-application-form-page',
    ];

    protected ?bool $hasUnsavedDataChangesAlert = true;

    public function getTitle(): string
    {
        return 'Editar '.($this->record?->name ?: 'aplicação');
    }

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                DeleteAction::make()
                    ->label('Excluir aplicação')
                    ->modalHeading('Excluir aplicação')
                    ->modalDescription('Tem certeza que deseja excluir esta aplicação? Esta ação não pode ser desfeita.')
                    ->modalSubmitActionLabel('Sim, excluir')
                    ->visible(fn (): bool => FundApplicationResource::canDelete($this->getRecord())),
            ])
                ->icon('heroicon-m-ellipsis-vertical')
                ->color('gray')
                ->tooltip('Mais opções')
                ->dropdownWidth(Width::ExtraSmall),
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

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Aplicação atualizada com sucesso.';
    }
}
