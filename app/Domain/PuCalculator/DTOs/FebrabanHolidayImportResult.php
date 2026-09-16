<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

/**
 * Resumo de uma execução de importação de feriados bancários FEBRABAN.
 *
 * - `marketDays`: datas lidas da tabela regida pela Resolução CMN 4.880/2020 (dias NÃO úteis de mercado);
 * - `specialHoursDays`: datas lidas da tabela de atendimento (quarta-feira de cinzas, último dia útil do
 *   ano). São registradas como evidência e NUNCA viram dia não útil;
 * - `imported` / `updated` / `skipped`: efeito sobre a evidência persistida (idempotência);
 * - `removalsDetected`: datas antes conhecidas que a carga atual não trouxe. Nada é apagado;
 * - `calendarApplied`: sempre 0 nesta fase — a importação não materializa calendário algum.
 */
final class FebrabanHolidayImportResult
{
    /**
     * @param  list<int>  $years
     * @param  list<string>  $errors
     */
    public function __construct(
        public readonly string $calendarCode,
        public readonly string $source,
        public readonly string $sourceDocument,
        public readonly bool $dryRun,
        public array $years = [],
        public int $total = 0,
        public int $marketDays = 0,
        public int $specialHoursDays = 0,
        public int $imported = 0,
        public int $updated = 0,
        public int $skipped = 0,
        public int $invalid = 0,
        public int $removalsDetected = 0,
        public int $calendarApplied = 0,
        public int $importRuns = 0,
        public ?string $checksum = null,
        public array $errors = [],
    ) {}

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'calendar_code' => $this->calendarCode,
            'source' => $this->source,
            'source_document' => $this->sourceDocument,
            'dry_run' => $this->dryRun,
            'years' => $this->years,
            'total' => $this->total,
            'market_days' => $this->marketDays,
            'special_hours_days' => $this->specialHoursDays,
            'imported' => $this->imported,
            'updated' => $this->updated,
            'skipped' => $this->skipped,
            'invalid' => $this->invalid,
            'removals_detected' => $this->removalsDetected,
            'calendar_applied' => $this->calendarApplied,
            'import_runs' => $this->importRuns,
            'checksum' => $this->checksum,
            'errors' => $this->errors,
        ];
    }
}
