<?php

namespace App\Console\Commands;

use App\Domain\PuCalculator\Services\BusinessCalendarCoverageService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class SeedPuBusinessCalendarCommand extends Command
{
    protected $signature = 'pu:business-calendar:seed
        {--calendar=B3 : Codigo do calendario a completar}
        {--from= : Data inicial YYYY-MM-DD (obrigatoria)}
        {--to= : Data final YYYY-MM-DD (obrigatoria)}
        {--dry-run : Apenas simula, sem persistir}';

    protected $description = 'Completa (backfill idempotente) o calendario de dias uteis para o periodo informado: fim de semana = nao util, dia de semana = util. Nao sobrescreve linhas existentes (feriados cadastrados manualmente sao preservados).';

    public function handle(BusinessCalendarCoverageService $coverageService): int
    {
        $calendarCode = (string) $this->option('calendar');
        $fromOption = $this->option('from');
        $toOption = $this->option('to');

        if (! filled($fromOption) || ! filled($toOption)) {
            $this->error('Informe --from e --to no formato YYYY-MM-DD.');

            return self::FAILURE;
        }

        try {
            $from = CarbonImmutable::parse((string) $fromOption)->startOfDay();
            $to = CarbonImmutable::parse((string) $toOption)->startOfDay();
        } catch (\Throwable $exception) {
            $this->error(sprintf('Datas invalidas: %s', $exception->getMessage()));

            return self::FAILURE;
        }

        if ($to->lt($from)) {
            $this->error('A data final nao pode ser anterior a data inicial.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $summary = $coverageService->backfill($calendarCode, $from, $to, $dryRun);

        $this->info(sprintf('Calendario %s | Periodo %s a %s', $calendarCode, $summary['from'], $summary['to']));
        $this->line(sprintf('Dias no periodo: %d', $summary['total_days']));

        if ($dryRun) {
            $this->warn(sprintf(
                'Dry-run: %d data(s) seriam criadas (%d uteis, %d nao uteis). Nada persistido.',
                $summary['would_create'],
                $summary['business_days'],
                $summary['non_business_days'],
            ));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Criadas %d data(s) (%d uteis, %d nao uteis). Datas existentes preservadas.',
            $summary['created'],
            $summary['business_days'],
            $summary['non_business_days'],
        ));

        return self::SUCCESS;
    }
}
