<?php

namespace App\Filament\Resources\SalesBoardCycles\Pages;

use App\Filament\Resources\SalesBoardCycles\Actions\CheckSalesBoardCycleStaleAction;
use App\Filament\Resources\SalesBoardCycles\Actions\OpenBuilderReviewAction;
use App\Filament\Resources\SalesBoardCycles\Actions\OpenManagementReviewAction;
use App\Filament\Resources\SalesBoardCycles\Actions\RecalculateSalesBoardCycleAction;
use App\Filament\Resources\SalesBoardCycles\SalesBoardCycleResource;
use App\Models\SalesBoardCycle;
use Filament\Resources\Pages\ViewRecord;

class ViewSalesBoardCycle extends ViewRecord
{
    protected static string $resource = SalesBoardCycleResource::class;

    protected static ?string $title = 'Ciclo do Quadro de Vendas';

    protected static ?string $breadcrumb = 'Visualizar';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-fund-form-page bsi-sales-board-cycle-view-page',
    ];

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
            CheckSalesBoardCycleStaleAction::make(),
            RecalculateSalesBoardCycleAction::make(),
        ];
    }
}
