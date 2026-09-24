<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\DTOs\SalesBoards\SalesDiscountPolicyPeriodAssessment;
use App\Exceptions\SalesDiscountPolicyPeriodException;
use App\Models\Construction;
use App\Models\SalesDiscountPolicy;
use App\Models\User;
use App\Support\SalesBoards\SalesDiscountPolicyTimeline;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Registro de uma política de desconto com período fechado.
 *
 * O formulário mostra a mesma avaliação antes de gravar, mas é aqui que ela vale:
 * a checagem roda de novo dentro da transação, com a obra travada, para que dois
 * registros simultâneos não avaliem o mesmo histórico e gravem por cima um do
 * outro.
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
        );
    }

    /**
     * @param  array{maximum_discount_percent: string|int|float, effective_from: string, effective_until: string, reason: string}  $attributes
     * @param  int|null  $confirmedSubstitutionId  a política cuja substituição foi confirmada na tela
     *
     * @throws SalesDiscountPolicyPeriodException
     */
    public function register(
        Construction $construction,
        array $attributes,
        ?int $confirmedSubstitutionId,
        ?User $registeredBy,
    ): SalesDiscountPolicy {
        $from = CarbonImmutable::parse($attributes['effective_from'])->startOfDay();
        $until = CarbonImmutable::parse($attributes['effective_until'])->startOfDay();

        if ($until->lessThan($from)) {
            throw SalesDiscountPolicyPeriodException::endsBeforeItStarts();
        }

        return DB::transaction(function () use ($construction, $attributes, $confirmedSubstitutionId, $registeredBy, $from, $until): SalesDiscountPolicy {
            Construction::query()->whereKey($construction->getKey())->lockForUpdate()->first();

            $assessment = $this->assess((int) $construction->getKey(), $from, $until);

            if ($assessment->isBlocked()) {
                throw SalesDiscountPolicyPeriodException::hiddenByLaterPolicy($assessment);
            }

            if ($assessment->substitutes() && ($assessment->substitutedPolicyId() !== $confirmedSubstitutionId)) {
                throw SalesDiscountPolicyPeriodException::substitutionNotConfirmed($assessment);
            }

            return SalesDiscountPolicy::query()->create([
                'construction_id' => $construction->getKey(),
                'maximum_discount_percent' => $attributes['maximum_discount_percent'],
                'effective_from' => $from->toDateString(),
                'effective_until' => $until->toDateString(),
                'reason' => $attributes['reason'],
                'created_by_id' => $registeredBy?->getKey(),
            ]);
        });
    }
}
