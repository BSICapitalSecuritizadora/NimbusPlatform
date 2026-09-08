<?php

declare(strict_types=1);

namespace App\Events\SalesBoards;

use App\Models\SalesBoardCycle;
use App\Models\SalesBoardCycleBaseline;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * O ponteiro de versão vigente de um ciclo mudou.
 *
 * Existe para que o recálculo -- que é Fase C -- não precise saber que existe
 * validação da construtora. Sem o evento, o serviço de recálculo teria de
 * chamar diretamente um serviço da Fase D, e cada fase seguinte acrescentaria
 * mais uma chamada dentro dele até ninguém mais conseguir dizer o que acontece
 * ao recalcular uma posição.
 *
 * Disparado **depois** do commit. Um recálculo que falha e desfaz não mudou
 * versão nenhuma, e não pode invalidar a conferência de ninguém.
 *
 * `snapshotChanged` é a distinção que interessa a quem escuta: uma versão nova
 * que apresenta exatamente o mesmo quadro não desfaz o que a construtora
 * conferiu.
 */
class SalesBoardCurrentBaselineChanged
{
    use Dispatchable;

    public function __construct(
        public readonly SalesBoardCycle $cycle,
        public readonly SalesBoardCycleBaseline $previousBaseline,
        public readonly SalesBoardCycleBaseline $newBaseline,
    ) {}

    /**
     * A posição apresentada mudou -- e não apenas a origem material dela.
     */
    public function snapshotChanged(): bool
    {
        return (string) $this->previousBaseline->snapshot_fingerprint
            !== (string) $this->newBaseline->snapshot_fingerprint;
    }
}
