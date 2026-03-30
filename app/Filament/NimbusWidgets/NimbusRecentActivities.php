<?php

namespace App\Filament\NimbusWidgets;

use Filament\Widgets\Widget;
use App\Models\Nimbus\Submission;
use App\Models\Nimbus\AccessToken;

class NimbusRecentActivities extends Widget
{
    protected static string $view = 'filament.widgets.nimbus-recent-activities';
    
    // Span 1 column out of 3
    protected int | string | array $columnSpan = 1;

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
