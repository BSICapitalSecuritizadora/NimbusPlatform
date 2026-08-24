<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A unidade deixou de estar como a análise a encontrou antes que a importação
 * fosse confirmada -- alguém cadastrou, distratou ou reativou um contrato entre
 * a conferência e o "Confirmar".
 *
 * Não é um erro do arquivo: a planilha continua válida, apenas foi analisada
 * contra uma posição que já não é a atual. O tipo próprio existe para que a tela
 * diga isso, em vez de deixar escapar a violação de índice único que o banco
 * levantaria -- e para separar este caso do {@see RuntimeException} genérico
 * que recusa uma planilha inconsistente.
 *
 * Nada foi gravado quando ela é lançada: a verificação roda dentro da mesma
 * transação, antes da primeira escrita, e a transação inteira é desfeita.
 */
class ContractImportConcurrencyException extends RuntimeException
{
    private const MESSAGE = 'A posição da unidade foi alterada após a análise. Revise novamente a importação antes de confirmar.';

    public static function forUnit(string $unitLabel): self
    {
        return new self(sprintf('%s (unidade %s)', self::MESSAGE, $unitLabel));
    }

    /**
     * A corrida que escapou da verificação: o outro contrato foi gravado depois
     * dela e só o índice único o percebeu.
     */
    public static function raced(): self
    {
        return new self(self::MESSAGE);
    }
}
