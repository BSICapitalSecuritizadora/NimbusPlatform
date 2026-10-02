<?php

namespace App\Enums;

use App\Events\PuCalculator\EmissionPuSourceChanged;

/**
 * O que mudou a fonte de PU de uma Emissão -- e, com ela, o saldo devedor que as
 * garantias usam.
 *
 * São os três atos do app que trocam a fonte de uma vez e disparam
 * {@see EmissionPuSourceChanged}. O resto (extensão diária da curva, projeção
 * legada, edição do Histórico de PU, integralização) é pego pela verificação
 * diária das competências encerradas.
 */
enum PuSourceChange: string
{
    case CurveHomologated = 'curve_homologated';

    case CurveInvalidated = 'curve_invalidated';

    case HistoryImported = 'history_imported';

    /**
     * O motivo gravado na competência marcada: "Curva de PU v7 homologada".
     */
    public function describe(?string $calculationVersion = null): string
    {
        $curve = filled($calculationVersion) ? 'Curva de PU '.$calculationVersion : 'Curva de PU';

        return match ($this) {
            self::CurveHomologated => $curve.' homologada',
            self::CurveInvalidated => $curve.' invalidada',
            self::HistoryImported => 'Histórico de PU importado',
        };
    }
}
