<?php

namespace App\Actions\Contracts;

use App\Enums\ReconciliationOutcome;
use Illuminate\Support\Collection;

/**
 * Outcome of reconciling an import spreadsheet against the contracts already on
 * record: every row classified, plus the counters shown before confirming.
 */
class ContractSpreadsheetAnalysis
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $fileErrors
     * @param  array<int, ProjectedUnitOccupancy>  $unitOccupancies  unit id => what the file leaves behind
     */
    public function __construct(
        public readonly array $rows = [],
        public readonly array $fileErrors = [],
        public readonly array $unitOccupancies = [],
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
     * Units the file distrata and sells again in one go. Shown on the preview so
     * the operator can see why a new contract on an occupied unit stopped being
     * a conflict; not recorded anywhere.
     */
    public function resaleCount(): int
    {
        return count(array_filter(
            $this->unitOccupancies,
            static fn (ProjectedUnitOccupancy $occupancy): bool => $occupancy->isResale(),
        ));
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
     * Updates in the order the database can accept them: the ones that free a
     * unit first.
     *
     * This is not a presentation detail. A resale writes a distrato and a new
     * contract for the same unit, and the unique index behind
     * `occupied_unit_lock` refuses the second holder even for the instant
     * between two statements of the same transaction. Freeing first is what
     * makes the constraint never see an impossible state -- so the order is
     * decided here, once, instead of being remembered at every call site.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function rowsToUpdate(): Collection
    {
        return $this->collect()
            ->filter(fn (array $row): bool => $row['outcome']->isUpdate())
            ->sortByDesc(fn (array $row): int => ($row['releases_unit'] ?? false) ? 1 : 0)
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
                fn (array $row): int => self::previewWeight($row['outcome']),
                fn (array $row): int => $row['line'],
            ])
            ->values();
    }

    private static function previewWeight(ReconciliationOutcome $outcome): int
    {
        return match ($outcome) {
            ReconciliationOutcome::Conflict, ReconciliationOutcome::Error, ReconciliationOutcome::DuplicatedInFile => 0,
            ReconciliationOutcome::CriticalUpdate => 1,
            ReconciliationOutcome::Update => 2,
            ReconciliationOutcome::New => 3,
            default => 4,
        };
    }
}
