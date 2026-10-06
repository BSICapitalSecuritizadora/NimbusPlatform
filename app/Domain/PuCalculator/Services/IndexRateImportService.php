<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\IndexRateRecordOutcome;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Models\IndexProjectionSeries;
use App\Models\IndexRate;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * Importação de número-índice por planilha CSV.
 *
 * Suporta CDI publicado, IPCA publicado e IPCA projetado. Para o IPCA a data é normalizada para o 1º dia
 * do mês de referência (a engine resolve o número-índice por mês). Linhas projetadas são sempre vinculadas
 * a uma SÉRIE PROJETADA (maker/checker) — projeção nunca entra solta nem mascarada de publicada.
 *
 * Publicado entra pelo {@see IndexRateObservationRecorder}: data nova é criada; a mesma data com o mesmo
 * valor e a mesma origem é idempotente; valor ou origem diferentes numa data já registrada viram conflito
 * explícito e nada é sobrescrito -- corrigir histórico é {@see IndexRateCorrectionService}.
 *
 * Formato do CSV (cabeçalho obrigatório): `rate_date,rate_value[,notes]`.
 *  - rate_date: `YYYY-MM-DD` (CDI e IPCA) ou `YYYY-MM` (somente IPCA mensal). Data impossível é recusada.
 *  - rate_value: ponto OU vírgula decimal (nunca os dois), na unidade da série: CDI em % a.a. base 252
 *    (14,90 = 14,90% a.a.), IPCA em número-índice.
 */
class IndexRateImportService
{
    public function __construct(
        private readonly IndexRateLookupService $lookupService,
        private readonly IndexProjectionSeriesService $seriesService,
        private readonly IndexRateObservationRecorder $recorder,
        private readonly PuAuditLogService $auditLog,
    ) {}

    /**
     * Importa número-índice PUBLICADO (is_projected = false).
     *
     * `imported` conta só as datas novas. `errors` reúne, linha a linha, o que não foi gravado e por quê --
     * linha malformada, valor fora do domínio, data futura, conflito com o já registrado ou valor retido
     * para confirmação --, e é o que a tela mostra.
     *
     * @return array{imported: int, unchanged: int, conflicts: list<array<string, mixed>>, needs_confirmation: list<array<string, mixed>>, errors: list<string>}
     */
    public function importPublished(
        PuIndexer $indexer,
        string $csvPath,
        ?string $source = null,
        ?int $importedByUserId = null,
        bool $confirmOutliers = false,
    ): array {
        [$rows, $errors] = $this->parseCsv($csvPath, $indexer);
        $source = filled($source) ? trim((string) $source) : 'manual_import';
        $timestamp = now();
        $imported = 0;
        $unchanged = 0;
        $conflicts = [];
        $needsConfirmation = [];
        $rejected = [];

        foreach ($rows as $row) {
            $outcome = $this->recorder->recordRealized(
                $indexer,
                $row['rate_date'],
                $row['raw_value'],
                [
                    'source' => $source,
                    'source_reference' => sprintf('import:%s', $timestamp->toDateTimeString()),
                    'notes' => $row['notes'],
                ],
                confirmOutlier: $confirmOutliers,
            );

            match (true) {
                $outcome->wasCreated() => $imported++,
                $outcome->isUnchanged() => $unchanged++,
                default => null,
            };

            if ($outcome->isConflict()) {
                $conflicts[] = ['line' => $row['line']] + $outcome->toArray();
            }

            if ($outcome->status === IndexRateRecordOutcome::NEEDS_CONFIRMATION) {
                $needsConfirmation[] = ['line' => $row['line']] + $outcome->toArray();
            }

            if ($outcome->status === IndexRateRecordOutcome::REJECTED) {
                $rejected[] = ['line' => $row['line']] + $outcome->toArray();
            }

            if ($outcome->isConflict() || $outcome->isRejected()) {
                $errors[] = sprintf('Linha %d ignorada: %s', $row['line'], $outcome->reason);
            }
        }

        $this->lookupService->flushCache();
        $this->auditLog->logIndexRatesImported([
            'indexer' => $indexer->value,
            'source' => $source,
            'file' => basename($csvPath),
            'imported' => $imported,
            'unchanged' => $unchanged,
            'conflicts' => $conflicts,
            'needs_confirmation' => $needsConfirmation,
            'rejected' => $rejected,
            'confirmed_outliers' => $confirmOutliers,
        ], $importedByUserId);

        return [
            'imported' => $imported,
            'unchanged' => $unchanged,
            'conflicts' => $conflicts,
            'needs_confirmation' => $needsConfirmation,
            'errors' => $errors,
        ];
    }

    /**
     * Importa uma SÉRIE PROJETADA (is_projected = true) vinculando as linhas à série criada (status importada).
     *
     * Só o IPCA tem modelo de projeção aprovado (série maker/checker sob política de mercado). Projeção de
     * CDI não existe no produto: é recusada, e a data futura de CDI continua simplesmente indisponível.
     * Uma data que já tem observação publicada nunca é trocada por projeção.
     *
     * @param  array<string, mixed>  $seriesAttributes
     * @return array{series: IndexProjectionSeries, imported: int, errors: list<string>}
     */
    public function importProjectedSeries(
        PuIndexer $indexer,
        string $csvPath,
        array $seriesAttributes,
        ?int $importedByUserId = null,
    ): array {
        if ($indexer !== PuIndexer::Ipca) {
            throw new InvalidArgumentException(sprintf(
                'Não existe modelo de projeção aprovado para %s: a taxa futura fica indisponível em vez de projetada.',
                $indexer->value,
            ));
        }

        [$rows, $errors] = $this->parseCsv($csvPath, $indexer);

        $series = $this->seriesService->create($indexer, $seriesAttributes, $importedByUserId);
        $imported = 0;

        foreach ($rows as $row) {
            try {
                $value = $this->recorder->normalizeValue($indexer, $row['raw_value'], $row['rate_date']);
            } catch (InvalidArgumentException $exception) {
                $errors[] = sprintf('Linha %d ignorada: %s', $row['line'], $exception->getMessage());

                continue;
            }

            $published = IndexRate::query()
                ->forIndexer($indexer)
                ->whereDate('rate_date', $row['rate_date']->toDateString())
                ->get()
                ->first(fn (IndexRate $rate): bool => ! $rate->isProjectedRate());

            if ($published instanceof IndexRate) {
                $errors[] = sprintf(
                    'Linha %d ignorada: %s já tem número-índice publicado (%s); projeção não substitui publicado.',
                    $row['line'],
                    $row['rate_date']->format('Y-m'),
                    (string) $published->rate_value,
                );

                continue;
            }

            IndexRate::query()->updateOrCreate(
                ['indexer' => $indexer->value, 'rate_date' => $row['rate_date']->startOfDay()],
                [
                    'rate_value' => $value,
                    'source' => $series->projection_source ?? 'projection_import',
                    'source_reference' => sprintf('projection_series:%d', $series->id),
                    'is_projected' => true,
                    'projection_source' => $series->projection_source,
                    'projection_reference_date' => $series->reference_date,
                    'projection_policy' => $series->projection_policy,
                    'index_projection_series_id' => $series->id,
                    'notes' => $row['notes'],
                ],
            );
            $imported++;
        }

        $this->lookupService->flushCache();

        return ['series' => $series, 'imported' => $imported, 'errors' => $errors];
    }

    /**
     * @return array{0: list<array{line: int, rate_date: CarbonImmutable, raw_value: string, notes: ?string}>, 1: list<string>}
     */
    private function parseCsv(string $csvPath, PuIndexer $indexer): array
    {
        if (! is_file($csvPath) || ! is_readable($csvPath)) {
            throw new InvalidArgumentException(sprintf('Arquivo CSV não encontrado ou ilegível: %s', $csvPath));
        }

        $handle = fopen($csvPath, 'rb');

        if ($handle === false) {
            throw new InvalidArgumentException(sprintf('Não foi possível abrir o CSV: %s', $csvPath));
        }

        $rows = [];
        $errors = [];
        $seenDates = [];
        $lineNumber = 0;

        try {
            while (($columns = fgetcsv($handle, 0, ',')) !== false) {
                $lineNumber++;

                if ($columns === [null] || $columns === []) {
                    continue;
                }

                $rawDate = trim((string) ($columns[0] ?? ''));
                $rawValue = trim((string) ($columns[1] ?? ''));

                if ($rawDate === '' && $rawValue === '') {
                    continue;
                }

                if ($lineNumber === 1 && ! $this->looksLikeDate($rawDate)) {
                    continue;
                }

                try {
                    $date = $this->normalizeDate($rawDate, $indexer);

                    if ($rawValue === '') {
                        throw new InvalidArgumentException('Valor vazio.');
                    }

                    // Duas linhas para a mesma data no mesmo arquivo não têm leitura única.
                    if (isset($seenDates[$date->toDateString()])) {
                        throw new InvalidArgumentException(sprintf(
                            'A data %s aparece de novo (primeira na linha %d); o arquivo precisa de uma linha por data.',
                            $date->toDateString(),
                            $seenDates[$date->toDateString()],
                        ));
                    }

                    $seenDates[$date->toDateString()] = $lineNumber;
                    $rows[] = [
                        'line' => $lineNumber,
                        'rate_date' => $date,
                        'raw_value' => $rawValue,
                        'notes' => isset($columns[2]) && trim((string) $columns[2]) !== '' ? trim((string) $columns[2]) : null,
                    ];
                } catch (Throwable $exception) {
                    $errors[] = sprintf('Linha %d ignorada: %s', $lineNumber, $exception->getMessage());
                }
            }
        } finally {
            fclose($handle);
        }

        if ($rows === []) {
            throw new InvalidArgumentException('Nenhuma linha válida encontrada no CSV. Verifique o cabeçalho (rate_date,rate_value) e o conteúdo.');
        }

        return [$rows, $errors];
    }

    private function looksLikeDate(string $value): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}(-\d{2})?$/', $value);
    }

    /**
     * Data exata, sem transbordo: `2026-02-30` é recusada (não vira 02/03), e `YYYY-MM` não herda o dia de
     * hoje (`2024-02` num dia 30 não vira março). O CDI é diário e exige a data completa.
     */
    private function normalizeDate(string $value, PuIndexer $indexer): CarbonImmutable
    {
        if (! $this->looksLikeDate($value)) {
            throw new InvalidArgumentException(sprintf('Data inválida "%s" (use YYYY-MM-DD ou YYYY-MM).', $value));
        }

        $isMonth = strlen($value) === 7;

        if ($isMonth && $indexer !== PuIndexer::Ipca) {
            throw new InvalidArgumentException(sprintf('Data "%s" sem dia: o %s é diário e exige YYYY-MM-DD.', $value, $indexer->value));
        }

        $format = $isMonth ? '!Y-m' : '!Y-m-d';
        $date = CarbonImmutable::createFromFormat($format, $value);

        if (! $date instanceof CarbonImmutable || $date->format(ltrim($format, '!')) !== $value) {
            throw new InvalidArgumentException(sprintf('Data inexistente "%s".', $value));
        }

        return $indexer === PuIndexer::Ipca ? $date->startOfMonth() : $date->startOfDay();
    }
}
