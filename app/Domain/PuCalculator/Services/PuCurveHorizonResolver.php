<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\Enums\PuEventType;
use Carbon\CarbonImmutable;

/**
 * Horizonte canônico da curva operacional: o último dia que a engine calcula.
 *
 * Contribuem, nesta ordem de leitura:
 *
 *  1. o vencimento contratual (`curve_end_date` dos parâmetros);
 *  2. a data efetiva de cada pagamento do cronograma cuja data CONTRATUAL
 *     (`original_date`, ou a própria efetiva quando não há original) está dentro do
 *     prazo, mas que a convenção de pagamento (fim de semana, feriado, decisão
 *     registrada) empurrou para depois do vencimento.
 *
 * É o caso do vencimento num sábado pago na segunda: a curva vai até a segunda, os
 * juros correm até ela e o pagamento acontece. Antes desta regra a engine parava
 * no sábado e o pagamento sumia sem aviso (P1-05).
 *
 * Um pagamento depois do vencimento SEM data contratual dentro do prazo não estende
 * nada: é configuração inconsistente e o {@see PuCurveInputValidator} a recusa.
 * Nenhum outro serviço deriva o fim da curva -- quem precisa dele lê o retrato.
 */
final class PuCurveHorizonResolver
{
    /**
     * @param  list<array<string, mixed>>  $events  eventos canônicos ATIVOS do retrato
     * @return array{curve_end_date: string, contributors: list<array<string, mixed>>}
     */
    public function resolve(CarbonImmutable $contractualMaturity, array $events): array
    {
        $maturity = $contractualMaturity->toDateString();
        $end = $maturity;
        $contributors = [[
            'source' => 'contractual_maturity',
            'date' => $maturity,
        ]];

        foreach ($events as $event) {
            if (! $this->extendsHorizon($event, $maturity)) {
                continue;
            }

            $effective = (string) $event['effective_date'];
            $contributors[] = [
                'source' => 'adjusted_payment',
                'event_type' => (string) $event['event_type'],
                'original_date' => $event['original_date'] ?? null,
                'effective_date' => $effective,
                'sequence' => (int) ($event['sequence'] ?? 0),
            ];

            if ($effective > $end) {
                $end = $effective;
            }
        }

        return ['curve_end_date' => $end, 'contributors' => $contributors];
    }

    /**
     * Pagamento do cronograma com data contratual no prazo e data efetiva depois do
     * vencimento.
     *
     * @param  array<string, mixed>  $event
     */
    public function extendsHorizon(array $event, string $maturity): bool
    {
        if (! (PuEventType::tryFrom((string) ($event['event_type'] ?? ''))?->isScheduledPayment() ?? false)) {
            return false;
        }

        $effective = (string) ($event['effective_date'] ?? '');
        $contractual = (string) ($event['original_date'] ?? $effective);

        return $effective > $maturity && $contractual !== '' && $contractual <= $maturity;
    }
}
