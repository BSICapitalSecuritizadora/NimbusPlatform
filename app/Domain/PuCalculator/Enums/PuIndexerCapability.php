<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

/**
 * O que um indexador pode fazer na operação do PU (Fase 6).
 *
 * Simular não grava nada; os demais são os degraus que levam um cálculo ao PU
 * oficial e às obrigações financeiras. Um indexador implementado na engine não
 * ganha nenhum degrau operacional por isso: a homologação do indexador é uma
 * decisão própria ({@see PuIndexer::isHomologated()}).
 */
enum PuIndexerCapability: string
{
    /** Cálculo em memória (simulação, conferência contra gabarito). Nunca grava curva. */
    case Simulation = 'simulation';

    /** Gravar uma versão de curva (de trabalho, candidata ou reprocessamento). */
    case CurveGeneration = 'curve_generation';

    /** Registrar o resultado da validação contra planilha numa versão operacional. */
    case Validation = 'validation';

    /** Homologar: o único ato que torna uma curva oficial. */
    case Homologation = 'homologation';

    /** Estender a curva oficial com o índice realizado. */
    case OfficialExtension = 'official_extension';

    /** Calcular o valor esperado das obrigações financeiras a partir da curva oficial. */
    case FinancialObligations = 'financial_obligations';

    public function label(): string
    {
        return match ($this) {
            self::Simulation => 'Simulação',
            self::CurveGeneration => 'Geração de curva',
            self::Validation => 'Validação contra planilha',
            self::Homologation => 'Homologação (PU oficial)',
            self::OfficialExtension => 'Extensão da curva oficial',
            self::FinancialObligations => 'Obrigações financeiras',
        };
    }

    /**
     * Degraus que produzem ou consomem dado operacional (tudo menos simular).
     */
    public function isOperational(): bool
    {
        return $this !== self::Simulation;
    }
}
