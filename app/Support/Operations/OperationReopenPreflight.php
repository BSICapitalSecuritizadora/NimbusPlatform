<?php

namespace App\Support\Operations;

use App\Models\ResponsibilityDelegation;

/**
 * O que volta a produzir autoridade operacional quando uma operação encerrada
 * é reaberta.
 *
 * Mesma razão do preflight de reativação de usuário: nada foi desfeito no
 * encerramento -- os responsáveis continuam gravados, as delegações continuam
 * registradas --, então reabrir devolve tudo no mesmo instante. Quem confirma a
 * reabertura precisa ver isso antes, e não descobrir depois de a operação já
 * estar aceitando trabalho novo.
 *
 * @param  list<array{responsibility: string, user: string}>  $responsibilities
 * @param  list<ResponsibilityDelegation>  $delegations
 */
class OperationReopenPreflight
{
    public function __construct(
        public readonly array $responsibilities,
        public readonly array $delegations,
    ) {}

    public function restoresAuthority(): bool
    {
        return $this->responsibilities !== [] || $this->delegations !== [];
    }

    public function responsibilityCount(): int
    {
        return count($this->responsibilities);
    }

    public function delegationCount(): int
    {
        return count($this->delegations);
    }

    /**
     * Cada responsabilidade pelo rótulo de domínio, nunca pelo nome da coluna:
     * quem confirma a reabertura decide por papel e por pessoa.
     *
     * @return list<string>
     */
    public function responsibilityLines(): array
    {
        return array_map(
            fn (array $held): string => $held['responsibility'].' — '.$held['user'],
            $this->responsibilities,
        );
    }

    /**
     * @return list<string>
     */
    public function delegationLines(): array
    {
        return array_map(function (ResponsibilityDelegation $delegation): string {
            $scope = ResponsibilityDelegation::SCOPE_OPTIONS[$delegation->scope_type] ?? $delegation->scope_type;

            return trim(sprintf(
                '%s → %s · %s · até %s',
                $delegation->delegator?->name ?? '—',
                $delegation->delegate?->name ?? '—',
                $scope,
                $delegation->ends_at?->format('d/m/Y H:i') ?? '—',
            ));
        }, $this->delegations);
    }
}
