<?php

declare(strict_types=1);

namespace App\DTOs\SalesBoards;

use App\DTOs\BaseDTO;
use App\Enums\SalesBoardBuilderDivergenceType;
use App\Enums\SalesBoardUnitClassification;
use App\Support\Money\IntegerMoney;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * O que a construtora está prestes a declarar, antes de ser validado.
 *
 * Existe para que a validação por tipo aconteça sobre uma estrutura tipada, e
 * não sobre um array solto vindo de um formulário. O que o formulário entrega é
 * texto -- "1.020.000,00", "15/07/2026" -- e a conversão para centavos e data
 * civil acontece aqui, uma vez, em vez de espalhada por cada serviço que fosse
 * ler o array.
 */
readonly class SalesBoardBuilderDivergenceInput extends BaseDTO
{
    public function __construct(
        public SalesBoardBuilderDivergenceType $type,
        public string $reason,
        public ?int $lineId = null,
        public ?int $movementId = null,
        public ?int $constructionUnitId = null,
        public ?int $contractId = null,
        public ?string $declaredBlock = null,
        public ?string $declaredUnit = null,
        public ?string $declaredContractCode = null,
        public ?int $declaredValueCents = null,
        public ?CarbonImmutable $declaredDate = null,
        public ?SalesBoardUnitClassification $declaredClassification = null,
    ) {}

    /**
     * Monta a declaração a partir do que um formulário entrega.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $trim = static fn (string $key): ?string => self::nullableString($data[$key] ?? null);

        return new self(
            type: $data['type'] instanceof SalesBoardBuilderDivergenceType
                ? $data['type']
                : SalesBoardBuilderDivergenceType::from((string) $data['type']),
            reason: trim((string) ($data['reason'] ?? '')),
            lineId: self::nullableInt($data['sales_board_cycle_line_id'] ?? null),
            movementId: self::nullableInt($data['sales_board_cycle_movement_id'] ?? null),
            constructionUnitId: self::nullableInt($data['construction_unit_id'] ?? null),
            contractId: self::nullableInt($data['contract_id'] ?? null),
            declaredBlock: $trim('declared_block'),
            declaredUnit: $trim('declared_unit'),
            declaredContractCode: $trim('declared_contract_code'),
            declaredValueCents: IntegerMoney::cents($data['declared_value'] ?? null),
            declaredDate: self::nullableDate($data['declared_date'] ?? null),
            declaredClassification: self::nullableClassification($data['declared_classification'] ?? null),
        );
    }

    /**
     * Os campos declarados que vieram preenchidos.
     *
     * @return list<string>
     */
    public function providedDeclarations(): array
    {
        return array_values(array_filter([
            $this->declaredBlock === null ? null : 'declared_block',
            $this->declaredUnit === null ? null : 'declared_unit',
            $this->declaredContractCode === null ? null : 'declared_contract_code',
            $this->declaredValueCents === null ? null : 'declared_value',
            $this->declaredDate === null ? null : 'declared_date',
            $this->declaredClassification === null ? null : 'declared_classification',
        ]));
    }

    public function hasAnchor(): bool
    {
        return ($this->lineId !== null) || ($this->movementId !== null);
    }

    /**
     * As colunas persistidas, já canonizadas.
     *
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'type' => $this->type,
            'sales_board_cycle_line_id' => $this->lineId,
            'sales_board_cycle_movement_id' => $this->movementId,
            'construction_unit_id' => $this->constructionUnitId,
            'contract_id' => $this->contractId,
            'declared_block' => $this->declaredBlock,
            'declared_unit' => $this->declaredUnit,
            'declared_contract_code' => $this->declaredContractCode,
            'declared_value' => $this->declaredValueCents === null
                ? null
                : IntegerMoney::decimalString($this->declaredValueCents),
            'declared_date' => $this->declaredDate?->toDateString(),
            'declared_classification' => $this->declaredClassification,
            'reason' => $this->reason,
        ];
    }

    private static function nullableInt(mixed $value): ?int
    {
        return blank($value) ? null : (int) $value;
    }

    private static function nullableDate(mixed $value): ?CarbonImmutable
    {
        if (blank($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    private static function nullableClassification(mixed $value): ?SalesBoardUnitClassification
    {
        if ($value instanceof SalesBoardUnitClassification) {
            return $value;
        }

        return blank($value) ? null : SalesBoardUnitClassification::tryFrom((string) $value);
    }
}
