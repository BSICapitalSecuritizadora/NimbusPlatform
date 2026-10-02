<?php

namespace App\Filament\Resources\SalesBoardRollouts\Pages;

use App\Enums\SalesBoardSource;
use App\Filament\Resources\SalesBoardRollouts\SalesBoardRolloutResource;
use App\Support\SalesBoards\SalesBoardAutomationConfig;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListSalesBoardRollouts extends ListRecords
{
    protected static string $resource = SalesBoardRolloutResource::class;

    protected static ?string $title = 'Rollout do Quadro de Vendas';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-sales-board-rollout-page',
    ];

    /**
     * O interruptor global aparece no cabeçalho porque ele muda o significado da
     * lista inteira: com ele desligado, o agendador não gera competências de
     * Emissão nenhuma, e fingir que ele está rodando seria a pior informação
     * possível nesta tela. Fingir o contrário também: o fluxo humano continua,
     * e o freio de uma Emissão é o retorno ao legado, não o interruptor.
     */
    public function getSubheading(): ?string
    {
        return SalesBoardAutomationConfig::enabled()
            ? 'A automação global está ligada: Emissões automatizadas são processadas a cada hora.'
            : 'A automação global está desligada: o agendador não gera competências nem envia lembretes. O fluxo humano continua — '
                .'“Congelar competência” segue disponível para Emissões ativadas. Para parar uma Emissão, use “Retornar ao modo legado” na tela dela.';
    }

    public function getTabs(): array
    {
        return [
            'todas' => Tab::make('Todas'),

            'legado' => Tab::make('Modo legado')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('sales_board_source', SalesBoardSource::Legacy)),

            'automatizadas' => Tab::make('Automatizadas')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('sales_board_source', SalesBoardSource::Automated))
                ->badge(fn (): int => static::getResource()::getEloquentQuery()
                    ->where('sales_board_source', SalesBoardSource::Automated)
                    ->count()),
        ];
    }
}
