<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

enum PuCurveStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Generated = 'generated';
    case Validated = 'validated';
    case Homologated = 'homologated';
    case Divergent = 'divergent';
    case Error = 'error';
    case Obsolete = 'obsolete';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendente',
            self::Processing => 'Processando',
            self::Generated => 'Gerada',
            self::Validated => 'Validada',
            self::Homologated => 'Homologada',
            self::Divergent => 'Divergente',
            self::Error => 'Erro',
            self::Obsolete => 'Obsoleta',
        };
    }

    /**
     * Cor do badge no Filament.
     */
    public function color(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Processing => 'info',
            self::Generated => 'warning',
            self::Validated => 'success',
            self::Homologated => 'success',
            self::Divergent => 'danger',
            self::Error => 'danger',
            self::Obsolete => 'gray',
        };
    }

    /**
     * Estados protegidos nao podem ser sobrescritos sem confirmacao explicita.
     */
    public function isProtected(): bool
    {
        return $this === self::Homologated;
    }

    public function isActive(): bool
    {
        return ! in_array($this, [self::Obsolete, self::Error], true);
    }
}
