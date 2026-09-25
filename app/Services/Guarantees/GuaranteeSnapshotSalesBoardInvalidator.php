<?php

namespace App\Services\Guarantees;

use App\Models\GuaranteeSnapshot;
use App\Models\SalesBoard;

/**
 * Marca como desatualizadas as competências de garantias que um Quadro de
 * Vendas publicado, registrado, alterado ou excluído depois da apuração mudaria.
 *
 * O quadro da competência M só existe no mês seguinte; quem apurou M antes
 * disso gravou a última posição conhecida. Sem esta marca, o número ficava
 * valendo — inclusive fechado — sem nada indicar que o quadro do mês chegou.
 *
 * Não recalcula nem reabre nada: o snapshot é histórico e só muda por ação
 * humana (atualizar a competência aberta ou reabrir a fechada). A marca é o
 * aviso de que essa ação é necessária.
 *
 * Roda dentro da escrita do quadro (observer), na mesma transação: se a
 * publicação desfizer, a marca desfaz junto.
 */
class GuaranteeSnapshotSalesBoardInvalidator
{
    /**
     * Considera a posição atual do quadro e, numa alteração, também a anterior:
     * mudar a competência ou o empreendimento de um quadro mexe nos dois lados.
     *
     * @return int quantidade de competências marcadas
     */
    public function salesBoardChanged(SalesBoard $salesBoard, bool $includeOriginal = false): int
    {
        $marked = $this->markAffectedBy(
            emissionId: $salesBoard->emission_id,
            constructionId: $salesBoard->construction_id,
            referenceMonth: $salesBoard->reference_month,
        );

        if (! $includeOriginal) {
            return $marked;
        }

        return $marked + $this->markAffectedBy(
            emissionId: $salesBoard->getOriginal('emission_id'),
            constructionId: $salesBoard->getOriginal('construction_id'),
            referenceMonth: $salesBoard->getOriginal('reference_month'),
        );
    }

    /**
     * @return int quantidade de competências marcadas
     */
    public function markAffectedBy(mixed $emissionId, mixed $constructionId, mixed $referenceMonth): int
    {
        $referenceMonth = GuaranteeSnapshot::normalizeReferenceMonth($referenceMonth);

        if (blank($emissionId) || blank($constructionId) || $referenceMonth === null) {
            return 0;
        }

        $snapshots = GuaranteeSnapshot::query()
            ->where('emission_id', (int) $emissionId)
            ->whereDate('reference_month', '>=', $referenceMonth)
            ->whereNotNull('sales_board_coverage')
            ->whereNull('sales_board_outdated_at')
            ->get();

        $marked = 0;

        foreach ($snapshots as $snapshot) {
            if (! $snapshot->salesBoardCoverage()?->dependsOn((int) $constructionId, $referenceMonth)) {
                continue;
            }

            $snapshot->forceFill(['sales_board_outdated_at' => now()])->save();

            $marked++;
        }

        return $marked;
    }
}
