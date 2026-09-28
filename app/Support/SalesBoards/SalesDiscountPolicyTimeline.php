<?php

declare(strict_types=1);

namespace App\Support\SalesBoards;

use App\Enums\SalesDiscountPolicyPosition;
use App\Models\SalesDiscountPolicy;
use App\Services\SalesBoards\SalesDiscountPolicyResolver;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * As políticas de uma obra em ordem de precedência, com o que cada uma passa a
 * valer de fato depois das substituições.
 *
 * A ordem é a do {@see SalesDiscountPolicyResolver}: `effective_from` e, no
 * empate, `id`. Uma política deixa de responder no início da seguinte nessa
 * ordem, se a seguinte começar antes do fim registrado dela -- e não volta a
 * responder depois. Esta classe não decide qual política vale numa data; ela
 * explica o período de cada linha para a tela e para a avaliação de um período
 * novo. A resposta por data continua sendo do resolvedor.
 */
final class SalesDiscountPolicyTimeline
{
    /** @var list<SalesDiscountPolicy> */
    private array $orderedPolicies;

    /** @var array<int, int> índice na ordem, por `id` */
    private array $positionById = [];

    /**
     * @param  iterable<SalesDiscountPolicy>  $policies  todas as políticas de uma única obra
     */
    public function __construct(iterable $policies)
    {
        $ordered = [];

        foreach ($policies as $policy) {
            $ordered[] = $policy;
        }

        usort($ordered, fn (SalesDiscountPolicy $left, SalesDiscountPolicy $right): int => [
            $left->effective_from?->toDateString(),
            (int) $left->getKey(),
        ] <=> [
            $right->effective_from?->toDateString(),
            (int) $right->getKey(),
        ]);

        $this->orderedPolicies = $ordered;

        foreach ($ordered as $index => $policy) {
            $this->positionById[(int) $policy->getKey()] = $index;
        }
    }

    /**
     * Dia em que uma política mais nova assumiu o lugar desta, antes do fim
     * registrado. Nulo quando a política terminou no próprio fim ou ainda não
     * tem sucessora.
     */
    public function supersededOn(SalesDiscountPolicy $policy): ?CarbonImmutable
    {
        $successor = $this->successorOf($policy);

        if (! $successor instanceof SalesDiscountPolicy) {
            return null;
        }

        $successorStart = self::day($successor->effective_from);
        $end = $policy->effective_until === null ? null : self::day($policy->effective_until);

        if (($end !== null) && $successorStart->greaterThan($end)) {
            return null;
        }

        return $successorStart;
    }

    /**
     * Último dia em que a política responde pelas vendas, depois das
     * substituições. Nulo quando ela segue valendo sem prazo -- só acontece com
     * linhas anteriores ao fim explícito e sem sucessora.
     *
     * Pode ser anterior ao início: é a política corrigida por outra com o mesmo
     * início, que nunca chegou a valer.
     */
    public function effectiveUntil(SalesDiscountPolicy $policy): ?CarbonImmutable
    {
        $supersededOn = $this->supersededOn($policy);

        if ($supersededOn instanceof CarbonImmutable) {
            return $supersededOn->subDay();
        }

        return $policy->effective_until === null ? null : self::day($policy->effective_until);
    }

    /**
     * @param  int|null  $currentPolicyId  a política que o resolvedor devolve para `$today`
     */
    public function positionOf(SalesDiscountPolicy $policy, CarbonInterface $today, ?int $currentPolicyId): SalesDiscountPolicyPosition
    {
        if ((int) $policy->getKey() === $currentPolicyId) {
            return SalesDiscountPolicyPosition::Current;
        }

        $day = self::day($today);
        $start = self::day($policy->effective_from);
        $supersededOn = $this->supersededOn($policy);

        if (($supersededOn !== null) && ($supersededOn->lessThanOrEqualTo($day) || $supersededOn->equalTo($start))) {
            return SalesDiscountPolicyPosition::Superseded;
        }

        if ($start->greaterThan($day)) {
            return SalesDiscountPolicyPosition::Scheduled;
        }

        return SalesDiscountPolicyPosition::Ended;
    }

    /**
     * A primeira política que começa depois de `$from` e até `$until`.
     *
     * Uma política nova com esse período ficaria escondida por ela a partir do
     * início dela, porque o início mais recente prevalece. Se houver correção
     * para o mesmo início, devolve a correção -- é ela que vale naquele dia.
     */
    public function firstStartingAfterUpTo(CarbonInterface $from, CarbonInterface $until): ?SalesDiscountPolicy
    {
        $after = self::day($from);
        $upTo = self::day($until);
        $found = null;

        foreach ($this->orderedPolicies as $policy) {
            $start = self::day($policy->effective_from);

            if ($found instanceof SalesDiscountPolicy) {
                if (! $start->equalTo(self::day($found->effective_from))) {
                    break;
                }

                $found = $policy;

                continue;
            }

            if ($start->greaterThan($after) && $start->lessThanOrEqualTo($upTo)) {
                $found = $policy;
            }
        }

        return $found;
    }

    public function isEmpty(): bool
    {
        return $this->orderedPolicies === [];
    }

    /**
     * Primeiro dia, a partir de `$day`, em que nenhuma política responde.
     *
     * Segue a cadeia de substituições: a vigente, a que a substitui, a que
     * começa logo depois do fim dela... até achar um dia descoberto. Nulo quando
     * a cobertura não tem fim -- só acontece com linha anterior ao fim explícito
     * e sem sucessora.
     *
     * É o dia a partir do qual uma venda bloqueia a competência por falta de
     * política, e é o que a tela avisa antes que aconteça.
     */
    public function firstUncoveredDayFrom(CarbonInterface $day): ?CarbonImmutable
    {
        $cursor = self::day($day);
        $index = $this->lastIndexStartingUpTo($cursor);

        if ($index === null) {
            return $cursor;
        }

        while (true) {
            $policy = $this->orderedPolicies[$index];
            $end = $policy->effective_until === null ? null : self::day($policy->effective_until);

            if (($end !== null) && $end->lessThan($cursor)) {
                return $cursor;
            }

            $next = $this->orderedPolicies[$index + 1] ?? null;

            if (! $next instanceof SalesDiscountPolicy) {
                return $end?->addDay();
            }

            $nextStart = self::day($next->effective_from);

            if (($end !== null) && $nextStart->greaterThan($end->addDay())) {
                return $end->addDay();
            }

            $cursor = $nextStart;
            $index = (int) $this->lastIndexStartingUpTo($nextStart);
        }
    }

    /**
     * Desde quando `$day` está descoberto: o dia seguinte ao fim da última
     * política que respondeu antes dele. Nulo quando nenhuma política começou
     * até `$day` -- aí não houve cobertura para terminar.
     *
     * Supõe `$day` descoberto; para um dia coberto a resposta não tem sentido.
     */
    public function uncoveredSince(CarbonInterface $day): ?CarbonImmutable
    {
        $index = $this->lastIndexStartingUpTo(self::day($day));

        if ($index === null) {
            return null;
        }

        $end = $this->orderedPolicies[$index]->effective_until;

        return $end === null ? null : self::day($end)->addDay();
    }

    /**
     * A primeira política que começa depois de `$day`.
     */
    public function firstStartingAfter(CarbonInterface $day): ?SalesDiscountPolicy
    {
        $after = self::day($day);

        foreach ($this->orderedPolicies as $policy) {
            if (self::day($policy->effective_from)->greaterThan($after)) {
                return $policy;
            }
        }

        return null;
    }

    /**
     * Posição, na ordem, da política que o resolvedor devolveria para `$day`:
     * a última com início até ele. Nulo quando nenhuma começou até ali.
     */
    private function lastIndexStartingUpTo(CarbonImmutable $day): ?int
    {
        $found = null;

        foreach ($this->orderedPolicies as $index => $policy) {
            if (self::day($policy->effective_from)->greaterThan($day)) {
                break;
            }

            $found = $index;
        }

        return $found;
    }

    private function successorOf(SalesDiscountPolicy $policy): ?SalesDiscountPolicy
    {
        $index = $this->positionById[(int) $policy->getKey()] ?? null;

        if ($index === null) {
            return null;
        }

        return $this->orderedPolicies[$index + 1] ?? null;
    }

    private static function day(CarbonInterface $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date->toDateString());
    }
}
