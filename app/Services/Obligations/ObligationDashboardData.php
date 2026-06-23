<?php

namespace App\Services\Obligations;

use App\Models\Emission;
use App\Models\Obligation;
use App\Models\ObligationEvidence;
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
     * Business-facing due date windows for operational filters.
     *
     * @var array<string, string>
     */
    public const DUE_WINDOW_OPTIONS = [
        'overdue' => 'Vencidas',
        'today' => 'Vencem hoje',
        'next_7_days' => 'Próximos 7 dias',
        'next_30_days' => 'Próximos 30 dias',
        'without_due_date' => 'Sem data de vencimento',
    ];

    /**
     * Evidence filters exposed in the operational table.
     *
     * @var array<string, string>
     */
    public const EVIDENCE_FILTER_OPTIONS = [
        ObligationEvidence::STATUS_APPROVED => 'Com evidência aprovada',
        ObligationEvidence::STATUS_PENDING => 'Com evidência pendente',
        ObligationEvidence::STATUS_REJECTED => 'Com evidência rejeitada',
        'without_evidence' => 'Sem evidência',
        'without_approved_evidence' => 'Sem evidência aprovada',
    ];

    /**
     * Consolidated KPIs across every emission.
     *
     * @return array{
     *     total: int,
     *     a_vencer: int,
     *     vencida: int,
     *     concluida: int,
     *     em_analise: int,
     *     nao_aplicavel: int,
     *     sem_data: int,
     *     sem_responsavel: int,
     *     vence_hoje: int,
     *     proximos_7_dias: int,
     *     proximos_30_dias: int,
     *     com_evidencia_aprovada: int,
     *     com_evidencia_pendente: int,
     *     com_evidencia_rejeitada: int,
     *     sem_evidencia: int,
     *     concluidas_sem_evidencia_aprovada: int
     * }
     */
    public function summary(?CarbonInterface $referenceDate = null): array
    {
        $today = $this->today($referenceDate);
        $counts = $this->countsByStatus();
        $evidenceCoverage = $this->evidenceCoverage();

        return [
            'total' => array_sum($counts),
            'a_vencer' => $counts['a_vencer'] ?? 0,
            'vencida' => $counts['vencida'] ?? 0,
            'concluida' => $counts['concluida'] ?? 0,
            'em_analise' => $counts['em_analise'] ?? 0,
            'nao_aplicavel' => $counts['nao_aplicavel'] ?? 0,
            'sem_data' => $this->pendingBaseQuery()->whereNull('due_date')->count(),
            'sem_responsavel' => $this->pendingBaseQuery()->whereNull('responsible_user_id')->count(),
            'vence_hoje' => $this->pendingBaseQuery()
                ->whereDate('due_date', $today)
                ->count(),
            'proximos_7_dias' => $this->dueWithinDays($today, 7),
            'proximos_30_dias' => $this->dueWithinDays($today, 30),
            'com_evidencia_aprovada' => $evidenceCoverage['approved'],
            'com_evidencia_pendente' => $evidenceCoverage['pending'],
            'com_evidencia_rejeitada' => $evidenceCoverage['rejected'],
            'sem_evidencia' => $evidenceCoverage['without_evidence'],
            'concluidas_sem_evidencia_aprovada' => Obligation::query()
                ->where('status', 'concluida')
                ->whereDoesntHave('evidences', fn (Builder $query): Builder => $query->approved())
                ->count(),
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
     * Number of open obligations grouped by manual priority.
     *
     * @return array<string, int>
     */
    public function priorityDistribution(): array
    {
        $counts = $this->countsByPriority();
        $distribution = [];

        foreach (array_keys(Obligation::PRIORITY_OPTIONS) as $priority) {
            $distribution[$priority] = $counts[$priority] ?? 0;
        }

        return $distribution;
    }

    /**
     * Obligations grouped by documentary situation.
     *
     * @return array{approved: int, pending: int, rejected: int, without_evidence: int}
     */
    public function evidenceCoverage(): array
    {
        return [
            'approved' => $this->obligationsWithEvidenceStatus(ObligationEvidence::STATUS_APPROVED),
            'pending' => $this->obligationsWithEvidenceStatus(ObligationEvidence::STATUS_PENDING),
            'rejected' => $this->obligationsWithEvidenceStatus(ObligationEvidence::STATUS_REJECTED),
            'without_evidence' => Obligation::query()->doesntHave('evidences')->count(),
        ];
    }

    /**
     * Emissions concentrating the most open obligations.
     *
     * @return Collection<int, Emission>
     */
    public function topEmissionsByPending(int $limit = 8, ?CarbonInterface $referenceDate = null): Collection
    {
        $today = $this->today($referenceDate);

        return Emission::query()
            ->withCount([
                'obligations as pending_obligations_count' => fn (Builder $query): Builder => $query
                    ->whereNotIn('status', self::FINALIZED_STATUSES),
                'obligations as overdue_obligations_count' => fn (Builder $query): Builder => $query
                    ->whereNotIn('status', self::FINALIZED_STATUSES)
                    ->whereNotNull('due_date')
                    ->whereDate('due_date', '<', $today),
            ])
            ->whereHas('obligations', fn (Builder $query): Builder => $query
                ->whereNotIn('status', self::FINALIZED_STATUSES))
            ->orderByDesc('pending_obligations_count')
            ->orderByDesc('overdue_obligations_count')
            ->orderBy('name')
            ->limit($limit)
            ->get();
    }

    /**
     * Users concentrating the largest volume of open obligations.
     *
     * @return Collection<int, object>
     */
    public function topResponsiblesByPending(int $limit = 8, ?CarbonInterface $referenceDate = null): Collection
    {
        $today = $this->today($referenceDate);

        return Obligation::query()
            ->selectRaw('users.id as responsible_user_id, users.name')
            ->selectRaw('COUNT(*) as pending_obligations_count')
            ->selectRaw(
                'SUM(CASE WHEN obligations.due_date IS NOT NULL AND obligations.due_date < ? THEN 1 ELSE 0 END) as overdue_obligations_count',
                [$today->toDateString()],
            )
            ->join('users', 'users.id', '=', 'obligations.responsible_user_id')
            ->whereNotIn('obligations.status', self::FINALIZED_STATUSES)
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('pending_obligations_count')
            ->orderByDesc('overdue_obligations_count')
            ->orderBy('users.name')
            ->limit($limit)
            ->get();
    }

    /**
     * Areas concentrating the largest volume of open obligations.
     *
     * @return Collection<int, object>
     */
    public function topAreasByPending(int $limit = 8, ?CarbonInterface $referenceDate = null): Collection
    {
        $today = $this->today($referenceDate);
        $labelExpression = "COALESCE(NULLIF(responsible_area, ''), 'Sem área')";

        return Obligation::query()
            ->selectRaw("{$labelExpression} as label")
            ->selectRaw('COUNT(*) as pending_obligations_count')
            ->selectRaw(
                'SUM(CASE WHEN due_date IS NOT NULL AND due_date < ? THEN 1 ELSE 0 END) as overdue_obligations_count',
                [$today->toDateString()],
            )
            ->whereNotIn('status', self::FINALIZED_STATUSES)
            ->groupBy(DB::raw($labelExpression))
            ->orderByDesc('pending_obligations_count')
            ->orderByDesc('overdue_obligations_count')
            ->orderBy('label')
            ->limit($limit)
            ->get();
    }

    /**
     * Aging buckets for overdue obligations still in operation.
     *
     * @return array{days_1_7: int, days_8_15: int, days_16_30: int, days_31_plus: int}
     */
    public function overdueAging(?CarbonInterface $referenceDate = null): array
    {
        $today = $this->today($referenceDate);

        return [
            'days_1_7' => $this->overdueBaseQuery($today)
                ->whereDate('due_date', '>=', $today->copy()->subDays(7))
                ->count(),
            'days_8_15' => $this->overdueBaseQuery($today)
                ->whereDate('due_date', '<', $today->copy()->subDays(7))
                ->whereDate('due_date', '>=', $today->copy()->subDays(15))
                ->count(),
            'days_16_30' => $this->overdueBaseQuery($today)
                ->whereDate('due_date', '<', $today->copy()->subDays(15))
                ->whereDate('due_date', '>=', $today->copy()->subDays(30))
                ->count(),
            'days_31_plus' => $this->overdueBaseQuery($today)
                ->whereDate('due_date', '<', $today->copy()->subDays(30))
                ->count(),
        ];
    }

    /**
     * Base query for the operational attention table, ordered by daily action priority.
     */
    public function operationalQuery(?CarbonInterface $referenceDate = null): Builder
    {
        $today = $this->today($referenceDate);

        return $this->withOperationalCounts(
            Obligation::query()
        )
            ->whereNotIn('status', self::FINALIZED_STATUSES)
            ->orderByRaw(
                <<<'SQL'
case
    when due_date is not null and DATE(due_date) < ? and priority = 'critical' then 0
    when due_date is not null and DATE(due_date) < ? then 1
    when due_date is not null and DATE(due_date) = ? then 2
    when due_date is not null and DATE(due_date) between ? and ? then 3
    when status = 'em_analise' then 4
    when rejected_evidences_count > 0 then 5
    when evidences_count = 0 then 6
    when responsible_user_id is null then 7
    when due_date is not null and DATE(due_date) between ? and ? then 8
    when due_date is null then 10
    else 9
end
SQL,
                [
                    $today->toDateString(),
                    $today->toDateString(),
                    $today->toDateString(),
                    $today->copy()->addDay()->toDateString(),
                    $today->copy()->addDays(7)->toDateString(),
                    $today->copy()->addDays(8)->toDateString(),
                    $today->copy()->addDays(30)->toDateString(),
                ],
            )
            ->orderByRaw(
                "case priority when 'critical' then 0 when 'high' then 1 when 'medium' then 2 else 3 end"
            )
            ->orderByRaw('case when due_date is null then 1 else 0 end')
            ->orderBy('due_date')
            ->orderBy('title');
    }

    /**
     * Visual-only urgency classification derived from the due date.
     */
    public function urgencyFor(Obligation $obligation, ?CarbonInterface $referenceDate = null): string
    {
        if ($obligation->due_date === null) {
            return 'undefined';
        }

        $today = $this->today($referenceDate);
        $daysUntilDue = (int) $today->diffInDays($obligation->due_date->copy()->startOfDay(), false);

        return match (true) {
            $daysUntilDue < 0 => 'critical',
            $daysUntilDue <= 3 => 'high',
            $daysUntilDue <= 7 => 'medium',
            default => 'low',
        };
    }

    public function agingLabelFor(Obligation $obligation, ?CarbonInterface $referenceDate = null): ?string
    {
        if ($obligation->due_date === null) {
            return null;
        }

        $today = $this->today($referenceDate);
        $daysOverdue = (int) $obligation->due_date->copy()->startOfDay()->diffInDays($today, false);

        if ($daysOverdue <= 0) {
            return null;
        }

        return match (true) {
            $daysOverdue <= 7 => '1 a 7 dias',
            $daysOverdue <= 15 => '8 a 15 dias',
            $daysOverdue <= 30 => '16 a 30 dias',
            default => 'Mais de 30 dias',
        };
    }

    public function documentStatusFor(Obligation $obligation): string
    {
        $approvedCount = $this->evidenceCountFromRecord($obligation, 'approved_evidences_count', fn (): int => $obligation->evidences()->approved()->count());
        $pendingCount = $this->evidenceCountFromRecord($obligation, 'pending_evidences_count', fn (): int => $obligation->evidences()->pending()->count());
        $rejectedCount = $this->evidenceCountFromRecord($obligation, 'rejected_evidences_count', fn (): int => $obligation->evidences()->rejected()->count());

        return match (true) {
            $approvedCount === 0 && $pendingCount === 0 && $rejectedCount === 0 => 'Sem evidência',
            $approvedCount > 0 && $pendingCount === 0 && $rejectedCount === 0 => 'Evidência aprovada',
            $approvedCount === 0 && $pendingCount > 0 && $rejectedCount === 0 => 'Evidência pendente',
            $approvedCount === 0 && $pendingCount === 0 && $rejectedCount > 0 => 'Evidência rejeitada',
            $approvedCount > 0 && $pendingCount > 0 && $rejectedCount === 0 => 'Aprovada com pendência',
            $approvedCount > 0 && $pendingCount === 0 && $rejectedCount > 0 => 'Aprovada com rejeição',
            $approvedCount === 0 && $pendingCount > 0 && $rejectedCount > 0 => 'Pendente com rejeição',
            default => 'Aprovada, pendente e rejeitada',
        };
    }

    public function documentStatusColorFor(Obligation $obligation): string
    {
        $approvedCount = $this->evidenceCountFromRecord($obligation, 'approved_evidences_count', fn (): int => $obligation->evidences()->approved()->count());
        $pendingCount = $this->evidenceCountFromRecord($obligation, 'pending_evidences_count', fn (): int => $obligation->evidences()->pending()->count());
        $rejectedCount = $this->evidenceCountFromRecord($obligation, 'rejected_evidences_count', fn (): int => $obligation->evidences()->rejected()->count());

        return match (true) {
            $rejectedCount > 0 => 'danger',
            $pendingCount > 0 => 'warning',
            $approvedCount > 0 => 'success',
            default => 'gray',
        };
    }

    public function applyDueWindowFilter(Builder $query, ?string $value, ?CarbonInterface $referenceDate = null): Builder
    {
        if (blank($value)) {
            return $query;
        }

        $today = $this->today($referenceDate);

        return match ($value) {
            'overdue' => $query
                ->whereNotNull('due_date')
                ->whereDate('due_date', '<', $today),
            'today' => $query
                ->whereDate('due_date', $today),
            'next_7_days' => $query
                ->whereNotNull('due_date')
                ->whereBetween('due_date', [$today->copy()->addDay(), $today->copy()->addDays(7)]),
            'next_30_days' => $query
                ->whereNotNull('due_date')
                ->whereBetween('due_date', [$today->copy()->addDay(), $today->copy()->addDays(30)]),
            'without_due_date' => $query->whereNull('due_date'),
            default => $query,
        };
    }

    public function applyEvidenceFilter(Builder $query, ?string $value): Builder
    {
        if (blank($value)) {
            return $query;
        }

        return match ($value) {
            ObligationEvidence::STATUS_APPROVED => $query
                ->whereHas('evidences', fn (Builder $evidenceQuery): Builder => $evidenceQuery->approved()),
            ObligationEvidence::STATUS_PENDING => $query
                ->whereHas('evidences', fn (Builder $evidenceQuery): Builder => $evidenceQuery->pending()),
            ObligationEvidence::STATUS_REJECTED => $query
                ->whereHas('evidences', fn (Builder $evidenceQuery): Builder => $evidenceQuery->rejected()),
            'without_evidence' => $query->whereDoesntHave('evidences'),
            'without_approved_evidence' => $query
                ->whereDoesntHave('evidences', fn (Builder $evidenceQuery): Builder => $evidenceQuery->approved()),
            default => $query,
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

    protected function overdueBaseQuery(CarbonInterface $today): Builder
    {
        return $this->pendingBaseQuery()
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', $today);
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

    /**
     * @return array<string, int>
     */
    protected function countsByPriority(): array
    {
        return $this->pendingBaseQuery()
            ->select(['priority', DB::raw('COUNT(*) as count')])
            ->groupBy('priority')
            ->get()
            ->pluck('count', 'priority')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }

    protected function obligationsWithEvidenceStatus(string $status): int
    {
        return Obligation::query()
            ->whereHas('evidences', fn (Builder $query): Builder => $query->where('status', $status))
            ->count();
    }

    protected function withOperationalCounts(Builder $query): Builder
    {
        return $query->withCount([
            'evidences',
            'evidences as approved_evidences_count' => fn (Builder $evidenceQuery): Builder => $evidenceQuery->approved(),
            'evidences as pending_evidences_count' => fn (Builder $evidenceQuery): Builder => $evidenceQuery->pending(),
            'evidences as rejected_evidences_count' => fn (Builder $evidenceQuery): Builder => $evidenceQuery->rejected(),
        ]);
    }

    protected function evidenceCountFromRecord(Obligation $obligation, string $attribute, callable $fallback): int
    {
        $value = $obligation->getAttribute($attribute);

        if (is_numeric($value)) {
            return (int) $value;
        }

        return (int) $fallback();
    }

    protected function today(?CarbonInterface $referenceDate = null): CarbonInterface
    {
        return ($referenceDate ?? now())->copy()->startOfDay();
    }
}
