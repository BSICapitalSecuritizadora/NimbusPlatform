<?php

namespace App\Enums;

/**
 * Como o módulo chegou a um campo extraído (§36 do escopo).
 *
 * A distinção é o que impede ausência de virar zero: `NotFound` nunca deve ser
 * convertido em valor, e `Inferred` sempre exige atenção humana na revisão.
 */
enum GuaranteeEvidenceLevel: string
{
    case Explicit = 'explicit';
    case Inferred = 'inferred';
    case NotFound = 'not_found';

    public function label(): string
    {
        return match ($this) {
            self::Explicit => 'Identificada explicitamente',
            self::Inferred => 'Inferida',
            self::NotFound => 'Não localizada',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Explicit => 'success',
            self::Inferred => 'warning',
            self::NotFound => 'gray',
        };
    }
}
