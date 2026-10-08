<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

/**
 * Quem responde pelo valor esperado de um componente.
 *
 *  - `OfficialCurve`: a curva homologada vigente calculou;
 *  - `InformedSchedule`: o Cronograma de Pagamentos informado (planilha ou
 *    cadastro manual) trouxe o valor -- a curva não o calcula.
 */
enum PuObligationComponentOwner: string
{
    case OfficialCurve = 'official_curve';
    case InformedSchedule = 'informed_schedule';

    public function label(): string
    {
        return match ($this) {
            self::OfficialCurve => 'Curva oficial',
            self::InformedSchedule => 'Cronograma informado',
        };
    }
}
