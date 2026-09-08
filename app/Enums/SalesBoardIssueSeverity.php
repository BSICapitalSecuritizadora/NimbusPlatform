<?php

namespace App\Enums;

/**
 * Se um achado da derivação impede a automação da competência.
 *
 * Ver {@see SalesBoardIssueCode} para a distinção entre falta de dado e fato
 * apurado.
 */
enum SalesBoardIssueSeverity: string
{
    case Blocker = 'bloqueador';

    case Warning = 'aviso';

    public function label(): string
    {
        return match ($this) {
            self::Blocker => 'Bloqueador',
            self::Warning => 'Aviso',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Blocker => 'danger',
            self::Warning => 'warning',
        };
    }
}
