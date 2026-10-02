<?php

namespace App\Exceptions;

use App\Support\Imports\ImportConferenceStore;
use RuntimeException;

/**
 * A posição cadastrada mudou entre a conferência e o Confirmar.
 *
 * A conferência vale como contrato: o Confirmar só grava o que o operador viu.
 * Quando a passada de gravação chega a outro resultado -- outra importação ou
 * uma edição manual mexeu nas mesmas parcelas no meio do caminho --, nada é
 * gravado: a transação inteira é desfeita, e a conferência nova vai junto com a
 * exceção para substituir a antiga no {@see ImportConferenceStore}.
 */
class ImportConferenceOutdatedException extends RuntimeException
{
    /**
     * O resumo recalculado difere do mostrado.
     */
    public const REASON_POSITION_CHANGED = 'posicao_mudou';

    /**
     * O banco recusou a gravação pelo índice único de número de parcela: outra
     * gravação criou a mesma parcela depois da classificação.
     */
    public const REASON_UNIQUE_INDEX = 'indice_unico';

    public const MESSAGE = 'A posição cadastrada mudou desde a conferência (outra importação ou edição manual). A conferência foi refeita: revise-a e confirme novamente.';

    /**
     * @param  array<string, mixed>  $freshSummary
     */
    private function __construct(
        public readonly array $freshSummary,
        public readonly string $reason,
    ) {
        parent::__construct(self::MESSAGE);
    }

    /**
     * @param  array<string, mixed>  $freshSummary
     */
    public static function positionChanged(array $freshSummary): self
    {
        return new self($freshSummary, self::REASON_POSITION_CHANGED);
    }

    /**
     * @param  array<string, mixed>  $freshSummary
     */
    public static function uniqueIndex(array $freshSummary): self
    {
        return new self($freshSummary, self::REASON_UNIQUE_INDEX);
    }
}
