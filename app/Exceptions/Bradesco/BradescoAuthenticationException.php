<?php

namespace App\Exceptions\Bradesco;

/**
 * The bank refused the caller identity (HTTP 401/403, codigoRetorno 04).
 *
 * Distinct from validation: the request shape may be fine while the
 * session, permission or credential is not.
 */
class BradescoAuthenticationException extends BradescoException
{
    public static function http(int $status, string $maskedDetail = ''): self
    {
        $suffix = $maskedDetail === '' ? '' : " Detalhe mascarado: {$maskedDetail}.";

        return new self("O Bradesco recusou a autenticação (HTTP {$status}).{$suffix}");
    }

    public static function backend(string $mensagem = ''): self
    {
        $suffix = trim($mensagem) === '' ? '' : " Retorno do banco: {$mensagem}.";

        return new self("O Bradesco recusou a autorização (codigoRetorno 04).{$suffix}");
    }
}
