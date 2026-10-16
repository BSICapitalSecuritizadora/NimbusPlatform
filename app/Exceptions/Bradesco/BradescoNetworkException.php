<?php

namespace App\Exceptions\Bradesco;

/**
 * Transport or bank-infrastructure failure: connection error, timeout,
 * HTTP 500/503, or backend codigoRetorno 24.
 *
 * Always transient-suspect and never silently retried: the caller
 * decides whether a new explicit attempt is safe.
 */
class BradescoNetworkException extends BradescoException
{
    public static function connection(string $maskedReason): self
    {
        return new self("Falha de comunicação com o Bradesco: {$maskedReason}. Nenhum dado foi retornado.");
    }

    public static function timeout(int $seconds): self
    {
        return new self("Tempo esgotado após {$seconds}s aguardando o Bradesco. Nenhum dado foi retornado.");
    }

    public static function serverError(int $status): self
    {
        return new self("O Bradesco retornou indisponibilidade temporária (HTTP {$status}). Nenhum dado foi retornado.");
    }

    public static function cloudError(string $mensagem = ''): self
    {
        $suffix = trim($mensagem) === '' ? '' : " Retorno do banco: {$mensagem}.";

        return new self("O Bradesco reportou falha de infraestrutura (codigoRetorno 24).{$suffix}");
    }
}
