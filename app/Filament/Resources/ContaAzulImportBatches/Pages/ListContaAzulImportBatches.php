<?php

namespace App\Filament\Resources\ContaAzulImportBatches\Pages;

use App\Filament\Resources\ContaAzulImportBatches\ContaAzulImportBatchResource;
use App\Filament\Resources\ContaAzulImportedBills\ContaAzulImportedBillResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListContaAzulImportBatches extends ListRecords
{
    protected static string $resource = ContaAzulImportBatchResource::class;

    protected static ?string $title = 'Importações Conta Azul';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page',
    ];

    public function getSubheading(): ?string
    {
        return 'Tentativas de importação por conta financeira, com resultado, contadores e recuperação de interrupções.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reconciliation')
                ->label('Conciliação')
                ->icon(Heroicon::OutlinedScale)
                ->color('gray')
                ->url(fn (): string => ContaAzulImportedBillResource::getUrl('index')),
        ];
    }
}
