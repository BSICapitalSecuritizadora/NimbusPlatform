<?php

namespace App\Filament\Resources\SalesBoardCycles\Pages;

use App\Filament\Resources\SalesBoardCycles\Actions\GenerateSalesBoardCycleAction;
use App\Filament\Resources\SalesBoardCycles\SalesBoardCycleResource;
use Filament\Resources\Pages\ListRecords;

class ListSalesBoardCycles extends ListRecords
{
    protected static string $resource = SalesBoardCycleResource::class;

    protected static ?string $title = 'Ciclos do Quadro de Vendas';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-sales-board-cycles-list-page',
    ];

    public function getSubheading(): ?string
    {
        return 'O que o Nimbus apurou em cada competência, congelado e versionado. A fonte pode mudar; estas posições não.';
    }

    protected function getHeaderActions(): array
    {
        return [
            GenerateSalesBoardCycleAction::make(),
        ];
    }
}
