<?php

namespace App\Filament\Resources\Banks\Pages;

use App\Filament\Resources\Banks\BankResource;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;

class EditBank extends EditRecord
{
    protected static string $resource = BankResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    protected static ?string $breadcrumb = 'Editar';

    protected ?string $subheading = 'Atualize os dados cadastrais e o logotipo institucional da instituição bancária.';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-fund-form-page bsi-bank-form-page',
    ];

    protected ?bool $hasUnsavedDataChangesAlert = true;

    public function getTitle(): string
    {
        return 'Editar '.($this->record?->name ?: 'banco');
    }

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                DeleteAction::make()
                    ->label('Excluir banco')
                    ->modalHeading('Excluir instituição bancária')
                    ->modalDescription('Tem certeza que deseja excluir este banco? Esta ação não pode ser desfeita.')
                    ->modalSubmitActionLabel('Sim, excluir')
                    ->visible(fn (): bool => BankResource::canDelete($this->getRecord())),
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
        return 'Banco atualizado com sucesso.';
    }
}
