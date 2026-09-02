<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

final readonly class PuNumericHomologationPlan
{
    /**
     * @param  array<string, mixed>  $parameterSnapshot
     * @param  array<string, mixed>  $calendarWindow
     * @param  array<string, mixed>  $rateWindow
     * @param  list<string>  $requiredRateDates
     * @param  list<array<string, mixed>>  $rates
     * @param  list<array<string, mixed>>  $events
     * @param  array<string, mixed>  $inputPayload
     * @param  array<string, mixed>  $existingCurves
     * @param  array<string, mixed>  $externalReference
     */
    public function __construct(
        public int $emissionId,
        public string $asOf,
        public string $readiness,
        public string $requiredReadiness,
        public string $action,
        public string $reason,
        public bool $canEvaluate,
        public ?int $parameterId,
        public array $parameterSnapshot,
        public ?string $curveStartDate,
        public ?string $curveEndDate,
        public ?string $homologationEndDate,
        public array $calendarWindow,
        public array $rateWindow,
        public array $requiredRateDates,
        public array $rates,
        public array $events,
        public ?string $inputFingerprint,
        public array $inputPayload,
        public array $existingCurves,
        public array $externalReference,
        public int $writes = 0,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'emission_id' => $this->emissionId,
            'as_of' => $this->asOf,
            'readiness' => $this->readiness,
            'required_readiness' => $this->requiredReadiness,
            'action' => $this->action,
            'reason' => $this->reason,
            'can_evaluate' => $this->canEvaluate,
            'parameter_id' => $this->parameterId,
            'parameter_snapshot' => $this->parameterSnapshot,
            'curve_start_date' => $this->curveStartDate,
            'curve_end_date' => $this->curveEndDate,
            'homologation_end_date' => $this->homologationEndDate,
            'calendar_window' => $this->calendarWindow,
            'rate_window' => $this->rateWindow,
            'required_rate_dates' => $this->requiredRateDates,
            'rates' => $this->rates,
            'events' => $this->events,
            'input_fingerprint' => $this->inputFingerprint,
            'input_payload' => $this->inputPayload,
            'existing_curves' => $this->existingCurves,
            'external_reference' => $this->externalReference,
            'writes' => $this->writes,
        ];
    }
}
