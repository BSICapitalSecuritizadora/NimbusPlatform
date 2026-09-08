<?php

declare(strict_types=1);

namespace App\DTOs\SalesBoards;

use App\DTOs\BaseDTO;
use App\Enums\ConstructionUnitExchangeKind;
use App\Enums\ContractSettlementState;
use App\Enums\ResolvedUnitValueSource;
use App\Enums\SalesBoardUnitClassification;
use App\Support\Money\IntegerMoney;
use Carbon\CarbonImmutable;

/**
 * Uma unidade, classificada numa data, com tudo o que explica a classificação.
 *
 * É a linha que sustenta o número agregado. Os totais do Quadro são a soma
 * destas linhas e nunca uma conta feita à parte -- é isso que permite abrir
 * qualquer número e chegar na unidade e no contrato que o produziram, e o que
 * impede uma unidade de ser contada em dois baldes.
 */
readonly class SalesBoardDerivedLine extends BaseDTO
{
    /**
     * @param  list<SalesBoardIssue>  $issues
     */
    public function __construct(
        public int $constructionUnitId,
        public ?string $block,
        public ?string $unit,
        public SalesBoardUnitClassification $classification,
        public ?int $contractId,
        public ?string $contractCode,
        public ?CarbonImmutable $contractSaleDate,
        public ?int $contractSaleValueCents,
        public ?int $unitReferenceValueCents,
        public ?ResolvedUnitValueSource $unitReferenceValueSource,
        public ?CarbonImmutable $unitReferenceEffectiveFrom,
        public ?ContractSettlementState $settlementState,
        public ?int $settlementInstallmentsTotal,
        public ?int $settlementInstallmentsPaid,
        public ?int $exchangeId,
        public ?int $exchangeValueCents,
        public ?CarbonImmutable $exchangeEffectiveFrom,
        public ?CarbonImmutable $exchangeEndedOn,
        public ?ConstructionUnitExchangeKind $exchangeKind,
        public array $issues,
    ) {}

    public function displayName(): string
    {
        return trim(sprintf('Bloco %s - Unidade %s', (string) $this->block, (string) $this->unit));
    }

    /**
     * Quanto esta linha contribui para o valor do seu balde.
     *
     * Cada balde tem a sua fonte, e elas não se misturam: estoque vale o valor
     * de referência da unidade, financiado e quitado valem o preço pelo qual a
     * unidade foi vendida, permutado vale o valor atribuído à permuta.
     */
    public function bucketValueCents(): ?int
    {
        return match ($this->classification) {
            SalesBoardUnitClassification::Stock => $this->unitReferenceValueCents,
            SalesBoardUnitClassification::Financed,
            SalesBoardUnitClassification::Settled => $this->contractSaleValueCents,
            SalesBoardUnitClassification::Exchanged => $this->exchangeValueCents,
            SalesBoardUnitClassification::Undetermined => null,
        };
    }

    public function hasBlockingIssue(): bool
    {
        return collect($this->issues)->contains(fn (SalesBoardIssue $issue): bool => $issue->isBlocker());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'construction_unit_id' => $this->constructionUnitId,
            'block' => $this->block,
            'unit' => $this->unit,
            'classification' => $this->classification->value,
            'contract_id' => $this->contractId,
            'contract_code' => $this->contractCode,
            'contract_sale_date' => $this->contractSaleDate?->toDateString(),
            'contract_sale_value' => $this->contractSaleValueCents === null ? null : IntegerMoney::format($this->contractSaleValueCents),
            'unit_reference_value' => $this->unitReferenceValueCents === null ? null : IntegerMoney::format($this->unitReferenceValueCents),
            'unit_reference_value_source' => $this->unitReferenceValueSource?->value,
            'unit_reference_effective_from' => $this->unitReferenceEffectiveFrom?->toDateString(),
            'settlement_state' => $this->settlementState?->value,
            'settlement_installments_total' => $this->settlementInstallmentsTotal,
            'settlement_installments_paid' => $this->settlementInstallmentsPaid,
            'exchange_id' => $this->exchangeId,
            'exchange_value' => $this->exchangeValueCents === null ? null : IntegerMoney::format($this->exchangeValueCents),
            'exchange_effective_from' => $this->exchangeEffectiveFrom?->toDateString(),
            'exchange_ended_on' => $this->exchangeEndedOn?->toDateString(),
            'exchange_kind' => $this->exchangeKind?->value,
            'issues' => array_map(fn (SalesBoardIssue $issue): array => $issue->toArray(), $this->issues),
        ];
    }
}
