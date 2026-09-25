<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\Events\SalesBoards\SalesBoardCurrentBaselineChanged;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardCycleBaseline;

/**
 * Conclui a substituição de revisões que o recálculo não conseguiu concluir.
 *
 * A substituição normal sai dos ouvintes de {@see SalesBoardCurrentBaselineChanged},
 * depois do commit do recálculo. Eles são síncronos e rodam cada um na sua
 * transação: se um deles falha -- um lock que não chega a tempo, por exemplo --
 * a versão nova já está commitada e a revisão desatualizada continua aberta. Sem
 * esta reconciliação a competência ficava presa: a abertura da validação recusava
 * o ciclo em análise da Gestão, a abertura da análise não achava validação
 * aplicável, e recalcular de novo devolvia "sem alteração" sem redisparar nada.
 *
 * A reconciliação é a mesma substituição, contra a versão vigente, e por isso é
 * idempotente: quem chama antes de decidir -- as aberturas e o recálculo sem
 * alteração -- não precisa saber se algum ouvinte falhou. No caminho normal ela
 * só lê e não trava nada.
 */
class SalesBoardReviewSupersessionReconciler
{
    public function __construct(
        private readonly SalesBoardManagementReviewSupersedingService $managementSuperseding,
        private readonly SalesBoardBuilderReviewSupersedingService $builderSuperseding,
        private readonly SalesBoardManagementReviewApplicability $managementApplicability,
        private readonly SalesBoardBuilderReviewApplicability $builderApplicability,
    ) {}

    /**
     * @return bool se alguma revisão foi substituída agora
     */
    public function reconcile(SalesBoardCycle $cycle): bool
    {
        $baselineId = SalesBoardCycle::query()->whereKey($cycle->getKey())->value('current_baseline_id');
        $baseline = $baselineId === null ? null : SalesBoardCycleBaseline::query()->find($baselineId);

        if (! $baseline instanceof SalesBoardCycleBaseline) {
            return false;
        }

        if (($this->managementApplicability->reviewsOutdatedBy($cycle, $baseline) === [])
            && ($this->builderApplicability->reviewsOutdatedBy($cycle, $baseline) === [])) {
            return false;
        }

        /**
         * A mesma ordem dos ouvintes: a análise da Gestão primeiro, a validação
         * da construtora -- que devolve o ciclo à construtora -- depois.
         */
        $management = $this->managementSuperseding->supersedeOutdated($cycle, $baseline);
        $builder = $this->builderSuperseding->supersedeOutdated($cycle, $baseline);

        return ($management !== []) || ($builder !== []);
    }
}
