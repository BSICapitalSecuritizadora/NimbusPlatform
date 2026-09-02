<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

final readonly class PuCandidateCurve
{
    /**
     * @param  list<PuDailyCurveRowData>  $rows
     * @param  list<array<string, mixed>>  $checkpoints
     */
    public function __construct(
        public array $rows,
        public string $checksum,
        public int $rowCount,
        public ?string $from,
        public ?string $to,
        public ?string $initialUnitValue,
        public ?string $lastUnitValue,
        public array $checkpoints,
    ) {}

    /** @return array<string, mixed> */
    public function summary(): array
    {
        return [
            'generated' => true,
            'persistence' => 'in_memory_only',
            'rows' => $this->rowCount,
            'from' => $this->from,
            'to' => $this->to,
            'initial_unit_value' => $this->initialUnitValue,
            'last_unit_value' => $this->lastUnitValue,
            'curve_checksum' => $this->checksum,
            'checkpoints' => $this->checkpoints,
        ];
    }
}
