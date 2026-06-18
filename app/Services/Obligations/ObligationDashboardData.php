<?php

namespace App\Services\Obligations;

use App\Models\Emission;
use App\Models\Obligation;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ObligationDashboardData
{
    /**
     * Statuses that finalize an obligation and therefore must be excluded from
     * the operational attention list and the pending date windows.
     *
     * @var list<string>
     */
    public const FINALIZED_STATUSES = ['concluida', 'nao_aplicavel'];

    /**
     * Consolidated KPIs across every emission.
     *
     * @return array{
     *     total: int,
     *     a_vencer: int,
     *     vencida: int,
     *     concluida: int,
     *     sem_data: int,
     *     vence_hoje: int,
     *     proximos_7_dias: int,
     *     proximos_30_dias: int
     * }
     */
    public function summary(?CarbonInterface $referenceDate = null): array
    {
        $today = ($referenceDate ?? now())->copy()->startOfDay();
        $counts = $this->countsByStatus();

        return [
            'total' => array_sum($counts),
            'a_vencer' => $counts['a_vencer'] ?? 0,
            'vencida' => $counts['vencida'] ?? 0,
            'concluida' => $counts['concluida'] ?? 0,
            'sem_data' => $this->pendingBaseQuery()->whereNull('due_date')->count(),
            'vence_hoje' => $this->pendingBaseQuery()
                ->whereDate('due_date', $today)
                ->count(),
            'proximos_7_dias' => $this->dueWithinDays($today, 7),
            'proximos_30_dias' => $this->dueWithinDays($today, 30),
        ];
    }

    /**
     * Number of obligations grouped by official status, ordered for display.
     *
     * @return array<string, int>
     */
    public function statusDistribution(): array
    {
        $counts = $this->countsByStatus();

        $distribution = [];

        foreach (array_keys(Obligation::STATUS_OPTIONS) as $status) {
            $distribution[$status] = $counts[$status] ?? 0;
        }

        return $distribution;
    }

    /**
     * Emissions concentrating the most pending obligations (a vencer/vencidas).
     *
     * @return Collection<int, Emission>
     */
    public function topEmissionsByPending(int $limit = 8): Collection
    {
        return Emission::query()
            ->withCount([
                'obligations as pending_obligations_count' => fn (Builder $query): Builder => $query
                    ->whereIn('status', ['a_vencer', 'vencida']),
                'obligations as overdue_obligations_count' => fn (Builder $query): Builder => $query
                    ->where('status', 'vencida'),
            ])
            ->whereHas('obligations', fn (Builder $query): Builder => $query
                ->whereIn('status', ['a_vencer', 'vencida']))
            ->orderByDesc('pending_obligations_count')
            ->orderByDesc('overdue_obligations_count')
            ->orderBy('name')
            ->limit($limit)
            ->get();
    }

    /**
     * Base query for the operational attention table: non-finalized obligations
     * ordered by urgency (overdue first, then nearest due date, undated last).
     */
    public function operationalQuery(): Builder
    {
        return Obligation::query()
            ->whereNotIn('status', self::FINALIZED_STATUSES)
            ->orderByRaw('case when due_date is null then 1 else 0 end')
            ->orderBy('due_date')
            ->orderByDesc('id');
    }

    /**
     * Visual-only urgency classification derived from the due date.
     */
    public function urgencyFor(Obligation $obligation, ?CarbonInterface $referenceDate = null): string
    {
        if ($obligation->due_date === null) {
            return 'undefined';
        }

        $today = ($referenceDate ?? now())->copy()->startOfDay();
        $daysUntilDue = (int) $today->diffInDays($obligation->due_date->copy()->startOfDay(), false);

        return match (true) {
            $daysUntilDue < 0 => 'critical',
            $daysUntilDue <= 3 => 'high',
            $daysUntilDue <= 7 => 'medium',
            default => 'low',
        };
    }

    /**
     * Count of non-finalized obligations due between today and today+$days.
     */
    protected function dueWithinDays(CarbonInterface $today, int $days): int
    {
        return $this->pendingBaseQuery()
            ->whereNotNull('due_date')
            ->whereBetween('due_date', [$today, $today->copy()->addDays($days)])
            ->count();
    }

    protected function pendingBaseQuery(): Builder
    {
        return Obligation::query()->whereNotIn('status', self::FINALIZED_STATUSES);
    }

    /**
     * @return array<string, int>
     */
    protected function countsByStatus(): array
    {
        return Obligation::query()
            ->select(['status', DB::raw('COUNT(*) as count')])
            ->groupBy('status')
            ->get()
            ->pluck('count', 'status')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }
}
