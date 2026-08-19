<?php

namespace App\Enums;

/**
 * Desfecho individual de cada arquivo num cadastro de documentos em lote.
 *
 * O processamento é independente por arquivo, então um lote pode terminar com
 * status diferentes em cada item — é isso que o resumo final apresenta.
 */
enum DocumentBatchItemStatus: string
{
    /** Documento criado e vinculado às séries do lote. */
    case Created = 'created';

    /** Arquivo idêntico a outro do mesmo lote; não foi cadastrado duas vezes. */
    case Duplicated = 'duplicated';

    /** Reprovado pelas regras de formato, tamanho ou pela varredura antivírus. */
    case Rejected = 'rejected';

    /** Falha de processamento (armazenamento ou banco) depois da validação. */
    case Failed = 'failed';

    /** Não chegou a ser processado — o orçamento de tempo do lote se esgotou. */
    case NotProcessed = 'not_processed';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Cadastrado',
            self::Duplicated => 'Arquivo repetido no lote',
            self::Rejected => 'Rejeitado',
            self::Failed => 'Falha no processamento',
            self::NotProcessed => 'Não processado',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Created => 'success',
            self::Duplicated => 'warning',
            self::Rejected, self::Failed => 'danger',
            self::NotProcessed => 'gray',
        };
    }

    /**
     * Itens que podem ser reprocessados sem risco de duplicar um documento já
     * criado: nada foi persistido para eles.
     */
    public function isRetryable(): bool
    {
        return match ($this) {
            self::Failed, self::NotProcessed => true,
            self::Created, self::Duplicated, self::Rejected => false,
        };
    }
}
