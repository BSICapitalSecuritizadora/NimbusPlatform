<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

/**
 * Recusa de apagar uma entidade que aparece em delegação de responsabilidade.
 *
 * As chaves estrangeiras de `responsibility_delegations` são RESTRICT: o banco é
 * a última linha de defesa e recusa por conta própria, com errno 1451. Esta
 * exceção existe para recusar antes disso, com a razão em vez do código -- e
 * para ser específica: outras chaves estrangeiras bloqueiam exclusão por outros
 * motivos, e traduzir todo 1451 como "possui delegações" mentiria sobre eles.
 */
class DelegationHistoryException extends RuntimeException implements ShouldntReport
{
    public static function forUser(): self
    {
        return new self('Este usuário possui histórico de delegações de responsabilidade e não pode ser excluído.');
    }

    public static function forOperation(): self
    {
        return new self('Esta operação possui histórico de delegações de responsabilidade e não pode ser excluída.');
    }
}
