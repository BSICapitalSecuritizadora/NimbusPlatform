<?php

declare(strict_types=1);

namespace App\DTOs\SalesBoards;

use App\DTOs\BaseDTO;
use App\Enums\ResolvedUnitValueSource;
use App\Enums\SalesBoardMovementType;
use App\Enums\SalesPriceConformityStatus;
use App\Models\SalesBoardCycleMovement;
use App\Support\Money\IntegerMoney;
use App\Support\SalesBoards\CanonicalDigest;
use Carbon\CarbonImmutable;

/**
 * Um movimento congelado, na forma em que o snapshot o compara e o resume.
 *
 * Os três tipos cabem numa estrutura só porque compartilham a âncora -- contrato
 * e unidade -- e diferem apenas em quais campos estão preenchidos. A venda é o
 * caso rico, porque carrega junto o veredito da política comercial e tudo o que
 * o produziu.
 */
readonly class SalesBoardSnapshotMovement extends BaseDTO
{
    public function __construct(
        public SalesBoardMovementType $type,
        public int $constructionUnitId,
        public ?string $block,
        public ?string $unit,
        public int $contractId,
        public ?string $contractCode,
        public ?CarbonImmutable $eventDate,
        public ?CarbonImmutable $saleDate,
        public ?int $saleValueCents,
        public ?CarbonImmutable $cancellationDate,
        public ?int $settlementInstallmentsTotal,
        public ?int $unitReferenceValueCents,
        public ?ResolvedUnitValueSource $unitReferenceValueSource,
        public ?CarbonImmutable $unitReferenceEffectiveFrom,
        public ?int $salesDiscountPolicyId,
        public ?int $authorizedDiscountBasisPoints,
        public ?int $minimumAuthorizedValueCents,
        public ?int $effectiveDiscountBasisPoints,
        public ?int $differenceCents,
        public ?SalesPriceConformityStatus $conformityStatus,
        public ?string $conformityReason,
    ) {}

    public static function fromSale(SalesBoardSaleMovement $sale): self
    {
        $conformity = $sale->conformity;

        return new self(
            type: SalesBoardMovementType::Sale,
            constructionUnitId: $sale->constructionUnitId,
            block: $sale->block,
            unit: $sale->unit,
            contractId: $sale->contractId,
            contractCode: $sale->contractCode,
            eventDate: $sale->saleDate,
            saleDate: $sale->saleDate,
            saleValueCents: $sale->saleValueCents,
            cancellationDate: null,
            settlementInstallmentsTotal: null,
            unitReferenceValueCents: $sale->unitReferenceValueCents,
            unitReferenceValueSource: $sale->unitReferenceValueSource,
            unitReferenceEffectiveFrom: $sale->unitReferenceEffectiveFrom,
            salesDiscountPolicyId: $sale->salesDiscountPolicyId,
            authorizedDiscountBasisPoints: $conformity->authorizedDiscountBasisPoints,
            minimumAuthorizedValueCents: $conformity->minimumAuthorizedValueCents,
            effectiveDiscountBasisPoints: $conformity->effectiveDiscountBasisPoints,
            differenceCents: $conformity->differenceCents,
            conformityStatus: $conformity->status,
            conformityReason: $conformity->reasonWhenUndetermined,
        );
    }

    /**
     * A quitação não tem `event_date`.
     *
     * O motor prova que o contrato não estava quitado antes da competência e
     * estava no fechamento dela. O dia exato exigiria uma regra nova -- qual
     * pagamento "conclui" a quitação -- que ninguém decidiu, e inventá-la aqui
     * para não deixar uma coluna vazia seria criar regra de negócio no lugar
     * errado.
     */
    public static function fromSettlement(SalesBoardSettlementMovement $settlement): self
    {
        return new self(
            type: SalesBoardMovementType::Settlement,
            constructionUnitId: $settlement->constructionUnitId,
            block: $settlement->block,
            unit: $settlement->unit,
            contractId: $settlement->contractId,
            contractCode: $settlement->contractCode,
            eventDate: null,
            saleDate: $settlement->saleDate,
            saleValueCents: $settlement->saleValueCents,
            cancellationDate: null,
            settlementInstallmentsTotal: $settlement->installments,
            unitReferenceValueCents: null,
            unitReferenceValueSource: null,
            unitReferenceEffectiveFrom: null,
            salesDiscountPolicyId: null,
            authorizedDiscountBasisPoints: null,
            minimumAuthorizedValueCents: null,
            effectiveDiscountBasisPoints: null,
            differenceCents: null,
            conformityStatus: null,
            conformityReason: null,
        );
    }

    public static function fromCancellation(SalesBoardCancellationMovement $cancellation): self
    {
        return new self(
            type: SalesBoardMovementType::Cancellation,
            constructionUnitId: $cancellation->constructionUnitId,
            block: $cancellation->block,
            unit: $cancellation->unit,
            contractId: $cancellation->contractId,
            contractCode: $cancellation->contractCode,
            eventDate: $cancellation->cancellationDate,
            saleDate: $cancellation->saleDate,
            saleValueCents: $cancellation->saleValueCents,
            cancellationDate: $cancellation->cancellationDate,
            settlementInstallmentsTotal: null,
            unitReferenceValueCents: null,
            unitReferenceValueSource: null,
            unitReferenceEffectiveFrom: null,
            salesDiscountPolicyId: null,
            authorizedDiscountBasisPoints: null,
            minimumAuthorizedValueCents: null,
            effectiveDiscountBasisPoints: null,
            differenceCents: null,
            conformityStatus: null,
            conformityReason: null,
        );
    }

    public static function fromPersisted(SalesBoardCycleMovement $movement): self
    {
        $date = static fn (mixed $value): ?CarbonImmutable => $value === null
            ? null
            : CarbonImmutable::parse($value->toDateString());

        return new self(
            type: $movement->movement_type,
            constructionUnitId: (int) $movement->construction_unit_id,
            block: $movement->block,
            unit: $movement->unit,
            contractId: (int) $movement->contract_id,
            contractCode: $movement->contract_code,
            eventDate: $date($movement->event_date),
            saleDate: $date($movement->sale_date),
            saleValueCents: IntegerMoney::cents($movement->sale_value),
            cancellationDate: $date($movement->cancellation_date),
            settlementInstallmentsTotal: $movement->settlement_installments_total,
            unitReferenceValueCents: IntegerMoney::cents($movement->unit_reference_value),
            unitReferenceValueSource: $movement->unit_reference_value_source,
            unitReferenceEffectiveFrom: $date($movement->unit_reference_value_effective_from),
            salesDiscountPolicyId: $movement->sales_discount_policy_id === null
                ? null
                : (int) $movement->sales_discount_policy_id,
            authorizedDiscountBasisPoints: $movement->authorized_discount_basis_points,
            minimumAuthorizedValueCents: IntegerMoney::cents($movement->minimum_authorized_value),
            effectiveDiscountBasisPoints: $movement->effective_discount_basis_points,
            differenceCents: IntegerMoney::cents($movement->difference_value),
            conformityStatus: $movement->conformity_status,
            conformityReason: $movement->conformity_reason,
        );
    }

    /**
     * Chave do movimento dentro de uma versão.
     *
     * Um contrato produz no máximo uma venda, um distrato e uma quitação por
     * competência, então tipo e contrato identificam o movimento sem ambiguidade
     * -- é a mesma propriedade que a unique da tabela garante.
     */
    public function key(): string
    {
        return $this->type->value.'@'.$this->contractId;
    }

    public function displayName(): string
    {
        return trim(sprintf('%s / %s', (string) $this->block, (string) $this->unit), ' /');
    }

    public function canonicalRow(): string
    {
        return CanonicalDigest::row([
            $this->type,
            $this->contractId,
            $this->constructionUnitId,
            $this->block,
            $this->unit,
            $this->contractCode,
            $this->eventDate,
            $this->saleDate,
            $this->saleValueCents,
            $this->cancellationDate,
            $this->settlementInstallmentsTotal,
            $this->unitReferenceValueCents,
            $this->unitReferenceValueSource,
            $this->unitReferenceEffectiveFrom,
            $this->salesDiscountPolicyId,
            $this->authorizedDiscountBasisPoints,
            $this->minimumAuthorizedValueCents,
            $this->effectiveDiscountBasisPoints,
            $this->differenceCents,
            $this->conformityStatus,
            $this->conformityReason,
        ]);
    }

    public function fingerprint(): string
    {
        return CanonicalDigest::of(['movement' => [$this->canonicalRow()]]);
    }
}
