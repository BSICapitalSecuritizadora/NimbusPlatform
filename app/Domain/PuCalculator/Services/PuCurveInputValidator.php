<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuCurveInputSnapshot;
use App\Domain\PuCalculator\Enums\PuAmortizationType;
use App\Domain\PuCalculator\Enums\PuCalculationMethod;
use App\Domain\PuCalculator\Enums\PuEventType;
use Carbon\CarbonImmutable;

/**
 * Coerência do retrato de insumos antes de qualquer cálculo operacional.
 *
 * Tudo que a engine ignoraria em silêncio vira bloqueio com motivo:
 *
 *  - evento ativo de um tipo que a engine da emissão não calcula (Fase 5);
 *  - pagamento antes do início da curva (a engine começa no início e nunca o veria);
 *  - pagamento depois do vencimento sem data contratual dentro do prazo (o
 *    horizonte só se estende por pagamento do vencimento deslocado pela convenção);
 *  - integralização depois do vencimento;
 *  - alteração de spread fora do início de um período de capitalização, no início
 *    da curva (é termo de base) ou sem período depois dela para reger;
 *  - vigência em evento instantâneo, ou vigência que termina antes de começar;
 *  - dois pagamentos de juros, ou duas alterações de spread, na mesma data: a
 *    engine aplicaria um só, e o outro sumiria.
 *
 * É a mesma lista nos pré-requisitos (mensagem para quem configura) e na geração
 * (recusa antes de gravar qualquer linha).
 */
final class PuCurveInputValidator
{
    /**
     * @return list<string>
     */
    public function issues(PuCurveInputSnapshot $snapshot): array
    {
        $issues = [];
        $method = PuCalculationMethod::tryFrom((string) ($snapshot->engine()['calculation_method'] ?? ''));
        $start = $snapshot->curveStartDate()->toDateString();
        $maturity = $snapshot->contractualMaturityDate()->toDateString();
        $horizonEnd = $snapshot->horizonEndDate()->toDateString();
        $events = $snapshot->events();
        $periodStarts = $this->periodStarts($events);
        $interestDates = [];
        $amendmentDates = [];

        foreach ($events as $event) {
            $type = PuEventType::tryFrom((string) $event['event_type']);
            $effective = (string) $event['effective_date'];
            $label = sprintf('%s de %s', $type?->label() ?? (string) $event['event_type'], $this->brazilian($effective));

            if (! $type instanceof PuEventType) {
                $issues[] = sprintf('O evento %s tem um tipo desconhecido: a curva não pode ser gerada até ele ser corrigido ou cancelado.', $label);

                continue;
            }

            if ($method instanceof PuCalculationMethod && ! $type->isSupportedBy($method)) {
                $issues[] = sprintf(
                    'O evento %s tem efeito financeiro que a engine %s ainda não calcula: a curva não pode ser gerada enquanto ele estiver ativo. Cancele-o ou aguarde a regra de cálculo.',
                    $label,
                    $method->label(),
                );
            }

            $until = $event['effective_until'] ?? null;

            if ($until !== null && ! $type->supportsDuration()) {
                $issues[] = sprintf('O evento %s é instantâneo e não aceita data final de vigência.', $label);
            }

            if ($until !== null && (string) $until < $effective) {
                $issues[] = sprintf('A vigência do evento %s termina antes de começar.', $label);
            }

            if ($effective < $start) {
                $issues[] = sprintf('O evento %s é anterior ao início da curva (%s) e nunca seria aplicado.', $label, $this->brazilian($start));
            }

            if ($type->isScheduledPayment() && $effective > $maturity) {
                $contractual = (string) ($event['original_date'] ?? $effective);

                if ($contractual > $maturity) {
                    $issues[] = sprintf(
                        'O evento %s cai depois do vencimento (%s) sem data contratual dentro do prazo: só um pagamento do vencimento deslocado pela convenção de pagamento estende a curva.',
                        $label,
                        $this->brazilian($maturity),
                    );
                }
            }

            if (! $type->isScheduledPayment() && $effective > $horizonEnd) {
                $issues[] = sprintf('O evento %s é posterior ao fim da curva (%s) e nunca seria aplicado.', $label, $this->brazilian($horizonEnd));
            }

            if ($type === PuEventType::InterestPayment) {
                if (isset($interestDates[$effective])) {
                    $issues[] = sprintf('Há mais de um pagamento de juros ativo em %s: a engine paga os juros uma vez só por data.', $this->brazilian($effective));
                }

                $interestDates[$effective] = true;
            }

            if ($type === PuEventType::SpreadAmendment) {
                $issues = [...$issues, ...$this->spreadAmendmentIssues($event, $label, $start, $horizonEnd, $periodStarts, $amendmentDates)];
                $amendmentDates[$effective] = true;
            }
        }

        foreach ($snapshot->integralizations() as $integralization) {
            if ((string) $integralization['date'] > $maturity) {
                $issues[] = sprintf(
                    'Há integralização em %s, depois do vencimento (%s).',
                    $this->brazilian((string) $integralization['date']),
                    $this->brazilian($maturity),
                );
            }
        }

        return array_values(array_unique($issues));
    }

    /**
     * Datas em que um período de capitalização termina e o seguinte começa:
     * pagamento de juros ou amortização com valor.
     *
     * @param  list<array<string, mixed>>  $events
     * @return array<string, true>
     */
    private function periodStarts(array $events): array
    {
        $starts = [];

        foreach ($events as $event) {
            $type = PuEventType::tryFrom((string) $event['event_type']);
            $resets = $type === PuEventType::InterestPayment
                || ($type === PuEventType::Amortization
                    && PuAmortizationType::tryFrom((string) ($event['amortization_type'] ?? '')) !== PuAmortizationType::None);

            if ($resets) {
                $starts[(string) $event['effective_date']] = true;
            }
        }

        return $starts;
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  array<string, true>  $periodStarts
     * @param  array<string, true>  $amendmentDates
     * @return list<string>
     */
    private function spreadAmendmentIssues(
        array $event,
        string $label,
        string $start,
        string $horizonEnd,
        array $periodStarts,
        array $amendmentDates,
    ): array {
        $issues = [];
        $effective = (string) $event['effective_date'];
        $rate = $event['financial_effect']['spread_rate'] ?? null;

        if (! is_string($rate) || ! is_numeric($rate)) {
            $issues[] = sprintf('A %s não informa o novo spread (efeito financeiro `spread_rate`).', $label);
        } elseif (bccomp($rate, '-100', 8) <= 0) {
            $issues[] = sprintf('A %s informa um spread de %s%%, que não forma base de capitalização positiva.', $label, $rate);
        }

        if ($effective <= $start) {
            $issues[] = sprintf('A %s vale desde o início da curva: altere o spread dos parâmetros de base em vez de cadastrar um evento.', $label);
        } elseif ($effective >= $horizonEnd) {
            $issues[] = sprintf('A %s cai no fim da curva e não teria período de capitalização para reger.', $label);
        } elseif (! isset($periodStarts[$effective])) {
            $issues[] = sprintf(
                'A %s cai no meio de um período de capitalização: a engine aplica o novo spread a partir de um pagamento (juros ou amortização) e não tem regra contratual para dividir o período.',
                $label,
            );
        }

        if (isset($amendmentDates[$effective])) {
            $issues[] = sprintf('Há mais de uma alteração de spread ativa em %s.', $this->brazilian($effective));
        }

        return $issues;
    }

    private function brazilian(string $date): string
    {
        return CarbonImmutable::parse($date)->format('d/m/Y');
    }
}
