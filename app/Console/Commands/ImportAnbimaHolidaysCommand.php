<?php

namespace App\Console\Commands;

use App\Domain\PuCalculator\DTOs\AnbimaHolidayImportResult;
use App\Domain\PuCalculator\Exceptions\AnbimaHolidayImportException;
use App\Domain\PuCalculator\Services\AnbimaHolidayImporter;
use Illuminate\Console\Command;

class ImportAnbimaHolidaysCommand extends Command
{
    protected $signature = 'pu:holidays:import-anbima
        {--url= : URL do arquivo .xls (default: ANBIMA feriados_nacionais.xls)}
        {--file= : Caminho local de um arquivo .xls/.xlsx (fallback de upload manual)}
        {--calendar=B3 : Codigo do calendario que recebe os feriados}
        {--dry-run : Apenas simula, sem persistir nem aplicar ao calendario}
        {--force : Atualiza nome/notas de feriados ja cadastrados}';

    protected $description = 'Importa (idempotente) os feriados nacionais da ANBIMA por URL ou arquivo e aplica-os ao calendario de dias uteis (NAO uteis). Se a URL falhar, oriente o upload manual com --file.';

    public function handle(AnbimaHolidayImporter $importer): int
    {
        $calendar = (string) $this->option('calendar');
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $file = $this->option('file');
        $url = $this->option('url');

        try {
            if (filled($file)) {
                $result = $importer->importFromFile((string) $file, $calendar, $dryRun, $force);
            } else {
                $resolvedUrl = filled($url) ? (string) $url : AnbimaHolidayImporter::DEFAULT_URL;
                $result = $importer->importFromUrl($resolvedUrl, $calendar, $dryRun, $force);
            }
        } catch (AnbimaHolidayImportException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->renderResult($result);

        return self::SUCCESS;
    }

    private function renderResult(AnbimaHolidayImportResult $result): void
    {
        $this->info(sprintf(
            'Calendario %s | Fonte %s | Arquivo %s%s',
            $result->calendarCode,
            $result->source,
            $result->sourceFile,
            $result->dryRun ? ' | DRY-RUN' : '',
        ));

        $this->line(sprintf('Feriados lidos: %d', $result->total));

        if ($result->dryRun) {
            $this->warn(sprintf(
                'Dry-run: %d seriam criados, %d atualizados, %d ja cadastrados, %d invalido(s). Nada persistido.',
                $result->imported,
                $result->updated,
                $result->skipped,
                $result->invalid,
            ));
        } else {
            $this->info(sprintf(
                'Criados: %d | Atualizados: %d | Ja cadastrados: %d | Invalido(s): %d | Aplicados ao calendario: %d',
                $result->imported,
                $result->updated,
                $result->skipped,
                $result->invalid,
                $result->calendarApplied,
            ));
        }

        if ($result->hasErrors()) {
            $this->warn('Linhas ignoradas:');

            foreach ($result->errors as $error) {
                $this->line('  - '.$error);
            }
        }
    }
}
