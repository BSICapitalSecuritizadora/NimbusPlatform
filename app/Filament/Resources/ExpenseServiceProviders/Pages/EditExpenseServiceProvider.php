<?php

namespace App\Filament\Resources\ExpenseServiceProviders\Pages;

use App\Filament\Resources\ExpenseServiceProviders\ExpenseServiceProviderResource;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;

class EditExpenseServiceProvider extends EditRecord
{
    protected static string $resource = ExpenseServiceProviderResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    protected static ?string $title = 'Editar prestador de serviço';

    protected static ?string $breadcrumb = 'Editar';

    protected ?string $subheading = 'Atualize os dados cadastrais e a classificação do prestador de serviço.';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-fund-form-page bsi-service-provider-form-page',
    ];

    protected ?bool $hasUnsavedDataChangesAlert = true;

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                DeleteAction::make()
                    ->label('Excluir prestador')
                    ->modalHeading('Excluir prestador de serviço')
                    ->modalDescription('Tem certeza que deseja excluir este prestador de serviço? Esta ação não pode ser desfeita.')
                    ->modalSubmitActionLabel('Sim, excluir')
                    ->visible(fn (): bool => ExpenseServiceProviderResource::canDelete($this->getRecord())),
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
            ->label('Salvar alterações');
    }

    protected function getCancelFormAction(): Action
    {
        return parent::getCancelFormAction()
            ->label('Cancelar')
            ->color('gray');
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Prestador de serviço atualizado com sucesso.';
    }
}
