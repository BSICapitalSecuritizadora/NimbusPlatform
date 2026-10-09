<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

use App\Domain\PuCalculator\Services\PuOperationalSeverityPolicy;

/**
 * Urgência operacional de uma condição do PU (Fase 6). Não é o estado de negócio:
 * "aguardando índice" é normal antes da divulgação esperada, e só a política
 * ({@see PuOperationalSeverityPolicy}) decide
 * quanto cada condição pede de atenção.
 *
 *  - INFO: situação esperada ou que se resolve sozinha; fica só no retrato;
 *  - WARNING: precisa de alguém olhar; vira incidente;
 *  - CRITICAL: intervenção financeira ou de governança; vira incidente.
 */
enum PuOperationalSeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';

    public function rank(): int
    {
        return match ($this) {
            self::Info => 0,
            self::Warning => 1,
            self::Critical => 2,
        };
    }

    public function atLeast(self $other): bool
    {
        return $this->rank() >= $other->rank();
    }

    /**
     * Pede ação: vira incidente durável (INFO fica só no retrato).
     */
    public function isActionable(): bool
    {
        return $this !== self::Info;
    }

    public function label(): string
    {
        return match ($this) {
            self::Info => 'Informativo',
            self::Warning => 'Atenção',
            self::Critical => 'Crítico',
        };
    }
}
