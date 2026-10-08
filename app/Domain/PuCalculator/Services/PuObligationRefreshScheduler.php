<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Agenda a atualização das obrigações de uma emissão para depois do commit da
 * transação corrente (fora de transação, na hora), e roda uma vez só por emissão
 * a cada commit: uma importação de cem linhas do cronograma informado vira uma
 * atualização, não cem.
 *
 * Cada pedido registra o seu callback; o primeiro que rodar depois do commit
 * atualiza e baixa a marca, os demais não fazem nada. Se a transação for
 * desfeita, os callbacks somem com ela e a marca fica -- o próximo pedido roda.
 *
 * Scoped: o que está pendente vale para uma requisição ou um job e nunca vaza para
 * o seguinte.
 */
final class PuObligationRefreshScheduler
{
    /** @var array<int, true> */
    private array $dirty = [];

    public function schedule(int $emissionId, Closure $refresh): void
    {
        $this->dirty[$emissionId] = true;

        DB::afterCommit(function () use ($emissionId, $refresh): void {
            if (! isset($this->dirty[$emissionId])) {
                return;
            }

            unset($this->dirty[$emissionId]);

            $refresh();
        });
    }
}
