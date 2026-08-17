<?php

declare(strict_types=1);

namespace App\DTOs\Guarantees;

use App\DTOs\BaseDTO;
use App\Enums\GuaranteeValueSource;
use App\Enums\GuaranteeValueStatus;

/**
 * Valor atual de uma garantia numa competência, com a origem que o produziu.
 *
 * `amount` nulo nunca deve ser lido como zero: o `status` diz se o dado está
 * pendente de digitação, é inaplicável ou simplesmente não tem fonte (§25).
 */
readonly class ResolvedGuaranteeValue extends BaseDTO
{
    /**
     * @param  array<string, mixed>  $metadata  memória de cálculo — qual conta,
     *                                          qual laudo, qual quadro de vendas originou o número
     */
    public function __construct(
        public ?float $amount,
        public GuaranteeValueSource $source,
        public GuaranteeValueStatus $status,
        public array $metadata = [],
    ) {}

    public static function automatic(float $amount, GuaranteeValueSource $source, array $metadata = []): self
    {
        return new self($amount, $source, GuaranteeValueStatus::Automatic, $metadata);
    }

    public static function manual(float $amount, array $metadata = []): self
    {
        return new self($amount, GuaranteeValueSource::Manual, GuaranteeValueStatus::Manual, $metadata);
    }

    /** Falta o dado, e alguém precisa informá-lo. */
    public static function pending(GuaranteeValueSource $source, array $metadata = []): self
    {
        return new self(null, $source, GuaranteeValueStatus::Pending, $metadata);
    }

    /** A garantia não tem posição monetária a apurar (aval, fiança). */
    public static function notApplicable(array $metadata = []): self
    {
        return new self(null, GuaranteeValueSource::NotAvailable, GuaranteeValueStatus::NotApplicable, $metadata);
    }

    public function isResolved(): bool
    {
        return $this->amount !== null && $this->status->isResolved();
    }

    /** Bloqueia o fechamento da competência por dado faltante? */
    public function isPending(): bool
    {
        return $this->status === GuaranteeValueStatus::Pending;
    }
}
