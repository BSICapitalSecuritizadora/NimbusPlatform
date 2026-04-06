<?php

namespace App\Filament\NimbusWidgets;

use App\Models\Nimbus\Submission;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;

class NimbusStatusDistribution extends ChartWidget
{
    protected ?string $heading = 'Distribuição por Situação';

    // Span 1 col out of 3
    protected int|string|array $columnSpan = 1;

    // Use a fixed max height
    protected ?string $maxHeight = '250px';

    protected function getData(): array
    {
        $statusCounts = Submission::select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();

        return [
            'datasets' => [
                [
                    'label' => 'Submissões',
                    'data' => [
                        $statusCounts[Submission::STATUS_COMPLETED] ?? 0,
                        $statusCounts[Submission::STATUS_REJECTED] ?? 0,
                        $statusCounts[Submission::STATUS_PENDING] ?? 0,
                        $statusCounts[Submission::STATUS_UNDER_REVIEW] ?? 0,
                        $statusCounts[Submission::STATUS_NEEDS_CORRECTION] ?? 0,
                    ],
                    'backgroundColor' => [
                        '#10b981', // emerald-500
                        '#f43f5e', // rose-500
                        '#f59e0b', // amber-500
                        '#3b82f6', // blue-500
                        '#d97706', // amber-600
                    ],
                ],
            ],
            'labels' => ['Aprovado', 'Rejeitado', 'Pendente', 'Em Análise', 'Aguardando Correção'],
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }
}
