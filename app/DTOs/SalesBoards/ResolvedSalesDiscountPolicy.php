<?php

declare(strict_types=1);

namespace App\DTOs\SalesBoards;

use App\DTOs\BaseDTO;
use App\Models\SalesDiscountPolicy;
use App\Support\Money\IntegerMoney;
use Carbon\CarbonImmutable;

/**
 * Qual desconto máximo estava autorizado para um empreendimento numa data.
 *
 * O limite é guardado em basis points inteiros (5,00% = 500) porque é ele que
 * entra no cálculo do preço mínimo; converter percentual por `float` na hora do
 * veredito é o que produz o centavo perdido.
 *
 * A ausência é explícita e nunca equivale a 0%: sem política registrada não há
 * limite conhecido, e assumir zero reprovaria toda venda com desconto, enquanto
 * assumir qualquer outro número aprovaria vendas que ninguém autorizou.
 */
readonly class ResolvedSalesDiscountPolicy extends BaseDTO
{
    public function __construct(
        public int $constructionId,
        public CarbonImmutable $positionDate,
        public ?int $maximumDiscountBasisPoints,
        public ?CarbonImmutable $effectiveFrom,
        public ?SalesDiscountPolicy $policy,
    ) {}

    public static function fromPolicy(
        int $constructionId,
        CarbonImmutable $positionDate,
        int $maximumDiscountBasisPoints,
        CarbonImmutable $effectiveFrom,
        SalesDiscountPolicy $policy,
    ): self {
        return new self(
            constructionId: $constructionId,
            positionDate: $positionDate,
            maximumDiscountBasisPoints: $maximumDiscountBasisPoints,
            effectiveFrom: $effectiveFrom,
            policy: $policy,
        );
    }

    public static function absent(int $constructionId, CarbonImmutable $positionDate): self
    {
        return new self(
            constructionId: $constructionId,
            positionDate: $positionDate,
            maximumDiscountBasisPoints: null,
            effectiveFrom: null,
            policy: null,
        );
    }

    public function isAbsent(): bool
    {
        return $this->maximumDiscountBasisPoints === null;
    }

    public function isPresent(): bool
    {
        return ! $this->isAbsent();
    }

    public function formattedMaximumDiscount(): ?string
    {
        return $this->maximumDiscountBasisPoints === null
            ? null
            : IntegerMoney::formatBasisPoints($this->maximumDiscountBasisPoints).'%';
    }

    public function effectiveFromDate(): ?string
    {
        return $this->effectiveFrom?->toDateString();
    }
}
