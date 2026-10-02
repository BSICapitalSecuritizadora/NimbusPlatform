<?php

namespace App\Actions\Clients;

use Illuminate\Support\Collection;

/**
 * Outcome of reading a client import spreadsheet: every row classified, plus the
 * counters shown in the preview.
 */
class ClientSpreadsheetAnalysis
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $fileErrors
     */
    public function __construct(
        public readonly array $rows = [],
        public readonly array $fileErrors = [],
    ) {}

    /**
     * @param  list<string>  $missingHeaders
     */
    public static function invalidHeaders(array $missingHeaders): self
    {
        return new self(fileErrors: [
            'A planilha não possui as colunas obrigatórias: '.implode(', ', $missingHeaders).'.',
        ]);
    }

    public static function emptyFile(): self
    {
        return new self(fileErrors: ['A planilha está vazia.']);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function collect(): Collection
    {
        return collect($this->rows);
    }

    public function totalLines(): int
    {
        return $this->collect()
            ->reject(fn (array $row): bool => $row['status'] === AnalyzeClientSpreadsheet::STATUS_EMPTY)
            ->count();
    }

    public function validCount(): int
    {
        return $this->countByStatus(AnalyzeClientSpreadsheet::STATUS_VALID);
    }

    public function errorCount(): int
    {
        return $this->countByStatus(AnalyzeClientSpreadsheet::STATUS_ERROR);
    }

    public function alreadyRegisteredCount(): int
    {
        return $this->countByStatus(AnalyzeClientSpreadsheet::STATUS_ALREADY_REGISTERED);
    }

    public function softDeletedCount(): int
    {
        return $this->countByStatus(AnalyzeClientSpreadsheet::STATUS_SOFT_DELETED);
    }

    public function duplicatedInFileCount(): int
    {
        return $this->countByStatus(AnalyzeClientSpreadsheet::STATUS_DUPLICATED_IN_FILE);
    }

    public function emptyLineCount(): int
    {
        return $this->countByStatus(AnalyzeClientSpreadsheet::STATUS_EMPTY);
    }

    /**
     * Rows that stop the import. Empty lines are ignored, never blocking.
     */
    public function blockingCount(): int
    {
        return $this->errorCount()
            + $this->alreadyRegisteredCount()
            + $this->softDeletedCount()
            + $this->duplicatedInFileCount();
    }

    public function canImport(): bool
    {
        return ($this->fileErrors === [])
            && ($this->blockingCount() === 0)
            && ($this->validCount() > 0);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function validRows(): Collection
    {
        return $this->collect()
            ->filter(fn (array $row): bool => $row['status'] === AnalyzeClientSpreadsheet::STATUS_VALID)
            ->values();
    }

    /**
     * Preview rows, the blocking ones first -- errors, documents already
     * registered or held by a deleted client, repetitions -- then the valid
     * ones, each group in the order of the file.
     *
     * The comparators take both rows. Given one argument, a comparator in
     * `sortBy([...])` is handed the pair anyway and answers for the first row
     * alone, which is no order at all.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function previewRows(): Collection
    {
        return $this->collect()
            ->reject(fn (array $row): bool => $row['status'] === AnalyzeClientSpreadsheet::STATUS_EMPTY)
            ->sortBy([
                fn (array $a, array $b): int => self::previewWeight($a) <=> self::previewWeight($b),
                fn (array $a, array $b): int => $a['line'] <=> $b['line'],
            ])
            ->values();
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function previewWeight(array $row): int
    {
        return $row['status'] === AnalyzeClientSpreadsheet::STATUS_VALID ? 3 : 0;
    }

    private function countByStatus(string $status): int
    {
        return $this->collect()->where('status', $status)->count();
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            AnalyzeClientSpreadsheet::STATUS_VALID => 'Válido',
            AnalyzeClientSpreadsheet::STATUS_ALREADY_REGISTERED => 'Já cadastrado',
            AnalyzeClientSpreadsheet::STATUS_SOFT_DELETED => 'Cadastro excluído',
            AnalyzeClientSpreadsheet::STATUS_DUPLICATED_IN_FILE => 'Duplicado na planilha',
            AnalyzeClientSpreadsheet::STATUS_EMPTY => 'Linha vazia',
            default => 'Erro',
        };
    }
}
