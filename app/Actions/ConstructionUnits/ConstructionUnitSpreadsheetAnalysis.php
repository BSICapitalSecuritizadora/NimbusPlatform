<?php

namespace App\Actions\ConstructionUnits;

use Illuminate\Support\Collection;

/**
 * Outcome of reading an import spreadsheet: every row classified, plus the
 * counters shown in the preview.
 */
class ConstructionUnitSpreadsheetAnalysis
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
            ->reject(fn (array $row): bool => $row['status'] === AnalyzeConstructionUnitSpreadsheet::STATUS_EMPTY)
            ->count();
    }

    public function validCount(): int
    {
        return $this->countByStatus(AnalyzeConstructionUnitSpreadsheet::STATUS_VALID);
    }

    public function errorCount(): int
    {
        return $this->countByStatus(AnalyzeConstructionUnitSpreadsheet::STATUS_ERROR);
    }

    public function alreadyRegisteredCount(): int
    {
        return $this->countByStatus(AnalyzeConstructionUnitSpreadsheet::STATUS_ALREADY_REGISTERED);
    }

    public function duplicatedInFileCount(): int
    {
        return $this->countByStatus(AnalyzeConstructionUnitSpreadsheet::STATUS_DUPLICATED_IN_FILE);
    }

    public function emptyLineCount(): int
    {
        return $this->countByStatus(AnalyzeConstructionUnitSpreadsheet::STATUS_EMPTY);
    }

    /**
     * Rows that stop the import. Empty lines are ignored, never blocking.
     */
    public function blockingCount(): int
    {
        return $this->errorCount() + $this->alreadyRegisteredCount() + $this->duplicatedInFileCount();
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
            ->filter(fn (array $row): bool => $row['status'] === AnalyzeConstructionUnitSpreadsheet::STATUS_VALID)
            ->values();
    }

    /**
     * Preview rows, with the blocking ones first so problems are seen at once.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function previewRows(): Collection
    {
        return $this->collect()
            ->reject(fn (array $row): bool => $row['status'] === AnalyzeConstructionUnitSpreadsheet::STATUS_EMPTY)
            ->sortBy([
                fn (array $row): int => $row['status'] === AnalyzeConstructionUnitSpreadsheet::STATUS_VALID ? 1 : 0,
                fn (array $row): int => $row['line'],
            ])
            ->values();
    }

    private function countByStatus(string $status): int
    {
        return $this->collect()->where('status', $status)->count();
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            AnalyzeConstructionUnitSpreadsheet::STATUS_VALID => 'Válido',
            AnalyzeConstructionUnitSpreadsheet::STATUS_ALREADY_REGISTERED => 'Já cadastrada',
            AnalyzeConstructionUnitSpreadsheet::STATUS_DUPLICATED_IN_FILE => 'Duplicada na planilha',
            AnalyzeConstructionUnitSpreadsheet::STATUS_EMPTY => 'Linha vazia',
            default => 'Erro',
        };
    }
}
