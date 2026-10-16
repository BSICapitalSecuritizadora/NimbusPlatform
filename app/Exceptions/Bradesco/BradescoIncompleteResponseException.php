<?php

namespace App\Exceptions\Bradesco;

/**
 * The bank answered with a partial page: backend codigoRetorno 01
 * ("success with more data"), or a quantidadeLancamentos count that
 * does not match the rows actually returned.
 *
 * A partial page must never feed matching or balances: it is evidence
 * of incompleteness, and the manual documents no pagination parameters
 * to fetch the remainder.
 */
class BradescoIncompleteResponseException extends BradescoBusinessDataException
{
    public static function moreDataAvailable(string $section): self
    {
        return new self("Resposta parcial em [{$section}]: o banco sinalizou mais registros (codigoRetorno 01) e o manual não documenta paginação para buscar o restante.");
    }

    public static function countMismatch(string $section, int $declared, int $actual): self
    {
        return new self("Resposta inconsistente em [{$section}]: quantidadeLancamentos declara {$declared} registro(s), mas {$actual} foram retornados.");
    }
}
