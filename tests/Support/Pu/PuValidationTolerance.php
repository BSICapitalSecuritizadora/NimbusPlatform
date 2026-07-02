<?php

declare(strict_types=1);

namespace Tests\Support\Pu;

/**
 * Tolerâncias de validação da curva de PU contra os gabaritos (CDI/IPCA/Prefixado), medidas
 * empiricamente e centralizadas para evitar números mágicos espalhados pelos testes.
 *
 * A engine Nimbus reproduz os gabaritos CDI também em raw-scale desde que passou a arredondar o
 * fator Spread×DI em 9 casas antes do cálculo dos juros nos modos "Exact" (mesma política da engine
 * externa, comprovada linha-a-linha em AMANI 2026-03-02 e TROUPE 2025-06-05). O ruído residual
 * (~1e-8 por unidade; ~1e-4 no valor total) vem do armazenamento em double (IEEE 754) das células
 * do próprio gabarito .xlsx — as tolerâncias abaixo carregam folga de ~10x sobre o pior caso medido.
 * Ver docs/pu-calculator-operacional.md ("Tolerâncias de validação e divergência raw-scale").
 */
final class PuValidationTolerance
{
    /** PU atualizado/residual em display-scale: exato em 6 casas decimais. */
    public const PU_DISPLAY = '0.000001';

    /** Valor total da carteira (PU x quantidade): pior caso medido 1e-4 (ruído float do gabarito). */
    public const TOTAL_VALUE = '0.001000';

    /** Diferença por unidade em raw-scale (16 casas): pior caso medido ~1e-8. */
    public const RAW_UNIT = '0.0000001';

    /** Fatores (DI, spread, acumulado) em raw-scale: muito abaixo de 1e-9. */
    public const FACTOR = '0.000000001';

    /** Escala usada nas comparações bccomp de valores de display. */
    public const DISPLAY_SCALE = 6;

    /** Escala usada nas comparações bccomp de valores raw. */
    public const RAW_SCALE = 16;
}
