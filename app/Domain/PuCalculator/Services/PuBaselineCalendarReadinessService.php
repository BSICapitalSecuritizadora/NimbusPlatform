<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\Support\BusinessCalendarRegistry;
use App\Models\BusinessCalendarYear;
use Carbon\CarbonImmutable;
use Throwable;

final class PuBaselineCalendarReadinessService
{
    public function __construct(
        private readonly BusinessCalendarYearService $calendarYears,
        private readonly BusinessCalendarCatalogService $catalog,
        private readonly NationalLegalCalendarReviewService $nationalCalendarReview,
    ) {}

    /** @return array<string, mixed> */
    public function evaluate(
        ?string $calendarCode,
        ?CarbonImmutable $from,
        ?CarbonImmutable $to,
    ): array {
        $base = [
            'calendar_code' => $calendarCode,
            'calendar_label' => $calendarCode,
            'required_from' => $from?->toDateString(),
            'required_to' => $to?->toDateString(),
            'resolvable' => false,
            'technical_coverage_satisfied' => false,
            'administratively_confirmed' => false,
            'is_national_legal_calendar' => false,
            'years' => [],
        ];

        if (blank($calendarCode) || $from === null || $to === null || $to->lt($from)) {
            return $base;
        }

        $normalized = BusinessCalendarRegistry::normalize($calendarCode);

        try {
            $calendar = $this->catalog->findOrFail($normalized);
        } catch (Throwable) {
            return [...$base, 'calendar_code' => $normalized];
        }

        if ($normalized === BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS) {
            return [
                ...$base,
                ...$this->nationalCalendarReview->reviewRange($from->year, $to->year),
                'calendar_code' => $normalized,
                'calendar_label' => $calendar->selectionLabel(),
                'required_from' => $from->toDateString(),
                'required_to' => $to->toDateString(),
                'resolvable' => true,
                'is_national_legal_calendar' => true,
            ];
        }

        $years = collect($this->calendarYears->coverageForRange($normalized, $from, $to))
            ->map(fn (array $coverage): array => [
                ...$coverage,
                'technical_criteria_satisfied' => $coverage['coverage_status'] === 'complete',
                'review_state' => match (true) {
                    $coverage['coverage_status'] !== 'complete' => 'technical_review_blocked',
                    $coverage['governance_status'] === BusinessCalendarYear::STATUS_CONFIRMED => 'confirmed',
                    default => 'ready_for_administrative_review',
                },
            ])
            ->all();

        return [
            ...$base,
            'calendar_code' => $normalized,
            'calendar_label' => $calendar->selectionLabel(),
            'resolvable' => true,
            // Sem nenhum ano avaliado não existe cobertura para afirmar:
            // `collect([])->every()` devolve true e transformaria "não sei" em
            // "está pronto", que é exatamente o contrário do que o gate faz.
            'technical_coverage_satisfied' => $years !== [] && collect($years)->every(
                fn (array $year): bool => $year['technical_criteria_satisfied'],
            ),
            'administratively_confirmed' => $years !== [] && collect($years)->every(
                fn (array $year): bool => $year['governance_status'] === BusinessCalendarYear::STATUS_CONFIRMED,
            ),
            'years' => $years,
        ];
    }
}
