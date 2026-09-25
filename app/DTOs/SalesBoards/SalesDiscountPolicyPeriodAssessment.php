<?php

declare(strict_types=1);

namespace App\DTOs\SalesBoards;

use App\DTOs\BaseDTO;
use App\Models\SalesDiscountPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;

/**
 * O que acontece com o histórico se uma política for registrada para o período
 * `[from, until]`.
 *
 * Três situações são possíveis, e nenhuma é silenciosa:
 *
 * - `blockingPolicy`: uma política já registrada começa dentro do período, depois
 *   do início dele. Como o início mais recente prevalece, a nova ficaria
 *   escondida a partir dali -- o registro é recusado.
 * - `substitutedPolicy`: a política que vale no início do período. A nova a
 *   substitui a partir do próprio início, e a substituída não volta a valer
 *   depois do fim da nova. O registro exige confirmação.
 * - `retroactiveThrough`: o início é anterior a hoje, e a política passa a
 *   decidir a conformidade de vendas que já aconteceram, até esse dia. O
 *   registro exige uma confirmação própria, que mostra as competências e as
 *   vendas alcançadas -- e é recusado se alguma dessas competências já foi
 *   aprovada e publicada (`approvedCompetences`): o veredito de uma posição
 *   publicada não é rejulgado.
 *
 * `substitutedEffectiveUntil` é o último dia em que a substituída valeria sem a
 * nova (nulo = sem prazo). Se passa do fim da nova, o intervalo entre os dois
 * fica sem política vigente, e a tela precisa dizer isso.
 */
readonly class SalesDiscountPolicyPeriodAssessment extends BaseDTO
{
    /**
     * Acima disto a mensagem resume as competências alcançadas em vez de
     * listá-las uma a uma: uma correção que volta anos atrás viraria um
     * parágrafo de meses.
     */
    private const LISTED_COMPETENCES_LIMIT = 12;

    /**
     * @param  CarbonImmutable|null  $retroactiveThrough  último dia já vivido que a política alcança; nulo quando o início não é anterior a hoje
     * @param  list<array{month: CarbonImmutable, sales: int}>  $reachedCompetences  competências do alcance retroativo, com as vendas de cada uma dentro dele
     * @param  list<CarbonImmutable>  $approvedCompetences  competências do alcance retroativo já aprovadas e publicadas para a obra
     */
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $until,
        public ?SalesDiscountPolicy $blockingPolicy,
        public ?SalesDiscountPolicy $substitutedPolicy,
        public ?CarbonImmutable $substitutedEffectiveUntil,
        public ?CarbonImmutable $retroactiveThrough = null,
        public array $reachedCompetences = [],
        public array $approvedCompetences = [],
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

    public function isRetroactive(): bool
    {
        return $this->retroactiveThrough instanceof CarbonImmutable;
    }

    public function reachesApprovedCompetence(): bool
    {
        return $this->approvedCompetences !== [];
    }

    /**
     * A confirmação do alcance só é pedida quando o registro é possível: com o
     * período bloqueado ou recusado, a tela mostra o motivo, não uma caixa a
     * marcar.
     */
    public function needsRetroactiveConfirmation(): bool
    {
        return $this->isRetroactive() && (! $this->isBlocked()) && (! $this->reachesApprovedCompetence());
    }

    /**
     * O que a tela guarda como confirmação do alcance e o servidor compara na
     * hora de gravar: se o dia de negócio virou entre as duas coisas, o alcance
     * cresceu e a confirmação dada valia para outro.
     */
    public function retroactiveThroughDate(): ?string
    {
        return $this->retroactiveThrough?->toDateString();
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

    public function retroactivityMessage(): ?string
    {
        if (! $this->retroactiveThrough instanceof CarbonImmutable) {
            return null;
        }

        return sprintf(
            'O início, %s, é anterior a hoje. A política passará a decidir a conformidade das vendas feitas de %s a %s: %s. Ciclos ainda não aprovados com vendas nesse período ficarão desatualizados e precisarão ser recalculados.',
            $this->from->format('d/m/Y'),
            $this->from->format('d/m/Y'),
            $this->retroactiveThrough->format('d/m/Y'),
            $this->describeReachedCompetences(),
        );
    }

    public function approvedCompetenceMessage(): ?string
    {
        if ($this->approvedCompetences === []) {
            return null;
        }

        $latest = $this->approvedCompetences[array_key_last($this->approvedCompetences)];

        return sprintf(
            'O período alcança %s desta obra (%s). A conformidade das vendas de uma competência publicada não é rejulgada: o início precisa ser posterior a %s.',
            count($this->approvedCompetences) === 1
                ? 'competência já aprovada e publicada'
                : 'competências já aprovadas e publicadas',
            Arr::join(
                array_map(fn (CarbonImmutable $month): string => $month->format('m/Y'), $this->approvedCompetences),
                ', ',
                ' e ',
            ),
            $latest->endOfMonth()->format('d/m/Y'),
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

    private function describeReachedCompetences(): string
    {
        if (count($this->reachedCompetences) > self::LISTED_COMPETENCES_LIMIT) {
            $first = $this->reachedCompetences[0]['month'];
            $last = $this->reachedCompetences[array_key_last($this->reachedCompetences)]['month'];

            return sprintf(
                '%d competências, de %s a %s, com %s no total',
                count($this->reachedCompetences),
                $first->format('m/Y'),
                $last->format('m/Y'),
                self::describeSales(array_sum(array_column($this->reachedCompetences, 'sales'))),
            );
        }

        return Arr::join(
            array_map(
                fn (array $competence): string => sprintf(
                    '%s (%s)',
                    $competence['month']->format('m/Y'),
                    self::describeSales($competence['sales']),
                ),
                $this->reachedCompetences,
            ),
            ', ',
            ' e ',
        );
    }

    private static function describeSales(int $sales): string
    {
        return match ($sales) {
            0 => 'nenhuma venda',
            1 => '1 venda',
            default => number_format($sales, 0, ',', '.').' vendas',
        };
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
