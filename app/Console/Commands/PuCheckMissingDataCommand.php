<?php

namespace App\Console\Commands;

use App\Domain\PuCalculator\Services\PuIndexCoverageService;
use App\Models\Emission;
use Illuminate\Console\Command;

class PuCheckMissingDataCommand extends Command
{
    protected $signature = 'pu:check-missing-data {emission? : ID da emissao a verificar (todas com parametros, se omitido)}';

    protected $description = 'Verifica calendario e indices (CDI) faltantes para a curva de PU das emissoes.';

    public function handle(PuIndexCoverageService $coverageService): int
    {
        $emissions = $this->resolveEmissions();

        if ($emissions->isEmpty()) {
            $this->warn('Nenhuma emissao com parametros de PU configurados foi encontrada.');

            return self::SUCCESS;
        }

        $hasBlockingGaps = false;

        foreach ($emissions as $emission) {
            $report = $coverageService->report($emission);

            $this->line('');
            $this->info(sprintf('Emissao #%d - %s', $emission->id, $emission->name ?? 'sem nome'));
            $this->line(sprintf('Periodo: %s a %s', $report->startDate ?? '-', $report->endDate ?? '-'));
            $this->line(sprintf('Ultimo CDI disponivel: %s', $report->lastAvailableIndexDate ?? '-'));

            $this->renderDateList('Datas de calendario faltantes', $report->missingCalendarDates);
            $this->renderDateList('Datas sem CDI obrigatorio', $report->missingIndexDates);
            $this->renderDateList('Datas usando CDI projetado', $report->projectedIndexDates, isWarningOnly: true);

            if ($report->hasBlockingGaps()) {
                $hasBlockingGaps = true;
                $this->error('=> Geracao bloqueada: faltam dados obrigatorios.');
            } else {
                $this->info('=> Cobertura completa para geracao.');
            }
        }

        return $hasBlockingGaps ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return \Illuminate\Support\Collection<int, Emission>
     */
    private function resolveEmissions(): \Illuminate\Support\Collection
    {
        $emissionId = $this->argument('emission');

        if ($emissionId !== null) {
            return Emission::query()
                ->whereKey($emissionId)
                ->with('puParameter')
                ->get();
        }

        return Emission::query()
            ->whereHas('puParameter')
            ->with('puParameter')
            ->get();
    }

    /**
     * @param  list<string>  $dates
     */
    private function renderDateList(string $label, array $dates, bool $isWarningOnly = false): void
    {
        if ($dates === []) {
            return;
        }

        $preview = implode(', ', array_slice($dates, 0, 10));
        $suffix = count($dates) > 10 ? sprintf(' (+%d)', count($dates) - 10) : '';
        $message = sprintf('%s (%d): %s%s', $label, count($dates), $preview, $suffix);

        if ($isWarningOnly) {
            $this->warn($message);

            return;
        }

        $this->error($message);
    }
}
