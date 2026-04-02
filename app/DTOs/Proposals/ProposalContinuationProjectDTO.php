<?php

namespace App\DTOs\Proposals;

use App\Models\ProposalProject;

readonly class ProposalContinuationProjectDTO
{
    public function __construct(
        public ?int $id,
        public string $name,
        public int $exchangedUnits,
        public int $paidUnits,
        public int $unpaidUnits,
        public int $stockUnits,
        public ?float $incurredCost,
        public ?float $costToIncur,
        public ?float $paidSalesValue,
        public ?float $unpaidSalesValue,
        public ?float $stockSalesValue,
        public ?float $receivedValue,
        public ?float $valueUntilKeys,
        public ?float $valueAfterKeys,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: self::nullableInteger($data['id'] ?? null),
            name: trim((string) ($data['name'] ?? '')),
            exchangedUnits: self::integer($data['exchanged_units'] ?? 0),
            paidUnits: self::integer($data['paid_units'] ?? 0),
            unpaidUnits: self::integer($data['unpaid_units'] ?? 0),
            stockUnits: self::integer($data['stock_units'] ?? 0),
            incurredCost: self::nullableDecimal($data['incurred_cost'] ?? null),
            costToIncur: self::nullableDecimal($data['cost_to_incur'] ?? null),
            paidSalesValue: self::nullableDecimal($data['paid_sales_value'] ?? null),
            unpaidSalesValue: self::nullableDecimal($data['unpaid_sales_value'] ?? null),
            stockSalesValue: self::nullableDecimal($data['stock_sales_value'] ?? null),
            receivedValue: self::nullableDecimal($data['received_value'] ?? null),
            valueUntilKeys: self::nullableDecimal($data['value_until_keys'] ?? null),
            valueAfterKeys: self::nullableDecimal($data['value_after_keys'] ?? null),
        );
    }

    protected static function integer(mixed $value): int
    {
        return (int) round(ProposalProject::normalizeDecimalValue($value));
    }

    protected static function nullableInteger(mixed $value): ?int
    {
        if (blank($value)) {
            return null;
        }

        return self::integer($value);
    }

    protected static function nullableDecimal(mixed $value): ?float
    {
        return blank($value) ? null : ProposalProject::normalizeDecimalValue($value);
    }
}
