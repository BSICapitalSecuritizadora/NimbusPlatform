<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

final readonly class PuNumericPreparationPlan
{
    /**
     * @param  array<string, mixed>  $parameterProvenance
     * @param  array<string, mixed>  $calendarWindow
     * @param  array<string, mixed>  $rateWindow
     * @param  array<string, mixed>  $rateSourceApproval
     * @param  list<string>  $requiredRateDates
     * @param  list<array<string, mixed>>  $presentRates
     * @param  list<string>  $missingRateDates
     * @param  list<array<string, mixed>>  $conflictingRates
     * @param  list<array<string, mixed>>  $eventRequirements
     * @param  list<array<string, mixed>>  $existingEvents
     * @param  list<array<string, mixed>>  $presentEvents
     * @param  list<array<string, mixed>>  $missingEvents
     * @param  list<array<string, mixed>>  $conflictingEvents
     * @param  array<string, int|bool>  $financialEffects
     * @param  list<string>  $blockingRequirements
     */
    public function __construct(
        public int $emissionId,
        public string $state,
        public string $action,
        public string $reason,
        public string $readinessBefore,
        public string $readinessAfterHypothetical,
        public bool $financialPreparationReady,
        public ?int $parameterId,
        public ?string $parameterFingerprint,
        public array $parameterProvenance,
        public ?string $curveStartDate,
        public ?string $curveEndDate,
        public ?string $homologationEndDate,
        public array $calendarWindow,
        public array $rateWindow,
        public ?string $rateSource,
        public ?string $rateSourceReference,
        public array $rateSourceApproval,
        public array $requiredRateDates,
        public array $presentRates,
        public array $missingRateDates,
        public array $conflictingRates,
        public array $eventRequirements,
        public array $existingEvents,
        public array $presentEvents,
        public array $missingEvents,
        public array $conflictingEvents,
        public array $financialEffects,
        public array $blockingRequirements,
        public int $writes = 0,
    ) {}

    public function hasFinancialEffects(): bool
    {
        return (bool) ($this->financialEffects['guard_blocking'] ?? false);
    }

    public function canPrepareRates(): bool
    {
        return $this->financialPreparationReady
            && $this->parameterId !== null
            && $this->missingRateDates !== []
            && $this->conflictingRates === []
            && ! $this->hasFinancialEffects();
    }

    public function canPrepareEvents(): bool
    {
        return $this->financialPreparationReady
            && $this->parameterId !== null
            && $this->missingEvents !== []
            && $this->conflictingEvents === []
            && ! $this->hasFinancialEffects();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'emission_id' => $this->emissionId,
            'state' => $this->state,
            'action' => $this->action,
            'reason' => $this->reason,
            'readiness_before' => $this->readinessBefore,
            'readiness_after_hypothetical' => $this->readinessAfterHypothetical,
            'financial_preparation_ready' => $this->financialPreparationReady,
            'parameter_id' => $this->parameterId,
            'parameter_fingerprint' => $this->parameterFingerprint,
            'parameter_provenance' => $this->parameterProvenance,
            'curve_start_date' => $this->curveStartDate,
            'curve_end_date' => $this->curveEndDate,
            'homologation_end_date' => $this->homologationEndDate,
            'calendar_window' => $this->calendarWindow,
            'rate_window' => $this->rateWindow,
            'rate_source' => $this->rateSource,
            'rate_source_reference' => $this->rateSourceReference,
            'rate_source_approval' => $this->rateSourceApproval,
            'required_rate_dates' => $this->requiredRateDates,
            'present_rate_dates' => array_column($this->presentRates, 'date'),
            'present_rates' => $this->presentRates,
            'missing_rate_dates' => $this->missingRateDates,
            'conflicting_rate_dates' => array_column($this->conflictingRates, 'date'),
            'conflicting_rates' => $this->conflictingRates,
            'event_requirements' => $this->eventRequirements,
            'existing_events' => $this->existingEvents,
            'present_events' => $this->presentEvents,
            'missing_events' => $this->missingEvents,
            'conflicting_events' => $this->conflictingEvents,
            'financial_effects' => $this->financialEffects,
            'blocking_requirements' => $this->blockingRequirements,
            'writes' => $this->writes,
        ];
    }
}
