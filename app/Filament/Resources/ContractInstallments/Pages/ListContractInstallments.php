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

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-contract-installments-list-page',
    ];

    public function getSubheading(): ?string
    {
        return 'Acompanhe os vencimentos, valores previstos e liquidações das parcelas dos contratos.';
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->installmentTemplateAction()
                ->color('gray'),

            $this->installmentImportAction()
                ->color('gray')
                ->visible(fn (): bool => ContractInstallmentResource::canCreate()),

            CreateAction::make()
                ->label('Nova Parcela')
                ->icon('heroicon-o-plus')
                ->color('primary'),
        ];
    }
}
