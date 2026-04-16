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

        return [
            'total' => (clone $query)->count(),
            'awaiting_completion' => $counts[ProposalStatus::AwaitingCompletion->value] ?? 0,
            'in_review' => $counts[ProposalStatus::InReview->value] ?? 0,
            'awaiting_information' => $counts[ProposalStatus::AwaitingInformation->value] ?? 0,
            'approved' => $counts[ProposalStatus::Approved->value] ?? 0,
            'rejected' => $counts[ProposalStatus::Rejected->value] ?? 0,
            'completed' => $counts[ProposalStatus::Completed->value] ?? 0,
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
            ProposalStatus::AwaitingCompletion->value => $counts[ProposalStatus::AwaitingCompletion->value] ?? 0,
            ProposalStatus::InReview->value => $counts[ProposalStatus::InReview->value] ?? 0,
            ProposalStatus::AwaitingInformation->value => $counts[ProposalStatus::AwaitingInformation->value] ?? 0,
            ProposalStatus::Approved->value => $counts[ProposalStatus::Approved->value] ?? 0,
            ProposalStatus::Rejected->value => $counts[ProposalStatus::Rejected->value] ?? 0,
            ProposalStatus::Completed->value => $counts[ProposalStatus::Completed->value] ?? 0,
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
                'case when status = ? then 0 when status = ? then 1 else 2 end',
                [ProposalStatus::AwaitingInformation->value, ProposalStatus::AwaitingCompletion->value],
            )
            ->latest('updated_at');
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
