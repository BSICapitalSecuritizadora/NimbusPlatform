<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

use App\Domain\PuCalculator\Enums\PuBaselineReadinessStatus;
use InvalidArgumentException;

final readonly class PuBaselineReadinessReport
{
    /**
     * @param  array<string, PuBaselineRequirement>  $requirements
     * @param  array<string, mixed>  $candidateConfiguration
     * @param  list<array<string, mixed>>  $candidateFields
     * @param  list<string>  $pendingFields
     * @param  array<string, mixed>  $calendarDiagnostics
     * @param  array<string, mixed>  $indexSourceDiagnostics
     * @param  array<string, mixed>  $integralizationDiagnostics
     * @param  array<string, mixed>  $quantityDiagnostics
     * @param  array<string, mixed>  $rateWindow
     * @param  array<string, mixed>  $eventDiagnostics
     * @param  list<string>  $limitations
     * @param  list<string>  $nextActions
     */
    public function __construct(
        public int $emissionId,
        public PuBaselineReadinessStatus $status,
        public array $requirements,
        public array $candidateConfiguration,
        public array $candidateFields,
        public array $pendingFields,
        public array $calendarDiagnostics,
        public array $indexSourceDiagnostics,
        public array $integralizationDiagnostics,
        public array $quantityDiagnostics,
        public array $rateWindow,
        public array $eventDiagnostics,
        public array $limitations,
        public array $nextActions,
    ) {}

    public function requirement(string $key): PuBaselineRequirement
    {
        return $this->requirements[$key]
            ?? throw new InvalidArgumentException(sprintf('Requisito de baseline [%s] não existe.', $key));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'emission_id' => $this->emissionId,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'requirements' => collect($this->requirements)
                ->map(fn (PuBaselineRequirement $requirement): array => $requirement->toArray())
                ->all(),
            'candidate_configuration' => $this->candidateConfiguration,
            'candidate_fields' => $this->candidateFields,
            'pending_fields' => $this->pendingFields,
            'calendar' => $this->calendarDiagnostics,
            'index_source' => $this->indexSourceDiagnostics,
            'integralization' => $this->integralizationDiagnostics,
            'quantity' => $this->quantityDiagnostics,
            'rate_window' => $this->rateWindow,
            'events' => $this->eventDiagnostics,
            'limitations' => $this->limitations,
            'next_actions' => $this->nextActions,
        ];
    }
}
