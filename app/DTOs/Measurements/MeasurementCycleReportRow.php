<?php

namespace App\DTOs\Measurements;

use App\Enums\MeasurementHistoryCompleteness;
use App\Enums\MeasurementResponsibility;
use App\Enums\MeasurementStageExitReason;
use Carbon\CarbonImmutable;

final readonly class MeasurementCycleReportRow
{
    /** @param list<string> $missingReasons */
    public function __construct(
        public int $measurementId,
        public string $measurementLabel,
        public int $operationId,
        public string $operationLabel,
        public ?int $emissionId,
        public string $emissionLabel,
        public ?string $referenceMonth,
        public int $stage,
        public int $sequence,
        public ?CarbonImmutable $enteredAt,
        public ?CarbonImmutable $exitedAt,
        public MeasurementStageExitReason $exitReason,
        public ?int $calendarDuration,
        public ?int $pausedDuration,
        public ?int $activeDuration,
        public ?int $actorId,
        public ?string $actorName,
        public ?MeasurementResponsibility $responsibility,
        public ?int $expectedResponsibleId,
        public ?string $expectedResponsibleName,
        public ?bool $delegated,
        public ?int $delegatorId,
        public ?string $delegatorName,
        public ?bool $adminOverride,
        public MeasurementHistoryCompleteness $completeness,
        public array $missingReasons,
    ) {}

    public function isDecision(): bool
    {
        return in_array($this->exitReason, [
            MeasurementStageExitReason::Approved,
            MeasurementStageExitReason::RejectedTerminal,
            MeasurementStageExitReason::ReturnedByRejection,
            MeasurementStageExitReason::ReturnedFromFinalization,
            MeasurementStageExitReason::Finalized,
        ], true);
    }
}
