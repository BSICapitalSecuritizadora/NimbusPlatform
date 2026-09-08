<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Support;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Shared\StringHelper;

/**
 * Binder textual para o CSV de benchmark externo.
 *
 * CSV é texto sem tipos ricos: cada célula chega ao parser de domínio como a
 * string original, sem passar por float64. A interpretação (data, decimal)
 * acontece depois, nos parsers dedicados do domínio. Booleanos vindos da
 * conversão de texto do leitor seguem o comportamento padrão.
 */
final class PuExternalBenchmarkCsvValueBinder extends DefaultValueBinder
{
    public function bindValue(Cell $cell, mixed $value): bool
    {
        if ($value === null || is_bool($value)) {
            return parent::bindValue($cell, $value);
        }

        $cell->setValueExplicit(
            StringHelper::sanitizeUTF8((string) $value),
            DataType::TYPE_STRING,
        );

        return true;
    }
}
