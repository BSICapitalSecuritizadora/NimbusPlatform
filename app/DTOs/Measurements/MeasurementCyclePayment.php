<?php

namespace App\DTOs\Measurements;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Support\Arrayable;

/** @implements Arrayable<string, mixed> */
final readonly class MeasurementCyclePayment implements Arrayable
{
    public function __construct(
        public int $id,
        public int $measurementId,
        public string $amount,
        public ?CarbonImmutable $payDate,
        public ?string $method,
        public ?int $createdById,
        public ?CarbonImmutable $createdAt,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'measurement_id' => $this->measurementId,
            'amount' => $this->amount,
            'pay_date' => $this->payDate?->toDateString(),
            'method' => $this->method,
            'created_by_id' => $this->createdById,
            'created_at' => $this->createdAt?->toIso8601String(),
        ];
    }
}
