<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

/**
 * Ciclo de vida de um incidente operacional do PU (Fase 6).
 *
 * Reconhecer só diz que alguém está olhando: não corrige nada financeiro, não
 * resolve e não silencia a condição. Resolver é exclusivo do monitor, quando a
 * condição de domínio deixa de existir; se ela volta, nasce um incidente novo
 * ligado ao anterior (reincidência).
 */
enum PuIncidentStatus: string
{
    case Active = 'active';
    case Acknowledged = 'acknowledged';
    case Resolved = 'resolved';

    public function isOpen(): bool
    {
        return $this !== self::Resolved;
    }

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Ativo',
            self::Acknowledged => 'Reconhecido',
            self::Resolved => 'Resolvido',
        };
    }
}
