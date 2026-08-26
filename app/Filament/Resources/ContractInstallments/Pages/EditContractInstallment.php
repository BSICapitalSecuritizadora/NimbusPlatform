<?php

namespace App\Filament\Resources\ContractInstallments\Pages;

use App\Filament\Resources\ContractInstallments\ContractInstallmentResource;
use App\Models\ContractInstallment;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditContractInstallment extends EditRecord
{
    protected static string $resource = ContractInstallmentResource::class;

    protected static ?string $breadcrumb = 'Editar';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page',
    ];

    public function getTitle(): string
    {
        return 'Parcela '.$this->getRecord()->number;
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->label('Excluir')
                ->modalHeading('Excluir parcela')
                ->modalDescription('Use a exclusão apenas para um registro criado por engano. Para tirar uma parcela do fluxo contratual preservando o histórico, informe a data de cancelamento.')
                ->visible(fn (ContractInstallment $record): bool => ContractInstallmentResource::canDelete($record)),

            RestoreAction::make()
                ->label('Restaurar')
                ->visible(fn (ContractInstallment $record): bool => ContractInstallmentResource::canRestore($record)),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
