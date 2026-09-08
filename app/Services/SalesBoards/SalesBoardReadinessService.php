<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\DTOs\SalesBoards\SalesBoardDerivedPosition;
use App\DTOs\SalesBoards\SalesBoardLegacyComparison;
use App\DTOs\SalesBoards\SalesBoardReadinessReport;
use App\Models\Construction;
use App\Support\Money\IntegerMoney;
use Carbon\CarbonInterface;

/**
 * Se a competência de um empreendimento está pronta para ser automatizada.
 *
 * Não decide por opinião: a prontidão é a ausência de bloqueadores na derivação,
 * e cada bloqueador aponta o dado que falta. Um empreendimento com venda fora da
 * política continua pronto -- a irregularidade foi apurada, não é lacuna.
 *
 * Também traz o comparativo contra o quadro publicado, que é material de
 * homologação: divergir do número digitado não reprova a derivação, mas ninguém
 * deve ligar a automação sem ter olhado a diferença.
 */
class SalesBoardReadinessService
{
    public function __construct(
        private readonly SalesBoardDerivationService $derivationService,
        private readonly SalesBoardPositionReader $positionReader,
    ) {}

    public function forConstruction(Construction $construction, CarbonInterface $referenceMonth): SalesBoardReadinessReport
    {
        $position = $this->derivationService->deriveForConstruction($construction, $referenceMonth);

        return $this->fromPosition($construction, $position);
    }

    /**
     * Monta o relatório a partir de uma posição já derivada, para quem acabou de
     * derivá-la e não precisa pagar a conta duas vezes.
     */
    public function fromPosition(Construction $construction, SalesBoardDerivedPosition $position): SalesBoardReadinessReport
    {
        return new SalesBoardReadinessReport(
            constructionId: (int) $construction->getKey(),
            constructionName: $construction->development_name,
            referenceMonth: $position->referenceMonth,
            positionDate: $position->positionDate,
            blockingIssues: $position->blockingIssues(),
            warnings: $position->warnings(),
            metrics: $this->metrics($position),
            legacyComparison: $this->compareWithLegacy($construction, $position),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function metrics(SalesBoardDerivedPosition $position): array
    {
        return [
            'units_total' => $position->unitsTotal,
            'stock_units' => $position->stockUnits,
            'financed_units' => $position->financedUnits,
            'settled_units' => $position->settledUnits,
            'exchanged_units' => $position->exchangedUnits,
            'undetermined_units' => $position->undeterminedUnits,
            'buckets_balance' => $position->bucketsBalance(),
            'is_complete' => $position->isComplete(),
            'sales' => $position->movements->salesCount(),
            'sales_conform' => $position->movements->conformSalesCount(),
            'sales_non_conform' => $position->movements->nonConformSalesCount(),
            'sales_undetermined' => $position->movements->undeterminedSalesCount(),
            'settlements' => $position->movements->settlementsCount(),
            'cancellations' => $position->movements->cancellationsCount(),
        ];
    }

    /**
     * Derivado contra publicado.
     *
     * A posição publicada vem do {@see SalesBoardPositionReader} da Fase 0, com
     * o carry-forward que ele já resolve -- reimplementar a leitura aqui criaria
     * a quarta semântica que aquela fase existiu para eliminar.
     */
    private function compareWithLegacy(Construction $construction, SalesBoardDerivedPosition $position): SalesBoardLegacyComparison
    {
        $legacy = $this->positionReader->forConstruction($construction, $position->referenceMonth);

        if (! $legacy->isResolved() || ($legacy->salesBoard === null)) {
            return SalesBoardLegacyComparison::noLegacyPosition();
        }

        /**
         * Os valores saem do `SalesBoard` de origem, e não dos `float` do DTO:
         * a coluna é `decimal:2` e chega como string exata, então a comparação
         * não passa por ponto flutuante em momento nenhum.
         */
        $board = $legacy->salesBoard;

        return SalesBoardLegacyComparison::fromDifferences(
            [
                'stock_units' => ['derived' => $position->stockUnits, 'legacy' => $legacy->stockUnits],
                'financed_units' => ['derived' => $position->financedUnits, 'legacy' => $legacy->financedUnits],
                'paid_units' => ['derived' => $position->settledUnits, 'legacy' => $legacy->paidUnits],
                'exchanged_units' => ['derived' => $position->exchangedUnits, 'legacy' => $legacy->exchangedUnits],
                'stock_value' => ['derived' => $position->stockValueCents, 'legacy' => IntegerMoney::cents($board->stock_value)],
                'financed_value' => ['derived' => $position->financedValueCents, 'legacy' => IntegerMoney::cents($board->financed_value)],
                'paid_value' => ['derived' => $position->settledValueCents, 'legacy' => IntegerMoney::cents($board->paid_value)],
                'exchanged_value' => ['derived' => $position->exchangedValueCents, 'legacy' => IntegerMoney::cents($board->exchanged_value)],
            ],
            $legacy->referenceMonthUsed?->format('m/Y'),
        );
    }
}
