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

    /**
     * Política de cada par (empreendimento, data) pedido, com um único
     * carregamento.
     *
     * A derivação valida vendas que aconteceram em dias diferentes da mesma
     * competência, e a política pode ter mudado entre elas. Resolver venda a
     * venda dispararia uma consulta por venda.
     *
     * @param  list<array{construction_id: int, date: CarbonInterface}>  $requests
     * @return array<string, ResolvedSalesDiscountPolicy> indexado por `{construction_id}@{Y-m-d}`
     */
    public function forConstructionDates(array $requests): array
    {
        if ($requests === []) {
            return [];
        }

        $normalized = array_map(
            fn (array $request): array => [
                'construction_id' => (int) $request['construction_id'],
                'date' => $this->normalizeDate($request['date']),
            ],
            $requests,
        );

        $constructionIds = array_values(array_unique(array_column($normalized, 'construction_id')));
        $latestDate = collect($normalized)
            ->map(fn (array $request): CarbonImmutable => $request['date'])
            ->sortDesc()
            ->first();

        $policiesByConstruction = [];

        SalesDiscountPolicy::query()
            ->whereIn('construction_id', $constructionIds)
            ->where('effective_from', '<=', InclusiveDateBound::upperBound($latestDate))
            ->orderBy('effective_from')
            ->orderBy('id')
            ->get()
            ->each(function (SalesDiscountPolicy $policy) use (&$policiesByConstruction): void {
                $policiesByConstruction[(int) $policy->construction_id][] = $policy;
            });

        $resolved = [];

        foreach ($normalized as $request) {
            $constructionId = $request['construction_id'];
            $date = $request['date'];
            $key = $constructionId.'@'.$date->toDateString();

            if (isset($resolved[$key])) {
                continue;
            }

            $resolved[$key] = $this->toResolved(
                $constructionId,
                $date,
                $this->latestUpTo($policiesByConstruction[$constructionId] ?? [], $date),
            );
        }

        return $resolved;
    }

    /**
     * Última política com vigência até a data, na lista já ordenada de forma
     * crescente. O desempate por `id` é herdado da ordenação.
     *
     * @param  list<SalesDiscountPolicy>  $policies
     */
    private function latestUpTo(array $policies, CarbonImmutable $date): ?SalesDiscountPolicy
    {
        $latest = null;

        foreach ($policies as $policy) {
            if ($this->normalizeDate($policy->effective_from)->greaterThan($date)) {
                break;
            }

            $latest = $policy;
        }

        return $latest;
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
