<?php

namespace App\Actions\ContractInstallments;

use App\Enums\ChangeSeverity;
use App\Enums\ReconciliationOutcome;
use App\Exceptions\UnreadableSpreadsheetException;
use App\Support\Imports\ImportConferenceStore;
use Closure;
use Illuminate\Support\Collection;

/**
 * The conference of an installment spreadsheet: what confirming would do, in a
 * summary whose size does not grow with the file.
 *
 * It used to hold every classified row. A monthly portfolio has hundreds of
 * thousands of them, and keeping them -- each with its hydrated installment --
 * cost hundreds of megabytes per analysis, four analyses per import. What the
 * screen and the confirmation actually need is bounded:
 *
 * - the counters, one per {@see ReconciliationOutcome};
 * - a preview sample of {@see self::PREVIEW_LIMIT} rows, chosen by weight and
 *   then by line, so the rows that need attention are always among them;
 * - the rows that block the import, up to {@see self::BLOCKING_LIMIT}, plus the
 *   total;
 * - how many rows carry a warning or touch a registered competence;
 * - the installments on record that the file left out, counted and sampled;
 * - a SHA-256 digest of everything above, folded row by row.
 *
 * The digest is what turns the conference into a contract: the confirmation
 * writes only if the pass that writes produces the same digest. If the position
 * on record moved in between, the operator is shown the new conference instead.
 *
 * Serializes to plain arrays of scalars ({@see self::toArray()}), which is how
 * it travels between the requests of the wizard through
 * {@see ImportConferenceStore}.
 */
class ContractInstallmentSpreadsheetAnalysis
{
    public const PREVIEW_LIMIT = 50;

    public const BLOCKING_LIMIT = 200;

    /**
     * Quantos grupos de prioridade a amostra da prévia tem
     * ({@see self::previewWeight()}).
     */
    private const PREVIEW_WEIGHTS = 6;

    /**
     * Fields of a classified row that make its line of the digest. A field the
     * conference shows or the confirmation writes belongs here; a new one (the
     * discount, the notice of a published competence) is added to this list.
     *
     * @var list<string>
     */
    private const DIGEST_FIELDS = [
        'line',
        'outcome',
        'contract_id',
        'installment_id',
        'number_normalized',
        'due_date',
        'expected_cents',
        'payment_date',
        'paid_cents',
        'discount_cents',
        'cancellation_date',
        'message',
        'warnings',
        'registered_competences',
        'registered_competence_notice',
    ];

    /**
     * Fields kept for each row of the samples. Plain scalars and lists only.
     *
     * @var list<string>
     */
    private const SUMMARY_FIELDS = [
        'line',
        'emission',
        'construction',
        'contract_code',
        'number',
        'due_date',
        'expected_value',
        'payment_date',
        'paid_value',
        'discount_value',
        'cancellation_date',
        'outcome',
        'message',
        'warnings',
        'registered_competences',
        'registered_competence_notice',
    ];

    private const SCHEMA_VERSION = 1;

    /**
     * @param  list<string>  $fileErrors
     * @param  array<string, int>  $counts  outcome value => rows
     * @param  list<array<string, mixed>>  $previewRows
     * @param  list<array<string, mixed>>  $blockingRows
     * @param  array<string, int>  $warningsByCode  warning code => rows
     * @param  list<array<string, mixed>>  $absentRows
     * @param  list<int>  $absentOpenConstructionIds
     */
    public function __construct(
        public readonly array $fileErrors = [],
        private readonly array $counts = [],
        private readonly array $previewRows = [],
        private readonly int $previewCandidateCount = 0,
        private readonly array $blockingRows = [],
        private readonly int $warningCount = 0,
        private readonly array $warningsByCode = [],
        private readonly int $registeredCompetenceCount = 0,
        private readonly int $writtenContractCount = 0,
        private readonly int $absentOpenCount = 0,
        private readonly int $absentPaidCount = 0,
        private readonly int $absentCancelledCount = 0,
        private readonly int $absentPartialCount = 0,
        private readonly array $absentRows = [],
        private readonly string $digest = '',
        private readonly array $absentOpenConstructionIds = [],
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
     * Folds a streamed reading into the summary, row by row.
     *
     * The observer, when given, sees every classified row as it goes by -- it is
     * how the import writes in the very same pass that recomputes the summary.
     *
     * @param  (Closure(array<string, mixed>): void)|null  $observer
     */
    public static function fold(ContractInstallmentSpreadsheetReading $reading, ?Closure $observer = null): self
    {
        if ($reading->fileErrors() !== []) {
            foreach ($reading->rows() as $ignored) {
                // A reading with file errors has no rows; iterating closes it.
            }

            return new self(fileErrors: $reading->fileErrors());
        }

        $counts = [];
        $buckets = array_fill_keys(range(1, self::PREVIEW_WEIGHTS), []);
        $previewCandidates = 0;
        $blocking = [];
        $warningRows = 0;
        $warningsByCode = [];
        $registered = 0;
        $contracts = [];
        $digest = hash_init('sha256');

        try {
            foreach ($reading->rows() as $row) {
                if ($observer !== null) {
                    $observer($row);
                }

                /** @var ReconciliationOutcome $outcome */
                $outcome = $row['outcome'];

                $counts[$outcome->value] = ($counts[$outcome->value] ?? 0) + 1;

                if ($outcome === ReconciliationOutcome::Empty) {
                    continue;
                }

                hash_update($digest, self::digestLine($row));

                if (($row['warnings'] ?? []) !== []) {
                    $warningRows++;

                    foreach (array_unique(array_column($row['warnings'], 'code')) as $code) {
                        $warningsByCode[$code] = ($warningsByCode[$code] ?? 0) + 1;
                    }
                }

                if (($row['registered_competences'] ?? []) !== []) {
                    $registered++;
                }

                if ($outcome->writesToDatabase()) {
                    $contracts[$row['contract_id']] = true;
                }

                if ($outcome->blocksImport()) {
                    if (count($blocking) < self::BLOCKING_LIMIT) {
                        $blocking[] = self::summaryRow($row);
                    }

                    continue;
                }

                $previewCandidates++;

                $weight = self::previewWeight($row);

                if (count($buckets[$weight]) < self::PREVIEW_LIMIT) {
                    $buckets[$weight][] = self::summaryRow($row);
                }
            }
        } catch (UnreadableSpreadsheetException $exception) {
            return new self(fileErrors: [$exception->getMessage()]);
        }

        $absences = $reading->absences();

        hash_update($digest, 'ausentes|'.$absences->digest());

        return new self(
            counts: $counts,
            previewRows: array_slice(array_merge(...array_values($buckets)), 0, self::PREVIEW_LIMIT),
            previewCandidateCount: $previewCandidates,
            blockingRows: $blocking,
            warningCount: $warningRows,
            warningsByCode: $warningsByCode,
            registeredCompetenceCount: $registered,
            writtenContractCount: count($contracts),
            absentOpenCount: $absences->openCount(),
            absentPaidCount: $absences->paidCount(),
            absentCancelledCount: $absences->cancelledCount(),
            absentPartialCount: $absences->partialCount(),
            absentRows: $absences->sample(),
            digest: hash_final($digest),
            absentOpenConstructionIds: $absences->openConstructionIds(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version' => self::SCHEMA_VERSION,
            'file_errors' => $this->fileErrors,
            'counts' => $this->counts,
            'preview' => array_map(self::exportRow(...), $this->previewRows),
            'preview_candidates' => $this->previewCandidateCount,
            'blocking' => array_map(self::exportRow(...), $this->blockingRows),
            'warning_rows' => $this->warningCount,
            'warnings_by_code' => $this->warningsByCode,
            'registered_competences' => $this->registeredCompetenceCount,
            'contracts' => $this->writtenContractCount,
            'absent' => [
                'open' => $this->absentOpenCount,
                'paid' => $this->absentPaidCount,
                'partial' => $this->absentPartialCount,
                'cancelled' => $this->absentCancelledCount,
                'rows' => $this->absentRows,
                'open_constructions' => $this->absentOpenConstructionIds,
            ],
            'digest' => $this->digest,
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    public static function fromArray(array $summary): self
    {
        return new self(
            fileErrors: array_values((array) ($summary['file_errors'] ?? [])),
            counts: (array) ($summary['counts'] ?? []),
            previewRows: array_map(self::importRow(...), array_values((array) ($summary['preview'] ?? []))),
            previewCandidateCount: (int) ($summary['preview_candidates'] ?? 0),
            blockingRows: array_map(self::importRow(...), array_values((array) ($summary['blocking'] ?? []))),
            warningCount: (int) ($summary['warning_rows'] ?? 0),
            warningsByCode: (array) ($summary['warnings_by_code'] ?? []),
            registeredCompetenceCount: (int) ($summary['registered_competences'] ?? 0),
            writtenContractCount: (int) ($summary['contracts'] ?? 0),
            absentOpenCount: (int) ($summary['absent']['open'] ?? 0),
            absentPaidCount: (int) ($summary['absent']['paid'] ?? 0),
            absentCancelledCount: (int) ($summary['absent']['cancelled'] ?? 0),
            absentPartialCount: (int) ($summary['absent']['partial'] ?? 0),
            absentRows: array_values((array) ($summary['absent']['rows'] ?? [])),
            digest: (string) ($summary['digest'] ?? ''),
            absentOpenConstructionIds: array_values(array_map('intval', (array) ($summary['absent']['open_constructions'] ?? []))),
        );
    }

    public function digest(): string
    {
        return $this->digest;
    }

    public function totalLines(): int
    {
        return array_sum($this->counts) - $this->countOf(ReconciliationOutcome::Empty);
    }

    public function countOf(ReconciliationOutcome $outcome): int
    {
        return (int) ($this->counts[$outcome->value] ?? 0);
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
     * Rows the file contradicts in a way the import never applies -- a receipt
     * on record that the file no longer carries. Nothing is written for them.
     */
    public function informativeDivergenceCount(): int
    {
        return $this->countOf(ReconciliationOutcome::InformativeDivergence);
    }

    /**
     * Rows that stop the import. "Sem alteração" is never one of them -- it is
     * the expected verdict for most of a monthly file.
     */
    public function blockingCount(): int
    {
        return $this->conflictCount() + $this->errorCount() + $this->duplicatedInFileCount();
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
        return $this->registeredCompetenceCount;
    }

    /**
     * Rows that carry at least one warning. Warnings never block.
     */
    public function warningCount(): int
    {
        return $this->warningCount;
    }

    /**
     * @return array<string, int> warning code => rows carrying it
     */
    public function warningsByCode(): array
    {
        return $this->warningsByCode;
    }

    /**
     * Contracts whose schedule confirming would write.
     */
    public function writtenContractCount(): int
    {
        return $this->writtenContractCount;
    }

    public function absentOpenCount(): int
    {
        return $this->absentOpenCount;
    }

    public function absentPaidCount(): int
    {
        return $this->absentPaidCount;
    }

    public function absentCancelledCount(): int
    {
        return $this->absentCancelledCount;
    }

    /**
     * Empreendimentos das parcelas em aberto ausentes da planilha -- os que a
     * opção de cancelamento alcança. Fora do digest: derivam das próprias
     * ausentes, que já entram nele. Uma conferência guardada antes deste campo
     * devolve a lista vazia, e o aviso só aparece depois de ela ser refeita.
     *
     * @return list<int>
     */
    public function absentOpenConstructionIds(): array
    {
        return $this->absentOpenConstructionIds;
    }

    /**
     * Absent installments with a receipt below the expected value and no
     * discount registered: they keep the contract financed on the Sales Board,
     * and the cancellation option never touches them -- they have a receipt.
     */
    public function absentPartialCount(): int
    {
        return $this->absentPartialCount;
    }

    /**
     * Installments on record, open, paid or partially paid, that the file left
     * out.
     */
    public function absentCount(): int
    {
        return $this->absentOpenCount + $this->absentPaidCount + $this->absentPartialCount;
    }

    /**
     * A sample of the absent installments, in the order of the record.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function absentRows(): Collection
    {
        return collect($this->absentRows);
    }

    public function canImport(): bool
    {
        return ($this->fileErrors === [])
            && ($this->blockingCount() === 0)
            && ($this->totalLines() > 0);
    }

    /**
     * The preview sample: rows that do not block the import, the ones that need
     * attention first -- critical updates and informative divergences, then the
     * rows that write with a warning or a registered competence, then the
     * unchanged ones with a warning --, then updates, new rows and the
     * unchanged, each group in the order of the file
     * ({@see self::previewWeight()}).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function previewRows(): Collection
    {
        return collect($this->previewRows);
    }

    /**
     * How many rows the preview sample was chosen from -- every row that does
     * not block the import.
     */
    public function previewCandidateCount(): int
    {
        return $this->previewCandidateCount;
    }

    /**
     * Rows that stop the import, in the order of the file -- the order in which
     * whoever fixes the spreadsheet walks through it. Up to
     * {@see self::BLOCKING_LIMIT}; {@see self::blockingCount()} has the total.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function blockingRows(): Collection
    {
        return collect($this->blockingRows);
    }

    /**
     * O grupo da linha na amostra da prévia, do que precisa de atenção para o
     * que é rotina:
     *
     * 1. a atualização crítica e a divergência informativa -- com ou sem aviso;
     * 2. a linha que grava (nova ou atualização) com aviso ⚠ ou competência
     *    registrada ⚑;
     * 3. a linha sem alteração com aviso -- o aviso de plausibilidade é
     *    recalculado em toda reimportação, também na linha igual ao cadastro;
     * 4. as atualizações, 5. as novas e 6. as sem alteração.
     *
     * O resultado decide antes do aviso. Quando aviso e ⚑ bastavam para pôr
     * qualquer linha no grupo da crítica, cinquenta quitações antecipadas já
     * cadastradas -- reenviadas iguais todo mês, sempre com o aviso de pago
     * acima do previsto -- ou cinquenta parcelas novas de um aditivo, todas com
     * ⚑, empurravam a atualização crítica da linha seguinte para fora da
     * tabela, e o Confirmar a gravava sem que ninguém a tivesse visto
     * ({@see ChangeSeverity::Critical}). O aviso e o ⚑ continuam acima das
     * atualizações comuns.
     *
     * @param  array<string, mixed>  $row
     */
    private static function previewWeight(array $row): int
    {
        /** @var ReconciliationOutcome $outcome */
        $outcome = $row['outcome'];

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

    /**
     * @param  array<string, mixed>  $row
     */
    private static function digestLine(array $row): string
    {
        $fields = [];

        foreach (self::DIGEST_FIELDS as $field) {
            $value = $row[$field] ?? null;

            $fields[$field] = $value instanceof ReconciliationOutcome ? $value->value : $value;
        }

        return json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function summaryRow(array $row): array
    {
        $summary = [];

        foreach (self::SUMMARY_FIELDS as $field) {
            $summary[$field] = $row[$field] ?? null;
        }

        $summary['warnings'] = array_values((array) ($summary['warnings'] ?? []));
        $summary['registered_competences'] = array_values((array) ($summary['registered_competences'] ?? []));

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function exportRow(array $row): array
    {
        return [...$row, 'outcome' => $row['outcome'] instanceof ReconciliationOutcome ? $row['outcome']->value : $row['outcome']];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function importRow(array $row): array
    {
        return [
            ...$row,
            'outcome' => $row['outcome'] instanceof ReconciliationOutcome
                ? $row['outcome']
                : (ReconciliationOutcome::tryFrom((string) ($row['outcome'] ?? '')) ?? ReconciliationOutcome::Error),
        ];
    }
}
