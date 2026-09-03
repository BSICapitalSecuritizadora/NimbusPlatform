<?php

namespace App\Enums;

/**
 * De onde veio uma linha do histórico de valores de uma unidade.
 *
 * Procedência persistida, gravada no momento do registro. Não confundir com
 * {@see ResolvedUnitValueSource}, que diz de onde o resolver tirou o valor
 * vigente numa data.
 */
enum UnitValueSource: string
{
    case Manual = 'manual';

    case SpreadsheetImport = 'spreadsheet_import';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Atualização manual',
            self::SpreadsheetImport => 'Importação em planilha',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Manual => 'info',
            self::SpreadsheetImport => 'gray',
        };
    }
}
