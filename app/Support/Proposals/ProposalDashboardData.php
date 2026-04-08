<?php

namespace App\Support\Proposals;

use App\Models\Proposal;
use App\Models\ProposalRepresentative;
use App\Models\User;
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

        return [
            'total' => (clone $query)->count(),
            'awaiting_completion' => $counts[Proposal::STATUS_AWAITING_COMPLETION] ?? 0,
            'in_review' => $counts[Proposal::STATUS_IN_REVIEW] ?? 0,
            'awaiting_information' => $counts[Proposal::STATUS_AWAITING_INFORMATION] ?? 0,
            'approved' => $counts[Proposal::STATUS_APPROVED] ?? 0,
            'rejected' => $counts[Proposal::STATUS_REJECTED] ?? 0,
            'completed' => $counts[Proposal::STATUS_COMPLETED] ?? 0,
            'received_last_30_days' => (clone $query)
                ->where('created_at', '>=', now()->subDays(30))
                ->count(),
            'attention' => $this->attentionQuery($user)->count(),
        ];
    }

    public function statusDistribution(?User $user = null): array
    {
        $counts = $this->countsByStatus($user);

        return [
            Proposal::STATUS_AWAITING_COMPLETION => $counts[Proposal::STATUS_AWAITING_COMPLETION] ?? 0,
            Proposal::STATUS_IN_REVIEW => $counts[Proposal::STATUS_IN_REVIEW] ?? 0,
            Proposal::STATUS_AWAITING_INFORMATION => $counts[Proposal::STATUS_AWAITING_INFORMATION] ?? 0,
            Proposal::STATUS_APPROVED => $counts[Proposal::STATUS_APPROVED] ?? 0,
            Proposal::STATUS_REJECTED => $counts[Proposal::STATUS_REJECTED] ?? 0,
            Proposal::STATUS_COMPLETED => $counts[Proposal::STATUS_COMPLETED] ?? 0,
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

    public function representativeLoad(int $limit = 8): Collection
    {
        return ProposalRepresentative::query()
            ->where('is_active', true)
            ->withCount([
                'proposals as active_proposals_count' => fn (Builder $query): Builder => $query->whereNotIn('status', [
                    Proposal::STATUS_REJECTED,
                    Proposal::STATUS_COMPLETED,
                ]),
            ])
            ->orderByDesc('active_proposals_count')
            ->orderBy('queue_position')
            ->orderBy('name')
            ->limit($limit)
            ->get();
    }

    public function recentQuery(?User $user = null): Builder
    {
        return $this->baseQuery($user)
            ->latest('created_at');
    }

    public function attentionQuery(?User $user = null): Builder
    {
        $staleThreshold = $this->staleThreshold();

        return $this->baseQuery($user)
            ->where(function (Builder $query) use ($staleThreshold): void {
                $query
                    ->whereIn('status', [
                        Proposal::STATUS_AWAITING_COMPLETION,
                        Proposal::STATUS_AWAITING_INFORMATION,
                    ])
                    ->orWhere(function (Builder $reviewQuery) use ($staleThreshold): void {
                        $reviewQuery
                            ->where('status', Proposal::STATUS_IN_REVIEW)
                            ->where('updated_at', '<=', $staleThreshold);
                    });
            })
            ->orderByRaw(
                'case when status = ? then 0 when status = ? then 1 else 2 end',
                [Proposal::STATUS_AWAITING_INFORMATION, Proposal::STATUS_AWAITING_COMPLETION],
            )
            ->latest('updated_at');
    }

    public function attentionReason(Proposal $proposal): string
    {
        return match ($proposal->status) {
            Proposal::STATUS_AWAITING_INFORMATION => 'Aguardando retorno do cliente.',
            Proposal::STATUS_AWAITING_COMPLETION => 'Cliente ainda não concluiu a complementação.',
            Proposal::STATUS_IN_REVIEW => 'Sem movimentação recente do comercial.',
            default => 'Acompanhamento recomendado.',
        };
    }

    public function staleThreshold(): CarbonInterface
    {
        return now()->subDays(3);
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
        return Proposal::query()->visibleTo($user ?? $this->resolveCurrentUser());
    }

    protected function resolveCurrentUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
