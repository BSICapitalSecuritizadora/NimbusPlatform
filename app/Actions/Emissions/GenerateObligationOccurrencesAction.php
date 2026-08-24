<?php

namespace App\Actions\Emissions;

use App\Enums\ObligationDueDateCalculationStatus;
use App\Enums\ObligationSeriesStatus;
use App\Models\Obligation;
use App\Models\ObligationHistoryEntry;
use App\Models\ObligationSeries;
use App\Models\ObligationSeriesRule;
use App\Services\Obligations\ObligationHistoryRecorder;
use App\Services\Obligations\ObligationScheduleCalculator;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GenerateObligationOccurrencesAction
{
    public function __construct(
        private readonly ObligationScheduleCalculator $scheduleCalculator,
    ) {}

    /**
     * @return array{series_analyzed: int, created: int, existing: int, skipped: int, resolved: int, pending: int}
     */
    public function handle(?CarbonInterface $referenceDate = null, ?int $seriesId = null): array
    {
        $referenceDate = CarbonImmutable::instance($referenceDate ?? now())->startOfDay();
        $result = [
            'series_analyzed' => 0,
            'created' => 0,
            'existing' => 0,
            'skipped' => 0,
            'resolved' => 0,
            'pending' => 0,
        ];

        ObligationSeries::query()
            ->where('status', ObligationSeriesStatus::Active->value)
            ->when($seriesId !== null, fn ($query) => $query->whereKey($seriesId))
            ->whereNotNull('frequency')
            ->whereNotNull('starts_on')
            ->whereNotNull('ends_on')
            ->whereNotNull('due_rule_type')
            ->with('rules.calendarSelectionEvidence')
            ->chunkById(100, function (Collection $seriesCollection) use ($referenceDate, &$result): void {
                foreach ($seriesCollection as $series) {
                    $result['series_analyzed']++;
                    $seriesResult = $this->generateForSeries($series, $referenceDate);

                    foreach (['created', 'existing', 'skipped', 'resolved', 'pending'] as $key) {
                        $result[$key] += $seriesResult[$key];
                    }
                }
            });

        Log::info('GenerateObligationOccurrences: concluído', $result);

        return $result;
    }

    /**
     * @return array{created: int, existing: int, skipped: int, resolved: int, pending: int}
     */
    public function generateForSeries(
        ObligationSeries $series,
        ?CarbonInterface $referenceDate = null,
    ): array {
        $referenceDate = CarbonImmutable::instance($referenceDate ?? now())->startOfDay();

        return DB::transaction(function () use ($series, $referenceDate): array {
            $lockedSeries = ObligationSeries::query()
                ->with('rules.calendarSelectionEvidence')
                ->lockForUpdate()
                ->findOrFail($series->getKey());

            if ($lockedSeries->status !== ObligationSeriesStatus::Active || ! $lockedSeries->frequency?->generatesAutomatically()) {
                if ($lockedSeries->status !== ObligationSeriesStatus::Active) {
                    return ['created' => 0, 'existing' => 0, 'skipped' => 1, 'resolved' => 0, 'pending' => 0];
                }

                $pendingResolution = $this->resolvePendingOccurrences($lockedSeries, $referenceDate);

                return [
                    'created' => 0,
                    'existing' => 0,
                    'skipped' => 1,
                    ...$pendingResolution,
                ];
            }

            $pendingResolution = $this->resolvePendingOccurrences($lockedSeries, $referenceDate);

            $horizonDays = max(1, $lockedSeries->generation_horizon_days ?: (int) config('obligations.recurrence.generation_horizon_days', 90));
            $horizon = $referenceDate->addDays($horizonDays)->endOfDay();
            $rules = $lockedSeries->rules->values();
            $created = 0;
            $existing = 0;
            $skipped = 0;
            $hasDocumentSourceOccurrence = $lockedSeries->occurrences()
                ->whereNotNull('extracted_obligation_id')
                ->exists();

            ObligationHistoryRecorder::usingSource(ObligationHistoryEntry::SOURCE_SCHEDULED_COMMAND, function () use (
                $lockedSeries,
                $rules,
                $horizon,
                $referenceDate,
                &$created,
                &$existing,
                &$skipped,
                &$hasDocumentSourceOccurrence,
                &$pendingResolution,
            ): void {
                foreach ($rules as $index => $rule) {
                    $nextRule = $rules->get($index + 1);
                    $candidates = $this->scheduleCalculator->occurrencesForRule(
                        $lockedSeries,
                        $rule,
                        $horizon,
                        $nextRule?->effective_from,
                    );

                    foreach ($candidates as $candidate) {
                        if ($lockedSeries->occurrences()->whereDate('competence_date', $candidate['competence_date'])->exists()) {
                            $existing++;

                            continue;
                        }

                        try {
                            $obligation = Obligation::create($this->occurrenceAttributes(
                                $lockedSeries,
                                $rule,
                                $candidate['competence_date'],
                                $candidate['due_date'],
                                $candidate['due_date_resolution'],
                                $referenceDate,
                                ! $hasDocumentSourceOccurrence,
                            ));
                        } catch (UniqueConstraintViolationException) {
                            $existing++;

                            continue;
                        }

                        $hasDocumentSourceOccurrence = $hasDocumentSourceOccurrence || $obligation->extracted_obligation_id !== null;
                        $created++;

                        if ($candidate['due_date'] === null) {
                            $pendingResolution['pending']++;
                        }
                    }

                    if ($candidates === []) {
                        $skipped++;
                    }
                }
            });

            return [
                ...compact('created', 'existing', 'skipped'),
                ...$pendingResolution,
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function occurrenceAttributes(
        ObligationSeries $series,
        ObligationSeriesRule $rule,
        CarbonInterface $competenceDate,
        ?CarbonInterface $dueDate,
        array $dueDateResolution,
        CarbonInterface $referenceDate,
        bool $includeDocumentSource,
    ): array {
        return [
            'emission_id' => $series->emission_id,
            'obligation_series_id' => $series->id,
            'obligation_series_rule_id' => $rule->id,
            'competence_date' => $competenceDate->toDateString(),
            'generation_source' => Obligation::GENERATION_SOURCE_AUTOMATIC,
            'generated_at' => now(),
            'extracted_obligation_id' => $includeDocumentSource ? $series->extracted_obligation_id : null,
            'responsible_user_id' => $series->responsible_user_id,
            'title' => $series->title,
            'obligation_type' => $series->obligation_type,
            'obligation_category' => $series->obligation_category,
            'description' => $series->description,
            'responsible_party' => $series->responsible_party,
            'responsible_area' => $series->responsible_area,
            'recurrence' => $rule->frequency->label(),
            'due_rule' => $series->due_rule,
            'due_date' => $dueDate?->toDateString(),
            'due_date_resolution' => $dueDateResolution,
            'due_date_calculation_status' => $dueDate === null
                ? ObligationDueDateCalculationStatus::AwaitingCalendar
                : ObligationDueDateCalculationStatus::Calculated,
            'priority' => $series->priority,
            'status' => $dueDate?->copy()->startOfDay()->lt($referenceDate) ? 'vencida' : 'a_vencer',
            'required_evidence' => $series->required_evidence,
            'source_clause' => $series->source_clause,
            'source_page' => $series->source_page,
            'source_excerpt' => $series->source_excerpt,
        ];
    }

    /** @return array{resolved:int,pending:int} */
    private function resolvePendingOccurrences(
        ObligationSeries $series,
        CarbonInterface $referenceDate,
    ): array {
        $resolved = 0;
        $pending = 0;

        $series->occurrences()
            ->where('due_date_calculation_status', ObligationDueDateCalculationStatus::AwaitingCalendar->value)
            ->with(['seriesRule.calendarSelectionEvidence', 'anchorEvent'])
            ->get()
            ->each(function (Obligation $obligation) use ($referenceDate, &$resolved, &$pending): void {
                $rule = $obligation->seriesRule;
                $calculationReference = $obligation->anchorEvent?->occurred_on ?? $obligation->competence_date;

                if (! $rule instanceof ObligationSeriesRule || $calculationReference === null) {
                    $pending++;

                    return;
                }

                $resolution = $this->scheduleCalculator->resolveDueDateWithExplanation($rule, $calculationReference);

                if ($resolution->dueDate === null) {
                    $pending++;

                    return;
                }

                $obligation->update([
                    'due_date' => $resolution->dueDate,
                    'due_date_resolution' => $resolution->toArray(),
                    'due_date_calculation_status' => ObligationDueDateCalculationStatus::Calculated,
                    'status' => $resolution->dueDate->lt($referenceDate->copy()->startOfDay())
                        ? 'vencida'
                        : 'a_vencer',
                ]);
                $resolved++;
            });

        return ['resolved' => $resolved, 'pending' => $pending];
    }
}
