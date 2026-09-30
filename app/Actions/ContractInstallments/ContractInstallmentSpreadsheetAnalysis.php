<?php

namespace App\Actions\ContractInstallments;

use App\Enums\ReconciliationOutcome;
use Illuminate\Support\Collection;

/**
 * Outcome of reconciling an import spreadsheet against the schedule already on
 * record: every row classified, plus the counters shown before confirming.
 */
class ContractInstallmentSpreadsheetAnalysis
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

    public function countOf(ReconciliationOutcome $outcome): int
    {
        return $this->collect()->where('outcome', $outcome)->count();
    }

    public function newCount(): int
    {
        return $this->countOf(ReconciliationOutcome::New);
    }

    public function updateCount(): int
    {
        return $this->countOf(ReconciliationOutcome::Update);
    }

    public function criticalUpdateCount(): int
    {
        return $this->countOf(ReconciliationOutcome::CriticalUpdate);
    }

    public function unchangedCount(): int
    {
        return $this->countOf(ReconciliationOutcome::Unchanged);
    }

    public function conflictCount(): int
    {
        return $this->countOf(ReconciliationOutcome::Conflict);
    }

    public function errorCount(): int
    {
        return $this->countOf(ReconciliationOutcome::Error);
    }

    public function duplicatedInFileCount(): int
    {
        return $this->countOf(ReconciliationOutcome::DuplicatedInFile);
    }

    public function emptyLineCount(): int
    {
        return $this->countOf(ReconciliationOutcome::Empty);
    }

    /**
     * Rows that stop the import. "Sem alteração" is never one of them -- it is
     * the expected verdict for most of a monthly file.
     */
    public function blockingCount(): int
    {
        return $this->collect()
            ->filter(fn (array $row): bool => $row['outcome']->blocksImport())
            ->count();
    }

    /**
     * How many rows confirming would actually write. Zero is a perfectly good
     * answer: it means the position on file is the position on record.
     */
    public function writeCount(): int
    {
        return $this->newCount() + $this->updateCount() + $this->criticalUpdateCount();
    }

    public function hasCriticalUpdates(): bool
    {
        return $this->criticalUpdateCount() > 0;
    }

    /**
     * Rows that, once written, touch a fact of a competence already registered
     * on the Sales Board. Shown on the conference, never blocking.
     */
    public function registeredCompetenceCount(): int
    {
        return $this->collect()
            ->filter(fn (array $row): bool => ($row['registered_competences'] ?? []) !== [])
            ->count();
    }

    /**
     * Rows the file contradicts in a way the import never applies -- a receipt
     * on record that the file no longer carries. Nothing is written for them.
     */
    public function informativeDivergenceCount(): int
    {
        return $this->countOf(ReconciliationOutcome::InformativeDivergence);
    }

    public function canImport(): bool
    {
        return ($this->fileErrors === [])
            && ($this->blockingCount() === 0)
            && ($this->totalLines() > 0);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function rowsToCreate(): Collection
    {
        return $this->collect()
            ->filter(fn (array $row): bool => $row['outcome'] === ReconciliationOutcome::New)
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function rowsToUpdate(): Collection
    {
        return $this->collect()
            ->filter(fn (array $row): bool => $row['outcome']->isUpdate())
            ->values();
    }

    /**
     * Preview rows, worst first so problems are seen at once, then the changes,
     * then everything that stayed the same.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function previewRows(?ReconciliationOutcome $only = null): Collection
    {
        return $this->collect()
            ->reject(fn (array $row): bool => $row['outcome'] === ReconciliationOutcome::Empty)
            ->when($only, fn (Collection $rows): Collection => $rows->where('outcome', $only))
            ->sortBy([
                fn (array $a, array $b): int => self::previewWeight($a['outcome']) <=> self::previewWeight($b['outcome']),
                fn (array $a, array $b): int => $a['line'] <=> $b['line'],
            ])
            ->values();
    }

    /**
     * Rows that stop the import, in the order of the file -- the order in which
     * whoever fixes the spreadsheet walks through it.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function blockingRows(): Collection
    {
        return $this->collect()
            ->filter(fn (array $row): bool => $row['outcome']->blocksImport())
            ->sortBy('line')
            ->values();
    }

    private static function previewWeight(ReconciliationOutcome $outcome): int
    {
        return match ($outcome) {
            ReconciliationOutcome::Conflict, ReconciliationOutcome::Error, ReconciliationOutcome::DuplicatedInFile => 0,
            ReconciliationOutcome::CriticalUpdate, ReconciliationOutcome::InformativeDivergence => 1,
            ReconciliationOutcome::Update => 2,
            ReconciliationOutcome::New => 3,
            default => 4,
        };
    }
}
