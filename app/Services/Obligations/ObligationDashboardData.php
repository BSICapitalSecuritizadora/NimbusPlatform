<?php

namespace App\Services\Obligations;

use App\Models\Emission;
use App\Models\Obligation;
use App\Models\ObligationEvidence;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
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
     * Origin filters exposed to the dashboard and operational table.
     *
     * @var array<string, string>
     */
    public const SOURCE_FILTER_OPTIONS = [
        'term' => 'Gerada pelo Termo',
        'manual' => 'Manual',
    ];

    /**
     * Quick operational focus buckets for the dashboard.
     *
     * @var array<string, string>
     */
    public const OPERATIONAL_FOCUS_OPTIONS = [
        'critical_overdue' => 'Pendências Críticas',
        'due_today' => 'Vencem Hoje',
        'next_7_days' => 'Próximos 7 Dias',
        'high_priority_next_7_days' => 'Alta Prioridade em 7 Dias',
        'in_review' => 'Em Análise',
        'rejected_evidence' => 'Evidência Rejeitada',
        'without_evidence' => 'Sem Evidência',
        'without_responsible' => 'Sem Responsável',
        'completed_without_approved_evidence' => 'Concluídas sem Evidência Aprovada',
        'in_review_with_pending_evidence' => 'Em análise com Evidência Pendente',
    ];

    /**
     * Documentary filters that should only be available to users with
     * obligations.view_evidence permission.
     *
     * @var list<string>
     */
    protected const EVIDENCE_RELATED_OPERATIONAL_FOCUSES = [
        'rejected_evidence',
        'without_evidence',
        'completed_without_approved_evidence',
        'in_review_with_pending_evidence',
    ];

    /**
     * Consolidated KPIs across every emission.
     *
     * @param  array<string, mixed>|null  $filters
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
     *     vencidas_criticas: int,
     *     alta_prioridade_proximos_7_dias: int,
     *     com_evidencia_aprovada: int,
     *     com_evidencia_pendente: int,
     *     com_evidencia_rejeitada: int,
     *     sem_evidencia: int,
     *     concluidas_com_evidencia_aprovada: int,
     *     em_analise_com_evidencia_pendente: int,
     *     concluidas_sem_evidencia_aprovada: int
     * }
     */
    public function summary(?array $filters = null, ?CarbonInterface $referenceDate = null): array
    {
        $today = $this->today($referenceDate);
        $counts = $this->countsByStatus($filters, $referenceDate);
        $evidenceCoverage = $this->evidenceCoverage($filters, $referenceDate);

        return [
            'total' => array_sum($counts),
            'a_vencer' => $counts['a_vencer'] ?? 0,
            'vencida' => $counts['vencida'] ?? 0,
            'concluida' => $counts['concluida'] ?? 0,
            'em_analise' => $counts['em_analise'] ?? 0,
            'nao_aplicavel' => $counts['nao_aplicavel'] ?? 0,
            'sem_data' => $this->pendingBaseQuery($filters, $referenceDate)
                ->whereNull('due_date')
                ->count(),
            'sem_responsavel' => $this->pendingBaseQuery($filters, $referenceDate)
                ->whereNull('responsible_user_id')
                ->count(),
            'vence_hoje' => $this->pendingBaseQuery($filters, $referenceDate)
                ->whereDate('due_date', $today)
                ->count(),
            'proximos_7_dias' => $this->dueWithinDays($today, 7, $filters),
            'proximos_30_dias' => $this->dueWithinDays($today, 30, $filters),
            'vencidas_criticas' => $this->overdueBaseQuery($today, $filters)
                ->where('priority', 'critical')
                ->count(),
            'alta_prioridade_proximos_7_dias' => $this->pendingBaseQuery($filters, $referenceDate)
                ->whereIn('priority', ['high', 'critical'])
                ->whereNotNull('due_date')
                ->whereBetween('due_date', [$today->copy()->addDay(), $today->copy()->addDays(7)])
                ->count(),
            'com_evidencia_aprovada' => $evidenceCoverage['approved'],
            'com_evidencia_pendente' => $evidenceCoverage['pending'],
            'com_evidencia_rejeitada' => $evidenceCoverage['rejected'],
            'sem_evidencia' => $evidenceCoverage['without_evidence'],
            'concluidas_com_evidencia_aprovada' => $this->filteredQuery($filters, $referenceDate)
                ->where('status', 'concluida')
                ->whereHas('evidences', fn (Builder $query): Builder => $query->approved())
                ->count(),
            'em_analise_com_evidencia_pendente' => $this->filteredQuery($filters, $referenceDate)
                ->where('status', 'em_analise')
                ->whereHas('evidences', fn (Builder $query): Builder => $query->pending())
                ->count(),
            'concluidas_sem_evidencia_aprovada' => $this->filteredQuery($filters, $referenceDate)
                ->where('status', 'concluida')
                ->whereDoesntHave('evidences', fn (Builder $query): Builder => $query->approved())
                ->count(),
        ];
    }

    /**
     * Number of obligations grouped by official status, ordered for display.
     *
     * @param  array<string, mixed>|null  $filters
     * @return array<string, int>
     */
    public function statusDistribution(?array $filters = null, ?CarbonInterface $referenceDate = null): array
    {
        $counts = $this->countsByStatus($filters, $referenceDate);
        $distribution = [];

        foreach (array_keys(Obligation::STATUS_OPTIONS) as $status) {
            $distribution[$status] = $counts[$status] ?? 0;
        }

        return $distribution;
    }

    /**
     * Number of open obligations grouped by manual priority.
     *
     * @param  array<string, mixed>|null  $filters
     * @return array<string, int>
     */
    public function priorityDistribution(?array $filters = null, ?CarbonInterface $referenceDate = null): array
    {
        $counts = $this->countsByPriority($filters, $referenceDate);
        $distribution = [];

        foreach (array_keys(Obligation::PRIORITY_OPTIONS) as $priority) {
            $distribution[$priority] = $counts[$priority] ?? 0;
        }

        return $distribution;
    }

    /**
     * Obligations grouped by documentary situation.
     *
     * @param  array<string, mixed>|null  $filters
     * @return array{approved: int, pending: int, rejected: int, without_evidence: int}
     */
    public function evidenceCoverage(?array $filters = null, ?CarbonInterface $referenceDate = null): array
    {
        return [
            'approved' => $this->obligationsWithEvidenceStatus(ObligationEvidence::STATUS_APPROVED, $filters, $referenceDate),
            'pending' => $this->obligationsWithEvidenceStatus(ObligationEvidence::STATUS_PENDING, $filters, $referenceDate),
            'rejected' => $this->obligationsWithEvidenceStatus(ObligationEvidence::STATUS_REJECTED, $filters, $referenceDate),
            'without_evidence' => $this->filteredQuery($filters, $referenceDate)
                ->doesntHave('evidences')
                ->count(),
        ];
    }

    /**
     * Emissions concentrating the most open obligations.
     *
     * @param  array<string, mixed>|null  $filters
     * @return Collection<int, Emission>
     */
    public function topEmissionsByPending(int $limit = 8, ?array $filters = null, ?CarbonInterface $referenceDate = null): Collection
    {
        $today = $this->today($referenceDate);
        $filters = $this->sanitizeFilters($filters);
        $emissionId = $filters['emission_id'] ?? null;

        return Emission::query()
            ->when(filled($emissionId), fn (Builder $query): Builder => $query->whereKey($emissionId))
            ->withCount([
                'obligations as pending_obligations_count' => fn (Builder $query): Builder => $this->applyDashboardFilters($query, $filters, $referenceDate)
                    ->whereNotIn('status', self::FINALIZED_STATUSES),
                'obligations as overdue_obligations_count' => fn (Builder $query): Builder => $this->applyDashboardFilters($query, $filters, $referenceDate)
                    ->whereNotIn('status', self::FINALIZED_STATUSES)
                    ->whereNotNull('due_date')
                    ->whereDate('due_date', '<', $today),
                'obligations as pending_evidence_obligations_count' => fn (Builder $query): Builder => $this->applyDashboardFilters($query, $filters, $referenceDate)
                    ->whereHas('evidences', fn (Builder $evidenceQuery): Builder => $evidenceQuery->pending()),
            ])
            ->whereHas('obligations', fn (Builder $query): Builder => $this->applyDashboardFilters($query, $filters, $referenceDate)
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
     * @param  array<string, mixed>|null  $filters
     * @return Collection<int, object>
     */
    public function topResponsiblesByPending(int $limit = 8, ?array $filters = null, ?CarbonInterface $referenceDate = null): Collection
    {
        $today = $this->today($referenceDate);

        return $this->filteredQuery($filters, $referenceDate)
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
     * @param  array<string, mixed>|null  $filters
     * @return Collection<int, object>
     */
    public function topAreasByPending(int $limit = 8, ?array $filters = null, ?CarbonInterface $referenceDate = null): Collection
    {
        $today = $this->today($referenceDate);
        $labelExpression = "COALESCE(NULLIF(responsible_area, ''), 'Sem área')";

        return $this->filteredQuery($filters, $referenceDate)
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
     * @param  array<string, mixed>|null  $filters
     * @return array{days_1_7: int, days_8_15: int, days_16_30: int, days_31_plus: int}
     */
    public function overdueAging(?array $filters = null, ?CarbonInterface $referenceDate = null): array
    {
        $today = $this->today($referenceDate);

        return [
            'days_1_7' => $this->overdueBaseQuery($today, $filters)
                ->whereDate('due_date', '>=', $today->copy()->subDays(7))
                ->count(),
            'days_8_15' => $this->overdueBaseQuery($today, $filters)
                ->whereDate('due_date', '<', $today->copy()->subDays(7))
                ->whereDate('due_date', '>=', $today->copy()->subDays(15))
                ->count(),
            'days_16_30' => $this->overdueBaseQuery($today, $filters)
                ->whereDate('due_date', '<', $today->copy()->subDays(15))
                ->whereDate('due_date', '>=', $today->copy()->subDays(30))
                ->count(),
            'days_31_plus' => $this->overdueBaseQuery($today, $filters)
                ->whereDate('due_date', '<', $today->copy()->subDays(30))
                ->count(),
        ];
    }

    /**
     * Base query for the operational attention table, ordered by daily action priority.
     *
     * @param  array<string, mixed>|null  $filters
     */
    public function operationalQuery(
        ?array $filters = null,
        ?CarbonInterface $referenceDate = null,
        bool $includeConcludedWithoutApprovedEvidence = false,
    ): Builder {
        $today = $this->today($referenceDate);

        return $this->withOperationalCounts(
            $this->operationalBaseQuery($filters, $referenceDate, $includeConcludedWithoutApprovedEvidence)
        )
            ->orderByRaw(
                <<<'SQL'
case
    when due_date is not null and DATE(due_date) < ? and priority = 'critical' then 0
    when due_date is not null and DATE(due_date) = ? then 1
    when due_date is not null and DATE(due_date) between ? and ? then 2
    when status = 'em_analise' then 3
    when rejected_evidences_count > 0 then 4
    when status = 'concluida' and approved_evidences_count = 0 then 5
    when evidences_count = 0 then 6
    when responsible_user_id is null then 7
    when due_date is not null and DATE(due_date) < ? then 8
    when due_date is not null and DATE(due_date) between ? and ? then 9
    when due_date is null then 11
    else 10
end
SQL,
                [
                    $today->toDateString(),
                    $today->toDateString(),
                    $today->copy()->addDay()->toDateString(),
                    $today->copy()->addDays(7)->toDateString(),
                    $today->toDateString(),
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

    public function operationalFocusLabelFor(Obligation $obligation, ?CarbonInterface $referenceDate = null): string
    {
        $today = $this->today($referenceDate);
        $approvedCount = $this->evidenceCountFromRecord($obligation, 'approved_evidences_count', fn (): int => $obligation->evidences()->approved()->count());
        $pendingCount = $this->evidenceCountFromRecord($obligation, 'pending_evidences_count', fn (): int => $obligation->evidences()->pending()->count());
        $rejectedCount = $this->evidenceCountFromRecord($obligation, 'rejected_evidences_count', fn (): int => $obligation->evidences()->rejected()->count());

        return match (true) {
            $obligation->due_date !== null && $obligation->due_date->isBefore($today) && $obligation->priority === 'critical' => 'Pendências Críticas',
            $obligation->due_date !== null && $obligation->due_date->isSameDay($today) => 'Vencem Hoje',
            $obligation->due_date !== null && $obligation->due_date->between($today->copy()->addDay(), $today->copy()->addDays(7)) => 'Próximos 7 Dias',
            $obligation->status === 'em_analise' && $pendingCount > 0 => 'Em análise com Evidência Pendente',
            $obligation->status === 'em_analise' => 'Em Análise',
            $rejectedCount > 0 => 'Evidência Rejeitada',
            $obligation->status === 'concluida' && $approvedCount === 0 => 'Concluídas sem Evidência Aprovada',
            $approvedCount === 0 && $pendingCount === 0 && $rejectedCount === 0 => 'Sem Evidência',
            $obligation->responsible_user_id === null => 'Sem Responsável',
            $obligation->due_date !== null && $obligation->due_date->isBefore($today) => 'Vencidas',
            $obligation->due_date !== null && $obligation->due_date->between($today->copy()->addDays(8), $today->copy()->addDays(30)) => 'Próximos 30 Dias',
            $obligation->due_date === null => 'Sem data de vencimento',
            default => 'Acompanhamento operacional',
        };
    }

    public function operationalFocusColorFor(Obligation $obligation, ?CarbonInterface $referenceDate = null): string
    {
        return match ($this->operationalFocusLabelFor($obligation, $referenceDate)) {
            'Pendências Críticas', 'Vencidas', 'Evidência Rejeitada', 'Concluídas sem Evidência Aprovada' => 'danger',
            'Vencem Hoje', 'Próximos 7 Dias', 'Em Análise', 'Em análise com Evidência Pendente', 'Sem Evidência', 'Sem Responsável' => 'warning',
            'Próximos 30 Dias' => 'info',
            default => 'gray',
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

    public function applyOperationalFocusFilter(
        Builder $query,
        ?string $value,
        ?CarbonInterface $referenceDate = null,
        bool $canUseEvidenceFilters = true,
    ): Builder {
        if (blank($value)) {
            return $query;
        }

        if (! $canUseEvidenceFilters && in_array($value, self::EVIDENCE_RELATED_OPERATIONAL_FOCUSES, true)) {
            return $query;
        }

        $today = $this->today($referenceDate);

        return match ($value) {
            'critical_overdue' => $query
                ->where('priority', 'critical')
                ->whereNotIn('status', self::FINALIZED_STATUSES)
                ->whereNotNull('due_date')
                ->whereDate('due_date', '<', $today),
            'due_today' => $query
                ->whereNotIn('status', self::FINALIZED_STATUSES)
                ->whereDate('due_date', $today),
            'next_7_days' => $query
                ->whereNotIn('status', self::FINALIZED_STATUSES)
                ->whereNotNull('due_date')
                ->whereBetween('due_date', [$today->copy()->addDay(), $today->copy()->addDays(7)]),
            'high_priority_next_7_days' => $query
                ->whereNotIn('status', self::FINALIZED_STATUSES)
                ->whereIn('priority', ['high', 'critical'])
                ->whereNotNull('due_date')
                ->whereBetween('due_date', [$today->copy()->addDay(), $today->copy()->addDays(7)]),
            'in_review' => $query->where('status', 'em_analise'),
            'rejected_evidence' => $query
                ->whereHas('evidences', fn (Builder $evidenceQuery): Builder => $evidenceQuery->rejected()),
            'without_evidence' => $query->whereDoesntHave('evidences'),
            'without_responsible' => $query->whereNull('responsible_user_id'),
            'completed_without_approved_evidence' => $query
                ->where('status', 'concluida')
                ->whereDoesntHave('evidences', fn (Builder $evidenceQuery): Builder => $evidenceQuery->approved()),
            'in_review_with_pending_evidence' => $query
                ->where('status', 'em_analise')
                ->whereHas('evidences', fn (Builder $evidenceQuery): Builder => $evidenceQuery->pending()),
            default => $query,
        };
    }

    /**
     * @param  array<string, mixed>|null  $filters
     * @return array<string, mixed>
     */
    public function sanitizeFilters(?array $filters = null, bool $canUseEvidenceFilters = true): array
    {
        $filters = collect($filters ?? [])
            ->only([
                'emission_id',
                'status',
                'responsible_user_id',
                'responsible_area',
                'priority',
                'due_window',
                'evidence_state',
                'source',
                'operational_focus',
            ])
            ->filter(fn (mixed $value): bool => filled($value))
            ->all();

        if (filled($filters['due_window'] ?? null) && ! array_key_exists((string) $filters['due_window'], self::DUE_WINDOW_OPTIONS)) {
            unset($filters['due_window']);
        }

        if (filled($filters['source'] ?? null) && ! array_key_exists((string) $filters['source'], self::SOURCE_FILTER_OPTIONS)) {
            unset($filters['source']);
        }

        if (filled($filters['operational_focus'] ?? null) && ! array_key_exists((string) $filters['operational_focus'], self::OPERATIONAL_FOCUS_OPTIONS)) {
            unset($filters['operational_focus']);
        }

        if (! $canUseEvidenceFilters) {
            unset($filters['evidence_state']);

            if (in_array($filters['operational_focus'] ?? null, self::EVIDENCE_RELATED_OPERATIONAL_FOCUSES, true)) {
                unset($filters['operational_focus']);
            }
        } elseif (filled($filters['evidence_state'] ?? null) && ! array_key_exists((string) $filters['evidence_state'], self::EVIDENCE_FILTER_OPTIONS)) {
            unset($filters['evidence_state']);
        }

        return $filters;
    }

    /**
     * @return array<string, string>
     */
    public function operationalFocusOptions(bool $canUseEvidenceFilters = true): array
    {
        if ($canUseEvidenceFilters) {
            return self::OPERATIONAL_FOCUS_OPTIONS;
        }

        return Arr::except(self::OPERATIONAL_FOCUS_OPTIONS, self::EVIDENCE_RELATED_OPERATIONAL_FOCUSES);
    }

    /**
     * Count of non-finalized obligations due between tomorrow and today+$days.
     *
     * @param  array<string, mixed>|null  $filters
     */
    protected function dueWithinDays(CarbonInterface $today, int $days, ?array $filters = null): int
    {
        return $this->pendingBaseQuery($filters, $today)
            ->whereNotNull('due_date')
            ->whereBetween('due_date', [$today->copy()->addDay(), $today->copy()->addDays($days)])
            ->count();
    }

    /**
     * @param  array<string, mixed>|null  $filters
     */
    protected function filteredQuery(?array $filters = null, ?CarbonInterface $referenceDate = null): Builder
    {
        return $this->applyDashboardFilters(
            Obligation::query(),
            $filters,
            $referenceDate,
        );
    }

    /**
     * @param  array<string, mixed>|null  $filters
     */
    protected function pendingBaseQuery(?array $filters = null, ?CarbonInterface $referenceDate = null): Builder
    {
        return $this->filteredQuery($filters, $referenceDate)
            ->whereNotIn('status', self::FINALIZED_STATUSES);
    }

    /**
     * @param  array<string, mixed>|null  $filters
     */
    protected function operationalBaseQuery(
        ?array $filters = null,
        ?CarbonInterface $referenceDate = null,
        bool $includeConcludedWithoutApprovedEvidence = false,
    ): Builder {
        $query = $this->filteredQuery($filters, $referenceDate);

        if (! $includeConcludedWithoutApprovedEvidence) {
            return $query->whereNotIn('status', self::FINALIZED_STATUSES);
        }

        return $query->where(function (Builder $builder): void {
            $builder
                ->whereNotIn('status', self::FINALIZED_STATUSES)
                ->orWhere(function (Builder $subQuery): void {
                    $subQuery
                        ->where('status', 'concluida')
                        ->whereDoesntHave('evidences', fn (Builder $evidenceQuery): Builder => $evidenceQuery->approved());
                });
        });
    }

    /**
     * @param  array<string, mixed>|null  $filters
     */
    protected function overdueBaseQuery(CarbonInterface $today, ?array $filters = null): Builder
    {
        return $this->pendingBaseQuery($filters, $today)
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', $today);
    }

    /**
     * @param  array<string, mixed>|null  $filters
     * @return array<string, int>
     */
    protected function countsByStatus(?array $filters = null, ?CarbonInterface $referenceDate = null): array
    {
        return $this->filteredQuery($filters, $referenceDate)
            ->select(['status', DB::raw('COUNT(*) as count')])
            ->groupBy('status')
            ->get()
            ->pluck('count', 'status')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }

    /**
     * @param  array<string, mixed>|null  $filters
     * @return array<string, int>
     */
    protected function countsByPriority(?array $filters = null, ?CarbonInterface $referenceDate = null): array
    {
        return $this->pendingBaseQuery($filters, $referenceDate)
            ->select(['priority', DB::raw('COUNT(*) as count')])
            ->groupBy('priority')
            ->get()
            ->pluck('count', 'priority')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }

    /**
     * @param  array<string, mixed>|null  $filters
     */
    protected function obligationsWithEvidenceStatus(string $status, ?array $filters = null, ?CarbonInterface $referenceDate = null): int
    {
        return $this->filteredQuery($filters, $referenceDate)
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

    /**
     * @param  array<string, mixed>|null  $filters
     */
    protected function applyDashboardFilters(Builder $query, ?array $filters = null, ?CarbonInterface $referenceDate = null): Builder
    {
        $filters = $this->sanitizeFilters($filters);

        if (filled($filters['emission_id'] ?? null)) {
            $query->where('emission_id', $filters['emission_id']);
        }

        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        if (filled($filters['responsible_user_id'] ?? null)) {
            $query->where('responsible_user_id', $filters['responsible_user_id']);
        }

        if (filled($filters['responsible_area'] ?? null)) {
            $query->where('responsible_area', $filters['responsible_area']);
        }

        if (filled($filters['priority'] ?? null)) {
            $query->where('priority', $filters['priority']);
        }

        if (filled($filters['due_window'] ?? null)) {
            $this->applyDueWindowFilter($query, (string) $filters['due_window'], $referenceDate);
        }

        if (filled($filters['source'] ?? null)) {
            match ($filters['source']) {
                'term' => $query->whereNotNull('extracted_obligation_id'),
                'manual' => $query->whereNull('extracted_obligation_id'),
                default => null,
            };
        }

        if (filled($filters['evidence_state'] ?? null)) {
            $this->applyEvidenceFilter($query, (string) $filters['evidence_state']);
        }

        if (filled($filters['operational_focus'] ?? null)) {
            $this->applyOperationalFocusFilter($query, (string) $filters['operational_focus'], $referenceDate);
        }

        return $query;
    }
}
