<?php

namespace App\Filament\Resources\ContaAzulImportedBills\Pages;

use App\Filament\Resources\ContaAzulImportBatches\ContaAzulImportBatchResource;
use App\Filament\Resources\ContaAzulImportedBills\ContaAzulImportedBillResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListContaAzulImportedBills extends ListRecords
{
    protected static string $resource = ContaAzulImportedBillResource::class;

    protected static ?string $title = 'Conciliação Conta Azul';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page',
    ];

    public function getSubheading(): ?string
    {
        return 'Títulos importados das contas financeiras, aguardando revisão e atribuição explícita a uma emissão.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('batches')
                ->label('Importações')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->url(fn (): string => ContaAzulImportBatchResource::getUrl('index')),
        ];
    }
}
