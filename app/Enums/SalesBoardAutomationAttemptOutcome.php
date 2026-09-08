<?php

namespace App\Enums;

/**
 * O que uma tentativa individual produziu.
 *
 * `Skipped` existe para a tentativa que nem chegou a rodar -- alvo ainda em
 * espera de retry, por exemplo. Registrá-la seria ruído; o que se registra é a
 * decisão de não tentar, quando ela precisa ser explicada.
 */
enum SalesBoardAutomationAttemptOutcome: string
{
    case Generated = 'gerado';

    case Existing = 'ja_existente';

    case Blocked = 'bloqueado';

    case Failed = 'falhou';

    case Skipped = 'ignorado';

    public function isSuccess(): bool
    {
        return $this === self::Generated || $this === self::Existing;
    }

    public function satisfiedVia(): ?SalesBoardAutomationSatisfiedVia
    {
        return match ($this) {
            self::Generated => SalesBoardAutomationSatisfiedVia::Generated,
            self::Existing => SalesBoardAutomationSatisfiedVia::Existing,
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Generated => 'Ciclo gerado',
            self::Existing => 'Ciclo já existente',
            self::Blocked => 'Bloqueado pela fonte',
            self::Failed => 'Falha técnica',
            self::Skipped => 'Ignorado',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Generated, self::Existing => 'success',
            self::Blocked => 'warning',
            self::Failed => 'danger',
            self::Skipped => 'gray',
        };
    }
}
