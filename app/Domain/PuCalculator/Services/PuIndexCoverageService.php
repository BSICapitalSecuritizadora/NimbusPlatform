<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuIndexCoverageReport;
use App\Models\BusinessCalendarDate;
use App\Models\Emission;
use App\Models\EmissionPuParameter;
use App\Models\IndexRate;
use Carbon\CarbonImmutable;

class PuIndexCoverageService
{
    private const PROJECTED_SOURCE_REFERENCE = 'forward_projection';

    public function __construct(
        private readonly BusinessCalendarCoverageService $calendarCoverage,
        private readonly PuIndexRateRequirementResolver $indexRateRequirementResolver,
    ) {}

    public function report(Emission $emission): PuIndexCoverageReport
    {
        $emission->loadMissing('puParameter');
        $parameter = $emission->puParameter;

        if ($parameter === null) {
            return new PuIndexCoverageReport(
                hasParameter: false,
                indexer: null,
                startDate: null,
                endDate: null,
                missingCalendarDates: [],
                missingIndexDates: [],
                projectedIndexDates: [],
                lastAvailableIndexDate: $this->lastAvailableIndexDate(null),
            );
        }

        $startDate = $parameter->curve_start_date !== null
            ? CarbonImmutable::instance($parameter->curve_start_date)
            : null;
        $endDate = $parameter->curve_end_date !== null
            ? CarbonImmutable::instance($parameter->curve_end_date)
            : null;

        $indexer = (string) $parameter->indexer;
        $lastAvailable = $this->lastAvailableIndexDate($indexer);

        if ($startDate === null || $endDate === null || $endDate->lt($startDate)) {
            return new PuIndexCoverageReport(
                hasParameter: true,
                indexer: $indexer,
                startDate: $startDate?->toDateString(),
                endDate: $endDate?->toDateString(),
                missingCalendarDates: [],
                missingIndexDates: [],
                projectedIndexDates: [],
                lastAvailableIndexDate: $lastAvailable,
            );
        }

        $missingCalendarDates = $this->missingCalendarDates($startDate, $endDate, (string) $parameter->calendar_code);
        [
            $missingIndexDates,
            $projectedIndexDates,
            $missingIndexMessages,
            $pendingIndexDates,
            $pendingIndexMessages,
        ] = $this->indexCoverage($parameter, $startDate, $endDate);

        return new PuIndexCoverageReport(
            hasParameter: true,
            indexer: $indexer,
            startDate: $startDate->toDateString(),
            endDate: $endDate->toDateString(),
            missingCalendarDates: $missingCalendarDates,
            missingIndexDates: $missingIndexDates,
            projectedIndexDates: $projectedIndexDates,
            lastAvailableIndexDate: $lastAvailable,
            missingIndexMessages: $missingIndexMessages,
            pendingIndexDates: $pendingIndexDates,
            pendingIndexMessages: $pendingIndexMessages,
        );
    }

    /**
     * @return list<string>
     */
    private function missingCalendarDates(CarbonImmutable $startDate, CarbonImmutable $endDate, string $calendarCode): array
    {
        // Calendarios auto-completaveis (B3) sao preenchidos automaticamente na geracao, logo nunca
        // representam um bloqueio real de cobertura para o dashboard/comando de dados faltantes.
        if ($calendarCode === '' || $this->calendarCoverage->willAutoComplete($calendarCode)) {
            return [];
        }

        $availableDates = BusinessCalendarDate::query()
            ->where('calendar_code', $calendarCode)
            ->whereDate('calendar_date', '>=', $startDate->toDateString())
            ->whereDate('calendar_date', '<=', $endDate->toDateString())
            ->pluck('calendar_date')
            ->mapWithKeys(fn ($calendarDate): array => [CarbonImmutable::parse((string) $calendarDate)->toDateString() => true])
            ->all();

        $missing = [];
        for ($currentDate = $startDate; $currentDate->lte($endDate); $currentDate = $currentDate->addDay()) {
            if (! isset($availableDates[$currentDate->toDateString()])) {
                $missing[] = $currentDate->toDateString();
            }
        }

        return $missing;
    }

    /**
     * @return array{0: list<string>, 1: list<string>, 2: list<string>, 3: list<string>, 4: list<string>}
     */
    private function indexCoverage(EmissionPuParameter $parameter, CarbonImmutable $startDate, CarbonImmutable $endDate): array
    {
        $missing = [];
        $projected = [];
        $missingMessages = [];
        $pending = [];
        $pendingMessages = [];
        $lastResolvedDate = null;

        for ($currentDate = $startDate->addDay(); $currentDate->lte($endDate); $currentDate = $currentDate->addDay()) {
            try {
                $rateRequirement = $this->indexRateRequirementResolver->resolve($parameter, $currentDate);
            } catch (\Throwable $exception) {
                $dateKey = $currentDate->toDateString();
                $missing[$dateKey] = $dateKey;
                $missingMessages[$dateKey] = sprintf(
                    'Nao foi possivel resolver a Taxa DI requerida para a data da curva %s. Modo: %s. Calendario: %s. Lag: %d dia(s) util(eis). Motivo: %s',
                    $dateKey,
                    $parameter->index_rate_lookup_mode_enum->name,
                    (string) $parameter->calendar_code,
                    (int) $parameter->index_rate_lag_business_days,
                    $exception->getMessage(),
                );

                continue;
            }

            if (! $rateRequirement->isRequiredForCalculation()) {
                continue;
            }

            $snapshot = $rateRequirement->rate;
            $requiredRateDate = $rateRequirement->requiredRateDate();
            $dateKey = $requiredRateDate?->toDateString() ?? $currentDate->toDateString();

            if ($snapshot === null) {
                if ($this->indexRateRequirementResolver->isAwaitingPublication(
                    $parameter,
                    $rateRequirement,
                    $lastResolvedDate !== null,
                )) {
                    $pending[$dateKey] = $dateKey;
                    $pendingMessages[$dateKey] = "Taxa DI aguardando publicação para a cauda futura da curva.\n\n{$rateRequirement->missingRateMessage()}";

                    break;
                }

                $missing[$dateKey] = $dateKey;
                $missingMessages[$dateKey] ??= $rateRequirement->missingRateMessage();

                continue;
            }

            $lastResolvedDate = $currentDate;

            if ($snapshot->isProjected) {
                $projected[$dateKey] = $dateKey;
            }
        }

        return [
            array_values($missing),
            array_values($projected),
            array_values($missingMessages),
            array_values($pending),
            array_values($pendingMessages),
        ];
    }

    private function lastAvailableIndexDate(?string $indexer): ?string
    {
        $query = IndexRate::query()
            ->where('source_reference', '!=', self::PROJECTED_SOURCE_REFERENCE);

        if ($indexer !== null) {
            $query->forIndexer($indexer);
        }

        $date = $query->max('rate_date');

        return $date !== null ? CarbonImmutable::parse((string) $date)->toDateString() : null;
    }
}
