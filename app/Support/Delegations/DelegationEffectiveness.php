<?php

namespace App\Support\Delegations;

use App\Enums\DelegationIneffectivenessReason;
use App\Services\ResponsibilityDelegationService;

/**
 * O status efetivo de uma delegação e, quando ele é `ineffective`, a condição
 * que falhou.
 *
 * Status e causa saem da mesma avaliação -- {@see ResponsibilityDelegationService::effectiveness()} --
 * de propósito: se a explicação nascesse de outra consulta, a interface poderia
 * dizer "delegante sem permissão" enquanto a autorização recusava por outro
 * motivo, e a explicação passaria a ser mais um lugar para errar.
 */
class DelegationEffectiveness
{
    private function __construct(
        public readonly string $status,
        public readonly ?DelegationIneffectivenessReason $reason,
    ) {}

    public static function of(string $status): self
    {
        return new self($status, null);
    }

    public static function ineffective(?DelegationIneffectivenessReason $reason): self
    {
        return new self('ineffective', $reason);
    }

    public function isEffective(): bool
    {
        return $this->status === 'active';
    }

    /** A causa em linguagem de operador, ou `null` quando não há o que explicar. */
    public function reasonLabel(): ?string
    {
        return $this->reason?->label();
    }

    /**
     * Rótulo e cor ficam aqui, e não em cada superfície, porque tabela e
     * infolist mostravam o mesmo badge com o mesmo `match` copiado -- e uma
     * terceira cópia nasceria junto com a próxima tela.
     */
    public function statusLabel(): string
    {
        return match ($this->status) {
            'active' => 'Ativa',
            'scheduled' => 'Agendada',
            'expired' => 'Expirada',
            'revoked' => 'Revogada',
            'ineffective' => 'Ineficaz',
            default => $this->status,
        };
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'active' => 'success',
            'scheduled' => 'info',
            'expired' => 'warning',
            'revoked', 'ineffective' => 'danger',
            default => 'gray',
        };
    }
}
