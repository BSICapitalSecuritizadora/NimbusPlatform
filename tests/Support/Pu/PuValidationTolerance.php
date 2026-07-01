<?php

declare(strict_types=1);

namespace Tests\Support\Pu;

/**
 * Tolerâncias de validação da curva de PU contra os gabaritos (CDI/IPCA/Prefixado), medidas
 * empiricamente e centralizadas para evitar números mágicos espalhados pelos testes.
 *
 * A engine Nimbus bate MATERIALMENTE os gabaritos. As divergências residuais raw-scale (~1e-7 por
 * unidade) vêm da política de arredondamento POR COLUNA da engine externa — o próprio gabarito é
 * internamente inconsistente nessa ordem de grandeza, logo bit-exatidão NÃO é requisito atual.
 * Ver docs/pu-calculator-operacional.md ("Tolerâncias de validação e divergência raw-scale").
 */
final class PuValidationTolerance
{
    /** PU atualizado/residual em display-scale: exato em 6 casas decimais. */
    public const PU_DISPLAY = '0.000001';

    /** Valor total da carteira (PU x quantidade): divergência sub-centavo. */
    public const TOTAL_VALUE = '0.010000';

    /** Diferença por unidade em raw-scale (16 casas): ruído de arredondamento por coluna. */
    public const RAW_UNIT = '0.000001';

    /** Fatores (DI, spread, acumulado) em raw-scale: muito abaixo de 1e-9. */
    public const FACTOR = '0.000000001';

    /** Escala usada nas comparações bccomp de valores de display. */
    public const DISPLAY_SCALE = 6;

    /** Escala usada nas comparações bccomp de valores raw. */
    public const RAW_SCALE = 16;
}
