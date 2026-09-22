<?php

namespace App\Filament\Resources\SalesBoardCycles\Pages;

use App\Enums\SalesBoardCycleStatus;
use App\Filament\Resources\SalesBoardCycles\Actions\CheckSalesBoardCycleStaleAction;
use App\Filament\Resources\SalesBoardCycles\Actions\OpenBuilderReviewAction;
use App\Filament\Resources\SalesBoardCycles\Actions\OpenManagementReviewAction;
use App\Filament\Resources\SalesBoardCycles\Actions\RecalculateSalesBoardCycleAction;
use App\Filament\Resources\SalesBoardCycles\SalesBoardCycleResource;
use App\Filament\Resources\SalesBoards\SalesBoardResource;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardPublication;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\View\View;

class ViewSalesBoardCycle extends ViewRecord
{
    protected static string $resource = SalesBoardCycleResource::class;

    protected static ?string $title = 'Ciclo do Quadro de Vendas';

    protected static ?string $breadcrumb = 'Visualizar';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-sales-board-cycle-view-page',
    ];

    public function getHeader(): ?View
    {
        return view('filament.resources.sales-board-cycles.pages.cycle-header', [
            'record' => $this->getRecord(),
        ]);
    }

    public function getSubheading(): ?string
    {
        /** @var SalesBoardCycle $record */
        $record = $this->getRecord();

        return sprintf(
            'Posição de %s em %s, apurada unidade a unidade e congelada na versão %s.',
            (string) $record->construction?->development_name,
            $record->position_date?->format('d/m/Y') ?? '—',
            $record->currentBaseline?->versionLabel() ?? '—',
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            OpenBuilderReviewAction::make(),
            OpenManagementReviewAction::make(),
            $this->viewPublishedBoardAction(),
            $this->viewManagementReviewAction(),
            CheckSalesBoardCycleStaleAction::make()->outlined(),
            RecalculateSalesBoardCycleAction::make()->color('gray')->link(),
        ];
    }

    /**
     * Depois de publicada, a competência continua sendo consultada: o quadro que
     * ela produziu e a análise que a aprovou são o histórico, e sem estes links
     * o único caminho até eles seria saber a URL.
     */
    protected function viewPublishedBoardAction(): Action
    {
        return Action::make('viewPublishedBoard')
            ->label('Ver Quadro de Vendas publicado')
            ->icon('heroicon-o-rectangle-stack')
            ->color('gray')
            ->visible(fn (SalesBoardCycle $record): bool => ($record->status === SalesBoardCycleStatus::Approved)
                && ($this->publishedSalesBoardId($record) !== null))
            ->url(fn (SalesBoardCycle $record): ?string => ($salesBoardId = $this->publishedSalesBoardId($record)) === null
                ? null
                : SalesBoardResource::getUrl('view', ['record' => $salesBoardId]));
    }

    protected function viewManagementReviewAction(): Action
    {
        return Action::make('viewManagementReview')
            ->label('Ver análise da Gestão')
            ->icon('heroicon-o-scale')
            ->color('gray')
            ->visible(fn (SalesBoardCycle $record): bool => $record->status === SalesBoardCycleStatus::Approved)
            ->url(fn (SalesBoardCycle $record): string => ManagementReviewWorkspace::getUrl(['record' => $record]));
    }

    private function publishedSalesBoardId(SalesBoardCycle $cycle): ?int
    {
        $salesBoardId = SalesBoardPublication::query()
            ->where('sales_board_cycle_id', $cycle->getKey())
            ->value('sales_board_id');

        return $salesBoardId === null ? null : (int) $salesBoardId;
    }
}
