<?php

namespace App\Filament\Resources\SalesBoards\Pages;

use App\Filament\Resources\SalesBoards\SalesBoardResource;
use App\Models\SalesBoard;
use App\Services\SalesBoards\SalesBoardWriteGuard;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

/**
 * A ficha do quadro.
 *
 * "Nova Atualização" continua à vista num quadro publicado pelo ciclo mensal,
 * desabilitada e dizendo por quê: o quadro passou pela governança e não recebe
 * versão manual. Esconder o botão deixaria a pessoa procurando o caminho; a
 * gravação recusaria de qualquer forma, pelo guard de escrita.
 */
class ViewSalesBoard extends ViewRecord
{
    public const PUBLISHED_BOARD_TOOLTIP = 'Este quadro foi publicado pelo fluxo de governança: não recebe nova versão manual.';

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
                ->disabled(fn (SalesBoard $record): bool => app(SalesBoardWriteGuard::class)->isPublished($record))
                ->tooltip(fn (SalesBoard $record): string => app(SalesBoardWriteGuard::class)->isPublished($record)
                    ? self::PUBLISHED_BOARD_TOOLTIP
                    : 'Registra uma nova posição a partir da atual, preservando o histórico.')
                ->visible(fn (SalesBoard $record): bool => SalesBoardResource::canEdit($record))
                ->url(fn (SalesBoard $record): string => SalesBoardResource::getUrl('create', [
                    'from' => $record->getKey(),
                ])),
        ];
    }
}
