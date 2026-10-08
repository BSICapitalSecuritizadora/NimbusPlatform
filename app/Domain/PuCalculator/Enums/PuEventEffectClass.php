<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

/**
 * Natureza do efeito de um evento contratual sobre a curva.
 *
 * - `Payment`: valor devido numa data (juros, amortização, prêmio, encargo);
 * - `TermsAmendment`: muda a regra de cálculo a partir da data (spread, indexador,
 *   vencimento, cronograma);
 * - `Regime`: muda o regime por um intervalo (carência, waiver, mora, cura);
 * - `Lifecycle`: muda o destino da operação (vencimento antecipado, liquidação,
 *   encerramento).
 *
 * Evento contratual não é obrigação financeira: o pagamento devido e liquidado é
 * do Cronograma de Pagamentos (Fase 5).
 */
enum PuEventEffectClass: string
{
    case Payment = 'payment';
    case TermsAmendment = 'terms_amendment';
    case Regime = 'regime';
    case Lifecycle = 'lifecycle';
}
