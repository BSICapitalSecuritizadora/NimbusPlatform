<?php

declare(strict_types=1);

namespace App\DTOs\SalesBoards;

use App\DTOs\BaseDTO;

/**
 * Fatos do cronograma de um contrato que não dependem da data da posição.
 *
 * Saem da mesma passada que decide a quitação ({@see ContractScheduleResolution}),
 * sem consulta a mais, e alimentam a plausibilidade da derivação:
 *
 * - parcelas com pagamento ou cancelamento anteriores a 1990 -- o ano digitado
 *   errado (0026, 0202) decide validade e quitação e não pode publicar posição;
 * - pagamentos com data depois de hoje no calendário de negócio -- um
 *   recebimento só se registra depois de acontecer.
 *
 * O vencimento fica de fora de propósito: ele não decide nada na derivação. De
 * cada fato vem o primeiro (a data mais antiga), com o número da parcela, que é
 * o que a mensagem precisa para levar alguém até a linha certa.
 */
readonly class ContractScheduleFacts extends BaseDTO
{
    /**
     * @param  'payment'|'cancellation'|null  $firstDateBefore1990Field
     */
    public function __construct(
        public int $contractId,
        public int $datesBefore1990 = 0,
        public ?string $firstDateBefore1990 = null,
        public ?string $firstDateBefore1990Number = null,
        public ?string $firstDateBefore1990Field = null,
        public int $paymentsAfterToday = 0,
        public ?string $firstPaymentAfterToday = null,
        public ?string $firstPaymentAfterTodayNumber = null,
    ) {}

    public function hasDatesBefore1990(): bool
    {
        return $this->datesBefore1990 > 0;
    }

    public function hasPaymentsAfterToday(): bool
    {
        return $this->paymentsAfterToday > 0;
    }
}
