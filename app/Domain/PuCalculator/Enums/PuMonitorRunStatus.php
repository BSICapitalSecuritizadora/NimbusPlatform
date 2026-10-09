<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

/**
 * Resultado de uma execução do monitor do PU (Fase 6). "Concluída" quer dizer que
 * TODAS as verificações rodaram -- é diferente de "o monitor não rodou" e de
 * "rodou em parte": incidente de uma verificação que falhou não é resolvido.
 */
enum PuMonitorRunStatus: string
{
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Partial = 'partial';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Running => 'Em execução',
            self::Succeeded => 'Concluída: todas as verificações rodaram',
            self::Partial => 'Parcial: alguma verificação falhou',
            self::Failed => 'Falhou',
        };
    }
}
