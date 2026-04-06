<?php

namespace App\DTOs\Proposals;

use App\Concerns\MoneyFormatter;
use App\DTOs\BaseDTO;

readonly class ProposalContinuationOverviewDTO extends BaseDTO
{
    public function __construct(
        public string $developmentName,
        public ?string $websiteUrl,
        public float $requestedAmount,
        public ?float $landMarketValue,
        public float $landArea,
        public string $launchDate,
        public string $salesLaunchDate,
        public string $constructionStartDate,
        public string $deliveryForecastDate,
        public ?int $remainingMonths,
        public string $zipCode,
        public string $street,
        public ?string $addressComplement,
        public string $addressNumber,
        public string $neighborhood,
        public string $city,
        public string $state,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            developmentName: trim((string) ($data['development_name'] ?? '')),
            websiteUrl: self::nullableString($data['website_url'] ?? null),
            requestedAmount: MoneyFormatter::normalizeDecimalValue($data['requested_amount'] ?? 0),
            landMarketValue: self::nullableDecimal($data['land_market_value'] ?? null),
            landArea: MoneyFormatter::normalizeDecimalValue($data['land_area'] ?? 0),
            launchDate: trim((string) ($data['launch_date'] ?? '')),
            salesLaunchDate: trim((string) ($data['sales_launch_date'] ?? '')),
            constructionStartDate: trim((string) ($data['construction_start_date'] ?? '')),
            deliveryForecastDate: trim((string) ($data['delivery_forecast_date'] ?? '')),
            remainingMonths: self::nullableInteger($data['remaining_months'] ?? null),
            zipCode: trim((string) ($data['zip_code'] ?? '')),
            street: trim((string) ($data['street'] ?? '')),
            addressComplement: self::nullableString($data['address_complement'] ?? null),
            addressNumber: trim((string) ($data['address_number'] ?? '')),
            neighborhood: trim((string) ($data['neighborhood'] ?? '')),
            city: trim((string) ($data['city'] ?? '')),
            state: trim((string) ($data['state'] ?? '')),
        );
    }

    protected static function nullableDecimal(mixed $value): ?float
    {
        return blank($value) ? null : MoneyFormatter::normalizeDecimalValue($value);
    }

    protected static function nullableInteger(mixed $value): ?int
    {
        if (blank($value)) {
            return null;
        }

        return (int) round(MoneyFormatter::normalizeDecimalValue($value));
    }
}
