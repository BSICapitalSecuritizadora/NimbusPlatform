<?php

namespace App\Filament\NimbusWidgets;

use Filament\Widgets\ChartWidget;
use App\Models\Nimbus\Submission;
use Illuminate\Support\Facades\DB;

class NimbusStatusDistribution extends ChartWidget
{
    protected static ?string $heading = 'Distribuição por Situação';
    
    // Span 1 col out of 3
    protected int | string | array $columnSpan = 1;

    // Use a fixed max height
    protected static ?string $maxHeight = '250px';

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
                        $statusCounts['COMPLETED'] ?? 0,
                        $statusCounts['REJECTED'] ?? 0,
                        $statusCounts['PENDING'] ?? 0,
                        $statusCounts['UNDER_REVIEW'] ?? 0,
                    ],
                    'backgroundColor' => [
                        '#10b981', // emerald-500
                        '#f43f5e', // rose-500
                        '#f59e0b', // amber-500
                        '#3b82f6', // blue-500
                    ],
                ],
            ],
            'labels' => ['Aprovado', 'Rejeitado', 'Pendente', 'Em Análise'],
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }
}
