<?php

namespace App\Http\Controllers\Nimbus;

use App\Http\Controllers\Controller;
use App\Models\Nimbus\Submission;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class NimbusDashboardController extends Controller
{
    public function index(): View
    {
        $user = Auth::guard('nimbus')->user();

        $counts = Submission::query()
            ->where('nimbus_portal_user_id', $user->id)
            ->select(['status', DB::raw('COUNT(*) as count')])
            ->groupBy('status')
            ->get()
            ->pluck('count', 'status')
            ->map(fn (mixed $count): int => (int) $count);

        $stats = [
            'total' => $counts->sum(),
            'pending' => $counts->only([
                Submission::STATUS_PENDING,
                Submission::STATUS_UNDER_REVIEW,
                Submission::STATUS_NEEDS_CORRECTION,
            ])->sum(),
            'approved' => $counts->only([
                Submission::STATUS_COMPLETED,
                Submission::STATUS_REJECTED,
            ])->sum(),
        ];

        $submissions = Submission::query()
            ->where('nimbus_portal_user_id', $user->id)
            ->orderByDesc('submitted_at')
            ->limit(5)
            ->get();

        return view('nimbus.dashboard', compact('stats', 'submissions'));
    }
}
