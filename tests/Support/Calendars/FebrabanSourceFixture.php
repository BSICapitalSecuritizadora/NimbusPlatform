<?php

declare(strict_types=1);

namespace Tests\Support\Calendars;

use App\Domain\PuCalculator\Support\BusinessCalendarRegistry;
use App\Models\BusinessCalendarImportRun;
use App\Models\BusinessCalendarYear;
use App\Models\BusinessHoliday;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Reproduz o formato REAL publicado pela FEBRABAN nos dois endpoints federais.
 *
 * O payload é uma lista de `{"diaMes":"04 de junho","diaSemana":"quinta-feira","nomeFeriado":"..."}`:
 * o ANO não aparece em lugar nenhum — ele só existe no parâmetro da requisição —, e é por isso que o
 * `diaSemana` é a única conferência disponível contra uma leitura deslocada de ano.
 *
 * Nenhum teste toca a FEBRABAN real.
 */
final class FebrabanSourceFixture
{
    /** @var array<int, string> */
    private const WEEKDAYS = [
        1 => 'segunda-feira', 2 => 'terça-feira', 3 => 'quarta-feira', 4 => 'quinta-feira',
        5 => 'sexta-feira', 6 => 'sábado', 7 => 'domingo',
    ];

    /** @var array<int, string> */
    private const MONTHS = [
        1 => 'janeiro', 2 => 'fevereiro', 3 => 'março', 4 => 'abril', 5 => 'maio', 6 => 'junho',
        7 => 'julho', 8 => 'agosto', 9 => 'setembro', 10 => 'outubro', 11 => 'novembro', 12 => 'dezembro',
    ];

    /**
     * @param  list<array{0:string,1:string}>  $entries  [data `Y-m-d`, nome]
     */
    public static function payload(array $entries): string
    {
        return json_encode(array_map(static function (array $entry): array {
            $date = CarbonImmutable::parse($entry[0]);

            return [
                'diaMes' => sprintf('%02d de %s', $date->day, self::MONTHS[$date->month]),
                'diaSemana' => self::WEEKDAYS[(int) $date->format('N')],
                'nomeFeriado' => $entry[1],
            ];
        }, $entries), JSON_THROW_ON_ERROR);
    }

    /**
     * Conjunto de 2026 que satisfaz a guarda de completude do parser: sem as datas móveis a carga é
     * recusada como ano fora da curadoria da fonte.
     *
     * @param  list<array{0:string,1:string}>  $extra
     */
    public static function marketPayload2026(array $extra = []): string
    {
        return self::payload([
            ['2026-01-01', 'Confraternização Universal'],
            ['2026-02-16', 'Carnaval'],
            ['2026-02-17', 'Carnaval'],
            ['2026-04-03', 'Sexta-Feira da Paixão'],
            ['2026-06-04', 'Corpus Christi'],
            ['2026-12-25', 'Natal'],
            ...$extra,
        ]);
    }

    public static function marketPayload2026Count(): int
    {
        return 6;
    }

    /**
     * Instala os stubs de UMA carga da fonte, descartando os da carga anterior.
     *
     * A troca da factory não é zelo supérfluo: `Http::fake()` ACUMULA stubs — cada chamada faz merge em
     * `stubCallbacks` — e a resolução escolhe o PRIMEIRO que casa com a URL. Chamar `fake()` de novo para
     * a segunda carga, portanto, não substitui o payload anterior: o stub antigo continua vencendo, e o
     * importador recebe outra vez a resposta da primeira carga. Um teste de remoção montado assim nunca
     * chega a exercer a detecção — a data "removida" continua chegando no payload —, e o resultado é um
     * falso negativo que parece bug do importador.
     *
     * Partir de uma factory limpa a cada carga é o que dá a cada `fakeYear()` o significado óbvio de
     * "a fonte agora responde isto".
     */
    public static function fakeYear(string $marketPayload, string $specialPayload = '[]'): void
    {
        $factory = new Factory(app(Dispatcher::class));
        Http::swap($factory);
        app()->instance(Factory::class, $factory);

        Http::preventStrayRequests();
        Http::fake([
            '*/Home/ObterFeriadosFederaisF*' => Http::response($specialPayload, 200, ['Content-Type' => 'application/json']),
            '*/Home/ObterFeriadosFederais*' => Http::response($marketPayload, 200, ['Content-Type' => 'application/json']),
        ]);
    }

    /**
     * Cobertura ANBIMA "complete" montada pela governança real, não por atalho.
     *
     * `BusinessCalendarYearService::coverage()` só devolve `complete` para um calendário de base
     * weekday-with-exceptions quando o ano tem fonte oficial documentada com checksum E uma execução
     * aplicada, bem-sucedida, sem conflitos, remoções ou erros, cujo checksum casa com o do ano. Montar
     * exatamente essa combinação é o que faz o teste exercer o portão de verdade.
     */
    public static function anbimaYearCoverage(int $year, string $checksum = 'anbima-checksum'): BusinessCalendarYear
    {
        $calendarYear = BusinessCalendarYear::query()->create([
            'calendar_code' => BusinessCalendarRegistry::BR_BANKING_ANBIMA,
            'year' => $year,
            'status' => BusinessCalendarYear::STATUS_PROVISIONAL,
            'source' => 'anbima',
            'source_is_official' => true,
            'source_document' => 'https://www.anbima.com.br/feriados/arqs/feriados_nacionais.xls',
            'source_revision' => 'feriados_nacionais.xls',
            'checksum' => $checksum,
            'revision' => 1,
        ]);

        BusinessCalendarImportRun::query()->create([
            'batch_uuid' => (string) Str::uuid(),
            'business_calendar_year_id' => $calendarYear->id,
            'calendar_code' => BusinessCalendarRegistry::BR_BANKING_ANBIMA,
            'year' => $year,
            'source' => 'anbima',
            'source_is_official' => true,
            'source_document' => 'https://www.anbima.com.br/feriados/arqs/feriados_nacionais.xls',
            'checksum' => $checksum,
            'started_at' => now(),
            'finished_at' => now(),
            'triggered_by_process' => 'console',
            'records_found' => 13,
            'conflicts_detected' => 0,
            'removals_detected' => 0,
            'errors' => null,
            'result' => BusinessCalendarImportRun::RESULT_SUCCEEDED,
            'dry_run' => false,
        ]);

        return $calendarYear;
    }

    /** Evidência da ANBIMA no calendário bancário próprio dela, que esta fase jamais altera. */
    public static function anbimaFact(string $date, string $name): BusinessHoliday
    {
        return BusinessHoliday::query()->create([
            'calendar_code' => BusinessCalendarRegistry::BR_BANKING_ANBIMA,
            'holiday_date' => $date,
            'name' => $name,
            'source' => 'anbima',
            'data_origin' => 'imported',
            'source_is_official' => true,
            'imported_at' => now(),
        ]);
    }
}
