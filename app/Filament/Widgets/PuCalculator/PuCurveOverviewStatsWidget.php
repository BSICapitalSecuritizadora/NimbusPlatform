<?php

namespace App\Filament\Widgets\PuCalculator;

use App\Domain\PuCalculator\Services\PuOperationalMonitorService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PuCurveOverviewStatsWidget extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected ?string $heading = 'Situação das Curvas de PU';

    protected ?string $description = 'Visão consolidada do estado das curvas e da fila de processamento.';

    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $monitor = app(PuOperationalMonitorService::class);
        $counts = $monitor->statusCounts();
        $queue = $monitor->queueMetrics();

        return [
            Stat::make('Emissões com PU', (string) $counts['total'])
                ->color('primary')
                ->description('Emissões com parâmetros configurados'),
            Stat::make('Homologadas', (string) $counts['homologated'])->color('success'),
            Stat::make('Validadas', (string) $counts['validated'])->color('success'),
            Stat::make('Divergentes', (string) $counts['divergent'])->color('danger'),
            Stat::make('Processando', (string) $counts['processing'])->color('info'),
            Stat::make('Com erro', (string) $counts['error'])->color('danger'),
            Stat::make('Obsoletas', (string) $counts['obsolete'])->color('gray'),
            Stat::make('Sem curva', (string) $counts['sem_curva'])
                ->color('warning')
                ->description('PU configurado mas curva ainda não gerada'),
            Stat::make('CDI faltante', (string) $monitor->missingCdiCount())
                ->color($monitor->missingCdiCount() > 0 ? 'danger' : 'success')
                ->description('Emissões bloqueadas por índice'),
            Stat::make('Jobs pendentes', (string) $queue['pending_jobs'])->color('gray'),
            Stat::make('Jobs de PU falhos', (string) $queue['failed_pu_jobs'])
                ->color($queue['failed_pu_jobs'] > 0 ? 'danger' : 'success'),
        ];
    }
}
