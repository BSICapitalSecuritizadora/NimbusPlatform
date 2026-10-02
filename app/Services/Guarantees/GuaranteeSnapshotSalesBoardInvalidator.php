<?php

namespace App\Services\Guarantees;

use App\DTOs\Guarantees\GuaranteeSalesBoardCoverage;
use App\Models\Emission;
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
 * aviso de que essa ação é necessária, e cada marca deixa o evento
 * {@see GuaranteeSnapshotWriter::EVENT_COMPETENCE_OUTDATED} na trilha protegida,
 * com o quadro que a causou.
 *
 * Roda dentro da escrita do quadro (observer), na mesma transação: se a
 * publicação desfizer, a marca desfaz junto.
 *
 * Antes de procurar snapshots, trava a emissão em modo compartilhado — a mesma
 * trava que a FK do quadro na emissão já pega ao criá-lo — e lê os snapshots
 * com `FOR UPDATE`, que enxerga o que já foi commitado e não a fotografia do
 * início da transação, na ordem da competência. O {@see GuaranteeSnapshotWriter}
 * trava a emissão em modo exclusivo antes de apurar. Assim, uma gravação de
 * competência em andamento (mesmo sem snapshot prévio, quando não haveria linha
 * a travar) termina antes de o invalidador procurar, e o snapshot que ela criar
 * é encontrado e marcado. Dois quadros publicados ao mesmo tempo não disputam a
 * emissão: o modo compartilhado é compatível entre eles. O
 * {@see GuaranteeSnapshotOutstandingBalanceInvalidator} trava os snapshots na
 * mesma ordem, e os dois nunca se esperam em sentidos opostos.
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
        $salesBoardId = $salesBoard->getKey() === null ? null : (int) $salesBoard->getKey();

        $marked = $this->markAffectedBy(
            emissionId: $salesBoard->emission_id,
            constructionId: $salesBoard->construction_id,
            referenceMonth: $salesBoard->reference_month,
            salesBoardId: $salesBoardId,
        );

        if (! $includeOriginal) {
            return $marked;
        }

        return $marked + $this->markAffectedBy(
            emissionId: $salesBoard->getOriginal('emission_id'),
            constructionId: $salesBoard->getOriginal('construction_id'),
            referenceMonth: $salesBoard->getOriginal('reference_month'),
            salesBoardId: $salesBoardId,
        );
    }

    /**
     * A competência é marcada quando o quadro mudaria o número dela.
     *
     * Com a origem gravada, quem responde é
     * {@see GuaranteeSalesBoardCoverage::dependsOn()}. Sem ela --
     * `sales_board_coverage` nulo --, decide
     * {@see GuaranteeSnapshot::hasUnrecordedSalesBoardCoverage()}: se alguma
     * garantia de estoque compunha a cobertura, a dependência existe mas não se
     * sabe de qual quadro, e qualquer quadro da emissão com competência igual
     * ou anterior marca a apuração; se nenhuma compunha, o Quadro de Vendas não
     * tinha como mudar aquele número, e nada é marcado.
     *
     * @return int quantidade de competências marcadas
     */
    public function markAffectedBy(mixed $emissionId, mixed $constructionId, mixed $referenceMonth, ?int $salesBoardId = null): int
    {
        $referenceMonth = GuaranteeSnapshot::normalizeReferenceMonth($referenceMonth);

        if (blank($emissionId) || blank($constructionId) || $referenceMonth === null) {
            return 0;
        }

        Emission::query()
            ->whereKey((int) $emissionId)
            ->sharedLock()
            ->value('id');

        $snapshots = GuaranteeSnapshot::query()
            ->where('emission_id', (int) $emissionId)
            ->whereDate('reference_month', '>=', $referenceMonth)
            ->whereNull('sales_board_outdated_at')
            ->orderBy('reference_month')
            ->lockForUpdate()
            ->get();

        $marked = 0;

        foreach ($snapshots as $snapshot) {
            $coverage = $snapshot->salesBoardCoverage();

            $affected = $coverage === null
                ? $snapshot->hasUnrecordedSalesBoardCoverage()
                : $coverage->dependsOn((int) $constructionId, $referenceMonth);

            if (! $affected) {
                continue;
            }

            $snapshot->forceFill(['sales_board_outdated_at' => now()])->save();

            activity(GuaranteeSnapshotWriter::LOG_NAME)
                ->performedOn($snapshot)
                ->event(GuaranteeSnapshotWriter::EVENT_COMPETENCE_OUTDATED)
                ->withProperties([
                    'emission_id' => (int) $emissionId,
                    'reference_month' => $snapshot->reference_month->toDateString(),
                    'source' => 'sales_board',
                    'sales_board_id' => $salesBoardId,
                    'construction_id' => (int) $constructionId,
                    'board_reference_month' => $referenceMonth,
                    'sales_board_coverage_recorded' => $coverage !== null,
                    'closed' => $snapshot->isClosed(),
                ])
                ->log('Competência de garantias desatualizada pelo Quadro de Vendas');

            $marked++;
        }

        return $marked;
    }
}
