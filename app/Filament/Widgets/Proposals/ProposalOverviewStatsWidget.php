<?php

namespace App\Filament\Widgets\Proposals;

use App\Filament\Resources\Proposals\ProposalResource;
use App\Support\Proposals\ProposalDashboardData;
use Filament\Widgets\Widget;

class ProposalOverviewStatsWidget extends Widget
{
    protected static bool $isDiscovered = false;

    protected string $view = 'filament.widgets.proposals.proposal-overview-stats-widget';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 2;

    protected ?string $heading = 'Indicadores Executivos do Fluxo Comercial';

    protected ?string $description = 'Leitura consolidada da carteira, eficiência de conversão e alertas de SLA.';

    public function getHeading(): ?string
    {
        return $this->heading;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    protected function getViewData(): array
    {
        $summary = app(ProposalDashboardData::class)->summary();
        $proposalsUrl = ProposalResource::getUrl('index');
        $awaitingDocsCount = (int) ($summary['awaiting_completion'] + $summary['awaiting_information']);

        $cards = [
            [
                'key' => 'total',
                'category' => 'Volume da Carteira',
                'title' => 'Total de Propostas',
                'value' => number_format($summary['total'], 0, ',', '.'),
                'description' => number_format($summary['received_last_30_days'], 0, ',', '.').' novas nos últimos 30 dias',
                'icon' => 'heroicon-o-chart-bar',
                'tone' => 'primary',
                'url' => $proposalsUrl,
                'aria_label' => 'Total de '.$summary['total'].' propostas na carteira comercial',
            ],
            [
                'key' => 'pipeline',
                'category' => 'Fila em Andamento',
                'title' => 'Fila Ativa',
                'value' => number_format($summary['active_pipeline'], 0, ',', '.'),
                'description' => $summary['in_review'].' em análise · '.$awaitingDocsCount.' com cliente',
                'icon' => 'heroicon-o-document-magnifying-glass',
                'tone' => 'info',
                'url' => $proposalsUrl,
                'aria_label' => $summary['active_pipeline'].' propostas ativas na esteira comercial',
            ],
            [
                'key' => 'conversion',
                'category' => 'Eficiência Comercial',
                'title' => 'Taxa de Conversão',
                'value' => $summary['conversion_rate'].'%',
                'description' => $summary['approved'].' aprovadas · '.$summary['completed'].' formalizadas',
                'icon' => 'heroicon-o-check-badge',
                'tone' => 'success',
                'url' => $proposalsUrl,
                'aria_label' => 'Taxa de conversão de '.$summary['conversion_rate'].' por cento',
            ],
            [
                'key' => 'attention',
                'category' => 'Controle de SLA',
                'title' => 'Atenção & SLA Crítico',
                'value' => number_format($summary['attention'], 0, ',', '.'),
                'description' => $summary['attention'] > 0
                    ? ($summary['stale_review'] > 0 ? $summary['stale_review'].' estagnadas há +3 dias' : 'Pendências documentais')
                    : 'Sem estagnação ou alertas de SLA',
                'icon' => $summary['attention'] > 0 ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-shield-check',
                'tone' => $summary['attention'] > 0 ? 'warning' : 'neutral',
                'url' => $proposalsUrl,
                'aria_label' => $summary['attention'] > 0
                    ? $summary['attention'].' propostas requerem atenção ou estão em SLA crítico'
                    : 'Nenhuma proposta em atraso de SLA',
            ],
            [
                'key' => 'awaiting_docs',
                'category' => 'Pendências com Cliente',
                'title' => 'Aguardando Documentos',
                'value' => number_format($awaitingDocsCount, 0, ',', '.'),
                'description' => $awaitingDocsCount > 0
                    ? $summary['awaiting_completion'].' complementação · '.$summary['awaiting_information'].' info'
                    : 'Nenhuma pendência documental ativa',
                'icon' => $awaitingDocsCount > 0 ? 'heroicon-o-clock' : 'heroicon-o-document-check',
                'tone' => $awaitingDocsCount > 0 ? 'warning' : 'neutral',
                'url' => $proposalsUrl,
                'aria_label' => $awaitingDocsCount.' propostas aguardando envio de documentos',
            ],
            [
                'key' => 'rejected',
                'category' => 'Histórico de Recusas',
                'title' => 'Indeferidas',
                'value' => number_format($summary['rejected'], 0, ',', '.'),
                'description' => $summary['rejected'] > 0
                    ? ($summary['rejected'] === 1 ? '1 proposta não enquadrada' : $summary['rejected'].' propostas não enquadradas')
                    : 'Propostas não enquadradas',
                'icon' => $summary['rejected'] > 0 ? 'heroicon-o-x-circle' : 'heroicon-o-check-circle',
                'tone' => $summary['rejected'] > 0 ? 'danger' : 'neutral',
                'url' => $proposalsUrl,
                'aria_label' => $summary['rejected'].' propostas indeferidas ou não enquadradas',
            ],
        ];

        return [
            'summary' => $summary,
            'cards' => $cards,
            'proposalsUrl' => $proposalsUrl,
            'heading' => $this->getHeading(),
            'description' => $this->getDescription(),
        ];
    }
}
