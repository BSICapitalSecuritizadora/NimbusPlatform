<?php

declare(strict_types=1);

namespace App\DTOs\SalesBoards;

use App\DTOs\BaseDTO;
use App\Enums\ConstructionUnitExchangeKind;
use App\Enums\ContractSettlementState;
use App\Enums\ResolvedUnitValueSource;
use App\Enums\SalesBoardUnitClassification;
use App\Models\SalesBoardCycleLine;
use App\Support\Money\IntegerMoney;
use App\Support\SalesBoards\CanonicalDigest;
use Carbon\CarbonImmutable;

/**
 * Uma unidade congelada, na forma em que o snapshot a compara e a resume.
 *
 * Existe uma única representação porque a comparação precisa ser possível entre
 * coisas de naturezas diferentes: a V1 gravada há três meses contra a V2 gravada
 * hoje, e qualquer uma das duas contra o que a fonte viva produziria agora. Sem
 * uma forma comum, cada par dessas exigiria o seu próprio comparador, e três
 * comparadores acabam discordando.
 */
readonly class SalesBoardSnapshotLine extends BaseDTO
{
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
    ) {}

    public static function fromDerived(SalesBoardDerivedLine $line): self
    {
        return new self(
            constructionUnitId: $line->constructionUnitId,
            block: $line->block,
            unit: $line->unit,
            classification: $line->classification,
            contractId: $line->contractId,
            contractCode: $line->contractCode,
            contractSaleDate: $line->contractSaleDate,
            contractSaleValueCents: $line->contractSaleValueCents,
            unitReferenceValueCents: $line->unitReferenceValueCents,
            unitReferenceValueSource: $line->unitReferenceValueSource,
            unitReferenceEffectiveFrom: $line->unitReferenceEffectiveFrom,
            settlementState: $line->settlementState,
            settlementInstallmentsTotal: $line->settlementInstallmentsTotal,
            settlementInstallmentsPaid: $line->settlementInstallmentsPaid,
            exchangeId: $line->exchangeId,
            exchangeValueCents: $line->exchangeValueCents,
            exchangeEffectiveFrom: $line->exchangeEffectiveFrom,
            exchangeEndedOn: $line->exchangeEndedOn,
            exchangeKind: $line->exchangeKind,
        );
    }

    public static function fromPersisted(SalesBoardCycleLine $line): self
    {
        $date = static fn (mixed $value): ?CarbonImmutable => $value === null
            ? null
            : CarbonImmutable::parse($value->toDateString());

        return new self(
            constructionUnitId: (int) $line->construction_unit_id,
            block: $line->block,
            unit: $line->unit,
            classification: $line->classification,
            contractId: $line->contract_id === null ? null : (int) $line->contract_id,
            contractCode: $line->contract_code,
            contractSaleDate: $date($line->contract_sale_date),
            contractSaleValueCents: IntegerMoney::cents($line->contract_sale_value),
            unitReferenceValueCents: IntegerMoney::cents($line->unit_reference_value),
            unitReferenceValueSource: $line->unit_reference_value_source,
            unitReferenceEffectiveFrom: $date($line->unit_reference_value_effective_from),
            settlementState: $line->settlement_state,
            settlementInstallmentsTotal: $line->settlement_installments_total,
            settlementInstallmentsPaid: $line->settlement_installments_paid,
            exchangeId: $line->construction_unit_exchange_id === null ? null : (int) $line->construction_unit_exchange_id,
            exchangeValueCents: IntegerMoney::cents($line->exchange_value),
            exchangeEffectiveFrom: $date($line->exchange_effective_from),
            exchangeEndedOn: $date($line->exchange_ended_on),
            exchangeKind: $line->exchange_kind,
        );
    }

    /**
     * Quanto esta linha contribui para o valor do seu balde.
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

    public function displayName(): string
    {
        return trim(sprintf('%s / %s', (string) $this->block, (string) $this->unit), ' /');
    }

    /**
     * A linha reduzida à sua forma canônica.
     *
     * Tudo o que foi congelado entra, não só a classificação: uma venda cujo
     * valor mudou de 950.000 para 960.000 continua financiada, e um resumo que
     * olhasse apenas o balde diria que nada aconteceu.
     */
    public function canonicalRow(): string
    {
        return CanonicalDigest::row([
            $this->constructionUnitId,
            $this->block,
            $this->unit,
            $this->classification,
            $this->contractId,
            $this->contractCode,
            $this->contractSaleDate,
            $this->contractSaleValueCents,
            $this->unitReferenceValueCents,
            $this->unitReferenceValueSource,
            $this->unitReferenceEffectiveFrom,
            $this->settlementState,
            $this->settlementInstallmentsTotal,
            $this->settlementInstallmentsPaid,
            $this->exchangeId,
            $this->exchangeValueCents,
            $this->exchangeEffectiveFrom,
            $this->exchangeEndedOn,
            $this->exchangeKind,
        ]);
    }

    public function fingerprint(): string
    {
        return CanonicalDigest::of(['line' => [$this->canonicalRow()]]);
    }
}
