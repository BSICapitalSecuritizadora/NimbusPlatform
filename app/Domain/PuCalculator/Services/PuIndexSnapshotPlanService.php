<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuIndexRateRequirement;
use App\Models\EmissionPuParameter;
use App\Models\IndexRate;
use Carbon\CarbonImmutable;

final class PuIndexSnapshotPlanService
{
    public function __construct(
        private readonly PuIndexRateRequirementResolver $rateRequirementResolver,
        private readonly IndexRateLookupService $indexRateLookup,
    ) {}

    /**
     * @return array{
     *     required_rate_dates:list<string>,
     *     present_rates:list<array<string,mixed>>,
     *     missing_rate_dates:list<string>,
     *     conflicting_rates:list<array<string,mixed>>,
     *     financial_requirement_start_date:string
     * }
     */
    public function inspect(
        EmissionPuParameter $parameter,
        CarbonImmutable $curveStartDate,
        CarbonImmutable $homologationEndDate,
        ?string $source,
        ?string $seriesCode,
        ?string $sourceReference,
        ?string $indexRateCalendarCode = null,
    ): array {
        if (! $parameter->indexer_enum->requiresIndexRates()) {
            return [
                'required_rate_dates' => [],
                'present_rates' => [],
                'missing_rate_dates' => [],
                'conflicting_rates' => [],
                'financial_requirement_start_date' => $curveStartDate->toDateString(),
            ];
        }

        $this->indexRateLookup->flushCache();
        $requirements = collect($this->rateRequirementResolver
            ->firstCouponPreIntegralizationRateRequirements($parameter, $indexRateCalendarCode));

        for ($curveDate = $curveStartDate; $curveDate->lte($homologationEndDate); $curveDate = $curveDate->addDay()) {
            $requirement = $this->rateRequirementResolver->resolve($parameter, $curveDate, $indexRateCalendarCode);

            if ($requirement->isRequiredForCalculation()) {
                $requirements->push($requirement);
            }
        }

        $requiredRateDates = $requirements
            ->filter(fn (PuIndexRateRequirement $requirement): bool => $requirement->requiredRateDate() !== null)
            ->map(fn (PuIndexRateRequirement $requirement): string => $requirement->requiredRateDate()->toDateString())
            ->unique()
            ->sort()
            ->values()
            ->all();
        $requiredWindowStart = $requiredRateDates === []
            ? null
            : CarbonImmutable::parse($requiredRateDates[0])->startOfDay();
        $requiredWindowEnd = $requiredRateDates === []
            ? null
            : CarbonImmutable::parse($requiredRateDates[array_key_last($requiredRateDates)])->endOfDay();
        // `whereBetween` reduz a consulta a uma varredura contígua, mas a
        // janela inclui o dia final inteiro. A classificação continua
        // determinística: somente as datas exatas requeridas entram no mapa,
        // então uma taxa gravada numa data intermediária NÃO requerida não pode
        // virar present, eliminar missing nem gerar conflito.
        $requiredRateDateIndex = array_fill_keys($requiredRateDates, true);
        $storedRates = $requiredRateDates === []
            ? collect()
            : IndexRate::query()
                ->forIndexer($parameter->indexer_enum)
                ->whereBetween('rate_date', [
                    $requiredWindowStart,
                    $requiredWindowEnd,
                ])
                ->orderBy('rate_date')
                ->get()
                ->keyBy(fn (IndexRate $rate): string => $rate->rate_date->toDateString())
                ->filter(fn (IndexRate $rate, string $rateDate): bool => isset($requiredRateDateIndex[$rateDate]));
        $presentRates = [];
        $missingRateDates = [];
        $conflictingRates = [];

        foreach ($requiredRateDates as $requiredRateDate) {
            /** @var IndexRate|null $storedRate */
            $storedRate = $storedRates->get($requiredRateDate);

            if (! $storedRate instanceof IndexRate) {
                $missingRateDates[] = $requiredRateDate;

                continue;
            }

            $conflictReasons = $this->storedRateConflictReasons(
                $storedRate,
                $source,
                $seriesCode,
                $sourceReference,
            );

            if ($conflictReasons !== []) {
                $conflictingRates[] = [
                    'date' => $requiredRateDate,
                    'existing' => $this->ratePayload($storedRate),
                    'expected_source' => $source,
                    'expected_series' => $seriesCode,
                    'reasons' => $conflictReasons,
                ];

                continue;
            }

            $presentRates[] = $this->ratePayload($storedRate);
        }

        return [
            'required_rate_dates' => $requiredRateDates,
            'present_rates' => $presentRates,
            'missing_rate_dates' => $missingRateDates,
            'conflicting_rates' => $conflictingRates,
            'financial_requirement_start_date' => $this->rateRequirementResolver
                ->firstCouponPreIntegralizationFinancialCalendarStartDate($parameter, $indexRateCalendarCode)?->toDateString()
                ?? $curveStartDate->toDateString(),
        ];
    }

    /** @return list<string> */
    private function storedRateConflictReasons(
        IndexRate $rate,
        ?string $source,
        ?string $seriesCode,
        ?string $sourceReference,
    ): array {
        $reasons = [];

        if ($source === null || $rate->source !== $source) {
            $reasons[] = 'source_mismatch';
        }

        if ($seriesCode === null || (string) $rate->external_series_code !== $seriesCode) {
            $reasons[] = 'series_mismatch';
        }

        // Provenance da fonte: aceita a referência exata (`bcb_sgs:4389`, gravada
        // pelo sync legado) e referências derivadas legítimas da MESMA série
        // (`bcb_sgs:4389:<sufixo>`). O separador `:` garante identidade
        // semântica — `bcb_sgs:43890` não casa com `bcb_sgs:4389`. A série
        // também é confrontada isoladamente em `external_series_code`.
        $storedSourceReference = (string) $rate->source_reference;

        if ($sourceReference === null
            || ($storedSourceReference !== $sourceReference
                && ! str_starts_with($storedSourceReference, $sourceReference.':'))) {
            $reasons[] = 'source_reference_mismatch';
        }

        if ($rate->isProjectedRate()) {
            $reasons[] = 'projected_rate_not_allowed';
        }

        if (preg_match('/^\d+(?:\.\d+)?$/', (string) $rate->rate_value) !== 1) {
            $reasons[] = 'invalid_rate_value';
        }

        return $reasons;
    }

    /** @return array<string, mixed> */
    private function ratePayload(IndexRate $rate): array
    {
        return [
            'id' => $rate->id,
            'date' => $rate->rate_date?->toDateString(),
            'value' => (string) $rate->rate_value,
            'source' => $rate->source,
            'source_reference' => $rate->source_reference,
            'external_series_code' => $rate->external_series_code,
        ];
    }
}
