<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\DTOs\SalesBoards\ResolvedSalesDiscountPolicy;
use App\Models\Construction;
use App\Models\SalesDiscountPolicy;
use App\Support\Dates\InclusiveDateBound;
use App\Support\Money\IntegerMoney;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Fonte única da resposta para "qual desconto a BSI autorizava neste
 * empreendimento nesta data?".
 *
 * A última política com `effective_from <= data`, desempatada pelo maior `id`.
 * Política registrada com vigência futura não retroage; política nenhuma
 * devolve ausência explícita, nunca 0% e nunca um limite presumido -- um
 * veredito de conformidade em cima de política inventada não vale nada.
 */
class SalesDiscountPolicyResolver
{
    public function policyAt(Construction $construction, CarbonInterface $positionDate): ResolvedSalesDiscountPolicy
    {
        $constructionId = (int) $construction->getKey();

        return $this->forConstructionId($constructionId, $positionDate);
    }

    public function forConstructionId(int $constructionId, CarbonInterface $positionDate): ResolvedSalesDiscountPolicy
    {
        $date = $this->normalizeDate($positionDate);

        $policy = SalesDiscountPolicy::query()
            ->where('construction_id', $constructionId)
            ->where('effective_from', '<=', InclusiveDateBound::upperBound($date))
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();

        return $this->toResolved($constructionId, $date, $policy);
    }

    /**
     * Política vigente de vários empreendimentos na mesma data, numa consulta.
     *
     * Existe para a varredura de carteira da fase seguinte: resolver
     * empreendimento por empreendimento dispararia uma consulta por obra.
     *
     * @param  list<int>  $constructionIds
     * @return array<int, ResolvedSalesDiscountPolicy> indexado por `construction_id`
     */
    public function forConstructionIds(array $constructionIds, CarbonInterface $positionDate): array
    {
        $date = $this->normalizeDate($positionDate);

        if ($constructionIds === []) {
            return [];
        }

        $latest = [];

        SalesDiscountPolicy::query()
            ->whereIn('construction_id', $constructionIds)
            ->where('effective_from', '<=', InclusiveDateBound::upperBound($date))
            ->orderBy('effective_from')
            ->orderBy('id')
            ->get()
            ->each(function (SalesDiscountPolicy $policy) use (&$latest): void {
                $latest[(int) $policy->construction_id] = $policy;
            });

        $resolved = [];

        foreach ($constructionIds as $constructionId) {
            $resolved[$constructionId] = $this->toResolved(
                $constructionId,
                $date,
                $latest[$constructionId] ?? null,
            );
        }

        return $resolved;
    }

    private function toResolved(int $constructionId, CarbonImmutable $date, ?SalesDiscountPolicy $policy): ResolvedSalesDiscountPolicy
    {
        if (! $policy instanceof SalesDiscountPolicy) {
            return ResolvedSalesDiscountPolicy::absent($constructionId, $date);
        }

        $basisPoints = IntegerMoney::basisPoints($policy->maximum_discount_percent);

        if ($basisPoints === null) {
            return ResolvedSalesDiscountPolicy::absent($constructionId, $date);
        }

        return ResolvedSalesDiscountPolicy::fromPolicy(
            constructionId: $constructionId,
            positionDate: $date,
            maximumDiscountBasisPoints: $basisPoints,
            effectiveFrom: $this->normalizeDate($policy->effective_from),
            policy: $policy,
        );
    }

    private function normalizeDate(mixed $value): CarbonImmutable
    {
        if ($value instanceof CarbonInterface) {
            return CarbonImmutable::parse($value->toDateString());
        }

        return CarbonImmutable::parse((string) $value)->startOfDay();
    }
}
