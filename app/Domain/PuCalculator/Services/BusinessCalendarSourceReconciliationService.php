<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\Enums\CalendarSourceReconciliationStatus;
use App\Domain\PuCalculator\Support\BusinessCalendarRegistry;
use App\Models\BusinessHoliday;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Reconcilia as fontes financeiras (ANBIMA e FEBRABAN) que sustentam o calendário consolidado
 * {@see BusinessCalendarRegistry::BR_FINANCIAL_MARKET}.
 *
 * O serviço CLASSIFICA e para. Ele não grava decisão, não materializa calendário e não desempata:
 * não há voto de maioria nem precedência silenciosa de uma fonte sobre a outra. `Conflict` e
 * `SourceOnly*` existem para que a divergência chegue a um humano em vez de ser resolvida sozinha.
 *
 * As duas fontes vivem em lugares diferentes de propósito. A ANBIMA permanece intocada em seu próprio
 * calendário (`BR_BANKING_ANBIMA`), preservando evidência, testes e auditoria já existentes; a FEBRABAN
 * é ingerida como evidência do calendário consolidado. Ler cada uma de onde ela realmente está evita
 * copiar dados de uma fonte oficial para outro código de calendário, o que criaria uma segunda verdade.
 *
 * Ausência de dado nunca vira confirmação: uma data que nenhuma fonte cobre é `Unknown`, e um período
 * sem cobertura é reportado como incompleto em {@see self::coverage()}.
 */
final class BusinessCalendarSourceReconciliationService
{
    public const MAX_RANGE_DAYS = 3660;

    /**
     * Reconciliação completa do período, data a data.
     *
     * @return array<string, mixed>
     */
    public function reconcile(CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($to->lt($from)) {
            throw new InvalidArgumentException('A data final não pode ser anterior à data inicial.');
        }

        if ($from->diffInDays($to) > self::MAX_RANGE_DAYS) {
            throw new InvalidArgumentException('A reconciliação administrativa está limitada a dez anos por execução.');
        }

        $anbima = $this->anbimaEvidence($from, $to);
        $febraban = $this->febrabanEvidence($from, $to);
        $specialHours = $this->specialHoursEvidence($from, $to);

        $dates = collect([...$anbima->keys(), ...$febraban->keys()])
            ->unique()
            ->sort()
            ->values();

        $rows = [];
        $tally = array_fill_keys(
            array_map(
                static fn (CalendarSourceReconciliationStatus $status): string => $status->value,
                CalendarSourceReconciliationStatus::cases(),
            ),
            0,
        );

        foreach ($dates as $dateKey) {
            $anbimaFact = $anbima->get($dateKey);
            $febrabanFact = $febraban->get($dateKey);
            $status = $this->classify($anbimaFact, $febrabanFact);
            $tally[$status->value]++;

            $rows[] = [
                'date' => $dateKey,
                'anbima' => $this->describe($anbimaFact),
                'febraban' => $this->describe($febrabanFact),
                'special_hours' => $this->describe($specialHours->get($dateKey)),
                'consolidated' => $status->isEligibleForMaterialization() ? 'non_business' : null,
                'status' => $status->value,
                'status_label' => $status->label(),
                'requires_human_decision' => $status->requiresHumanDecision(),
                'evidence' => $this->evidenceSources($anbimaFact, $febrabanFact),
            ];
        }

        // Datas de expediente especial que nenhuma fonte declarou como feriado de mercado precisam
        // aparecer explicitamente: é justamente esse o conjunto que não pode virar dia não útil.
        foreach ($specialHours as $dateKey => $fact) {
            if ($anbima->has($dateKey) || $febraban->has($dateKey)) {
                continue;
            }

            $rows[] = [
                'date' => $dateKey,
                'anbima' => null,
                'febraban' => null,
                'special_hours' => $this->describe($fact),
                'consolidated' => null,
                'status' => CalendarSourceReconciliationStatus::Unknown->value,
                'status_label' => CalendarSourceReconciliationStatus::Unknown->label(),
                'requires_human_decision' => false,
                'evidence' => [],
            ];
            $tally[CalendarSourceReconciliationStatus::Unknown->value]++;
        }

        usort($rows, static fn (array $a, array $b): int => $a['date'] <=> $b['date']);

        return [
            'calendar_code' => BusinessCalendarRegistry::BR_FINANCIAL_MARKET,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'norm_reference' => FebrabanHolidayImporter::NORM_REFERENCE,
            'totals' => $tally,
            'total_dates' => count($rows),
            'has_conflicts' => $tally[CalendarSourceReconciliationStatus::Conflict->value] > 0,
            'coverage' => $this->coverage($from, $to),
            'rows' => $rows,
        ];
    }

    /**
     * Reconciliação de UMA data, para explicar a decisão e sua proveniência.
     *
     * @return array<string, mixed>
     */
    public function explain(CarbonImmutable $date): array
    {
        $reconciliation = $this->reconcile($date->startOfDay(), $date->startOfDay());
        $dateKey = $date->toDateString();

        foreach ($reconciliation['rows'] as $row) {
            if ($row['date'] === $dateKey) {
                return $row;
            }
        }

        return [
            'date' => $dateKey,
            'anbima' => null,
            'febraban' => null,
            'special_hours' => null,
            'consolidated' => null,
            'status' => CalendarSourceReconciliationStatus::Unknown->value,
            'status_label' => CalendarSourceReconciliationStatus::Unknown->label(),
            'requires_human_decision' => false,
            'evidence' => [],
        ];
    }

    /**
     * Cobertura por fonte. Um período só é reconciliável onde AS DUAS fontes têm dados; fora disso o
     * resultado é incompleto, e dizer isso é obrigatório — a alternativa seria deixar a ausência de
     * dados passar por "segunda a sexta é dia útil".
     *
     * @return array<string, mixed>
     */
    public function coverage(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $anbimaYears = $this->coveredYears(BusinessCalendarRegistry::BR_BANKING_ANBIMA, 'anbima', $from, $to);
        $febrabanYears = $this->coveredYears(
            BusinessCalendarRegistry::BR_FINANCIAL_MARKET,
            FebrabanHolidayImporter::SOURCE_MARKET,
            $from,
            $to,
        );

        $requested = range($from->year, $to->year);
        $reconcilable = array_values(array_intersect($anbimaYears, $febrabanYears));
        $missing = array_values(array_diff($requested, $reconcilable));

        return [
            'requested_years' => $requested,
            'anbima_years' => $anbimaYears,
            'febraban_years' => $febrabanYears,
            'reconcilable_years' => $reconcilable,
            'years_without_full_coverage' => $missing,
            'covered_from' => $reconcilable === [] ? null : CarbonImmutable::create(min($reconcilable), 1, 1)->toDateString(),
            'covered_to' => $reconcilable === [] ? null : CarbonImmutable::create(max($reconcilable), 12, 31)->toDateString(),
            'coverage_status' => $missing === [] ? 'complete' : 'incomplete',
        ];
    }

    private function classify(?BusinessHoliday $anbima, ?BusinessHoliday $febraban): CalendarSourceReconciliationStatus
    {
        $anbimaSaysNonBusiness = $anbima instanceof BusinessHoliday && $anbima->removed_detected_at === null;
        $febrabanSaysNonBusiness = $febraban instanceof BusinessHoliday && $febraban->removed_detected_at === null;

        // Uma remoção detectada é uma retratação da fonte: ela deixou de afirmar o feriado, mas a linha
        // permanece para auditoria. Se a outra fonte ainda afirma, isso é divergência real.
        $anbimaRetracted = $anbima instanceof BusinessHoliday && $anbima->removed_detected_at !== null;
        $febrabanRetracted = $febraban instanceof BusinessHoliday && $febraban->removed_detected_at !== null;

        return match (true) {
            $anbimaSaysNonBusiness && $febrabanSaysNonBusiness => CalendarSourceReconciliationStatus::Confirmed,
            $anbimaSaysNonBusiness && $febrabanRetracted => CalendarSourceReconciliationStatus::Conflict,
            $febrabanSaysNonBusiness && $anbimaRetracted => CalendarSourceReconciliationStatus::Conflict,
            $anbimaSaysNonBusiness => CalendarSourceReconciliationStatus::SourceOnlyAnbima,
            $febrabanSaysNonBusiness => CalendarSourceReconciliationStatus::SourceOnlyFebraban,
            default => CalendarSourceReconciliationStatus::Unknown,
        };
    }

    /** @return list<string> */
    private function evidenceSources(?BusinessHoliday $anbima, ?BusinessHoliday $febraban): array
    {
        $evidence = [];

        if ($anbima instanceof BusinessHoliday && $anbima->removed_detected_at === null) {
            $evidence[] = 'ANBIMA';
        }

        if ($febraban instanceof BusinessHoliday && $febraban->removed_detected_at === null) {
            $evidence[] = 'FEBRABAN';
        }

        return $evidence;
    }

    /** @return array<string, mixed>|null */
    private function describe(?BusinessHoliday $holiday): ?array
    {
        if (! $holiday instanceof BusinessHoliday) {
            return null;
        }

        return [
            'decision' => $holiday->removed_detected_at === null ? 'non_business' : 'retracted',
            'name' => $holiday->name,
            'source' => $holiday->source,
            'source_document' => $holiday->source_document,
            'source_revision' => $holiday->source_revision,
            'source_is_official' => (bool) $holiday->source_is_official,
            'imported_at' => $holiday->imported_at?->toIso8601String(),
            'removed_detected_at' => $holiday->removed_detected_at?->toIso8601String(),
            'notes' => $holiday->notes,
        ];
    }

    /** @return Collection<string, BusinessHoliday> */
    private function anbimaEvidence(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return $this->evidence(BusinessCalendarRegistry::BR_BANKING_ANBIMA, ['anbima'], $from, $to);
    }

    /** @return Collection<string, BusinessHoliday> */
    private function febrabanEvidence(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return $this->evidence(
            BusinessCalendarRegistry::BR_FINANCIAL_MARKET,
            [FebrabanHolidayImporter::SOURCE_MARKET],
            $from,
            $to,
        );
    }

    /** @return Collection<string, BusinessHoliday> */
    private function specialHoursEvidence(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return $this->evidence(
            BusinessCalendarRegistry::BR_FINANCIAL_MARKET,
            [FebrabanHolidayImporter::SOURCE_SPECIAL_HOURS],
            $from,
            $to,
        );
    }

    /**
     * @param  list<string>  $sources
     * @return Collection<string, BusinessHoliday>
     */
    private function evidence(string $calendarCode, array $sources, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return BusinessHoliday::query()
            ->where('calendar_code', $calendarCode)
            ->whereIn('source', $sources)
            ->whereDate('holiday_date', '>=', $from->toDateString())
            ->whereDate('holiday_date', '<=', $to->toDateString())
            ->orderBy('holiday_date')
            ->get()
            ->keyBy(fn (BusinessHoliday $holiday): string => CarbonImmutable::instance($holiday->holiday_date)->toDateString());
    }

    /** @return list<int> */
    private function coveredYears(string $calendarCode, string $source, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $years = BusinessHoliday::query()
            ->where('calendar_code', $calendarCode)
            ->where('source', $source)
            ->whereDate('holiday_date', '>=', CarbonImmutable::create($from->year, 1, 1)->toDateString())
            ->whereDate('holiday_date', '<=', CarbonImmutable::create($to->year, 12, 31)->toDateString())
            ->pluck('holiday_date')
            ->map(static fn ($date): int => CarbonImmutable::parse((string) $date)->year)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return array_map(intval(...), $years);
    }
}
