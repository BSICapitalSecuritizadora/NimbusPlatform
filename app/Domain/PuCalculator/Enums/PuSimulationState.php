<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

/**
 * Estado de uma simulação de PU.
 *
 * Deliberadamente NÃO é readiness operacional: nenhum destes estados fala de
 * candidate, review, validação externa ou promoção. Só bloqueia a simulação o
 * que é matematicamente impeditivo -- input ausente, taxa exigida ausente,
 * calendário irresolvível ou erro da engine.
 */
enum PuSimulationState: string
{
    case MissingInput = 'missing_input';
    case CalendarIncomplete = 'calendar_incomplete';
    case RatesMissing = 'rates_missing';
    case CalculationFailed = 'calculation_failed';
    case Calculated = 'calculated';

    public function label(): string
    {
        return match ($this) {
            self::MissingInput => 'Parâmetros incompletos',
            self::CalendarIncomplete => 'Calendário incompleto',
            self::RatesMissing => 'Taxas CDI ausentes',
            self::CalculationFailed => 'Falha no cálculo',
            self::Calculated => 'Simulação calculada',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::MissingInput, self::CalendarIncomplete, self::RatesMissing => 'warning',
            self::CalculationFailed => 'danger',
            self::Calculated => 'success',
        };
    }

    public function calculated(): bool
    {
        return $this === self::Calculated;
    }
}
