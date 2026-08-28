<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * Instrumentação de um trecho de código sob medição.
 *
 * Separa SETUP de MEASURED SECTION: nada do que este probe reporta inclui
 * migrations, boot da aplicação ou criação de fixtures -- só a chamada passada.
 *
 * Queries são contadas numa execução isolada (são determinísticas). Tempo vem
 * de várias execuções, porque uma única medição em máquina compartilhada não
 * diz nada; reportamos mínimo, mediana e máximo. Memória é o pico da execução,
 * com o contador de pico do processo zerado antes para que o pico de outra
 * seção não contamine esta.
 */
final class PerformanceProbe
{
    /**
     * @param  list<array{query: string, bindings: array<int, mixed>}>  $queries
     */
    private function __construct(
        public readonly int $queryCount,
        public readonly int $duplicateQueryCount,
        public readonly float $minMs,
        public readonly float $medianMs,
        public readonly float $maxMs,
        public readonly float $peakMemoryKb,
        public readonly mixed $result,
        public readonly array $queries,
    ) {}

    /**
     * @param  callable():mixed  $subject  o código sob medição
     * @param  ?callable():void  $reset  desfaz efeitos entre execuções, quando o alvo não é idempotente
     */
    public static function measure(callable $subject, int $runs = 5, ?callable $reset = null): self
    {
        // Uma execução descartada antes de medir: resolução de container e
        // autoload são custo de primeira chamada, não do algoritmo.
        $subject();
        self::reset($reset);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $result = $subject();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        DB::flushQueryLog();
        self::reset($reset);

        $durations = [];
        $peaks = [];

        for ($run = 0; $run < $runs; $run++) {
            memory_reset_peak_usage();
            $before = memory_get_peak_usage();
            $startedAt = hrtime(true);

            $subject();

            $durations[] = (hrtime(true) - $startedAt) / 1_000_000;
            $peaks[] = (memory_get_peak_usage() - $before) / 1024;

            self::reset($reset);
        }

        sort($durations);
        sort($peaks);

        $normalized = array_map(
            fn (array $entry): array => [
                'query' => (string) $entry['query'],
                'bindings' => (array) ($entry['bindings'] ?? []),
            ],
            $queries,
        );

        return new self(
            queryCount: count($normalized),
            duplicateQueryCount: self::duplicates($normalized),
            minMs: $durations[0],
            medianMs: $durations[intdiv(count($durations), 2)],
            maxMs: $durations[count($durations) - 1],
            peakMemoryKb: $peaks[intdiv(count($peaks), 2)],
            result: $result,
            queries: $normalized,
        );
    }

    /**
     * Quantas linhas as queries medidas trouxeram do banco, reexecutando-as.
     * Só faz sentido para alvos de leitura -- é o custo de materialização que a
     * contagem de queries sozinha esconde.
     */
    public function rowsLoaded(?string $matching = null): int
    {
        $rows = 0;

        foreach ($this->queries as $entry) {
            if ($matching !== null && ! str_contains($entry['query'], $matching)) {
                continue;
            }

            if (! str_starts_with(ltrim($entry['query']), 'select')) {
                continue;
            }

            $rows += count(DB::select($entry['query'], $entry['bindings']));
        }

        return $rows;
    }

    public function countMatching(string $needle): int
    {
        return count(array_filter(
            $this->queries,
            fn (array $entry): bool => str_contains($entry['query'], $needle),
        ));
    }

    /**
     * As consultas repetidas com os mesmos bindings, da mais repetida para a
     * menos -- é onde mora trabalho evitável.
     *
     * @return list<array{query: string, times: int}>
     */
    public function repeatedQueries(int $limit = 5): array
    {
        $tally = [];

        foreach ($this->queries as $entry) {
            $key = $entry['query'].'|'.json_encode($entry['bindings']);
            $tally[$key] ??= ['query' => $entry['query'], 'times' => 0];
            $tally[$key]['times']++;
        }

        $repeated = array_values(array_filter($tally, fn (array $entry): bool => $entry['times'] > 1));
        usort($repeated, fn (array $a, array $b): int => $b['times'] <=> $a['times']);

        return array_slice($repeated, 0, $limit);
    }

    /**
     * Agrupa as queries por forma (sem bindings), da mais frequente para a
     * menos -- é assim que um N+1 se revela: uma forma só, repetida N vezes.
     *
     * @return list<array{query: string, times: int}>
     */
    public function queryShapes(int $limit = 6): array
    {
        $tally = [];

        foreach ($this->queries as $entry) {
            $tally[$entry['query']] ??= ['query' => $entry['query'], 'times' => 0];
            $tally[$entry['query']]['times']++;
        }

        $shapes = array_values($tally);
        usort($shapes, fn (array $a, array $b): int => $b['times'] <=> $a['times']);

        return array_slice($shapes, 0, $limit);
    }

    public function summary(string $label): string
    {
        return sprintf(
            '%-30s queries=%-5d dup=%-4d min=%7.2fms med=%7.2fms max=%7.2fms mem=%8.1fKB',
            $label,
            $this->queryCount,
            $this->duplicateQueryCount,
            $this->minMs,
            $this->medianMs,
            $this->maxMs,
            $this->peakMemoryKb,
        );
    }

    private static function reset(?callable $reset): void
    {
        if ($reset !== null) {
            $reset();
        }
    }

    /**
     * @param  list<array{query: string, bindings: array<int, mixed>}>  $queries
     */
    private static function duplicates(array $queries): int
    {
        $seen = [];

        foreach ($queries as $entry) {
            $seen[$entry['query'].'|'.json_encode($entry['bindings'])] = true;
        }

        return count($queries) - count($seen);
    }
}
