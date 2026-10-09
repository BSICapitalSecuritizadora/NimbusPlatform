<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

/**
 * Natureza da obrigação financeira econômica de uma emissão (Fase 5).
 *
 * A obrigação é o que o contrato diz que é devido numa data -- nunca o valor
 * calculado nem o liquidado. Cada uma nasce do cronograma contratual APROVADO na
 * curva oficial e tem identidade estável: emissão, natureza, data contratual e
 * sequência. Uma versão nova da curva recalcula o valor esperado da mesma
 * obrigação; não cria outra.
 *
 *  - `ScheduledPayment`: pagamento do cronograma ordinário (juros e amortização
 *    ordinários de uma data efetiva, mais o prêmio informado para ela);
 *  - `ExtraordinaryAmortization`: amortização extraordinária declarada por evento
 *    contratual explícito;
 *  - `EarlyMaturity`: obrigação acelerada pelo vencimento antecipado.
 */
enum PuObligationType: string
{
    case ScheduledPayment = 'scheduled_payment';
    case ExtraordinaryAmortization = 'extraordinary_amortization';
    case EarlyMaturity = 'early_maturity';

    public function label(): string
    {
        return match ($this) {
            self::ScheduledPayment => 'Pagamento do cronograma',
            self::ExtraordinaryAmortization => 'Amortização extraordinária',
            self::EarlyMaturity => 'Vencimento antecipado',
        };
    }
}
