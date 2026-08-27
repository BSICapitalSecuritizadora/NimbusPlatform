<?php

namespace App\Filament\NimbusWidgets;

use App\Filament\Resources\Nimbus\Submissions\SubmissionResource;
use App\Models\Nimbus\Submission;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;

class NimbusStatusDistribution extends ChartWidget
{
    protected ?string $heading = 'Distribuição por situação';

    protected ?string $description = 'Submissões por status operacional';

    protected string $view = 'filament.widgets.nimbus.nimbus-status-distribution-widget';

    // Span 4 of 12 columns (~35%)
    protected int|string|array $columnSpan = [
        'default' => 'full',
        'lg' => 4,
        'xl' => 4,
    ];

    protected ?string $maxHeight = '160px';

    /**
     * @return array{
     *     total: int,
     *     items: array<int, array{status: string, label: string, count: int, percentage: float, color_hex: string}>,
     *     active_items: array<int, array{status: string, label: string, count: int, percentage: float, color_hex: string}>
     * }
     */
    public function getDetails(): array
    {
        $statusCounts = Submission::select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();

        $definitions = [
            Submission::STATUS_COMPLETED => ['label' => 'Aprovados', 'color' => '#10b981'],
            Submission::STATUS_REJECTED => ['label' => 'Rejeitados', 'color' => '#f43f5e'],
            Submission::STATUS_PENDING => ['label' => 'Pendentes', 'color' => '#f59e0b'],
            Submission::STATUS_UNDER_REVIEW => ['label' => 'Em análise', 'color' => '#3b82f6'],
            Submission::STATUS_NEEDS_CORRECTION => ['label' => 'Aguardando correção', 'color' => '#d97706'],
        ];

        $total = array_sum($statusCounts);
        $items = [];
        $activeItems = [];

        foreach ($definitions as $status => $def) {
            $count = (int) ($statusCounts[$status] ?? 0);
            $percentage = $total > 0 ? round(($count / $total) * 100, 1) : 0.0;
            $entry = [
                'status' => $status,
                'label' => $def['label'],
                'count' => $count,
                'percentage' => $percentage,
                'color_hex' => $def['color'],
            ];
            $items[] = $entry;
            if ($count > 0) {
                $activeItems[] = $entry;
            }
        }

        return [
            'total' => $total,
            'items' => $items,
            'active_items' => $activeItems,
        ];
    }

    public function getSubmissionsUrl(): string
    {
        return SubmissionResource::getUrl('index', panel: 'admin');
    }

    protected function getData(): array
    {
        $details = $this->getDetails();

        if ($details['total'] === 0) {
            return [
                'labels' => ['Sem dados'],
                'datasets' => [[
                    'data' => [1],
                    'backgroundColor' => ['rgba(148, 163, 184, 0.15)'],
                    'borderWidth' => 0,
                ]],
            ];
        }

        return [
            'labels' => array_column($details['active_items'], 'label'),
            'datasets' => [[
                'label' => 'Submissões',
                'data' => array_column($details['active_items'], 'count'),
                'backgroundColor' => array_column($details['active_items'], 'color_hex'),
                'borderWidth' => 2,
                'borderColor' => '#0d252e',
                'hoverOffset' => 4,
            ]],
        ];
    }

    protected function getOptions(): array
    {
        return [
            'cutout' => '72%',
            'maintainAspectRatio' => false,
            'plugins' => [
                'legend' => [
                    'display' => false,
                ],
                'tooltip' => [
                    'padding' => 10,
                    'boxPadding' => 4,
                    'usePointStyle' => true,
                ],
            ],
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }
}
