<?php

namespace App\Filament\Resources\SalesBoards\Pages;

use App\Filament\Resources\SalesBoards\SalesBoardResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSalesBoards extends ListRecords
{
    protected static string $resource = SalesBoardResource::class;

    protected static ?string $title = 'Quadro de Vendas';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-sales-boards-list-page',
    ];

    public function getSubheading(): ?string
    {
        return 'Acompanhamento consolidado de estoque, vendas, quitações e permutas por empreendimento.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Novo Quadro de Vendas')
                ->icon('heroicon-m-plus')
                ->color('primary'),
        ];
    }
}
