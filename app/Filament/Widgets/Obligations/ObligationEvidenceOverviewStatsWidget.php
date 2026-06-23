<?php

namespace App\Filament\Widgets\Obligations;

use App\Enums\AccessPermission;
use App\Services\Obligations\ObligationDashboardData;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ObligationEvidenceOverviewStatsWidget extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected ?string $heading = 'Situação Documental';

    protected ?string $description = 'Cobertura documental, pendências de revisão e gaps de comprovação.';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return (bool) auth()->user()?->can(AccessPermission::ObligationsViewEvidence->value);
    }

    protected function getStats(): array
    {
        $summary = app(ObligationDashboardData::class)->summary();

        return [
            Stat::make('Com Evidência Aprovada', $this->format($summary['com_evidencia_aprovada']))
                ->color('success')
                ->description('Ao menos uma evidência já aprovada'),
            Stat::make('Evidência Pendente', $this->format($summary['com_evidencia_pendente']))
                ->color('warning')
                ->description('Com revisão documental ainda em aberto'),
            Stat::make('Evidência Rejeitada', $this->format($summary['com_evidencia_rejeitada']))
                ->color('danger')
                ->description('Exigem novo anexo ou correção'),
            Stat::make('Sem Evidência', $this->format($summary['sem_evidencia']))
                ->color('gray')
                ->description('Ainda sem comprovação anexada'),
            Stat::make('Concluídas sem Aprovada', $this->format($summary['concluidas_sem_evidencia_aprovada']))
                ->color('warning')
                ->description('Concluídas sem evidência aprovada'),
        ];
    }

    protected function format(int $value): string
    {
        return number_format($value, 0, ',', '.');
    }
}
