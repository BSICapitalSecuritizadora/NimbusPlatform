<?php

namespace App\DTOs\Measurements;

use App\Enums\MeasurementHistoryCompleteness;
use App\Enums\MeasurementStageExitReason;
use Carbon\CarbonImmutable;

final readonly class MeasurementCycleReportFilters
{
    public function __construct(
        public ?CarbonImmutable $periodFrom = null,
        public ?CarbonImmutable $periodTo = null,
        public ?int $operationId = null,
        public ?int $emissionId = null,
        public ?int $measurementId = null,
        public ?int $stage = null,
        public ?MeasurementStageExitReason $decisionType = null,
        public ?int $actorId = null,
        public ?int $expectedResponsibleId = null,
        public ?MeasurementHistoryCompleteness $completeness = null,
    ) {}

    /** @param array<string, mixed> $input */
    public static function fromArray(array $input): self
    {
        return new self(
            periodFrom: self::date($input['period_from'] ?? null, endOfDay: false),
            periodTo: self::date($input['period_to'] ?? null, endOfDay: true),
            operationId: self::positiveInteger($input['operation_id'] ?? null),
            emissionId: self::positiveInteger($input['emission_id'] ?? null),
            measurementId: self::positiveInteger($input['measurement_id'] ?? null),
            stage: self::stage($input['stage'] ?? null),
            decisionType: is_string($input['decision_type'] ?? null)
                ? MeasurementStageExitReason::tryFrom($input['decision_type'])
                : null,
            actorId: self::positiveInteger($input['actor_id'] ?? null),
            expectedResponsibleId: self::positiveInteger($input['expected_responsible_id'] ?? null),
            completeness: is_string($input['completeness'] ?? null)
                ? MeasurementHistoryCompleteness::tryFrom($input['completeness'])
                : null,
        );
    }

    /** @return array<string, int|string> */
    public function toQuery(): array
    {
        return array_filter([
            'period_from' => $this->periodFrom?->toDateString(),
            'period_to' => $this->periodTo?->toDateString(),
            'operation_id' => $this->operationId,
            'emission_id' => $this->emissionId,
            'measurement_id' => $this->measurementId,
            'stage' => $this->stage,
            'decision_type' => $this->decisionType?->value,
            'actor_id' => $this->actorId,
            'expected_responsible_id' => $this->expectedResponsibleId,
            'completeness' => $this->completeness?->value,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /** @return array<string, int|string> */
    public function sanitizedAuditContext(): array
    {
        return $this->toQuery();
    }

    private static function positiveInteger(mixed $value): ?int
    {
        $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $validated === false ? null : (int) $validated;
    }

    private static function stage(mixed $value): ?int
    {
        $stage = self::positiveInteger($value);

        return $stage !== null && $stage <= 5 ? $stage : null;
    }

    private static function date(mixed $value, bool $endOfDay): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            return null;
        }

        if (! $date instanceof CarbonImmutable || $date->toDateString() !== $value) {
            return null;
        }

        return $endOfDay ? $date->endOfDay() : $date->startOfDay();
    }
}
