<?php

namespace App\Filament\Widgets\Dashboard;

use App\Filament\Resources\Measurements\MeasurementResource;
use App\Filament\Resources\Measurements\Tables\MeasurementsTable;
use App\Filament\Resources\PaymentWorkspaces\PaymentWorkspaceResource;
use App\Models\User;
use App\Services\MeasurementCockpitService;
use App\Services\MeasurementWorkflow;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

class MeasurementCockpit extends Widget
{
    use InteractsWithPageFilters;

    protected string $view = 'filament.widgets.dashboard.measurement-cockpit';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 1;

    public static function canView(): bool
    {
        return auth()->user()?->can('measurements.view') ?? false;
    }

    protected function getViewData(): array
    {
        $viewer = auth()->user();
        abort_unless($viewer instanceof User, 403);

        $pageFilters = $this->normalizedPageFilters();
        $summary = app(MeasurementCockpitService::class)->summaryFor($viewer, $pageFilters);

        return [
            'summary' => $summary,
            'stages' => collect(MeasurementWorkflow::STAGE_LABELS)
                ->map(fn (string $label, int $stage): array => [
                    'label' => $label,
                    'count' => $summary['stages'][$stage],
                    'url' => $this->measurementUrl($pageFilters, ['stage' => $stage]),
                ])
                ->values()
                ->push([
                    'label' => 'Finalizado',
                    'count' => $summary['finalized'],
                    'url' => $this->measurementUrl($pageFilters, ['status' => 'finalized']),
                ])
                ->values(),
            'signals' => [
                [
                    'label' => 'SLA vencido',
                    'count' => $summary['overdue'],
                    'description' => 'Exige atuação imediata',
                    'tone' => 'danger',
                    'url' => $this->measurementUrl($pageFilters, ['sla_status' => 'overdue']),
                ],
                [
                    'label' => 'SLA em atenção',
                    'count' => $summary['approaching'],
                    'description' => 'Prazo se aproximando',
                    'tone' => 'warning',
                    'url' => $this->measurementUrl($pageFilters, ['sla_status' => 'approaching']),
                ],
                [
                    'label' => 'Pausadas',
                    'count' => $summary['paused'],
                    'description' => 'SLA interrompido pelo workflow',
                    'tone' => 'warning',
                    'url' => $this->measurementUrl($pageFilters, ['status' => 'paused']),
                ],
                [
                    'label' => 'Delegadas no meu escopo',
                    'count' => $summary['delegated'],
                    'description' => 'Atuação temporária efetiva',
                    'tone' => 'info',
                    'url' => $this->measurementUrl($pageFilters, ['assignment' => 'delegated']),
                ],
                [
                    'label' => 'Comprovantes pendentes',
                    'count' => $summary['pending_receipt_count'],
                    'description' => 'Pagamentos sem comprovante',
                    'tone' => 'danger',
                    'url' => $this->paymentUrl($pageFilters, ['receipt_state' => 'pending']),
                ],
                [
                    'label' => 'Aguardando comprovante',
                    'count' => $summary['awaiting_receipt'],
                    'description' => 'Medições na etapa formal',
                    'tone' => 'warning',
                    'url' => $this->paymentUrl($pageFilters, ['status' => 'awaiting_receipt']),
                ],
                [
                    'label' => 'Prontas para finalizar',
                    'count' => $summary['ready_to_finalize'],
                    'description' => 'Finalização ainda necessária',
                    'tone' => 'success',
                    'url' => $this->paymentUrl($pageFilters, ['status' => 'approved']),
                ],
                [
                    'label' => 'Calendário indisponível',
                    'count' => $summary['calendar_unavailable'],
                    'description' => 'SLA em fail-closed',
                    'tone' => 'info',
                    'url' => $this->measurementUrl($pageFilters, ['sla_status' => 'calendar_unavailable']),
                ],
            ],
            'paymentWorkspaceUrl' => PaymentWorkspaceResource::getUrl('index', [
                'tableFilters' => $this->tableFilters($pageFilters),
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $pageFilters
     * @param  array<string, mixed>  $cardRefinement
     */
    private function measurementUrl(array $pageFilters, array $cardRefinement = []): ?string
    {
        $filters = $this->resolveDrillDownFilters($pageFilters, $cardRefinement);

        if ($filters === null) {
            return null;
        }

        return MeasurementResource::getUrl('index', [
            'filters' => MeasurementsTable::cockpitFiltersToTableState($filters),
        ]);
    }

    /**
     * @param  array<string, mixed>  $pageFilters
     * @param  array<string, mixed>  $cardRefinement
     */
    private function paymentUrl(array $pageFilters, array $cardRefinement = []): ?string
    {
        $filters = $this->resolveDrillDownFilters($pageFilters, $cardRefinement);

        if ($filters === null) {
            return null;
        }

        return PaymentWorkspaceResource::getUrl('index', [
            'tableFilters' => $this->tableFilters($filters),
        ]);
    }

    /**
     * @param  array<string, mixed>  $baseFilters
     * @param  array<string, mixed>  $cardRefinement
     * @return array<string, mixed>|null
     */
    private function resolveDrillDownFilters(array $baseFilters, array $cardRefinement): ?array
    {
        foreach ($cardRefinement as $dimension => $value) {
            if (array_key_exists($dimension, $baseFilters) && $baseFilters[$dimension] !== $value) {
                return null;
            }
        }

        return $baseFilters + $cardRefinement;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, array<string, mixed>>
     */
    private function tableFilters(array $filters): array
    {
        $tableFilters = [];

        if (filled($filters['competence_from'] ?? null) || filled($filters['competence_to'] ?? null)) {
            $tableFilters['competence_period'] = [
                'from' => $filters['competence_from'] ?? null,
                'to' => $filters['competence_to'] ?? null,
            ];
        }

        foreach (['operation_id', 'emission_id', 'responsible_user_id', 'stage', 'status', 'sla_status', 'assignment', 'receipt_state'] as $filter) {
            if (filled($filters[$filter] ?? null)) {
                $tableFilters[$filter] = ['value' => $filters[$filter]];
            }
        }

        return $tableFilters;
    }

    /** @return array<string, int|string> */
    private function normalizedPageFilters(): array
    {
        $filters = $this->pageFilters ?? [];

        return array_filter([
            'competence_from' => filled($filters['competence_from'] ?? null) ? (string) $filters['competence_from'] : null,
            'competence_to' => filled($filters['competence_to'] ?? null) ? (string) $filters['competence_to'] : null,
            'operation_id' => filled($filters['operation_id'] ?? null) ? (int) $filters['operation_id'] : null,
            'emission_id' => filled($filters['emission_id'] ?? null) ? (int) $filters['emission_id'] : null,
            'responsible_user_id' => filled($filters['responsible_user_id'] ?? null) ? (int) $filters['responsible_user_id'] : null,
            'stage' => filled($filters['stage'] ?? null) ? (int) $filters['stage'] : null,
            'status' => filled($filters['status'] ?? null) ? (string) $filters['status'] : null,
            'sla_status' => filled($filters['sla_status'] ?? null) ? (string) $filters['sla_status'] : null,
            'assignment' => filled($filters['assignment'] ?? null) ? (string) $filters['assignment'] : null,
            'receipt_state' => filled($filters['receipt_state'] ?? null) ? (string) $filters['receipt_state'] : null,
        ], static fn (mixed $value): bool => filled($value));
    }
}
