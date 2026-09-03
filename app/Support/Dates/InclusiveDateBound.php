<?php

declare(strict_types=1);

namespace App\Support\Dates;

use Carbon\CarbonInterface;

/**
 * Limite superior para comparar uma coluna `date` com "até este dia,
 * inclusive", igual nos dois bancos e sem abrir mão do índice.
 *
 * O problema é real e silencioso. O SQLite guarda uma coluna `date` gravada
 * pelo Eloquent como `"2026-07-01 00:00:00"` -- o cast `date` serializa pelo
 * formato de data do model, que inclui hora. Comparar essa coluna com a string
 * `'2026-07-01'` é comparação de texto: `"2026-07-01 00:00:00" <= "2026-07-01"`
 * é falso, e a linha que passa a valer exatamente no dia consultado desaparece.
 * No MySQL, onde a coluna é `DATE` de verdade, a mesma consulta acerta. Uma
 * regra financeira que muda de resposta conforme o banco não é uma regra.
 *
 * Comparar com o fim do dia resolve nos dois: no SQLite a ordenação de texto
 * fica correta porque `"...00:00:00" <= "...23:59:59"`, e no MySQL o literal
 * datetime é comparado contra a data à meia-noite, que também satisfaz.
 *
 * A alternativa seria `whereDate()`, que envolve a coluna em `date(...)` e
 * impede o uso do índice `(construction_unit_id, effective_from)`. Aqui a
 * coluna continua nua na comparação, então o índice segue valendo.
 */
final class InclusiveDateBound
{
    public static function upperBound(CarbonInterface $date): string
    {
        return $date->copy()->endOfDay()->format('Y-m-d H:i:s');
    }
}
