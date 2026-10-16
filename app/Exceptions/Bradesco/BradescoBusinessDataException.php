<?php

namespace App\Exceptions\Bradesco;

/**
 * The bank answered but the payload is unusable: backend codigoRetorno
 * 08, unknown return codes, malformed JSON, or rows that contradict
 * the documented contract.
 *
 * The raw payload is kept out of the message; diagnostics expose the
 * classified evidence with identifiers masked.
 */
class BradescoBusinessDataException extends BradescoException
{
    public static function backend(string $mensagem = ''): self
    {
        $suffix = trim($mensagem) === '' ? '' : " Retorno do banco: {$mensagem}.";

        return new self("O Bradesco reportou erro de negócio (codigoRetorno 08).{$suffix}");
    }

    public static function unknownReturnCode(string $code): self
    {
        return new self("O Bradesco retornou um codigoRetorno não documentado ({$code}). O manual v2.2.2 lista apenas 00, 01, 04, 08 e 24.");
    }

    public static function invalidPayload(string $reason): self
    {
        return new self("Resposta do Bradesco fora do contrato documentado: {$reason}.");
    }
}
