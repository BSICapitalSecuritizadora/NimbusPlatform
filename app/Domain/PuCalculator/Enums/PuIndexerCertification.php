<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

/**
 * Certificação financeira de um indexador para uso operacional (Fase 7).
 *
 * Implementar a engine não certifica: certificar exige referência independente
 * (gabarito externo, exemplo contratual, comparação com o sistema de origem) das
 * convenções do indexador. `conditionally_certified` é a engine validada contra
 * referência independente com verificações de implantação ainda pendentes; só ela
 * e `certified` chegam ao PU oficial. O resto só simula.
 */
enum PuIndexerCertification: string
{
    case Certified = 'certified';
    case ConditionallyCertified = 'conditionally_certified';
    case NotCertified = 'not_certified';

    public function label(): string
    {
        return match ($this) {
            self::Certified => 'Certificado',
            self::ConditionallyCertified => 'Certificado com condições',
            self::NotCertified => 'Não certificado (só simulação)',
        };
    }

    public function allowsOperationalUse(): bool
    {
        return $this !== self::NotCertified;
    }
}
