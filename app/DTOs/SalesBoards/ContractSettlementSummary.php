<?php

declare(strict_types=1);

namespace App\DTOs\SalesBoards;

use App\DTOs\BaseDTO;
use App\Enums\ContractSettlementState;
use Carbon\CarbonImmutable;

/**
 * Se um contrato estava quitado numa data, e a contagem que sustenta a resposta.
 *
 * Os contadores acompanham o estado porque "quitado" sem "3 de 3 parcelas pagas"
 * é uma afirmação que ninguém consegue conferir.
 *
 * Os campos depois da contagem são o que a plausibilidade da derivação lê sem
 * uma consulta a mais: quantas parcelas válidas já tinham pagamento, quanto
 * faltou nas pagas abaixo do previsto, a maior parcela prevista e a maior paga
 * (com o número), e a primeira parcela paga cem vezes acima do previsto. Todos
 * têm default, para que construir o resumo só com a contagem continue valendo.
 */
readonly class ContractSettlementSummary extends BaseDTO
{
    public function __construct(
        public int $contractId,
        public CarbonImmutable $positionDate,
        public ContractSettlementState $state,
        public int $validInstallments,
        public int $paidInstallments,
        public int $installmentsWithPayment = 0,
        public int $shortfallCents = 0,
        public ?int $maxExpectedCents = null,
        public ?string $maxExpectedNumber = null,
        public ?int $maxPaidCents = null,
        public ?string $maxPaidNumber = null,
        public ?string $paidFarAboveExpectedNumber = null,
    ) {}

    public function isSettled(): bool
    {
        return $this->state === ContractSettlementState::Settled;
    }

    public function isOutstanding(): bool
    {
        return $this->state === ContractSettlementState::Outstanding;
    }

    public function isUndetermined(): bool
    {
        return $this->state === ContractSettlementState::Undetermined;
    }

    /**
     * O que segura a quitação é só o pagamento abaixo do previsto: toda parcela
     * válida já teve pagamento até a data, e alguma ficou abaixo do previsto sem
     * desconto registrado. É o caso do aviso UNDERPAID_INSTALLMENTS -- um
     * contrato com parcela ainda por pagar está em aberto por outro motivo, e
     * avisar sobre ele seria ruído mensal de todo pagamento parcial em curso.
     */
    public function isHeldOnlyByShortfall(): bool
    {
        return ($this->validInstallments > 0)
            && ($this->installmentsWithPayment === $this->validInstallments)
            && ($this->paidInstallments < $this->validInstallments);
    }

    /**
     * Quantas parcelas válidas foram pagas abaixo do previsto.
     */
    public function underpaidInstallments(): int
    {
        return max(0, $this->installmentsWithPayment - $this->paidInstallments);
    }
}
