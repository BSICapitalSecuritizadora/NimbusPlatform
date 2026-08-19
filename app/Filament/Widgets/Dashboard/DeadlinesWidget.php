<?php

namespace App\Filament\Widgets\Dashboard;

use App\Filament\Pages\ObligationDashboard;
use App\Filament\Resources\Emissions\EmissionResource;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\ObligationsRelationManager;
use App\Models\Obligation;
use App\Services\Obligations\ObligationDashboardData;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Closure;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class DeadlinesWidget extends Widget
{
    private const PREVIEW_LIMIT = 5;

    protected string $view = 'filament.widgets.dashboard.deadlines-widget';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 5;

    protected function getViewData(): array
    {
        if (! auth()->user()->can('obligations.view')) {
            return [
                'canViewObligations' => false,
                'activeUrgentGroups' => collect(),
                'emptyGroups' => collect(),
                'urgentGroups' => collect(),
                'withoutDeadlineGroup' => null,
                'totalCount' => 0,
            ];
        }

        $today = Carbon::today();
        $canAccessDashboard = ObligationDashboard::canAccess();
        $baseQuery = Obligation::query()
            ->whereNotIn('status', ObligationDashboardData::FINALIZED_STATUSES);

        $urgentGroups = collect([
            $this->buildGroup(
                baseQuery: $baseQuery,
                today: $today,
                key: 'overdue',
                title: 'Vencidos',
                description: 'Prazo ultrapassado',
                tone: 'danger',
                icon: 'heroicon-o-exclamation-circle',
                constraint: fn (Builder $query): Builder => $query
                    ->whereNotNull('due_date')
                    ->whereDate('due_date', '<', $today),
                dashboardFilter: 'overdue',
                canAccessDashboard: $canAccessDashboard,
            ),
            $this->buildGroup(
                baseQuery: $baseQuery,
                today: $today,
                key: 'today',
                title: 'Vencem hoje',
                description: 'Ação até o fim do dia',
                tone: 'urgent',
                icon: 'heroicon-o-clock',
                constraint: fn (Builder $query): Builder => $query
                    ->whereDate('due_date', $today),
                dashboardFilter: 'today',
                canAccessDashboard: $canAccessDashboard,
            ),
            $this->buildGroup(
                baseQuery: $baseQuery,
                today: $today,
                key: 'next_3_days',
                title: 'Próx. 3 dias',
                description: 'Janela imediata',
                tone: 'warning',
                icon: 'heroicon-o-calendar',
                constraint: fn (Builder $query): Builder => $query
                    ->whereDate('due_date', '>=', $today->copy()->addDay())
                    ->whereDate('due_date', '<=', $today->copy()->addDays(3)),
                dashboardFilter: 'next_7_days',
                canAccessDashboard: $canAccessDashboard,
            ),
            $this->buildGroup(
                baseQuery: $baseQuery,
                today: $today,
                key: 'days_4_to_7',
                title: 'Próx. 7 dias',
                description: 'Acompanhamento preventivo',
                tone: 'primary',
                icon: 'heroicon-o-calendar-days',
                constraint: fn (Builder $query): Builder => $query
                    ->whereDate('due_date', '>=', $today->copy()->addDays(4))
                    ->whereDate('due_date', '<=', $today->copy()->addDays(7)),
                dashboardFilter: 'next_7_days',
                canAccessDashboard: $canAccessDashboard,
            ),
        ]);

        $withoutDeadlineGroup = $this->buildGroup(
            baseQuery: $baseQuery,
            today: $today,
            key: 'without_deadline',
            title: 'Sem prazo',
            description: 'Itens que precisam de definição, sem criticidade de vencimento.',
            tone: 'neutral',
            icon: 'heroicon-o-calendar-date-range',
            constraint: fn (Builder $query): Builder => $query->whereNull('due_date'),
            dashboardFilter: 'without_due_date',
            canAccessDashboard: $canAccessDashboard,
        );

        $activeUrgentGroups = $urgentGroups
            ->where('count', '>', 0)
            ->values();
        $emptyGroups = $urgentGroups
            ->where('count', 0)
            ->values();

        if ($withoutDeadlineGroup['count'] === 0 && $activeUrgentGroups->isNotEmpty()) {
            $emptyGroups->push($withoutDeadlineGroup);
        }

        return [
            'canViewObligations' => true,
            'activeUrgentGroups' => $activeUrgentGroups,
            'emptyGroups' => $emptyGroups,
            'urgentGroups' => $urgentGroups,
            'withoutDeadlineGroup' => $withoutDeadlineGroup,
            'totalCount' => $urgentGroups->sum('count') + $withoutDeadlineGroup['count'],
        ];
    }

    /**
     * @param  Closure(Builder<Obligation>): Builder<Obligation>  $constraint
     * @return array{
     *     key: string,
     *     title: string,
     *     description: string,
     *     tone: string,
     *     icon: string,
     *     count: int,
     *     items: Collection<int, array<string, mixed>>,
     *     dashboardUrl: ?string,
     *     actionLabel: string
     * }
     */
    private function buildGroup(
        Builder $baseQuery,
        CarbonInterface $today,
        string $key,
        string $title,
        string $description,
        string $tone,
        string $icon,
        Closure $constraint,
        ?string $dashboardFilter,
        bool $canAccessDashboard,
    ): array {
        $groupQuery = $constraint(clone $baseQuery);
        $count = (clone $groupQuery)->count();
        $itemsQuery = (clone $groupQuery)
            ->select([
                'id',
                'emission_id',
                'title',
                'competence_date',
                'due_date',
                'priority',
                'status',
            ])
            ->with('emission:id,name');

        if ($key !== 'without_deadline') {
            $itemsQuery->orderBy('due_date');
        }

        $items = $itemsQuery
            ->orderByRaw("case priority when 'critical' then 0 when 'high' then 1 when 'medium' then 2 else 3 end")
            ->orderBy('title')
            ->limit(self::PREVIEW_LIMIT)
            ->get()
            ->map(fn (Obligation $obligation): array => $this->formatItem($obligation, $today, $tone));

        $dashboardUrl = $canAccessDashboard
            ? ObligationDashboard::getUrl($dashboardFilter === null ? [] : ['filters' => ['due_window' => $dashboardFilter]])
            : null;

        return [
            'key' => $key,
            'title' => $title,
            'description' => $description,
            'tone' => $tone,
            'icon' => $icon,
            'count' => $count,
            'items' => $items,
            'dashboardUrl' => $dashboardUrl,
            'actionLabel' => $key === 'without_deadline'
                ? "Ver todos os {$count}"
                : 'Abrir no painel',
        ];
    }

    /**
     * @return array{
     *     id: int,
     *     title: string,
     *     operation: string,
     *     deadline: string,
     *     priority: string,
     *     priorityTone: string,
     *     tone: string,
     *     url: string
     * }
     */
    private function formatItem(Obligation $obligation, CarbonInterface $today, string $tone): array
    {
        return [
            'id' => $obligation->id,
            'title' => $obligation->operational_title,
            'operation' => $obligation->emission?->name ?? 'Operação não informada',
            'deadline' => $this->deadlineLabel($obligation, $today),
            'priority' => $obligation->priority_label,
            'priorityTone' => match ($obligation->priority) {
                'critical' => 'danger',
                'high' => 'warning',
                default => 'neutral',
            },
            'tone' => $tone,
            'url' => EmissionResource::getUrl('edit', [
                'record' => $obligation->emission_id,
                'relation' => ObligationsRelationManager::class,
            ]),
        ];
    }

    private function deadlineLabel(Obligation $obligation, CarbonInterface $today): string
    {
        if ($obligation->due_date === null) {
            return 'Sem prazo definido';
        }

        $dueDate = $obligation->due_date->copy()->startOfDay();
        $daysUntilDue = (int) $today->diffInDays($dueDate, false);
        $formattedDate = $dueDate->format('d/m/Y');

        return match (true) {
            $daysUntilDue < -1 => 'Vencida há '.abs($daysUntilDue).' dias · '.$formattedDate,
            $daysUntilDue === -1 => 'Vencida há 1 dia · '.$formattedDate,
            $daysUntilDue === 0 => 'Vence hoje · '.$formattedDate,
            $daysUntilDue === 1 => 'Vence amanhã · '.$formattedDate,
            default => "Vence em {$daysUntilDue} dias · {$formattedDate}",
        };
    }
}
