<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

/**
 * Resultado de uma tentativa de sincronização de índice (Fase 6). "Respondeu sem
 * observação nova" é diferente de "trouxe a observação esperada": é por isso que
 * a falta do CDI depois da divulgação esperada continua índice ausente.
 */
enum PuIndexSyncOutcome: string
{
    case NewObservations = 'new_observations';
    case NoNewObservation = 'no_new_observation';
    case PartialFailure = 'partial_failure';
    case RateConflict = 'rate_conflict';
    case HistoricalCorrection = 'historical_correction';
    case Failed = 'failed';

    /**
     * A fonte respondeu (com ou sem observação nova).
     */
    public function reachedProvider(): bool
    {
        return $this !== self::Failed;
    }

    public function label(): string
    {
        return match ($this) {
            self::NewObservations => 'Observações novas gravadas',
            self::NoNewObservation => 'A fonte respondeu sem observação nova',
            self::PartialFailure => 'Parcial: parte dos blocos falhou',
            self::RateConflict => 'Valor divergente do já registrado (exige correção governada)',
            self::HistoricalCorrection => 'Revisão de histórico aplicada pela fonte',
            self::Failed => 'Falhou',
        };
    }
}
