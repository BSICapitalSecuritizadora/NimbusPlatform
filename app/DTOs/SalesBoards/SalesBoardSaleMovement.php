<?php

declare(strict_types=1);

namespace App\DTOs\SalesBoards;

use App\DTOs\BaseDTO;
use App\Enums\ResolvedUnitValueSource;
use App\Enums\SalesBoardMovementTiming;
use App\Support\Money\IntegerMoney;
use Carbon\CarbonImmutable;

/**
 * Uma venda ocorrida na competência, com o veredito da política comercial
 * vigente na data em que ela foi feita.
 *
 * A procedência do valor de referência e a política que produziu o veredito
 * acompanham o resultado. Elas não entram em conta nenhuma -- a conformidade já
 * vem apurada em {@see SalesPriceConformityResult} -- mas são o que permite ao
 * snapshot da fase seguinte explicar a venda sem reabrir a tabela viva, que é
 * justamente a que pode ter mudado desde então.
 *
 * O `timing` separa a venda do mês (`null`) da venda de competência anterior --
 * extemporânea, revisão de venda publicada ou de competência sem posição. A
 * conformidade é sempre a da data da venda.
 */
readonly class SalesBoardSaleMovement extends BaseDTO
{
    public function __construct(
        public int $contractId,
        public ?string $contractCode,
        public int $constructionUnitId,
        public ?string $block,
        public ?string $unit,
        public CarbonImmutable $saleDate,
        public ?int $saleValueCents,
        public SalesPriceConformityResult $conformity,
        public ?int $unitReferenceValueCents,
        public ?ResolvedUnitValueSource $unitReferenceValueSource,
        public ?CarbonImmutable $unitReferenceEffectiveFrom,
        public ?int $salesDiscountPolicyId,
        public ?SalesBoardMovementTiming $timing = null,
    ) {}

    /**
     * A venda é de competência já fechada (extemporânea) ou revisão de venda
     * publicada: os achados dela avisam, nunca bloqueiam.
     */
    public function isLate(): bool
    {
        return $this->timing?->isLate() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'contract_id' => $this->contractId,
            'contract_code' => $this->contractCode,
            'construction_unit_id' => $this->constructionUnitId,
            'block' => $this->block,
            'unit' => $this->unit,
            'sale_date' => $this->saleDate->toDateString(),
            'sale_value' => $this->saleValueCents === null ? null : IntegerMoney::format($this->saleValueCents),
            'unit_reference_value_source' => $this->unitReferenceValueSource?->value,
            'unit_reference_value_effective_from' => $this->unitReferenceEffectiveFrom?->toDateString(),
            'sales_discount_policy_id' => $this->salesDiscountPolicyId,
            'conformity' => $this->conformity->toDisplayArray(),
            'timing' => $this->timing?->value,
        ];
    }
}
