<?php

namespace App\DTOs\Measurements;

use App\Enums\MeasurementResponsibility;

final readonly class MeasurementCurrentWorkload
{
    public function __construct(
        public ?int $responsibleId,
        public string $responsibleName,
        public MeasurementResponsibility $responsibility,
        public int $stage,
        public int $pendingCount,
        public int $overdueCount,
        public ?int $delegatedCount = null,
    ) {}
}
