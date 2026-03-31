<?php

namespace App\Filament\NimbusWidgets;

use App\Models\Nimbus\AccessToken;
use App\Models\Nimbus\Submission;
use Filament\Widgets\Widget;

class NimbusRecentActivities extends Widget
{
    protected string $view = 'filament.widgets.nimbus-recent-activities';

    // Span 1 column out of 3
    protected int|string|array $columnSpan = 1;

    protected function getViewData(): array
    {
        $oldPendingCount = Submission::where('status', 'PENDING')
            ->where('submitted_at', '<=', now()->subDays(7))
            ->count();

        $expiredTokensCount = AccessToken::where('status', 'PENDING')
            ->where('expires_at', '<', now())
            ->count();

        $recentActivities = \App\Models\Nimbus\PortalUser::latest('last_login_at')
            ->whereNotNull('last_login_at')
            ->take(5)
            ->get();

        return [
            'oldPendingCount' => $oldPendingCount,
            'expiredTokensCount' => $expiredTokensCount,
            'recentActivities' => $recentActivities,
        ];
    }
}
