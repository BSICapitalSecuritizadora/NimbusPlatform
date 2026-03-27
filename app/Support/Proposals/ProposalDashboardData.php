<?php

namespace App\Support\Proposals;

use App\Models\Proposal;
use App\Models\ProposalRepresentative;
use App\Models\User;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ProposalDashboardData
{
    public function summary(?User $user = null): array
    {
        $query = $this->baseQuery($user);

        return [
            'total' => (clone $query)->count(),
            'awaiting_completion' => $this->countByStatus(Proposal::STATUS_AWAITING_COMPLETION, $user),
            'in_review' => $this->countByStatus(Proposal::STATUS_IN_REVIEW, $user),
            'awaiting_information' => $this->countByStatus(Proposal::STATUS_AWAITING_INFORMATION, $user),
            'approved' => $this->countByStatus(Proposal::STATUS_APPROVED, $user),
            'rejected' => $this->countByStatus(Proposal::STATUS_REJECTED, $user),
            'completed' => $this->countByStatus(Proposal::STATUS_COMPLETED, $user),
            'received_last_30_days' => (clone $query)
                ->where('created_at', '>=', now()->subDays(30))
                ->count(),
            'attention' => $this->attentionQuery($user)->count(),
        ];
    }

    public function statusDistribution(?User $user = null): array
    {
        return [
            Proposal::STATUS_AWAITING_COMPLETION => $this->countByStatus(Proposal::STATUS_AWAITING_COMPLETION, $user),
            Proposal::STATUS_IN_REVIEW => $this->countByStatus(Proposal::STATUS_IN_REVIEW, $user),
            Proposal::STATUS_AWAITING_INFORMATION => $this->countByStatus(Proposal::STATUS_AWAITING_INFORMATION, $user),
            Proposal::STATUS_APPROVED => $this->countByStatus(Proposal::STATUS_APPROVED, $user),
            Proposal::STATUS_REJECTED => $this->countByStatus(Proposal::STATUS_REJECTED, $user),
            Proposal::STATUS_COMPLETED => $this->countByStatus(Proposal::STATUS_COMPLETED, $user),
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
            ->with(['company', 'representative', 'latestStatusHistory'])
            ->latest('created_at');
    }

    public function attentionQuery(?User $user = null): Builder
    {
        $staleThreshold = $this->staleThreshold();

        return $this->baseQuery($user)
            ->with(['company', 'representative', 'latestStatusHistory'])
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

    protected function countByStatus(string $status, ?User $user = null): int
    {
        return $this->baseQuery($user)
            ->where('status', $status)
            ->count();
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
