<?php

namespace App\Console\Commands;

use App\Domain\PuCalculator\DTOs\FinancialMarketCalendarMaterializationResult;
use App\Domain\PuCalculator\Services\FinancialMarketCalendarMaterializationService;
use Illuminate\Console\Command;
use Throwable;

class MaterializeFinancialMarketCalendarCommand extends Command
{
    protected $signature = 'pu:calendar:materialize-financial-market
        {--year= : Ano a materializar (obrigatório)}
        {--dry-run : Apenas simula; nenhuma decisão é persistida}';

    protected $description = 'Materializa as decisões diárias de BR_FINANCIAL_MARKET para um ano, a partir da reconciliação ANBIMA × FEBRABAN já persistida. Não consulta fontes externas, não migra emissões e não recalcula PU.';

    public function handle(FinancialMarketCalendarMaterializationService $materializer): int
    {
        $year = filter_var($this->option('year'), FILTER_VALIDATE_INT);

        if ($year === false) {
            $this->error('Informe --year com um ano válido. Exemplo: --year=2026');

            return self::FAILURE;
        }

        try {
            $result = $materializer->materialize($year, (bool) $this->option('dry-run'));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->renderResult($result);

        return $result->blocked ? self::FAILURE : self::SUCCESS;
    }

    private function renderResult(FinancialMarketCalendarMaterializationResult $result): void
    {
        $this->info(sprintf('Calendário: %s', $result->calendarCode));
        $this->info(sprintf('Ano: %d', $result->year));
        $this->newLine();

        if ($result->blocked) {
            $this->error('Materialização bloqueada. Nenhuma decisão foi persistida.');

            foreach ($result->blockingReasons as $reason) {
                $this->line('  - '.$reason);
            }

            if ($result->blockedDates !== []) {
                $this->newLine();
                $this->warn('Datas que exigem revisão humana:');
                $this->table(
                    ['Data', 'Status', 'Evidência'],
                    collect($result->blockedDates)->map(fn (array $date): array => [
                        $date['date'],
                        $date['status_label'],
                        $date['evidence'] === [] ? '—' : implode(' + ', $date['evidence']),
                    ])->all(),
                );
            }

            return;
        }

        $this->line(sprintf('Dias analisados: %d', $result->totalDays));
        $this->line(sprintf('Cobertura ANBIMA: %s', $result->coverage['anbima_year']['coverage_status'] ?? '—'));
        $this->line(sprintf('Cobertura FEBRABAN: %s', $result->coverage['febraban_status'] ?? '—'));
        $this->line(sprintf('Conflitos: %d', $result->conflicts));
        $this->line(sprintf('Somente uma fonte: %d', $result->sourceOnly));
        $this->line(sprintf('Unknown: %d', $result->unknown));
        $this->newLine();

        $this->line(sprintf('Dias úteis: %d', $result->businessDays));
        $this->line(sprintf('Dias não úteis: %d', $result->nonBusinessDays));
        $this->line(sprintf('  Fins de semana: %d', $result->weekendDays));
        $this->line(sprintf('  Feriados financeiros: %d', $result->financialHolidays));
        $this->line(sprintf(
            'Expediente especial observado (NÃO vira dia não útil): %d',
            $result->specialHoursObserved,
        ));
        $this->newLine();

        $this->line(sprintf(
            'Linhas: %d a criar | %d a atualizar | %d inalteradas | %d preservadas por override manual',
            $result->rowsCreated,
            $result->rowsUpdated,
            $result->rowsUnchanged,
            $result->rowsPreservedByOverride,
        ));

        if ($result->dryRun) {
            $this->newLine();
            $this->warn('DRY-RUN');
            $this->warn('Nenhuma decisão foi persistida.');

            return;
        }

        $this->newLine();
        $this->info(sprintf(
            'Ano %d materializado. Governança: %s | revisão %d | checksum %s',
            $result->year,
            $result->yearStatus ?? '—',
            $result->revision,
            substr((string) $result->checksum, 0, 12),
        ));
        $this->warn('O ano NÃO foi confirmado automaticamente; a revisão administrativa continua obrigatória.');
    }
}
