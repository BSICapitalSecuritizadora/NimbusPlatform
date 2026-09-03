<?php

declare(strict_types=1);

namespace App\DTOs\SalesBoards;

use App\DTOs\BaseDTO;
use App\Enums\SalesPriceConformityStatus;
use App\Support\Money\IntegerMoney;
use Carbon\CarbonImmutable;

/**
 * Se uma venda respeitou a política comercial vigente na data em que foi feita.
 *
 * Nada aqui é persistido. A não conformidade desta fase é uma leitura: registrar
 * ocorrência, tratativa e exceção aprovada é assunto das fases seguintes, e
 * antecipar o schema disso congelaria decisões que ainda não foram tomadas.
 *
 * Tudo em centavos e basis points inteiros. O percentual efetivo é informação
 * de exibição e auditoria -- o veredito sai da comparação monetária exata entre
 * o valor da venda e o preço mínimo autorizado.
 */
readonly class SalesPriceConformityResult extends BaseDTO
{
    public function __construct(
        public SalesPriceConformityStatus $status,
        public CarbonImmutable $evaluatedAt,
        public ?int $referenceValueCents,
        public ?int $saleValueCents,
        public ?int $authorizedDiscountBasisPoints,
        public ?int $minimumAuthorizedValueCents,
        public ?int $effectiveDiscountBasisPoints,
        public ?int $differenceCents,
        public ?string $reasonWhenUndetermined,
    ) {}

    public static function undetermined(CarbonImmutable $evaluatedAt, string $reason, ?int $referenceValueCents = null, ?int $saleValueCents = null, ?int $authorizedDiscountBasisPoints = null): self
    {
        return new self(
            status: SalesPriceConformityStatus::Undetermined,
            evaluatedAt: $evaluatedAt,
            referenceValueCents: $referenceValueCents,
            saleValueCents: $saleValueCents,
            authorizedDiscountBasisPoints: $authorizedDiscountBasisPoints,
            minimumAuthorizedValueCents: null,
            effectiveDiscountBasisPoints: null,
            differenceCents: null,
            reasonWhenUndetermined: $reason,
        );
    }

    public static function evaluated(
        CarbonImmutable $evaluatedAt,
        int $referenceValueCents,
        int $saleValueCents,
        int $authorizedDiscountBasisPoints,
        int $minimumAuthorizedValueCents,
    ): self {
        return new self(
            status: $saleValueCents >= $minimumAuthorizedValueCents
                ? SalesPriceConformityStatus::Conform
                : SalesPriceConformityStatus::NonConform,
            evaluatedAt: $evaluatedAt,
            referenceValueCents: $referenceValueCents,
            saleValueCents: $saleValueCents,
            authorizedDiscountBasisPoints: $authorizedDiscountBasisPoints,
            minimumAuthorizedValueCents: $minimumAuthorizedValueCents,
            effectiveDiscountBasisPoints: IntegerMoney::effectiveDiscountBasisPoints($referenceValueCents, $saleValueCents),
            differenceCents: $saleValueCents - $minimumAuthorizedValueCents,
            reasonWhenUndetermined: null,
        );
    }

    public function isConform(): bool
    {
        return $this->status === SalesPriceConformityStatus::Conform;
    }

    public function isNonConform(): bool
    {
        return $this->status === SalesPriceConformityStatus::NonConform;
    }

    public function isUndetermined(): bool
    {
        return $this->status === SalesPriceConformityStatus::Undetermined;
    }

    /**
     * Venda acima da tabela: não houve desconto, houve ágio.
     */
    public function hasPremium(): bool
    {
        return ($this->effectiveDiscountBasisPoints !== null) && ($this->effectiveDiscountBasisPoints < 0);
    }

    /**
     * Rótulo do desconto praticado, com o ágio apresentado como tal em vez de
     * um desconto negativo.
     */
    public function effectiveDiscountLabel(): ?string
    {
        if ($this->effectiveDiscountBasisPoints === null) {
            return null;
        }

        if ($this->effectiveDiscountBasisPoints < 0) {
            return 'Ágio de '.IntegerMoney::formatBasisPoints(-$this->effectiveDiscountBasisPoints).'%';
        }

        if ($this->effectiveDiscountBasisPoints === 0) {
            return 'Sem desconto';
        }

        return 'Desconto de '.IntegerMoney::formatBasisPoints($this->effectiveDiscountBasisPoints).'%';
    }

    /**
     * @return array<string, string|null>
     */
    public function toDisplayArray(): array
    {
        return [
            'status' => $this->status->label(),
            'reference_value' => $this->referenceValueCents === null ? null : IntegerMoney::format($this->referenceValueCents),
            'sale_value' => $this->saleValueCents === null ? null : IntegerMoney::format($this->saleValueCents),
            'authorized_discount_percent' => $this->authorizedDiscountBasisPoints === null
                ? null
                : IntegerMoney::formatBasisPoints($this->authorizedDiscountBasisPoints).'%',
            'minimum_authorized_value' => $this->minimumAuthorizedValueCents === null
                ? null
                : IntegerMoney::format($this->minimumAuthorizedValueCents),
            'effective_discount' => $this->effectiveDiscountLabel(),
            'difference_value' => $this->differenceCents === null ? null : IntegerMoney::format($this->differenceCents),
            'undetermined_reason' => $this->reasonWhenUndetermined,
        ];
    }
}
