<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

/**
 * Conflito de liquidação: aberto até alguém decidir. Aceitar cria uma correção
 * da liquidação existente com os dados que chegaram; rejeitar descarta os dados
 * novos. Nos dois casos o conflito fica registrado com quem decidiu e por quê.
 */
enum PuSettlementConflictStatus: string
{
    case Open = 'open';
    case Accepted = 'accepted';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Em aberto',
            self::Accepted => 'Aceito como correção',
            self::Rejected => 'Rejeitado',
        };
    }
}
