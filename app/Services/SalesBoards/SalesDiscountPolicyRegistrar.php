<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\DTOs\SalesBoards\SalesDiscountPolicyPeriodAssessment;
use App\Enums\SalesBoardCycleStatus;
use App\Exceptions\SalesDiscountPolicyPeriodException;
use App\Models\Construction;
use App\Models\Contract;
use App\Models\SalesBoardCycle;
use App\Models\SalesDiscountPolicy;
use App\Models\User;
use App\Support\BusinessTime;
use App\Support\Dates\InclusiveDateBound;
use App\Support\Money\IntegerMoney;
use App\Support\SalesBoards\SalesDiscountPolicyTimeline;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Registro de uma política de desconto com período fechado.
 *
 * O formulário mostra a mesma avaliação antes de gravar, mas é aqui que ela vale:
 * a checagem roda de novo dentro da transação, com a obra travada, para que dois
 * registros simultâneos não avaliem o mesmo histórico e gravem por cima um do
 * outro.
 *
 * Um início anterior ao dia de negócio de hoje é permitido -- corrigir uma
 * política errada exige isso --, mas a política passa a decidir a conformidade
 * de vendas que já aconteceram. Por isso ele exige confirmação própria, dada
 * sobre as competências e vendas alcançadas, e é recusado quando alcança uma
 * competência já aprovada e publicada da obra: o veredito publicado não é
 * rejulgado.
 */
class SalesDiscountPolicyRegistrar
{
    public function __construct(
        private readonly SalesDiscountPolicyResolver $salesDiscountPolicyResolver,
    ) {}

    public function timeline(int $constructionId): SalesDiscountPolicyTimeline
    {
        return new SalesDiscountPolicyTimeline(
            SalesDiscountPolicy::query()
                ->where('construction_id', $constructionId)
                ->get(),
        );
    }

    /**
     * O que registrar uma política para `[from, until]` faria com o histórico da
     * obra. Supõe `from <= until`.
     */
    public function assess(int $constructionId, CarbonInterface $from, CarbonInterface $until): SalesDiscountPolicyPeriodAssessment
    {
        $from = CarbonImmutable::parse($from->toDateString());
        $until = CarbonImmutable::parse($until->toDateString());
        $retroactiveThrough = self::retroactiveThrough($from, $until);

        $timeline = $this->timeline($constructionId);

        /**
         * A política que o resolvedor devolve para o primeiro dia do período é a
         * única que a nova substitui: as anteriores a ela já tinham deixado de
         * valer antes desse dia, e nenhuma começa dentro do período sem que o
         * registro seja bloqueado.
         */
        $substituted = $this->salesDiscountPolicyResolver->forConstructionId($constructionId, $from)->policy;

        return new SalesDiscountPolicyPeriodAssessment(
            from: $from,
            until: $until,
            blockingPolicy: $timeline->firstStartingAfterUpTo($from, $until),
            substitutedPolicy: $substituted,
            substitutedEffectiveUntil: $substituted instanceof SalesDiscountPolicy
                ? $timeline->effectiveUntil($substituted)
                : null,
            retroactiveThrough: $retroactiveThrough,
            reachedCompetences: $retroactiveThrough === null
                ? []
                : $this->reachedCompetences($constructionId, $from, $retroactiveThrough),
            approvedCompetences: $retroactiveThrough === null
                ? []
                : $this->approvedCompetences($constructionId, $from, $retroactiveThrough),
        );
    }

    /**
     * @param  array{maximum_discount_percent: string|int|float, effective_from: string, effective_until: string, reason: string}  $attributes
     * @param  int|null  $confirmedSubstitutionId  a política cuja substituição foi confirmada na tela
     * @param  string|null  $confirmedRetroactiveThrough  o último dia do alcance retroativo que a tela mostrou e o usuário confirmou (`Y-m-d`)
     *
     * @throws SalesDiscountPolicyPeriodException
     */
    public function register(
        Construction $construction,
        array $attributes,
        ?int $confirmedSubstitutionId,
        ?User $registeredBy,
        ?string $confirmedRetroactiveThrough = null,
    ): SalesDiscountPolicy {
        $from = CarbonImmutable::parse($attributes['effective_from'])->startOfDay();
        $until = CarbonImmutable::parse($attributes['effective_until'])->startOfDay();

        if ($until->lessThan($from)) {
            throw SalesDiscountPolicyPeriodException::endsBeforeItStarts();
        }

        $maximumDiscountPercent = self::maximumDiscountPercent($attributes['maximum_discount_percent']);
        $confirmedRetroactiveThrough = self::normalizeDate($confirmedRetroactiveThrough);

        return DB::transaction(function () use ($construction, $attributes, $confirmedSubstitutionId, $confirmedRetroactiveThrough, $registeredBy, $from, $until, $maximumDiscountPercent): SalesDiscountPolicy {
            Construction::query()->whereKey($construction->getKey())->lockForUpdate()->first();

            $assessment = $this->assess((int) $construction->getKey(), $from, $until);

            if ($assessment->isBlocked()) {
                throw SalesDiscountPolicyPeriodException::hiddenByLaterPolicy($assessment);
            }

            if ($assessment->reachesApprovedCompetence()) {
                throw SalesDiscountPolicyPeriodException::reachesApprovedCompetence($assessment);
            }

            if ($assessment->substitutes() && ($assessment->substitutedPolicyId() !== $confirmedSubstitutionId)) {
                throw SalesDiscountPolicyPeriodException::substitutionNotConfirmed($assessment);
            }

            if ($assessment->isRetroactive() && ($assessment->retroactiveThroughDate() !== $confirmedRetroactiveThrough)) {
                throw SalesDiscountPolicyPeriodException::retroactivityNotConfirmed($assessment);
            }

            return SalesDiscountPolicy::query()->create([
                'construction_id' => $construction->getKey(),
                'maximum_discount_percent' => $maximumDiscountPercent,
                'effective_from' => $from->toDateString(),
                'effective_until' => $until->toDateString(),
                'reason' => $attributes['reason'],
                'created_by_id' => $registeredBy?->getKey(),
            ]);
        });
    }

    /**
     * Último dia já vivido que a política alcança: hoje, ou o fim dela se o
     * período inteiro já passou. Nulo quando o início não é anterior ao dia de
     * negócio de hoje -- uma política que começa hoje ou depois não rejulga
     * nada que já tenha sido apurado.
     */
    private static function retroactiveThrough(CarbonImmutable $from, CarbonImmutable $until): ?CarbonImmutable
    {
        $today = CarbonImmutable::parse(BusinessTime::dateString());

        if (! $from->lessThan($today)) {
            return null;
        }

        return $until->lessThan($today) ? $until : $today;
    }

    /**
     * Competências do alcance retroativo, da primeira à última, com as vendas
     * que cada uma tem dentro dele -- inclusive as que não têm nenhuma, para que
     * a lista mostre o período inteiro.
     *
     * @return list<array{month: CarbonImmutable, sales: int}>
     */
    private function reachedCompetences(int $constructionId, CarbonImmutable $from, CarbonImmutable $through): array
    {
        $salesByMonth = Contract::query()
            ->where('construction_id', $constructionId)
            ->where('sale_date', '>=', $from->toDateString())
            ->where('sale_date', '<=', InclusiveDateBound::upperBound($through))
            ->pluck('sale_date')
            ->countBy(fn (mixed $saleDate): string => CarbonImmutable::parse($saleDate)->format('Y-m'));

        $competences = [];

        for ($month = $from->startOfMonth(); $month->lessThanOrEqualTo($through); $month = $month->addMonth()) {
            $competences[] = [
                'month' => $month,
                'sales' => (int) ($salesByMonth[$month->format('Y-m')] ?? 0),
            ];
        }

        return $competences;
    }

    /**
     * Competências do alcance retroativo que já têm posição aprovada e
     * publicada para a obra, da mais antiga para a mais recente.
     *
     * O quadro legado, digitado à mão, fica de fora: ele não carrega veredito
     * de conformidade, então não há o que uma política nova rejulgue nele.
     *
     * @return list<CarbonImmutable>
     */
    private function approvedCompetences(int $constructionId, CarbonImmutable $from, CarbonImmutable $through): array
    {
        return SalesBoardCycle::query()
            ->where('construction_id', $constructionId)
            ->where('status', SalesBoardCycleStatus::Approved)
            ->where('reference_month', '>=', $from->startOfMonth()->toDateString())
            ->where('reference_month', '<=', InclusiveDateBound::upperBound($through))
            ->orderBy('reference_month')
            ->pluck('reference_month')
            ->map(fn (mixed $month): CarbonImmutable => CarbonImmutable::parse($month)->startOfMonth())
            ->unique(fn (CarbonImmutable $month): string => $month->toDateString())
            ->values()
            ->all();
    }

    /**
     * O limite no formato da coluna, `decimal(5,2)`.
     *
     * A conversão por basis points recusa a terceira casa em vez de
     * arredondá-la; gravar o texto como veio deixaria o banco arredondar 4,255%
     * para 4,26% em silêncio.
     *
     * @throws SalesDiscountPolicyPeriodException
     */
    private static function maximumDiscountPercent(mixed $percent): string
    {
        $basisPoints = IntegerMoney::basisPoints($percent);

        if (($basisPoints === null)
            || ($basisPoints < SalesDiscountPolicy::MINIMUM_DISCOUNT_PERCENT * 100)
            || ($basisPoints > SalesDiscountPolicy::MAXIMUM_DISCOUNT_PERCENT * 100)) {
            throw SalesDiscountPolicyPeriodException::invalidMaximumDiscount();
        }

        return sprintf('%d.%02d', intdiv($basisPoints, 100), $basisPoints % 100);
    }

    private static function normalizeDate(?string $date): ?string
    {
        if (blank($date)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($date)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }
}
