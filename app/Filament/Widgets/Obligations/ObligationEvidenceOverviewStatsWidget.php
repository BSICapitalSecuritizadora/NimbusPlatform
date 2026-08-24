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

    protected string $view = 'filament.widgets.obligations.obligation-evidence-overview-stats-widget';

    public static function canView(): bool
    {
        return (bool) auth()->user()?->can(AccessPermission::ObligationsViewEvidence->value);
    }

    /**
     * @return array<string, mixed>
     */
    public function getSummaryData(): array
    {
        $filters = app(ObligationDashboardData::class)->sanitizeFilters($this->pageFilters, true);
        $summary = app(ObligationDashboardData::class)->summary($filters);

        $summary['sem_evidencia_aprovada_formatted'] = $this->format($summary['sem_evidencia_aprovada']);
        $summary['com_evidencia_aprovada_formatted'] = $this->format($summary['com_evidencia_aprovada']);
        $summary['com_evidencia_pendente_formatted'] = $this->format($summary['com_evidencia_pendente']);
        $summary['com_evidencia_rejeitada_formatted'] = $this->format($summary['com_evidencia_rejeitada']);

        return $summary;
    }

    /**
     * @return list<array{
     *     key: string,
     *     label: string,
     *     value: string,
     *     raw_value: int,
     *     badge: string,
     *     tone: string,
     *     icon: string,
     *     description: string
     * }>
     */
    public function getPrimaryCards(): array
    {
        $summary = $this->getSummaryData();

        return [
            [
                'key' => 'sem_evidencia_aprovada',
                'label' => 'Sem evidência aprovada',
                'value' => $this->format($summary['sem_evidencia_aprovada']),
                'raw_value' => $summary['sem_evidencia_aprovada'],
                'badge' => 'Pendente',
                'tone' => $summary['sem_evidencia_aprovada'] > 0 ? 'danger' : 'neutral',
                'icon' => 'heroicon-o-document-magnifying-glass',
                'description' => 'Ainda sem comprovação válida no recorte',
            ],
            [
                'key' => 'com_evidencia_aprovada',
                'label' => 'Evidência Aprovada',
                'value' => $this->format($summary['com_evidencia_aprovada']),
                'raw_value' => $summary['com_evidencia_aprovada'],
                'badge' => 'Válida',
                'tone' => 'success',
                'icon' => 'heroicon-o-document-check',
                'description' => 'Ao menos uma comprovação válida anexada',
            ],
            [
                'key' => 'com_evidencia_pendente',
                'label' => 'Evidência Pendente',
                'value' => $this->format($summary['com_evidencia_pendente']),
                'raw_value' => $summary['com_evidencia_pendente'],
                'badge' => 'Em Revisão',
                'tone' => $summary['com_evidencia_pendente'] > 0 ? 'warning' : 'neutral',
                'icon' => 'heroicon-o-clock',
                'description' => 'Ainda em revisão documental',
            ],
            [
                'key' => 'com_evidencia_rejeitada',
                'label' => 'Evidência Rejeitada',
                'value' => $this->format($summary['com_evidencia_rejeitada']),
                'raw_value' => $summary['com_evidencia_rejeitada'],
                'badge' => 'Ajuste',
                'tone' => $summary['com_evidencia_rejeitada'] > 0 ? 'danger' : 'neutral',
                'icon' => 'heroicon-o-x-circle',
                'description' => 'Exigem novo anexo ou ajuste',
            ],
        ];
    }

    /**
     * @return list<array{
     *     key: string,
     *     label: string,
     *     value: string,
     *     raw_value: int,
     *     tag: string,
     *     tone: string,
     *     description: string
     * }>
     */
    public function getSecondaryCards(): array
    {
        $summary = $this->getSummaryData();

        return [
            [
                'key' => 'sem_evidencia',
                'label' => 'Sem anexo',
                'value' => $this->format($summary['sem_evidencia']),
                'raw_value' => $summary['sem_evidencia'],
                'tag' => 'Sem Anexo',
                'tone' => 'neutral',
                'description' => 'Sem documento enviado até o momento',
            ],
            [
                'key' => 'em_analise_com_evidencia_pendente',
                'label' => 'Em análise com evidência pendente',
                'value' => $this->format($summary['em_analise_com_evidencia_pendente']),
                'raw_value' => $summary['em_analise_com_evidencia_pendente'],
                'tag' => 'Validação',
                'tone' => $summary['em_analise_com_evidencia_pendente'] > 0 ? 'warning' : 'neutral',
                'description' => 'Fluxos em validação documental',
            ],
            [
                'key' => 'concluidas_com_evidencia_aprovada',
                'label' => 'Concluídas com evidência aprovada',
                'value' => $this->format($summary['concluidas_com_evidencia_aprovada']),
                'raw_value' => $summary['concluidas_com_evidencia_aprovada'],
                'tag' => 'Regular',
                'tone' => 'success',
                'description' => 'Concluídas com comprovação válida',
            ],
            [
                'key' => 'concluidas_sem_evidencia_aprovada',
                'label' => 'Concluídas sem evidência aprovada',
                'value' => $this->format($summary['concluidas_sem_evidencia_aprovada']),
                'raw_value' => $summary['concluidas_sem_evidencia_aprovada'],
                'tag' => 'Exceção',
                'tone' => $summary['concluidas_sem_evidencia_aprovada'] > 0 ? 'warning' : 'neutral',
                'description' => 'Concluídas sem comprovação válida',
            ],
        ];
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
