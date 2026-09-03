<?php

namespace App\Actions\ConstructionUnitValues;

use App\Enums\ReconciliationOutcome;
use Illuminate\Support\Collection;

/**
 * Outcome of reading a batch repricing spreadsheet: every row classified, plus
 * the counters shown in the preview.
 */
class UnitValueSpreadsheetAnalysis
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
            ->reject(fn (array $row): bool => $row['outcome'] === ReconciliationOutcome::Empty)
            ->count();
    }

    public function newCount(): int
    {
        return $this->countByOutcome(ReconciliationOutcome::New);
    }

    public function updateCount(): int
    {
        return $this->countByOutcome(ReconciliationOutcome::Update);
    }

    public function unchangedCount(): int
    {
        return $this->countByOutcome(ReconciliationOutcome::Unchanged);
    }

    public function conflictCount(): int
    {
        return $this->countByOutcome(ReconciliationOutcome::Conflict);
    }

    public function errorCount(): int
    {
        return $this->countByOutcome(ReconciliationOutcome::Error);
    }

    public function duplicatedInFileCount(): int
    {
        return $this->countByOutcome(ReconciliationOutcome::DuplicatedInFile);
    }

    public function emptyLineCount(): int
    {
        return $this->countByOutcome(ReconciliationOutcome::Empty);
    }

    /**
     * Rows that stop the import. "Sem alteração" never does -- it is the
     * expected verdict of a file re-sent to prove nothing moved.
     */
    public function blockingCount(): int
    {
        return $this->collect()
            ->filter(fn (array $row): bool => $row['outcome']->blocksImport())
            ->count();
    }

    /**
     * A file whose every row is already in force is importable: confirming it
     * writes nothing and records that the position was reconciled.
     */
    public function canImport(): bool
    {
        return ($this->fileErrors === [])
            && ($this->blockingCount() === 0)
            && ($this->totalLines() > 0);
    }

    public function writesAnything(): bool
    {
        return ($this->newCount() + $this->updateCount()) > 0;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function writableRows(): Collection
    {
        return $this->collect()
            ->filter(fn (array $row): bool => $row['outcome']->writesToDatabase())
            ->values();
    }

    /**
     * Preview rows, blocking ones first so problems are seen at once.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function previewRows(): Collection
    {
        return $this->collect()
            ->reject(fn (array $row): bool => $row['outcome'] === ReconciliationOutcome::Empty)
            ->sortBy([
                fn (array $row): int => $row['outcome']->blocksImport() ? 0 : 1,
                fn (array $row): int => $row['line'],
            ])
            ->values();
    }

    private function countByOutcome(ReconciliationOutcome $outcome): int
    {
        return $this->collect()->where('outcome', $outcome)->count();
    }
}
