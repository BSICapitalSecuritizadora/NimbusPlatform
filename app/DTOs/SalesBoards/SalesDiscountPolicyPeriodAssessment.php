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
 *
 * Quando esse intervalo já começou (`uncoveredThrough`), ele também rejulga
 * vendas feitas: elas perdem a política que as decidia e ficam sem veredito. O
 * alcance do registro vai então até o fim dele, e é sobre esse alcance inteiro
 * que a confirmação é dada e que a competência aprovada é procurada -- senão
 * uma correção de um mês passado deixaria sem política, calada, uma
 * competência publicada depois dele.
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
     * @param  list<array{month: CarbonImmutable, sales: int}>  $reachedCompetences  competências de `[from, retroactiveThrough]`, com as vendas de cada uma dentro dele
     * @param  list<CarbonImmutable>  $approvedCompetences  competências do alcance inteiro (`[from, reachThrough()]`) já aprovadas e publicadas para a obra
     * @param  CarbonImmutable|null  $uncoveredThrough  último dia já vivido que a substituição deixa sem política depois do fim da nova; nulo quando ela não deixa lacuna no passado
     * @param  list<array{month: CarbonImmutable, sales: int}>  $uncoveredCompetences  competências de `[until + 1, uncoveredThrough]`, com as vendas de cada uma dentro dele
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
        public ?CarbonImmutable $uncoveredThrough = null,
        public array $uncoveredCompetences = [],
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
     * Último dia já vivido que o registro muda: o fim do alcance da própria
     * política ou, se a substituição deixa lacuna no passado, o fim dela.
     */
    public function reachThrough(): ?CarbonImmutable
    {
        return $this->uncoveredThrough ?? $this->retroactiveThrough;
    }

    /**
     * A substituição deixa sem política vendas já feitas depois do fim da nova.
     */
    public function leavesPastSalesUncovered(): bool
    {
        return $this->uncoveredThrough instanceof CarbonImmutable;
    }

    /**
     * O que a tela guarda como confirmação do alcance e o servidor compara na
     * hora de gravar: se o dia de negócio virou entre as duas coisas, o alcance
     * cresceu e a confirmação dada valia para outro. Inclui a lacuna que a
     * substituição deixa no passado -- confirmar só o período da nova não é
     * confirmar as vendas que ficam sem política.
     */
    public function retroactiveThroughDate(): ?string
    {
        return $this->reachThrough()?->toDateString();
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

        $message = sprintf(
            'O início, %s, é anterior a hoje. A política passará a decidir a conformidade das vendas feitas de %s a %s: %s.',
            $this->from->format('d/m/Y'),
            $this->from->format('d/m/Y'),
            $this->retroactiveThrough->format('d/m/Y'),
            self::describeCompetences($this->reachedCompetences),
        );

        if (! $this->leavesPastSalesUncovered()) {
            return $message.' Ciclos ainda não aprovados com vendas nesse período ficarão desatualizados e precisarão ser recalculados.';
        }

        return sprintf(
            '%s Como a política de %s não volta a valer depois de %s, as vendas feitas de %s a %s ficarão sem política vigente e sem veredito de conformidade: %s. Ciclos ainda não aprovados com vendas nesses períodos ficarão desatualizados e precisarão ser recalculados.',
            $message,
            $this->substitutedPolicy?->formatted_maximum_discount_percent,
            $this->until->format('d/m/Y'),
            $this->until->addDay()->format('d/m/Y'),
            $this->uncoveredThrough?->format('d/m/Y'),
            self::describeCompetences($this->uncoveredCompetences),
        );
    }

    /**
     * Quando a competência aprovada está na lacuna que a substituição deixa, e
     * não no período da nova, a mensagem diz por quê: o usuário corrigiu um mês
     * passado e não reconheceria a competência posterior como alcançada.
     *
     * A saída é a mesma nos dois casos: o alcance sempre começa no início da
     * nova, e alongar o fim não o encurta -- as vendas da lacuna passariam a ser
     * decididas pela nova em vez de ficar sem política.
     */
    public function approvedCompetenceMessage(): ?string
    {
        if ($this->approvedCompetences === []) {
            return null;
        }

        $latest = $this->approvedCompetences[array_key_last($this->approvedCompetences)];

        $competences = sprintf(
            '%s desta obra (%s)',
            count($this->approvedCompetences) === 1
                ? 'competência já aprovada e publicada'
                : 'competências já aprovadas e publicadas',
            Arr::join(
                array_map(fn (CarbonImmutable $month): string => $month->format('m/Y'), $this->approvedCompetences),
                ', ',
                ' e ',
            ),
        );

        $reason = $this->approvedCompetenceIsUncovered()
            ? sprintf(
                'O registro alcança %s: como a política de %s (%s) não volta a valer depois de %s, as vendas feitas de %s a %s ficariam sem política vigente.',
                $competences,
                $this->substitutedPolicy?->formatted_maximum_discount_percent,
                $this->substitutedPolicy instanceof SalesDiscountPolicy ? self::describeRegisteredPeriod($this->substitutedPolicy) : '—',
                $this->until->format('d/m/Y'),
                $this->until->addDay()->format('d/m/Y'),
                $this->uncoveredThrough?->format('d/m/Y'),
            )
            : sprintf('O período alcança %s.', $competences);

        return sprintf(
            '%s A conformidade das vendas de uma competência publicada não é rejulgada: o início precisa ser posterior a %s.',
            $reason,
            $latest->endOfMonth()->format('d/m/Y'),
        );
    }

    /**
     * Alguma competência aprovada tem dias na lacuna que a substituição deixa
     * no passado.
     */
    private function approvedCompetenceIsUncovered(): bool
    {
        if (! $this->leavesPastSalesUncovered()) {
            return false;
        }

        foreach ($this->approvedCompetences as $month) {
            if ($month->endOfMonth()->startOfDay()->greaterThan($this->until)) {
                return true;
            }
        }

        return false;
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

    /**
     * @param  list<array{month: CarbonImmutable, sales: int}>  $competences
     */
    private static function describeCompetences(array $competences): string
    {
        if (count($competences) > self::LISTED_COMPETENCES_LIMIT) {
            $first = $competences[0]['month'];
            $last = $competences[array_key_last($competences)]['month'];

            return sprintf(
                '%d competências, de %s a %s, com %s no total',
                count($competences),
                $first->format('m/Y'),
                $last->format('m/Y'),
                self::describeSales(array_sum(array_column($competences, 'sales'))),
            );
        }

        return Arr::join(
            array_map(
                fn (array $competence): string => sprintf(
                    '%s (%s)',
                    $competence['month']->format('m/Y'),
                    self::describeSales($competence['sales']),
                ),
                $competences,
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
