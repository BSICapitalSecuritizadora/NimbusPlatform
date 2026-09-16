<?php

namespace App\Console\Commands;

use App\Domain\PuCalculator\DTOs\FebrabanHolidayImportResult;
use App\Domain\PuCalculator\Exceptions\FebrabanHolidayImportException;
use App\Domain\PuCalculator\Services\FebrabanHolidayImporter;
use Illuminate\Console\Command;

class ImportFebrabanHolidaysCommand extends Command
{
    protected $signature = 'pu:holidays:import-febraban
        {--year=* : Ano(s) a importar. Repetível: --year=2025 --year=2026}
        {--from-year= : Primeiro ano de um intervalo}
        {--to-year= : Último ano de um intervalo}
        {--file= : Caminho de um JSON salvo manualmente da fonte (exige --year)}
        {--url= : Base alternativa da fonte (default: site oficial de feriados bancários da FEBRABAN)}
        {--dry-run : Apenas simula; nenhuma evidência é persistida}
        {--force : Atualiza o nome de datas já cadastradas quando a fonte mudou}';

    protected $description = 'Importa os feriados bancários federais da FEBRABAN como EVIDÊNCIA do calendário financeiro consolidado (BR_FINANCIAL_MARKET). Não materializa calendário, não altera dados ANBIMA e não migra nenhuma emissão.';

    public function handle(FebrabanHolidayImporter $importer): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $file = $this->option('file');

        try {
            $years = $this->resolveYears();

            if (filled($file)) {
                if (count($years) !== 1) {
                    $this->error('A FEBRABAN não publica o ano dentro do payload. Informe exatamente um --year ao usar --file.');

                    return self::FAILURE;
                }

                $result = $importer->importFromFile((string) $file, $years[0], $dryRun, $force);
            } else {
                $baseUrl = filled($this->option('url'))
                    ? (string) $this->option('url')
                    : FebrabanHolidayImporter::DEFAULT_BASE_URL;
                $result = $importer->importFromSource($years, $baseUrl, $dryRun, $force);
            }
        } catch (FebrabanHolidayImportException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->renderResult($result);

        return self::SUCCESS;
    }

    /** @return list<int> */
    private function resolveYears(): array
    {
        /** @var list<string> $explicit */
        $explicit = (array) $this->option('year');
        $years = array_map(intval(...), array_filter($explicit, static fn ($year): bool => $year !== null && $year !== ''));

        $fromYear = $this->option('from-year');
        $toYear = $this->option('to-year');

        if (filled($fromYear) || filled($toYear)) {
            if (blank($fromYear) || blank($toYear)) {
                throw new FebrabanHolidayImportException('Informe --from-year e --to-year em conjunto.');
            }

            if ((int) $fromYear > (int) $toYear) {
                throw new FebrabanHolidayImportException('--from-year não pode ser maior que --to-year.');
            }

            $years = [...$years, ...range((int) $fromYear, (int) $toYear)];
        }

        if ($years === []) {
            throw new FebrabanHolidayImportException('Informe --year, ou --from-year com --to-year.');
        }

        $years = array_values(array_unique($years));
        sort($years);

        return $years;
    }

    private function renderResult(FebrabanHolidayImportResult $result): void
    {
        $this->info(sprintf(
            'Calendário %s | Fonte %s | Documento %s%s',
            $result->calendarCode,
            $result->source,
            $result->sourceDocument,
            $result->dryRun ? ' | DRY-RUN' : '',
        ));

        $this->line(sprintf('Anos cobertos: %s', implode(', ', $result->years)));
        $this->line(sprintf('Datas lidas: %d', $result->total));
        $this->line(sprintf('  Dias NÃO úteis de mercado (Resolução CMN 4.880/2020): %d', $result->marketDays));
        $this->line(sprintf('  Expediente especial de agência (NÃO vira dia não útil): %d', $result->specialHoursDays));

        if ($result->dryRun) {
            $this->warn(sprintf(
                'Dry-run: %d nova(s), %d atualizável(is), %d já conhecida(s), %d remoção(ões) detectada(s), %d inválida(s). '
                .'Nenhuma evidência foi gravada.',
                $result->imported,
                $result->updated,
                $result->skipped,
                $result->removalsDetected,
                $result->invalid,
            ));
        } else {
            $this->info(sprintf(
                'Novas: %d | Atualizadas: %d | Já conhecidas: %d | Remoções detectadas: %d | Inválidas: %d | Execuções auditadas: %d',
                $result->imported,
                $result->updated,
                $result->skipped,
                $result->removalsDetected,
                $result->invalid,
                $result->importRuns,
            ));
        }

        $this->line(sprintf(
            'Aplicadas ao calendário de dias úteis: %d (esta fase não materializa decisão; use a reconciliação para revisar).',
            $result->calendarApplied,
        ));

        if ($result->hasErrors()) {
            $this->warn('Registros ignorados:');

            foreach ($result->errors as $error) {
                $this->line('  - '.$error);
            }
        }
    }
}
