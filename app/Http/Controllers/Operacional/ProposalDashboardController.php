<?php

namespace App\Http\Controllers\Operacional;

use App\Enums\ProposalStatus;
use App\Http\Controllers\Controller;
use App\Models\Proposal;
use App\Models\User;
use App\Support\Proposals\ProposalDashboardData;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProposalDashboardController extends Controller
{
    public function index(Request $request, ProposalDashboardData $dashboardData): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        abort_unless(
            (bool) $user?->hasAnyRole(['super-admin', 'admin', 'commercial-representative'])
                || (bool) $user?->can('proposals.view'),
            403,
        );

        return Inertia::render('Operacional/Propostas/Index', [
            'summary' => $dashboardData->summary($user),
            'statusDistribution' => $this->statusDistribution($dashboardData, $user),
            'recent' => $this->recent($dashboardData, $user),
        ]);
    }

    /**
     * @return array<int, array{status: string, label: string, color: string, count: int}>
     */
    private function statusDistribution(ProposalDashboardData $dashboardData, ?User $user): array
    {
        return collect($dashboardData->statusDistribution($user))
            ->map(fn (int $count, string $value): array => [
                'status' => $value,
                'label' => ProposalStatus::from($value)->label(),
                'color' => ProposalStatus::from($value)->color(),
                'count' => $count,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{id: int, company: string, status: string, status_label: string, status_color: string, created_at: string}>
     */
    private function recent(ProposalDashboardData $dashboardData, ?User $user): array
    {
        return $dashboardData->recentQuery($user)
            ->with('company:id,name')
            ->limit(5)
            ->get()
            ->map(fn (Proposal $proposal): array => [
                'id' => $proposal->id,
                'company' => $proposal->company?->name ?? '—',
                'status' => $proposal->status,
                'status_label' => ProposalStatus::labelFor($proposal->status),
                'status_color' => ProposalStatus::fromValue($proposal->status)?->color() ?? 'gray',
                'created_at' => $proposal->created_at?->toIso8601String() ?? '',
            ])
            ->all();
    }
}
