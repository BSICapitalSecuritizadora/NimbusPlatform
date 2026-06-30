<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Models\BusinessCalendarDate;
use App\Models\BusinessHoliday;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

/**
 * Cobertura e preenchimento (backfill) do calendario de dias uteis.
 *
 * Para calendarios auto-completaveis (B3, por config) as datas faltantes sao geradas de forma
 * idempotente: fim de semana = nao util; dia de semana = util. Feriados NAO sao derivados aqui —
 * quando relevantes, devem ser cadastrados/importados manualmente (is_business_day=false). Como o
 * backfill so insere datas SEM linha persistida, feriados cadastrados manualmente nunca sao
 * sobrescritos.
 */
class BusinessCalendarCoverageService
{
    private const INSERT_CHUNK = 500;

    public function autoCompleteEnabled(): bool
    {
        return (bool) config('pu_calculator.business_calendar.auto_complete', true);
    }

    public function isAutoCompletable(string $calendarCode): bool
    {
        if ($calendarCode === '') {
            return false;
        }

        $codes = array_map(
            static fn ($code): string => strtoupper((string) $code),
            (array) config('pu_calculator.business_calendar.auto_completable_codes', ['B3']),
        );

        return in_array(strtoupper($calendarCode), $codes, true);
    }

    public function willAutoComplete(string $calendarCode): bool
    {
        return $this->autoCompleteEnabled() && $this->isAutoCompletable($calendarCode);
    }

    /**
     * Datas de calendario (todas, inclusive fins de semana) sem linha persistida no periodo.
     *
     * @return list<string>
     */
    public function missingDates(string $calendarCode, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($calendarCode === '' || $to->lt($from)) {
            return [];
        }

        $existing = $this->existingDates($calendarCode, $from, $to);

        $missing = [];
        for ($date = $from; $date->lte($to); $date = $date->addDay()) {
            if (! isset($existing[$date->toDateString()])) {
                $missing[] = $date->toDateString();
            }
        }

        return $missing;
    }

    /**
     * Resumo de cobertura do calendario no periodo, diferenciando dia util, fim de semana e feriado
     * importado. `weekend_only` indica que o periodo nao tem nenhum feriado cadastrado (calendario
     * derivado apenas de fins de semana — menos preciso na base 252 para curvas longas de CDI/Prefixado).
     *
     * @return array{calendar_code:string, from:string, to:string, total_days:int, covered_days:int, missing_count:int, first_missing:?string, auto_completable:bool, holiday_count:int, weekend_only:bool}
     */
    public function summary(string $calendarCode, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $missing = $this->missingDates($calendarCode, $from, $to);
        $holidays = $this->holidayDates($calendarCode, $from, $to);
        $totalDays = $to->lt($from) ? 0 : ($from->diffInDays($to) + 1);

        return [
            'calendar_code' => $calendarCode,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'total_days' => $totalDays,
            'covered_days' => max(0, $totalDays - count($missing)),
            'missing_count' => count($missing),
            'first_missing' => $missing[0] ?? null,
            'auto_completable' => $this->willAutoComplete($calendarCode),
            'holiday_count' => count($holidays),
            'weekend_only' => $holidays === [],
        ];
    }

    /**
     * Indica se o periodo nao tem nenhum feriado cadastrado para o calendario (derivacao weekend-only).
     */
    public function isWeekendOnly(string $calendarCode, CarbonImmutable $from, CarbonImmutable $to): bool
    {
        return $this->holidayDates($calendarCode, $from, $to) === [];
    }

    /**
     * Feriados cadastrados (qualquer fonte) para o calendario no periodo.
     *
     * @return array<string, ?string> indexado por `YYYY-MM-DD` => nome do feriado
     */
    public function holidayDates(string $calendarCode, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($calendarCode === '' || $to->lt($from)) {
            return [];
        }

        return BusinessHoliday::query()
            ->where('calendar_code', $calendarCode)
            ->whereDate('holiday_date', '>=', $from->toDateString())
            ->whereDate('holiday_date', '<=', $to->toDateString())
            ->get(['holiday_date', 'name'])
            ->mapWithKeys(fn (BusinessHoliday $holiday): array => [
                CarbonImmutable::instance($holiday->holiday_date)->toDateString() => $holiday->name,
            ])
            ->all();
    }

    /**
     * Preenche (idempotente) as datas faltantes do periodo. Nunca sobrescreve linhas existentes.
     *
     * @return array{calendar_code:string, from:string, to:string, created:int, would_create:int, business_days:int, non_business_days:int, total_days:int, dry_run:bool, holiday_count:int, weekend_only:bool}
     */
    public function backfill(string $calendarCode, CarbonImmutable $from, CarbonImmutable $to, bool $dryRun = false): array
    {
        $missing = $this->missingDates($calendarCode, $from, $to);
        $holidays = $this->holidayDates($calendarCode, $from, $to);

        $rows = [];
        $businessDays = 0;
        $nonBusinessDays = 0;
        $timestamp = Date::now();

        foreach ($missing as $dateString) {
            $isHoliday = array_key_exists($dateString, $holidays);
            $isBusinessDay = ! $isHoliday && ! CarbonImmutable::parse($dateString)->isWeekend();
            $isBusinessDay ? $businessDays++ : $nonBusinessDays++;

            $rows[] = [
                'calendar_code' => $calendarCode,
                'calendar_date' => $dateString,
                'is_business_day' => $isBusinessDay,
                'description' => $isHoliday ? $holidays[$dateString] : null,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        $created = 0;

        if (! $dryRun && $rows !== []) {
            foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
                BusinessCalendarDate::query()->insert($chunk);
            }

            $created = count($rows);
        }

        return [
            'calendar_code' => $calendarCode,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'created' => $created,
            'would_create' => count($rows),
            'business_days' => $businessDays,
            'non_business_days' => $nonBusinessDays,
            'total_days' => $to->lt($from) ? 0 : ($from->diffInDays($to) + 1),
            'dry_run' => $dryRun,
            'holiday_count' => count($holidays),
            'weekend_only' => $holidays === [],
        ];
    }

    /**
     * Garante cobertura do periodo quando o calendario e auto-completavel e a feature esta ligada.
     *
     * @return bool true se alguma linha foi efetivamente criada
     */
    public function ensureCoverage(string $calendarCode, CarbonImmutable $from, CarbonImmutable $to): bool
    {
        if (! $this->willAutoComplete($calendarCode)) {
            return false;
        }

        return $this->backfill($calendarCode, $from, $to)['created'] > 0;
    }

    /**
     * @return array<string, bool>
     */
    private function existingDates(string $calendarCode, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return BusinessCalendarDate::query()
            ->where('calendar_code', $calendarCode)
            ->whereDate('calendar_date', '>=', $from->toDateString())
            ->whereDate('calendar_date', '<=', $to->toDateString())
            ->pluck('calendar_date')
            ->mapWithKeys(fn ($calendarDate): array => [
                CarbonImmutable::parse((string) $calendarDate)->toDateString() => true,
            ])
            ->all();
    }
}
