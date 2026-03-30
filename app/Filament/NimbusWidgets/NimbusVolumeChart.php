<?php

namespace App\Filament\NimbusWidgets;

use Filament\Widgets\ChartWidget;
use App\Models\Nimbus\Submission;
use Illuminate\Support\Carbon;

class NimbusVolumeChart extends ChartWidget
{
    protected static ?string $heading = 'Volume de Envios (30 Dias)';
    
    // Span 2 cols out of 3
    protected int | string | array $columnSpan = 2;

    protected static ?string $maxHeight = '250px';

    protected function getData(): array
    {
        $startDate = Carbon::now()->subDays(29)->startOfDay();
        $endDate = Carbon::now()->endOfDay();
        
        $submissions = Submission::whereBetween('submitted_at', [$startDate, $endDate])
            ->get()
            ->groupBy(function($date) {
                return Carbon::parse($date->submitted_at)->format('d/m');
            });
            
        $labels = [];
        $data = [];
        
        for ($i = 29; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i)->format('d/m');
            $labels[] = $date;
            $data[] = isset($submissions[$date]) ? count($submissions[$date]) : 0;
        }

        return [
            'datasets' => [
                [
                    'label' => 'Submissões Totais',
                    'data' => $data,
                    'fill' => 'start',
                    'backgroundColor' => 'rgba(59, 130, 246, 0.2)', // blue-500 light
                    'borderColor' => '#3b82f6', // blue-500
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
