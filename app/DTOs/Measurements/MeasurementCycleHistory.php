<?php

namespace App\DTOs\Measurements;

use App\Enums\MeasurementHistoryCompleteness;
use App\Enums\MeasurementStageExitReason;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Support\Arrayable;

/** @implements Arrayable<string, mixed> */
final readonly class MeasurementCycleHistory implements Arrayable
{
    /**
     * @param  list<MeasurementCycleEvent>  $events
     * @param  list<MeasurementStageVisit>  $stageVisits
     * @param  list<MeasurementPauseInterval>  $pauses
     * @param  list<MeasurementCyclePayment>  $payments
     * @param  list<string>  $warnings
     */
    public function __construct(
        public int $measurementId,
        public int $operationId,
        public ?string $referenceMonth,
        public ?string $measurementLabel,
        public string $currentStatus,
        public int $currentStage,
        public array $events,
        public array $stageVisits,
        public array $pauses,
        public array $payments,
        public ?CarbonImmutable $cycleStart,
        public ?CarbonImmutable $cycleEnd,
        public ?MeasurementStageExitReason $terminalReason,
        public MeasurementHistoryCompleteness $completeness,
        public array $warnings,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'measurement_id' => $this->measurementId,
            'operation_id' => $this->operationId,
            'reference_month' => $this->referenceMonth,
            'measurement_label' => $this->measurementLabel,
            'current_status' => $this->currentStatus,
            'current_stage' => $this->currentStage,
            'events' => array_map(
                fn (MeasurementCycleEvent $event): array => $event->toArray(),
                $this->events,
            ),
            'stage_visits' => array_map(
                fn (MeasurementStageVisit $visit): array => $visit->toArray(),
                $this->stageVisits,
            ),
            'pauses' => array_map(
                fn (MeasurementPauseInterval $pause): array => $pause->toArray(),
                $this->pauses,
            ),
            'payments' => array_map(
                fn (MeasurementCyclePayment $payment): array => $payment->toArray(),
                $this->payments,
            ),
            'cycle_start' => $this->cycleStart?->toIso8601String(),
            'cycle_end' => $this->cycleEnd?->toIso8601String(),
            'terminal_reason' => $this->terminalReason?->value,
            'completeness' => $this->completeness->value,
            'warnings' => $this->warnings,
        ];
    }
}
