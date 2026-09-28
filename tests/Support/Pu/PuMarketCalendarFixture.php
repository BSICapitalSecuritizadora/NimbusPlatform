<?php

namespace Tests\Support\Pu;

use App\Domain\PuCalculator\Services\BusinessCalendarYearService;
use App\Domain\PuCalculator\Support\BusinessCalendarRegistry;
use App\Models\BusinessCalendarDate;
use App\Models\BusinessCalendarImportRun;
use App\Models\BusinessCalendarYear;
use App\Models\BusinessHoliday;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Calendário de mercado da curva oficial (`BusinessCalendarRegistry::MARKET_CALENDAR`)
 * montado pela governança real: feriados nacionais da ANBIMA (inclusive Carnaval,
 * Paixão e Corpus Christi), ano com fonte oficial e checksum, execução aplicada
 * sem conflitos e confirmação administrativa.
 */
final class PuMarketCalendarFixture
{
    public static function prepare(int $fromYear = 2026, int $toYear = 2031, bool $confirmed = true): void
    {
        $responsible = User::factory()->create();

        foreach (range($fromYear, $toYear) as $year) {
            $alreadyPrepared = BusinessCalendarYear::query()
                ->where('calendar_code', BusinessCalendarRegistry::MARKET_CALENDAR)
                ->where('year', $year)
                ->exists();

            if ($alreadyPrepared) {
                continue;
            }

            $checksum = hash('sha256', BusinessCalendarRegistry::MARKET_CALENDAR.$year);
            $calendarYear = BusinessCalendarYear::query()->create([
                'calendar_code' => BusinessCalendarRegistry::MARKET_CALENDAR,
                'year' => $year,
                'status' => BusinessCalendarYear::STATUS_PROVISIONAL,
                'source' => 'anbima',
                'source_is_official' => true,
                'source_document' => 'https://www.anbima.com.br/feriados/arqs/feriados_nacionais.xls',
                'source_revision' => 'feriados_nacionais.xls',
                'checksum' => $checksum,
                'revision' => 1,
            ]);

            $run = BusinessCalendarImportRun::query()->create([
                'batch_uuid' => (string) Str::uuid(),
                'business_calendar_year_id' => $calendarYear->id,
                'calendar_code' => BusinessCalendarRegistry::MARKET_CALENDAR,
                'year' => $year,
                'source' => 'anbima',
                'source_is_official' => true,
                'source_document' => 'https://www.anbima.com.br/feriados/arqs/feriados_nacionais.xls',
                'checksum' => $checksum,
                'started_at' => now(),
                'finished_at' => now(),
                'triggered_by_process' => 'console',
                'records_found' => count(self::holidays($year)),
                'conflicts_detected' => 0,
                'removals_detected' => 0,
                'errors' => null,
                'result' => BusinessCalendarImportRun::RESULT_SUCCEEDED,
                'dry_run' => false,
            ]);

            foreach (self::holidays($year) as $date => $name) {
                BusinessHoliday::query()->create([
                    'calendar_code' => BusinessCalendarRegistry::MARKET_CALENDAR,
                    'holiday_date' => $date,
                    'name' => $name,
                    'source' => 'anbima',
                    'data_origin' => 'imported',
                    'source_is_official' => true,
                    'imported_at' => now(),
                ]);
                BusinessCalendarDate::query()->create([
                    'calendar_code' => BusinessCalendarRegistry::MARKET_CALENDAR,
                    'calendar_date' => $date,
                    'business_calendar_year_id' => $calendarYear->id,
                    'is_business_day' => false,
                    'description' => $name,
                    'data_origin' => 'imported',
                    'source' => 'anbima',
                    'source_is_official' => true,
                    'source_document' => 'https://www.anbima.com.br/feriados/arqs/feriados_nacionais.xls',
                    'source_revision' => 'feriados_nacionais.xls',
                    'import_run_id' => $run->id,
                    'revision' => 1,
                ]);
            }

            if ($confirmed) {
                app(BusinessCalendarYearService::class)->confirm(
                    BusinessCalendarRegistry::MARKET_CALENDAR,
                    $year,
                    'anbima',
                    'https://www.anbima.com.br/feriados/arqs/feriados_nacionais.xls',
                    'feriados_nacionais.xls',
                    $checksum,
                    $responsible->id,
                );
            }
        }
    }

    /**
     * Feriados nacionais da lista ANBIMA para o ano.
     *
     * @return array<string, string>
     */
    public static function holidays(int $year): array
    {
        $easter = self::easter($year);
        $holidays = [
            sprintf('%d-01-01', $year) => 'Confraternização Universal',
            $easter->subDays(48)->toDateString() => 'Carnaval',
            $easter->subDays(47)->toDateString() => 'Carnaval',
            $easter->subDays(2)->toDateString() => 'Paixão de Cristo',
            sprintf('%d-04-21', $year) => 'Tiradentes',
            sprintf('%d-05-01', $year) => 'Dia do Trabalho',
            $easter->addDays(60)->toDateString() => 'Corpus Christi',
            sprintf('%d-09-07', $year) => 'Independência do Brasil',
            sprintf('%d-10-12', $year) => 'Nossa Sr.a Aparecida - Padroeira do Brasil',
            sprintf('%d-11-02', $year) => 'Finados',
            sprintf('%d-11-15', $year) => 'Proclamação da República',
            sprintf('%d-11-20', $year) => 'Dia Nacional de Zumbi e da Consciência Negra',
            sprintf('%d-12-25', $year) => 'Natal',
        ];
        ksort($holidays);

        return $holidays;
    }

    /**
     * Domingo de Páscoa pelo algoritmo gregoriano anônimo (Meeus/Jones/Butcher).
     */
    private static function easter(int $year): CarbonImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return CarbonImmutable::create($year, $month, $day);
    }
}
