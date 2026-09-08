<?php

declare(strict_types=1);

namespace App\DTOs\SalesBoards;

use App\DTOs\BaseDTO;
use App\Enums\SalesBoardUnitClassification;
use App\Models\SalesBoardCycleLine;
use App\Support\Money\IntegerMoney;
use Carbon\CarbonImmutable;

/**
 * Uma unidade como a construtora a enxerga.
 *
 * Projetada a partir da linha congelada, e só dela. O que não está aqui está
 * fora de propósito: o comprador não aparece porque a construtora está
 * conferindo unidades e contratos, não uma lista de clientes, e um dado pessoal
 * exposto numa superfície externa é um dado pessoal a mais para proteger sem
 * ganho nenhum para a conferência.
 */
readonly class SalesBoardBuilderWorkspaceUnitRow extends BaseDTO
{
    public function __construct(
        public int $lineId,
        public ?string $block,
        public ?string $unit,
        public SalesBoardUnitClassification $classification,
        public ?string $contractCode,
        public ?CarbonImmutable $saleDate,
        public ?int $saleValueCents,
        public ?int $referenceValueCents,
        public ?string $settlementLabel,
        public ?int $exchangeValueCents,
    ) {}

    public static function fromLine(SalesBoardCycleLine $line): self
    {
        return new self(
            lineId: (int) $line->getKey(),
            block: $line->block,
            unit: $line->unit,
            classification: $line->classification,
            contractCode: $line->contract_code,
            saleDate: $line->contract_sale_date === null
                ? null
                : CarbonImmutable::parse($line->contract_sale_date->toDateString()),
            saleValueCents: IntegerMoney::cents($line->contract_sale_value),
            referenceValueCents: IntegerMoney::cents($line->unit_reference_value),
            settlementLabel: $line->settlement_state === null
                ? null
                : sprintf(
                    '%s (%d de %d)',
                    $line->settlement_state->label(),
                    (int) $line->settlement_installments_paid,
                    (int) $line->settlement_installments_total,
                ),
            exchangeValueCents: IntegerMoney::cents($line->exchange_value),
        );
    }

    public function displayName(): string
    {
        $label = trim(sprintf('%s / %s', (string) $this->block, (string) $this->unit), ' /');

        return $label === '' ? '—' : $label;
    }
}
