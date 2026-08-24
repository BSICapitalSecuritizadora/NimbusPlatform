<?php

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\Contracts\BusinessDayCalendar;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexRateLookupMode;
use App\Domain\PuCalculator\Exceptions\PuCalendarHomologationException;
use App\Models\BusinessCalendar;
use App\Models\Emission;
use App\Models\EmissionPuParameter;
use App\Models\PuCalendarHomologation;
use App\Models\User;
use Carbon\CarbonImmutable;

final class PuCalendarHomologationGuard
{
    /** @var list<string> */
    public const REQUIRED_EVIDENCE_FUNCTIONS = [
        'daily_application',
        'dup',
        'spread',
        'index_lag',
        'index_lookup',
        'business_day_basis',
        'payment_dates',
    ];

    public function __construct(
        private readonly BusinessCalendarCatalogService $catalog,
        private readonly BusinessCalendarYearService $calendarYears,
        private readonly BusinessDayCalendar $businessDayCalendar,
    ) {}

    public function activeCdiParameter(Emission $emission): EmissionPuParameter
    {
        $emission->loadMissing('puParameter');
        $parameter = $emission->puParameter;

        if (! $parameter instanceof EmissionPuParameter) {
            throw new PuCalendarHomologationException('A emissão não possui configuração ativa de PU para estabelecer o cenário legado.');
        }

        if ($parameter->indexer_enum !== PuIndexer::Cdi) {
            throw new PuCalendarHomologationException('A homologação de calendário desta fase é exclusiva para emissões CDI.');
        }

        return $parameter;
    }

    public function assertCandidateCalendarAllowed(string $calendarCode): BusinessCalendar
    {
        try {
            $calendar = $this->catalog->findOrFail($calendarCode);
        } catch (\InvalidArgumentException $exception) {
            throw new PuCalendarHomologationException($exception->getMessage(), previous: $exception);
        }

        if (
            ! $calendar->financial_use_allowed
            || ! $calendar->available_for_new_configurations
            || $calendar->is_legacy
            || $calendar->is_homologation
        ) {
            throw new PuCalendarHomologationException(sprintf(
                'O calendário %s não é permitido como candidato financeiro. Calendários legados, HML ou sem fonte aprovada permanecem apenas como evidência comparativa.',
                $calendar->code,
            ));
        }

        return $calendar;
    }

    public function assertPeriod(
        EmissionPuParameter $parameter,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
    ): void {
        $curveStart = CarbonImmutable::instance($parameter->curve_start_date);
        $curveEnd = CarbonImmutable::instance($parameter->curve_end_date);

        if ($periodStart->gt($periodEnd)) {
            throw new PuCalendarHomologationException('A data inicial da comparação deve ser anterior ou igual à data final.');
        }

        if ($periodStart->lt($curveStart) || $periodEnd->gt($curveEnd)) {
            throw new PuCalendarHomologationException(sprintf(
                'O período deve estar contido na curva ativa (%s a %s).',
                $curveStart->toDateString(),
                $curveEnd->toDateString(),
            ));
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function calendarCoverageSnapshot(
        string $calendarCode,
        CarbonImmutable $calculationStart,
        CarbonImmutable $periodEnd,
        PuIndexRateLookupMode $lookupMode,
        int $businessDayLag,
    ): array {
        $coverageStart = $calculationStart;
        $coverageEnd = $periodEnd;

        if ($lookupMode === PuIndexRateLookupMode::BusinessDayLagExact && $businessDayLag !== 0) {
            $shiftedStart = $this->businessDayCalendar->shiftBusinessDays(
                $calculationStart,
                $businessDayLag,
                $calendarCode,
            );
            $shiftedEnd = $this->businessDayCalendar->shiftBusinessDays(
                $periodEnd,
                $businessDayLag,
                $calendarCode,
            );
            $coverageStart = collect([$calculationStart, $shiftedStart, $shiftedEnd])
                ->sortBy(fn (CarbonImmutable $date): int => $date->getTimestamp())
                ->first();
            $coverageEnd = collect([$periodEnd, $shiftedStart, $shiftedEnd])
                ->sortByDesc(fn (CarbonImmutable $date): int => $date->getTimestamp())
                ->first();
        }

        $coverage = $this->calendarYears->coverageForRange($calendarCode, $coverageStart, $coverageEnd);

        foreach ($coverage as $year => $snapshot) {
            if (($snapshot['coverage_status'] ?? 'none') !== 'complete') {
                throw new PuCalendarHomologationException(sprintf(
                    'O calendário candidato %s não possui cobertura completa em %d. A comparação não usará fallback técnico.',
                    $calendarCode,
                    $year,
                ));
            }
        }

        return $coverage;
    }

    /** @param  list<array<string, mixed>>  $matrix */
    public function assertEvidenceRecorded(array $matrix): void
    {
        $byFunction = collect($matrix)->keyBy('function');

        foreach (self::REQUIRED_EVIDENCE_FUNCTIONS as $function) {
            $row = $byFunction->get($function);

            if (! is_array($row)) {
                throw new PuCalendarHomologationException(sprintf('A matriz de evidências não contém a função material [%s].', $function));
            }

            foreach (['rule', 'document', 'reference', 'interpretation', 'confidence'] as $field) {
                if (blank($row[$field] ?? null)) {
                    throw new PuCalendarHomologationException(sprintf(
                        'A evidência de [%s] precisa informar %s.',
                        $function,
                        $field,
                    ));
                }
            }
        }
    }

    /** @param  list<array<string, mixed>>  $matrix */
    public function assertEvidenceReadyForReview(array $matrix): void
    {
        $this->assertEvidenceRecorded($matrix);

        foreach ($matrix as $row) {
            $confidence = mb_strtolower(trim((string) ($row['confidence'] ?? '')));

            if (in_array($confidence, ['baixa', 'indeterminada', 'low', 'indeterminate'], true)) {
                throw new PuCalendarHomologationException(sprintf(
                    'A função [%s] ainda possui confiança %s. Resolva a interpretação material antes da revisão.',
                    (string) ($row['function'] ?? 'não identificada'),
                    (string) ($row['confidence'] ?? 'indeterminada'),
                ));
            }
        }
    }

    public function assertPrimarySelectionEvidence(
        PuCalendarHomologation $homologation,
        bool $mustBeConfirmed = false,
    ): void {
        $evidence = $homologation->selectionEvidence()
            ->where('context', 'pu_calendar_homologation_candidate')
            ->latest('id')
            ->first();

        if (
            $evidence === null
            || blank($evidence->source_document)
            || blank($evidence->clause_reference)
            || blank($evidence->excerpt)
        ) {
            throw new PuCalendarHomologationException(
                'Registre documento, cláusula/página e excerto que sustentam o calendário candidato.',
            );
        }

        if ($mustBeConfirmed && $evidence->confirmed_at === null) {
            throw new PuCalendarHomologationException(
                'A evidência principal do calendário ainda não foi confirmada por um responsável.',
            );
        }
    }

    /** @param  array<string, mixed>|null  $externalReference */
    public function assertExternalReferenceAssessed(?array $externalReference): void
    {
        $availability = (string) ($externalReference['availability'] ?? 'not_assessed');

        if (! in_array($availability, ['available', 'unavailable'], true)) {
            throw new PuCalendarHomologationException('Informe se existe gabarito externo para esta emissão.');
        }

        if ($availability === 'available' && blank($externalReference['result'] ?? null)) {
            throw new PuCalendarHomologationException('O resultado contra o gabarito externo é obrigatório quando a planilha está disponível.');
        }
    }

    /** @param  array<int, array<string, mixed>>  $coverage */
    public function assertGovernanceConfirmed(array $coverage): void
    {
        $unconfirmedYears = collect($coverage)
            ->reject(fn (array $snapshot): bool => ($snapshot['state'] ?? null) === 'confirmed')
            ->keys()
            ->all();

        if ($unconfirmedYears !== []) {
            throw new PuCalendarHomologationException(sprintf(
                'A comparação pode ser executada, mas não enviada para aprovação: o calendário candidato não está confirmado em %s.',
                implode(', ', $unconfirmedYears),
            ));
        }
    }

    public function assertReviewerIsIndependent(?int $analyzedBy, ?int $executedBy, User $reviewer): void
    {
        if (method_exists($reviewer, 'hasRole') && $reviewer->hasRole('super-admin')) {
            return;
        }

        if (in_array($reviewer->id, array_filter([$analyzedBy, $executedBy]), true)) {
            throw new PuCalendarHomologationException(
                'A decisão exige maker/checker: quem analisou ou executou a comparação não pode aprová-la.',
            );
        }
    }
}
