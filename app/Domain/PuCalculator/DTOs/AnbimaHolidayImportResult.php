<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

/**
 * Resumo de uma execução de importação de feriados nacionais ANBIMA (idempotente).
 *
 * - `imported`: feriados novos persistidos;
 * - `updated`: feriados existentes atualizados (apenas com --force, quando nome/notas mudaram);
 * - `skipped`: feriados já cadastrados e inalterados (idempotência);
 * - `invalid`: linhas com data ilegível/fora de faixa que foram ignoradas;
 * - `calendarApplied`: datas marcadas como NÃO úteis no calendário de dias úteis.
 */
final class AnbimaHolidayImportResult
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(
        public readonly string $calendarCode,
        public readonly string $source,
        public readonly string $sourceFile,
        public readonly bool $dryRun,
        public int $total = 0,
        public int $imported = 0,
        public int $updated = 0,
        public int $changesDetected = 0,
        public int $skipped = 0,
        public int $invalid = 0,
        public int $calendarApplied = 0,
        public int $removalsDetected = 0,
        public int $conflictsDetected = 0,
        public int $importRuns = 0,
        public ?string $checksum = null,
        public array $errors = [],
    ) {}

    public function addError(string $message): void
    {
        $this->errors[] = $message;
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'calendar_code' => $this->calendarCode,
            'source' => $this->source,
            'source_file' => $this->sourceFile,
            'dry_run' => $this->dryRun,
            'total' => $this->total,
            'imported' => $this->imported,
            'updated' => $this->updated,
            'changes_detected' => $this->changesDetected,
            'skipped' => $this->skipped,
            'invalid' => $this->invalid,
            'calendar_applied' => $this->calendarApplied,
            'removals_detected' => $this->removalsDetected,
            'conflicts_detected' => $this->conflictsDetected,
            'import_runs' => $this->importRuns,
            'checksum' => $this->checksum,
            'errors' => $this->errors,
        ];
    }
}
