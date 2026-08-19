<?php

namespace App\Support\Proposals;

use App\Enums\ProposalStatus;
use App\Models\Proposal;
use App\Models\ProposalRepresentative;
use App\Models\User;
use App\Services\ProposalVisibilityFilter;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProposalDashboardData
{
    public function summary(?User $user = null): array
    {
        $query = $this->baseQuery($user);
        $counts = $this->countsByStatus($user);

        $total = (clone $query)->count();
        $approved = $counts[ProposalStatus::Approved->value] ?? 0;
        $completed = $counts[ProposalStatus::Completed->value] ?? 0;
        $rejected = $counts[ProposalStatus::Rejected->value] ?? 0;
        $resolved = $approved + $completed + $rejected;
        $conversionRate = $resolved > 0 ? round((($approved + $completed) / $resolved) * 100, 1) : 0.0;

        $inReview = $counts[ProposalStatus::InReview->value] ?? 0;
        $awaitingCompletion = $counts[ProposalStatus::AwaitingCompletion->value] ?? 0;
        $awaitingInformation = $counts[ProposalStatus::AwaitingInformation->value] ?? 0;
        $activePipeline = $inReview + $awaitingCompletion + $awaitingInformation;

        $unassigned = (clone $query)
            ->whereNull('assigned_representative_id')
            ->whereNotIn('status', [ProposalStatus::Rejected->value, ProposalStatus::Completed->value])
            ->count();

        $staleReview = (clone $query)
            ->where('status', ProposalStatus::InReview->value)
            ->where('updated_at', '<=', $this->staleThreshold())
            ->count();

        return [
            'total' => $total,
            'awaiting_completion' => $awaitingCompletion,
            'in_review' => $inReview,
            'awaiting_information' => $awaitingInformation,
            'approved' => $approved,
            'rejected' => $rejected,
            'completed' => $completed,
            'received_last_30_days' => (clone $query)
                ->where('created_at', '>=', now()->subDays(30))
                ->count(),
            'attention' => $this->attentionQuery($user)->count(),
            'conversion_rate' => $conversionRate,
            'active_pipeline' => $activePipeline,
            'unassigned' => $unassigned,
            'stale_review' => $staleReview,
        ];
    }

    public function statusDistribution(?User $user = null): array
    {
        $counts = $this->countsByStatus($user);

        return [
            ProposalStatus::AwaitingCompletion->value => $counts[ProposalStatus::AwaitingCompletion->value] ?? 0,
            ProposalStatus::InReview->value => $counts[ProposalStatus::InReview->value] ?? 0,
            ProposalStatus::AwaitingInformation->value => $counts[ProposalStatus::AwaitingInformation->value] ?? 0,
            ProposalStatus::Approved->value => $counts[ProposalStatus::Approved->value] ?? 0,
            ProposalStatus::Rejected->value => $counts[ProposalStatus::Rejected->value] ?? 0,
            ProposalStatus::Completed->value => $counts[ProposalStatus::Completed->value] ?? 0,
        ];
    }

    /**
     * @return array{
     *     total: int,
     *     items: array<int, array{status: string, label: string, count: int, percentage: float, color_hex: string, color_name: string}>,
     *     active_items: array<int, array{status: string, label: string, count: int, percentage: float, color_hex: string, color_name: string}>,
     *     inactive_items_count: int,
     *     dominant_item: ?array{status: string, label: string, count: int, percentage: float}
     * }
     */
    public function statusDistributionDetails(?User $user = null): array
    {
        $raw = $this->statusDistribution($user);
        $total = (int) array_sum($raw);

        $colorMap = [
            ProposalStatus::AwaitingCompletion->value => ['hex' => '#d97706', 'name' => 'amber'],
            ProposalStatus::InReview->value => ['hex' => '#12313b', 'name' => 'navy'],
            ProposalStatus::AwaitingInformation->value => ['hex' => '#ea580c', 'name' => 'orange'],
            ProposalStatus::Approved->value => ['hex' => '#059669', 'name' => 'emerald'],
            ProposalStatus::Rejected->value => ['hex' => '#dc2626', 'name' => 'red'],
            ProposalStatus::Completed->value => ['hex' => '#b7832f', 'name' => 'gold'],
        ];

        $items = [];
        $activeItems = [];
        $dominant = null;
        $maxCount = 0;

        foreach ($raw as $status => $count) {
            $count = (int) $count;
            $percentage = $total > 0 ? round(($count / $total) * 100, 1) : 0.0;
            $label = ProposalStatus::labelFor($status);
            $meta = [
                'status' => $status,
                'label' => $label,
                'count' => $count,
                'percentage' => $percentage,
                'color_hex' => $colorMap[$status]['hex'] ?? '#64748b',
                'color_name' => $colorMap[$status]['name'] ?? 'gray',
            ];

            $items[] = $meta;

            if ($count > 0) {
                $activeItems[] = $meta;
                if ($count > $maxCount) {
                    $maxCount = $count;
                    $dominant = [
                        'status' => $status,
                        'label' => $label,
                        'count' => $count,
                        'percentage' => $percentage,
                    ];
                }
            }
        }

        usort($activeItems, fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return [
            'total' => $total,
            'items' => $items,
            'active_items' => $activeItems,
            'inactive_items_count' => count($items) - count($activeItems),
            'dominant_item' => $dominant,
        ];
    }

    public function monthlyVolume(int $months = 6, ?User $user = null): array
    {
        $start = now()->startOfMonth()->subMonths($months - 1);
        $end = now()->endOfMonth();

        $received = (clone $this->baseQuery($user))
            ->select(['created_at'])
            ->whereBetween('created_at', [$start, $end])
            ->get()
            ->countBy(fn (Proposal $proposal): string => $proposal->created_at->startOfMonth()->format('Y-m-01'));

        $completed = (clone $this->baseQuery($user))
            ->select(['completed_at'])
            ->whereNotNull('completed_at')
            ->whereBetween('completed_at', [$start, $end])
            ->get()
            ->countBy(fn (Proposal $proposal): string => $proposal->completed_at->startOfMonth()->format('Y-m-01'));

        $labels = [];
        $receivedSeries = [];
        $completedSeries = [];

        foreach (CarbonPeriod::create($start, '1 month', $end) as $date) {
            $key = $date->format('Y-m-01');
            $labels[] = ucfirst($date->locale('pt_BR')->translatedFormat('M/y'));
            $receivedSeries[] = (int) ($received[$key] ?? 0);
            $completedSeries[] = (int) ($completed[$key] ?? 0);
        }

        return [
            'labels' => $labels,
            'received' => $receivedSeries,
            'completed' => $completedSeries,
        ];
    }

    /**
     * @return array{
     *     total_received: int,
     *     total_completed: int,
     *     conversion_rate: float,
     *     peak_month: string,
     *     peak_count: int,
     *     latest_active_month: ?string,
     *     current_month_label: string,
     *     current_month_received: int,
     *     current_month_completed: int,
     *     has_activity: bool
     * }
     */
    public function monthlyVolumeMetrics(int $months = 6, ?User $user = null): array
    {
        $series = $this->monthlyVolume($months, $user);

        $totalReceived = (int) array_sum($series['received']);
        $totalCompleted = (int) array_sum($series['completed']);
        $conversionRate = $totalReceived > 0 ? round(($totalCompleted / $totalReceived) * 100, 1) : 0.0;

        $maxReceived = 0;
        $peakMonth = null;
        foreach ($series['labels'] as $idx => $label) {
            $val = (int) ($series['received'][$idx] ?? 0);
            if ($val >= $maxReceived && $val > 0) {
                $maxReceived = $val;
                $peakMonth = $label;
            }
        }

        $latestActiveMonth = null;
        for ($i = count($series['labels']) - 1; $i >= 0; $i--) {
            $rec = (int) ($series['received'][$i] ?? 0);
            $comp = (int) ($series['completed'][$i] ?? 0);
            if ($rec > 0 || $comp > 0) {
                $latestActiveMonth = $series['labels'][$i];
                break;
            }
        }

        $currentMonthIndex = max(0, count($series['labels']) - 1);
        $currentMonthLabel = $series['labels'][$currentMonthIndex] ?? '';
        $currentMonthReceived = (int) ($series['received'][$currentMonthIndex] ?? 0);
        $currentMonthCompleted = (int) ($series['completed'][$currentMonthIndex] ?? 0);

        return [
            'total_received' => $totalReceived,
            'total_completed' => $totalCompleted,
            'conversion_rate' => $conversionRate,
            'peak_month' => $peakMonth ?? $currentMonthLabel,
            'peak_count' => $maxReceived,
            'latest_active_month' => $latestActiveMonth,
            'current_month_label' => $currentMonthLabel,
            'current_month_received' => $currentMonthReceived,
            'current_month_completed' => $currentMonthCompleted,
            'has_activity' => ($totalReceived > 0 || $totalCompleted > 0),
        ];
    }

    public function representativeLoad(int $limit = 8): Collection
    {
        return ProposalRepresentative::query()
            ->where('is_active', true)
            ->withCount([
                'proposals as active_proposals_count' => fn (Builder $query): Builder => $query->whereNotIn('status', [
                    ProposalStatus::Rejected->value,
                    ProposalStatus::Completed->value,
                ]),
            ])
            ->orderByDesc('active_proposals_count')
            ->orderBy('queue_position')
            ->orderBy('name')
            ->limit($limit)
            ->get();
    }

    /**
     * @return array{
     *     total_representatives: int,
     *     total_active_proposals: int,
     *     average_load: float,
     *     max_load: int,
     *     min_load: int,
     *     balance_status: array{
     *         status: string,
     *         label: string,
     *         badge_color: string,
     *         icon: string
     *     },
     *     has_activity: bool,
     *     items: array<int, array{
     *         id: int,
     *         name: string,
     *         email: string,
     *         queue_position: ?int,
     *         count: int,
     *         count_label: string,
     *         percentage: float,
     *         is_highest: bool,
     *         is_available: bool
     *     }>
     * }
     */
    public function representativeLoadDetails(int $limit = 8): array
    {
        $representatives = $this->representativeLoad($limit);
        $totalRepresentatives = $representatives->count();
        $totalActiveProposals = (int) $representatives->sum('active_proposals_count');
        $averageLoad = $totalRepresentatives > 0
            ? round($totalActiveProposals / $totalRepresentatives, 1)
            : 0.0;

        $maxLoad = (int) ($representatives->max('active_proposals_count') ?? 0);
        $minLoad = (int) ($representatives->min('active_proposals_count') ?? 0);

        $topRepresentative = $representatives->firstWhere('active_proposals_count', $maxLoad);
        $topName = $topRepresentative ? explode(' ', (string) $topRepresentative->name)[0] : '';

        if ($totalRepresentatives <= 1 || $totalActiveProposals === 0) {
            $balanceStatus = [
                'status' => 'neutral',
                'label' => $totalActiveProposals === 0 ? 'Fila sem processos em andamento' : 'Distribuição direta',
                'badge_color' => 'gray',
                'icon' => 'heroicon-m-minus-circle',
            ];
        } elseif ($maxLoad === $minLoad) {
            $balanceStatus = [
                'status' => 'balanced',
                'label' => 'Distribuição perfeitamente equilibrada',
                'badge_color' => 'success',
                'icon' => 'heroicon-m-check-badge',
            ];
        } elseif (($maxLoad - $minLoad) <= 1) {
            $balanceStatus = [
                'status' => 'uniform',
                'label' => 'Carga operacional uniforme',
                'badge_color' => 'info',
                'icon' => 'heroicon-m-scale',
            ];
        } elseif ($maxLoad >= 3 && $maxLoad >= ($averageLoad * 1.8)) {
            $balanceStatus = [
                'status' => 'concentrated',
                'label' => "Maior concentração em {$topName}",
                'badge_color' => 'warning',
                'icon' => 'heroicon-m-exclamation-circle',
            ];
        } else {
            $balanceStatus = [
                'status' => 'distributed',
                'label' => 'Distribuição regular da equipe',
                'badge_color' => 'gray',
                'icon' => 'heroicon-m-scale',
            ];
        }

        $items = [];
        foreach ($representatives as $rep) {
            $count = (int) $rep->active_proposals_count;
            $percentage = $totalActiveProposals > 0
                ? round(($count / $totalActiveProposals) * 100, 1)
                : 0.0;

            $countLabel = match ($count) {
                0 => '0 processos (Disponível)',
                1 => '1 processo em andamento',
                default => "{$count} processos em andamento",
            };

            $items[] = [
                'id' => $rep->id,
                'name' => $rep->name,
                'email' => $rep->email,
                'queue_position' => $rep->queue_position,
                'count' => $count,
                'count_label' => $countLabel,
                'percentage' => $percentage,
                'is_highest' => ($count === $maxLoad && $count > 0 && $maxLoad > $minLoad),
                'is_available' => ($count === 0),
            ];
        }

        return [
            'total_representatives' => $totalRepresentatives,
            'total_active_proposals' => $totalActiveProposals,
            'average_load' => $averageLoad,
            'max_load' => $maxLoad,
            'min_load' => $minLoad,
            'balance_status' => $balanceStatus,
            'has_activity' => ($totalActiveProposals > 0),
            'items' => $items,
        ];
    }

    public function recentQuery(?User $user = null): Builder
    {
        return $this->baseQuery($user)
            ->latest('created_at');
    }

    public function attentionQuery(?User $user = null): Builder
    {
        $staleThreshold = $this->staleThreshold();
        $criticalReview = $this->criticalReviewThreshold();
        $criticalPending = $this->criticalPendingThreshold();

        return $this->baseQuery($user)
            ->where(function (Builder $query) use ($staleThreshold): void {
                $query
                    ->whereIn('status', [
                        ProposalStatus::AwaitingCompletion->value,
                        ProposalStatus::AwaitingInformation->value,
                    ])
                    ->orWhere(function (Builder $reviewQuery) use ($staleThreshold): void {
                        $reviewQuery
                            ->where('status', ProposalStatus::InReview->value)
                            ->where('updated_at', '<=', $staleThreshold);
                    });
            })
            ->orderByRaw(
                'case when status = ? and updated_at <= ? then 0 when status in (?, ?) and updated_at <= ? then 1 when status = ? then 2 when status = ? then 3 else 4 end',
                [
                    ProposalStatus::InReview->value,
                    $criticalReview,
                    ProposalStatus::AwaitingInformation->value,
                    ProposalStatus::AwaitingCompletion->value,
                    $criticalPending,
                    ProposalStatus::AwaitingInformation->value,
                    ProposalStatus::AwaitingCompletion->value,
                ],
            )
            ->orderBy('updated_at', 'asc');
    }

    public function attentionSeverity(Proposal $proposal): string
    {
        if (! $proposal->updated_at) {
            return 'attention';
        }

        if ($proposal->status === ProposalStatus::InReview->value && $proposal->updated_at <= $this->criticalReviewThreshold()) {
            return 'critical';
        }

        if (in_array($proposal->status, [ProposalStatus::AwaitingCompletion->value, ProposalStatus::AwaitingInformation->value], true)
            && $proposal->updated_at <= $this->criticalPendingThreshold()) {
            return 'critical';
        }

        return 'attention';
    }

    public function attentionSeverityLabel(Proposal $proposal): string
    {
        return $this->attentionSeverity($proposal) === 'critical' ? 'SLA Crítico' : 'Atenção';
    }

    public function attentionSeverityColor(Proposal $proposal): string
    {
        return $this->attentionSeverity($proposal) === 'critical' ? 'danger' : 'warning';
    }

    public function attentionSeverityIcon(Proposal $proposal): string
    {
        return $this->attentionSeverity($proposal) === 'critical'
            ? 'heroicon-m-exclamation-circle'
            : 'heroicon-m-exclamation-triangle';
    }

    public function attentionDiagnosis(Proposal $proposal): string
    {
        $days = (int) ($proposal->updated_at ? $proposal->updated_at->diffInDays(now()) : 0);
        $timeText = match (true) {
            $days === 0 => 'hoje',
            $days === 1 => 'há 1 dia',
            default => "há {$days} dias",
        };

        return match ($proposal->status) {
            ProposalStatus::InReview->value => "Parado em análise {$timeText}",
            ProposalStatus::AwaitingCompletion->value => "Aguardando documentação {$timeText}",
            ProposalStatus::AwaitingInformation->value => "Aguardando cliente {$timeText}",
            default => "Sem movimentação {$timeText}",
        };
    }

    public function attentionReason(Proposal $proposal): string
    {
        return match ($proposal->status) {
            ProposalStatus::AwaitingInformation->value => 'Aguardando retorno do cliente.',
            ProposalStatus::AwaitingCompletion->value => 'Cliente ainda não concluiu a complementação.',
            ProposalStatus::InReview->value => 'Sem movimentação recente do comercial.',
            default => 'Acompanhamento recomendado.',
        };
    }

    public function staleThreshold(): CarbonInterface
    {
        return now()->subDays(3);
    }

    public function criticalReviewThreshold(): CarbonInterface
    {
        return now()->subDays(5);
    }

    public function criticalPendingThreshold(): CarbonInterface
    {
        return now()->subDays(7);
    }

    /**
     * @return array<string, int>
     */
    protected function countsByStatus(?User $user = null): array
    {
        return $this->baseQuery($user)
            ->select(['status', DB::raw('COUNT(*) as count')])
            ->groupBy('status')
            ->get()
            ->pluck('count', 'status')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }

    protected function baseQuery(?User $user = null): Builder
    {
        return ProposalVisibilityFilter::apply(
            Proposal::query(),
            $user ?? $this->resolveCurrentUser(),
        );
    }

    protected function resolveCurrentUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
