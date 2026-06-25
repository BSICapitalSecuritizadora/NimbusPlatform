<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

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
 * Formato do CSV (cabeçalho obrigatório): `rate_date,rate_value[,notes]`.
 *  - rate_date: `YYYY-MM-DD` ou `YYYY-MM` (mês para IPCA).
 *  - rate_value: ponto ou vírgula decimal.
 */
class IndexRateImportService
{
    public function __construct(
        private readonly IndexRateLookupService $lookupService,
        private readonly IndexProjectionSeriesService $seriesService,
    ) {}

    /**
     * Importa número-índice PUBLICADO (is_projected = false).
     *
     * @return array{imported: int, errors: list<string>}
     */
    public function importPublished(
        PuIndexer $indexer,
        string $csvPath,
        ?string $source = null,
        ?int $importedByUserId = null,
    ): array {
        [$rows, $errors] = $this->parseCsv($csvPath, $indexer);
        $imported = 0;
        $timestamp = now();

        foreach ($rows as $row) {
            IndexRate::query()->updateOrCreate(
                ['indexer' => $indexer->value, 'rate_date' => $row['rate_date']->startOfDay()],
                [
                    'rate_value' => $row['rate_value'],
                    'source' => $source ?? 'manual_import',
                    'source_reference' => sprintf('import:%s', $timestamp->toDateTimeString()),
                    'is_projected' => false,
                    'projection_source' => null,
                    'projection_reference_date' => null,
                    'projection_policy' => null,
                    'index_projection_series_id' => null,
                    'notes' => $row['notes'],
                ],
            );
            $imported++;
        }

        $this->lookupService->flushCache();

        return ['imported' => $imported, 'errors' => $errors];
    }

    /**
     * Importa uma SÉRIE PROJETADA (is_projected = true) vinculando as linhas à série criada (status importada).
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
        [$rows, $errors] = $this->parseCsv($csvPath, $indexer);

        $series = $this->seriesService->create($indexer, $seriesAttributes, $importedByUserId);
        $imported = 0;

        foreach ($rows as $row) {
            IndexRate::query()->updateOrCreate(
                ['indexer' => $indexer->value, 'rate_date' => $row['rate_date']->startOfDay()],
                [
                    'rate_value' => $row['rate_value'],
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
     * @return array{0: list<array{rate_date: CarbonImmutable, rate_value: string, notes: ?string}>, 1: list<string>}
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
                    $rows[] = [
                        'rate_date' => $this->normalizeDate($rawDate, $indexer),
                        'rate_value' => $this->normalizeValue($rawValue),
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

    private function normalizeDate(string $value, PuIndexer $indexer): CarbonImmutable
    {
        if (! $this->looksLikeDate($value)) {
            throw new InvalidArgumentException(sprintf('Data inválida "%s" (use YYYY-MM-DD ou YYYY-MM).', $value));
        }

        $date = strlen($value) === 7
            ? CarbonImmutable::createFromFormat('Y-m', $value)->startOfMonth()
            : CarbonImmutable::parse($value);

        return $indexer === PuIndexer::Ipca ? $date->startOfMonth() : $date;
    }

    private function normalizeValue(string $value): string
    {
        $normalized = str_replace([' ', ','], ['', '.'], $value);

        if ($normalized === '' || ! is_numeric($normalized)) {
            throw new InvalidArgumentException(sprintf('Valor inválido "%s".', $value));
        }

        return $normalized;
    }
}
