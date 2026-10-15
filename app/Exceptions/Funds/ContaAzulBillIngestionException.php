<?php

namespace App\Exceptions\Funds;

use RuntimeException;

/**
 * Domain error for the account-level Conta Azul bill staging pipeline.
 *
 * Messages are user-safe Portuguese strings: they describe the violated
 * staging rule without leaking identifiers, payloads or internals, and are
 * raised before any write (or inside a rolled-back transaction).
 */
class ContaAzulBillIngestionException extends RuntimeException
{
    public static function forbidden(string $action): self
    {
        return new self("Você não tem permissão para {$action}.");
    }

    public static function missingAccountIdentifier(): self
    {
        return new self('Esta conta bancária não possui um identificador Conta Azul. Vincule a conta financeira antes de importar.');
    }

    public static function invalidDateRange(string $detail): self
    {
        return new self("Período de importação inválido: {$detail}.");
    }

    public static function duplicateImport(): self
    {
        return new self('Já existe uma importação em andamento para esta conta e período. Aguarde a conclusão antes de iniciar outra.');
    }

    public static function notRetriable(string $status): self
    {
        return new self("Somente importações parciais ou com falha podem ser retomadas. Esta está '{$status}'.");
    }

    public static function invalidPayload(string $detail): self
    {
        return new self("Resposta da fonte inválida: {$detail}.");
    }

    public static function sourceFailed(int $page, int $recordsReceived): self
    {
        $exception = new self("A fonte Conta Azul falhou na página {$page} após {$recordsReceived} registros recebidos. A importação ficou parcial e pode ser retomada.");

        $exception->context = [
            'page' => $page,
            'records_received' => $recordsReceived,
        ];

        return $exception;
    }

    /**
     * @var array<string, mixed>
     */
    public array $context = [];
}
