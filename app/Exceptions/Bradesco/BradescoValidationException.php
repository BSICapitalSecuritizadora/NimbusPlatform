<?php

namespace App\Exceptions\Bradesco;

/**
 * The request itself was rejected (HTTP 400/404/405): bad agency,
 * account, date range or method.
 *
 * Local identity-mapping faults raise this before any HTTP call, so a
 * malformed BankAccount can never produce a bank request.
 */
class BradescoValidationException extends BradescoException
{
    public static function http(int $status, string $maskedHint = ''): self
    {
        $suffix = $maskedHint === '' ? '' : " {$maskedHint}";

        return new self("O Bradesco rejeitou a requisição (HTTP {$status}). Revise agência, conta e parâmetros.{$suffix}");
    }

    public static function invalidIdentity(string $reason): self
    {
        return new self("Identidade bancária inválida para o Bradesco: {$reason}.");
    }

    public static function unsupportedAccount(string $reason): self
    {
        return new self("Conta não suportada pela API de Saldo e Extrato: {$reason}.");
    }
}
