<?php

namespace App\Actions\Contracts;

use App\Enums\ReconciliationOutcome;
use Illuminate\Support\Collection;

/**
 * Outcome of reconciling an import spreadsheet against the contracts already on
 * record: every row classified, plus the counters shown before confirming.
 *
 * A contracts file is small enough -- a couple of thousand lines -- to be held
 * whole and recomputed on every request of the wizard. What travels between
 * the conference and the confirmation is only its {@see self::digest()}: the
 * confirmation writes only if the file, analysed again, still says the same.
 */
class ContractSpreadsheetAnalysis
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $fileErrors
     * @param  array<int, ProjectedUnitOccupancy>  $unitOccupancies  unit id => what the file leaves behind
     * @param  list<array{construction: string, unit: string, code: string, status: string}>  $absentContracts  a sample
     * @param  list<int>  $absentContractIds  every absent contract, for the digest
     */
    public function __construct(
        public readonly array $rows = [],
        public readonly array $fileErrors = [],
        public readonly array $unitOccupancies = [],
        public readonly array $absentContracts = [],
        public readonly int $absentContractCount = 0,
        public readonly array $absentContractIds = [],
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
     * Rows that will be written with at least one warning beside them. Warnings
     * never block.
     */
    public function warningCount(): int
    {
        return $this->collect()
            ->filter(fn (array $row): bool => ! $row['outcome']->blocksImport()
                && ($row['outcome'] !== ReconciliationOutcome::Empty)
                && (($row['warnings'] ?? []) !== []))
            ->count();
    }

    /**
     * @return array<string, int> warning code => rows carrying it
     */
    public function warningsByCode(): array
    {
        $byCode = [];

        foreach ($this->rows as $row) {
            if ($row['outcome']->blocksImport()) {
                continue;
            }

            foreach (array_unique(array_column($row['warnings'] ?? [], 'code')) as $code) {
                $byCode[$code] = ($byCode[$code] ?? 0) + 1;
            }
        }

        return $byCode;
    }

    /**
     * Live contracts of the developments in the file that the file does not
     * mention. Nothing is done to them.
     *
     * @return Collection<int, array{construction: string, unit: string, code: string, status: string}>
     */
    public function absentContracts(): Collection
    {
        return collect($this->absentContracts);
    }

    public function absentContractCount(): int
    {
        return $this->absentContractCount;
    }

    /**
     * SHA-256 of what the conference shows and the confirmation writes: every
     * row with its verdict, the attributes it would write and its buyers, the
     * rows that block, the warnings, and the contracts the file leaves out.
     *
     * Two analyses of the same file against the same position agree on it;
     * anything that moved in between -- a contract edited, sold or distratado by
     * hand -- changes it.
     */
    public function digest(): string
    {
        $context = hash_init('sha256');

        foreach ($this->rows as $row) {
            if ($row['outcome'] === ReconciliationOutcome::Empty) {
                continue;
            }

            hash_update($context, json_encode([
                'lines' => $row['lines'] ?? [$row['line']],
                'outcome' => $row['outcome']->value,
                'contract_id' => $row['contract_id'] ?? null,
                'construction_unit_id' => $row['construction_unit_id'] ?? null,
                'code' => $row['code_normalized'] ?? null,
                'sale_date' => $row['sale_date'] ?? null,
                'sale_value_cents' => $row['sale_value_cents'] ?? null,
                'status' => ($row['contract_status'] ?? null)?->value,
                'cancellation_date' => $row['cancellation_date'] ?? null,
                'attributes' => ($row['comparison'] ?? null)?->attributes(),
                'client_ids' => $row['client_ids'] ?? [],
                'message' => $row['message'] ?? null,
                'warnings' => $row['warnings'] ?? [],
                'registered_competences' => $row['registered_competences'] ?? [],
                'registered_competence_notice' => $row['registered_competence_notice'] ?? null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        }

        hash_update($context, 'ausentes|'.implode(',', $this->absentContractIds));

        return hash_final($context);
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
     * Preview rows, worst first so problems are seen at once, then what needs
     * attention, then the changes, then everything that stayed the same --
     * each group in the order of the file.
     *
     * The comparators take both rows. Given one argument, a comparator in
     * `sortBy([...])` is handed the pair anyway and answers for the first row
     * alone, which is no order at all: with sixty rows, a critical update on the
     * last line fell out of the fifty rendered.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function previewRows(?ReconciliationOutcome $only = null): Collection
    {
        return $this->collect()
            ->reject(fn (array $row): bool => $row['outcome'] === ReconciliationOutcome::Empty)
            ->when($only, fn (Collection $rows): Collection => $rows->where('outcome', $only))
            ->sortBy([
                fn (array $a, array $b): int => self::previewWeight($a) <=> self::previewWeight($b),
                fn (array $a, array $b): int => $a['line'] <=> $b['line'],
            ])
            ->values();
    }

    /**
     * O grupo da linha na prévia: 0 para as que bloqueiam; 1 para a atualização
     * crítica e a divergência informativa, com ou sem aviso; 2 para a linha que
     * grava com aviso ⚠ ou competência registrada ⚑; 3 para a sem alteração com
     * aviso; depois atualizações, contratos novos e os sem alteração.
     *
     * O resultado decide antes do aviso: com aviso e ⚑ no mesmo grupo da
     * crítica, cinquenta linhas sem alteração com aviso antes dela a tiravam da
     * tabela -- e uma alteração crítica nunca é gravada sem ter sido vista.
     *
     * @param  array<string, mixed>  $row
     */
    private static function previewWeight(array $row): int
    {
        /** @var ReconciliationOutcome $outcome */
        $outcome = $row['outcome'];

        if ($outcome->blocksImport()) {
            return 0;
        }

        if (in_array($outcome, [ReconciliationOutcome::CriticalUpdate, ReconciliationOutcome::InformativeDivergence], true)) {
            return 1;
        }

        if ((($row['warnings'] ?? []) !== []) || (($row['registered_competences'] ?? []) !== [])) {
            return $outcome->writesToDatabase() ? 2 : 3;
        }

        return match ($outcome) {
            ReconciliationOutcome::Update => 4,
            ReconciliationOutcome::New => 5,
            default => 6,
        };
    }
}
