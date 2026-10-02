<?php

declare(strict_types=1);

namespace App\Actions\ContractInstallments;

/**
 * A decisão explícita de cancelar as parcelas em aberto que a planilha não
 * trouxe: a data do cancelamento, o motivo e quem decidiu.
 *
 * Nunca é o padrão. Uma planilha dividida ou parcial omitiria parcelas reais, e
 * cancelá-las sozinha seria apagar dívida com um arquivo incompleto -- por isso
 * só existe quando alguém marca a opção na conferência, com data e motivo.
 */
final readonly class AbsentInstallmentCancellation
{
    /**
     * @param  string  $date  data do cancelamento (`Y-m-d`)
     */
    public function __construct(
        public string $date,
        public string $reason,
        public ?int $userId,
    ) {}
}
