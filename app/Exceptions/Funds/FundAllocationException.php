<?php

namespace App\Exceptions\Funds;

use RuntimeException;

/**
 * Domain error for the monthly fund-allocation workflow.
 *
 * Messages are user-safe Portuguese strings: they describe the violated
 * financial rule without leaking internals, and are raised before any write
 * (or inside the rolled-back approval transaction).
 */
class FundAllocationException extends RuntimeException
{
    public static function forbidden(string $action): self
    {
        return new self("Você não tem permissão para {$action}.");
    }

    public static function staleReference(): self
    {
        return new self('O saldo de referência mudou desde a criação desta revisão. Descarte o rascunho e proponha novamente sobre a versão vigente.');
    }

    public static function segregationOfDuties(): self
    {
        return new self('Quem propôs ou enviou a alocação não pode aprová-la. A aprovação exige um segundo usuário.');
    }

    public static function alreadyApproved(): self
    {
        return new self('Esta revisão já foi aprovada. Correções exigem uma nova revisão.');
    }

    public static function superseded(string $state): self
    {
        return new self("Esta revisão não pode ser aprovada: ela está {$state} e outra revisão assumiu a competência.");
    }

    public static function invalidTransition(string $from, string $to): self
    {
        return new self("Transição inválida: a revisão está '{$from}' e não pode ir para '{$to}'.");
    }

    public static function invalid(string $message): self
    {
        return new self($message);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function invalidLines(string $message, array $context = []): self
    {
        $exception = new self($message);
        $exception->context = $context;

        return $exception;
    }

    /**
     * @var array<string, mixed>
     */
    public array $context = [];
}
