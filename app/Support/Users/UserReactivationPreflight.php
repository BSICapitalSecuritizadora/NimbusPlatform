<?php

namespace App\Support\Users;

use App\Models\ResponsibilityDelegation;

/**
 * O que a reativação de um usuário devolve, no instante em que for confirmada.
 *
 * Existe porque a autoridade volta sozinha: os vínculos nunca foram desfeitos,
 * só deixaram de produzir efeito enquanto a pessoa estava inativa. Quem clica
 * "Reativar" precisa ver isso antes, não descobrir depois.
 *
 * @param  list<array{operation: string, responsibility: string}>  $directResponsibilities
 * @param  list<ResponsibilityDelegation>  $delegations
 */
class UserReactivationPreflight
{
    public function __construct(
        public readonly array $directResponsibilities,
        public readonly array $delegations,
    ) {}

    public function restoresAuthority(): bool
    {
        return $this->directResponsibilities !== [] || $this->delegations !== [];
    }

    public function directResponsibilityCount(): int
    {
        return count($this->directResponsibilities);
    }

    public function delegationCount(): int
    {
        return count($this->delegations);
    }

    /** @return list<string> */
    public function directResponsibilityLines(): array
    {
        return array_map(
            fn (array $held): string => $held['operation'].' — '.$held['responsibility'],
            $this->directResponsibilities,
        );
    }

    /**
     * Cada delegação em uma linha legível: quem delega a quem, em que escopo, até
     * quando. Sem id interno -- o operador decide por nome e prazo.
     *
     * @return list<string>
     */
    public function delegationLines(): array
    {
        return array_map(function (ResponsibilityDelegation $delegation): string {
            $scope = ResponsibilityDelegation::SCOPE_OPTIONS[$delegation->scope_type] ?? $delegation->scope_type;
            $operation = $delegation->scopeOperation?->code;

            return trim(sprintf(
                '%s → %s · %s%s · até %s',
                $delegation->delegator?->name ?? '—',
                $delegation->delegate?->name ?? '—',
                $scope,
                $operation !== null ? " ({$operation})" : '',
                $delegation->ends_at?->format('d/m/Y H:i') ?? '—',
            ));
        }, $this->delegations);
    }
}
