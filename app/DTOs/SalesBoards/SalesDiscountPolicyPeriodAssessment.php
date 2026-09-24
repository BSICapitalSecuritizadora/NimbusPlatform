<?php

declare(strict_types=1);

namespace App\DTOs\SalesBoards;

use App\DTOs\BaseDTO;
use App\Models\SalesDiscountPolicy;
use Carbon\CarbonImmutable;

/**
 * O que acontece com o histórico se uma política for registrada para o período
 * `[from, until]`.
 *
 * Duas situações são possíveis, e nenhuma é silenciosa:
 *
 * - `blockingPolicy`: uma política já registrada começa dentro do período, depois
 *   do início dele. Como o início mais recente prevalece, a nova ficaria
 *   escondida a partir dali -- o registro é recusado.
 * - `substitutedPolicy`: a política que vale no início do período. A nova a
 *   substitui a partir do próprio início, e a substituída não volta a valer
 *   depois do fim da nova. O registro exige confirmação.
 *
 * `substitutedEffectiveUntil` é o último dia em que a substituída valeria sem a
 * nova (nulo = sem prazo). Se passa do fim da nova, o intervalo entre os dois
 * fica sem política vigente, e a tela precisa dizer isso.
 */
readonly class SalesDiscountPolicyPeriodAssessment extends BaseDTO
{
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $until,
        public ?SalesDiscountPolicy $blockingPolicy,
        public ?SalesDiscountPolicy $substitutedPolicy,
        public ?CarbonImmutable $substitutedEffectiveUntil,
    ) {}

    public function isBlocked(): bool
    {
        return $this->blockingPolicy instanceof SalesDiscountPolicy;
    }

    public function substitutes(): bool
    {
        return (! $this->isBlocked()) && ($this->substitutedPolicy instanceof SalesDiscountPolicy);
    }

    public function substitutedPolicyId(): ?int
    {
        return $this->substitutes() ? (int) $this->substitutedPolicy?->getKey() : null;
    }

    public function blockingMessage(): ?string
    {
        if (! $this->blockingPolicy instanceof SalesDiscountPolicy) {
            return null;
        }

        $blockingStart = $this->blockingPolicy->effective_from?->format('d/m/Y');

        return sprintf(
            'Já existe a política de %s com início em %s, dentro do período informado. O fim desta política precisa ser anterior a %s.',
            $this->blockingPolicy->formatted_maximum_discount_percent,
            $blockingStart,
            $blockingStart,
        );
    }

    public function substitutionMessage(): ?string
    {
        if (! $this->substitutes()) {
            return null;
        }

        $policy = $this->substitutedPolicy;
        $startsTogether = $policy->effective_from?->toDateString() === $this->from->toDateString();

        $message = $startsTogether
            ? sprintf(
                'A política de %s (%s) começa no mesmo dia e será substituída por inteiro.',
                $policy->formatted_maximum_discount_percent,
                self::describeRegisteredPeriod($policy),
            )
            : sprintf(
                'A política de %s (%s) deixa de valer a partir de %s. As vendas anteriores continuam com ela.',
                $policy->formatted_maximum_discount_percent,
                self::describeRegisteredPeriod($policy),
                $this->from->format('d/m/Y'),
            );

        $gap = $this->uncoveredAfterUntilMessage();

        return $gap === null ? $message : $message.' '.$gap;
    }

    /**
     * O intervalo que a substituída cobriria e que a nova, por terminar antes,
     * deixa sem política.
     */
    private function uncoveredAfterUntilMessage(): ?string
    {
        $firstUncoveredDay = $this->until->addDay();

        if ($this->substitutedEffectiveUntil === null) {
            return sprintf(
                'Ela não volta a valer depois de %s: a partir de %s a obra ficará sem política vigente até que outra seja registrada.',
                $this->until->format('d/m/Y'),
                $firstUncoveredDay->format('d/m/Y'),
            );
        }

        if ($this->substitutedEffectiveUntil->lessThan($firstUncoveredDay)) {
            return null;
        }

        return sprintf(
            'Ela não volta a valer depois de %s: de %s a %s a obra ficará sem política vigente.',
            $this->until->format('d/m/Y'),
            $firstUncoveredDay->format('d/m/Y'),
            $this->substitutedEffectiveUntil->format('d/m/Y'),
        );
    }

    public static function describeRegisteredPeriod(SalesDiscountPolicy $policy): string
    {
        $start = $policy->effective_from?->format('d/m/Y') ?? '—';

        if ($policy->effective_until === null) {
            return sprintf('desde %s, sem data de fim', $start);
        }

        return sprintf('%s a %s', $start, $policy->effective_until->format('d/m/Y'));
    }
}
