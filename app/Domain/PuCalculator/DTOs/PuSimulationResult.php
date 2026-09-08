<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

use App\Domain\PuCalculator\Enums\PuSimulationState;
use Carbon\CarbonImmutable;

/**
 * Resultado de uma simulação de PU.
 *
 * NÃO é candidate, não é curva operacional e não carrega review, validação
 * externa ou promoção: é um retorno de sandbox. Todos os valores financeiros
 * são strings decimais produzidas pela engine oficial.
 */
final readonly class PuSimulationResult
{
    /**
     * @param  array<string, mixed>  $parameters  configuração resolvida usada na simulação
     * @param  array<string, string>  $origins  origem de cada parâmetro
     * @param  list<string>  $missingFields
     * @param  list<string>  $requiredRateDates
     * @param  list<string>  $missingRateDates
     * @param  list<array<string, mixed>>  $conflictingRates
     * @param  array<string, mixed>  $calendarDiagnostics
     * @param  list<array<string, mixed>>  $events
     * @param  array<string, mixed>  $scheduleDiagnostics
     * @param  list<PuDailyCurveRowData>  $rows
     * @param  array<string, mixed>  $premium
     */
    public function __construct(
        public PuSimulationState $state,
        public string $reason,
        public PuSimulationInput $input,
        public array $parameters = [],
        public array $origins = [],
        public array $missingFields = [],
        public ?CarbonImmutable $startDate = null,
        public ?CarbonImmutable $endDate = null,
        public array $requiredRateDates = [],
        public array $missingRateDates = [],
        public array $conflictingRates = [],
        public array $calendarDiagnostics = [],
        public array $events = [],
        public array $scheduleDiagnostics = [],
        public array $rows = [],
        public ?PuDailyCurveRowData $selectedRow = null,
        public array $premium = [],
    ) {}

    public function calculated(): bool
    {
        return $this->state->calculated();
    }

    /**
     * PU unitário atualizado na data selecionada. É o resultado principal da
     * tela e vem direto da engine, sem rearredondamento.
     */
    public function selectedUnitValue(): ?string
    {
        return $this->selectedRow?->updatedUnitValue;
    }

    public function selectedResidualUnitValue(): ?string
    {
        return $this->selectedRow?->residualUnitValue;
    }

    public function rowCount(): int
    {
        return count($this->rows);
    }

    public function firstRow(): ?PuDailyCurveRowData
    {
        return $this->rows[0] ?? null;
    }

    public function lastRow(): ?PuDailyCurveRowData
    {
        return $this->rows === [] ? null : $this->rows[array_key_last($this->rows)];
    }

    public function rowForDate(string $date): ?PuDailyCurveRowData
    {
        foreach ($this->rows as $row) {
            if ($row->date->toDateString() === $date) {
                return $row;
            }
        }

        return null;
    }

    /** @return list<PuDailyCurveRowData> */
    public function paymentRows(): array
    {
        return array_values(array_filter(
            $this->rows,
            static fn (PuDailyCurveRowData $row): bool => $row->hasPayment(),
        ));
    }

    /**
     * Posição financeira total na data selecionada. Só existe quando o usuário
     * informou uma quantidade de simulação: o PU unitário nunca depende dela.
     */
    public function selectedTotalValue(): ?string
    {
        if ($this->selectedRow === null || $this->input->quantity === null) {
            return null;
        }

        return $this->selectedRow->totalValue;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'state' => $this->state->value,
            'reason' => $this->reason,
            'input' => $this->input->toArray(),
            'parameters' => $this->parameters,
            'origins' => $this->origins,
            'missing_fields' => $this->missingFields,
            'start_date' => $this->startDate?->toDateString(),
            'end_date' => $this->endDate?->toDateString(),
            'required_rate_dates' => $this->requiredRateDates,
            'missing_rate_dates' => $this->missingRateDates,
            'conflicting_rates' => $this->conflictingRates,
            'calendar_diagnostics' => $this->calendarDiagnostics,
            'events' => $this->events,
            'schedule_diagnostics' => $this->scheduleDiagnostics,
            'rows_count' => $this->rowCount(),
            'selected_curve_date' => $this->selectedRow?->date->toDateString(),
            'selected_unit_value' => $this->selectedUnitValue(),
            'premium' => $this->premium,
        ];
    }
}
