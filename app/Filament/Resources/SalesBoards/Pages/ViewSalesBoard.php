<?php

namespace App\Filament\Resources\SalesBoards\Pages;

use App\Filament\Resources\SalesBoards\SalesBoardResource;
use App\Models\SalesBoard;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewSalesBoard extends ViewRecord
{
    protected static string $resource = SalesBoardResource::class;

    protected static ?string $title = 'Visualizar Quadro de Vendas';

    protected static ?string $breadcrumb = 'Visualizar';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-fund-form-page bsi-sales-board-view-page bsi-sales-board-form-page',
    ];

    public function getSubheading(): ?string
    {
        return 'Acompanhe a posição inicial, a posição mais recente e o histórico de atualizações do empreendimento.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('newUpdate')
                ->label('Nova Atualização')
                ->icon('heroicon-m-plus')
                ->color('primary')
                ->tooltip('Registra uma nova posição a partir da atual, preservando o histórico.')
                ->visible(fn (SalesBoard $record): bool => SalesBoardResource::canEdit($record))
                ->url(fn (SalesBoard $record): string => SalesBoardResource::getUrl('create', [
                    'from' => $record->getKey(),
                ])),
        ];
    }
}
