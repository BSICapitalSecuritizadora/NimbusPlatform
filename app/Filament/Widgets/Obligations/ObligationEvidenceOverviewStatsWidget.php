<?php

namespace App\Filament\Widgets\Obligations;

use App\Enums\AccessPermission;
use App\Services\Obligations\ObligationDashboardData;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ObligationEvidenceOverviewStatsWidget extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected ?string $heading = 'Situação Documental';

    protected ?string $description = 'Comprovação documental, pendências de revisão e lacunas de evidência. Apenas evidência aprovada conta como comprovação válida.';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return (bool) auth()->user()?->can(AccessPermission::ObligationsViewEvidence->value);
    }

    protected function getStats(): array
    {
        $filters = app(ObligationDashboardData::class)->sanitizeFilters($this->pageFilters, true);
        $summary = app(ObligationDashboardData::class)->summary($filters);

        return [
            Stat::make('Sem evidência aprovada', $this->format($summary['sem_evidencia_aprovada']))
                ->color('danger')
                ->description('Ainda sem comprovação válida no recorte'),
            Stat::make('Evidência Aprovada', $this->format($summary['com_evidencia_aprovada']))
                ->color('success')
                ->description('Ao menos uma comprovação válida anexada'),
            Stat::make('Evidência Pendente', $this->format($summary['com_evidencia_pendente']))
                ->color('warning')
                ->description('Ainda em revisão documental'),
            Stat::make('Evidência Rejeitada', $this->format($summary['com_evidencia_rejeitada']))
                ->color('danger')
                ->description('Exigem novo anexo ou ajuste'),
            Stat::make('Sem anexo', $this->format($summary['sem_evidencia']))
                ->color('gray')
                ->description('Sem documento enviado até o momento'),
            Stat::make('Em análise com evidência pendente', $this->format($summary['em_analise_com_evidencia_pendente']))
                ->color('warning')
                ->description('Fluxos em validação documental'),
            Stat::make('Concluídas com evidência aprovada', $this->format($summary['concluidas_com_evidencia_aprovada']))
                ->color('success')
                ->description('Concluídas com comprovação válida'),
            Stat::make('Concluídas sem evidência aprovada', $this->format($summary['concluidas_sem_evidencia_aprovada']))
                ->color('warning')
                ->description('Concluídas sem comprovação válida'),
        ];
    }

    protected function format(int $value): string
    {
        return number_format($value, 0, ',', '.');
    }
}
