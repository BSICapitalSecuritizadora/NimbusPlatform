<?php

namespace App\Filament\Resources\ContractInstallments\Pages;

use App\Concerns\ImportsContractInstallments;
use App\Filament\Resources\ContractInstallments\ContractInstallmentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListContractInstallments extends ListRecords
{
    use ImportsContractInstallments;

    protected static string $resource = ContractInstallmentResource::class;

    protected static ?string $title = 'Parcelas dos Contratos';

    protected function getHeaderActions(): array
    {
        return [
            $this->installmentTemplateAction(),

            $this->installmentImportAction()
                ->visible(fn (): bool => ContractInstallmentResource::canCreate()),

            CreateAction::make()
                ->label('Nova Parcela')
                ->icon('heroicon-o-plus'),
        ];
    }
}
