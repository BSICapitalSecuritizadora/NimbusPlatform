<?php

namespace App\Services\Obligations;

use App\Domain\PuCalculator\Services\BusinessCalendarCatalogService;
use App\Domain\PuCalculator\Services\BusinessCalendarYearService;
use App\Enums\ObligationDueRuleType;
use App\Exceptions\ObligationCalendarCoverageException;
use App\Models\BusinessCalendar;
use App\Models\ObligationSeries;
use App\Models\ObligationSeriesRule;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class ObligationCalendarGuard
{
    public function __construct(
        private readonly BusinessCalendarCatalogService $catalog,
        private readonly BusinessCalendarYearService $yearService,
    ) {}

    /**
     * @param  array<string, mixed>  $configuration
     */
    public function validateForActivation(
        array $configuration,
        ?ObligationSeries $existingSeries = null,
    ): void {
        $this->validateForConfiguration($configuration, $existingSeries);
    }

    /**
     * Valida somente a estrutura e a governança da escolha. Cobertura temporal
     * pertence à materialização ou ao cálculo, quando as datas são conhecidas.
     *
     * @param  array<string, mixed>  $configuration
     */
    public function validateForConfiguration(
        array $configuration,
        ?ObligationSeries $existingSeries = null,
    ): void {
        $ruleType = ObligationDueRuleType::tryFrom((string) ($configuration['due_rule_type'] ?? ''));

        if (! $ruleType?->requiresBusinessCalendar()) {
            return;
        }

        $calendarCode = strtoupper(trim((string) ($configuration['calendar_code'] ?? '')));

        try {
            $calendar = $this->catalog->findOrFail($calendarCode);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'calendar_code' => $exception->getMessage(),
            ]);
        }

        if (! $this->isAllowedForConfiguration($calendar, $existingSeries)) {
            throw ValidationException::withMessages([
                'calendar_code' => sprintf(
                    'O calendário %s não está liberado no catálogo para novas regras contratuais de obrigação.',
                    $calendar->code,
                ),
            ]);
        }
    }

    public function assertDateCovered(ObligationSeriesRule $rule, CarbonImmutable $date): void
    {
        if (! $this->requiresStrictCoverage($rule)) {
            return;
        }

        $calendarCode = (string) $rule->calendar_code;
        $coverage = $this->yearService->coverage($calendarCode, $date->year);

        if ($coverage['confirmed']) {
            return;
        }

        throw new ObligationCalendarCoverageException(
            $calendarCode,
            $date->toDateString(),
            $coverage,
        );
    }

    /** @return list<array<string, mixed>> */
    public function calendarSnapshotForRange(
        ObligationSeriesRule $rule,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): array {
        if (blank($rule->calendar_code)) {
            return [];
        }

        return collect($this->yearService->coverageForRange(
            (string) $rule->calendar_code,
            $from->min($to),
            $from->max($to),
        ))
            ->map(fn (array $coverage): array => [
                'year' => $coverage['year'],
                'calendar_year_id' => $coverage['calendar_year_id'],
                'revision' => $coverage['revision'],
                'checksum' => $coverage['checksum'],
                'source_revision' => $coverage['source_revision'],
                'coverage_basis' => $coverage['coverage_basis'],
                'coverage_status' => $coverage['coverage_status'],
                'governance_status' => $coverage['governance_status'],
                'operational_state' => $coverage['state'],
            ])
            ->values()
            ->all();
    }

    public function validateForReactivation(ObligationSeries $series, ObligationSeriesRule $rule): void
    {
        if (! $this->requiresStrictCoverage($rule)) {
            return;
        }

        $this->validateForActivation([
            'starts_on' => $series->starts_on,
            'ends_on' => $series->ends_on,
            'due_rule_type' => $rule->due_rule_type?->value,
            'due_offset_months' => $rule->due_offset_months,
            'relative_offset_quantity' => $rule->relative_offset_quantity,
            'calendar_code' => $rule->calendar_code,
        ], $series);
    }

    private function isAllowedForConfiguration(BusinessCalendar $calendar, ?ObligationSeries $existingSeries): bool
    {
        $retainsExistingCalendar = $existingSeries?->rules()
            ->where('calendar_code', $calendar->code)
            ->exists() ?? false;

        if ($retainsExistingCalendar) {
            return true;
        }

        return $calendar->available_for_new_configurations
            && $calendar->financial_use_allowed
            && ! $calendar->is_homologation
            && ! $calendar->is_legacy;
    }

    private function requiresStrictCoverage(ObligationSeriesRule $rule): bool
    {
        return $rule->due_rule_type === ObligationDueRuleType::BusinessDaysRelativeToEvent
            || $rule->hasConfirmedCalendarEvidence();
    }
}
