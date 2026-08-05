<?php

namespace App\Filament\NimbusWidgets;

use App\Filament\Resources\Nimbus\AccessTokens\AccessTokenResource;
use App\Models\Nimbus\AccessToken;
use App\Models\Nimbus\PortalUser;
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

        $recentActivities = PortalUser::latest('last_login_at')
            ->whereNotNull('last_login_at')
            ->take(5)
            ->get();

        return [
            'oldPendingCount' => $oldPendingCount,
            'expiredTokensCount' => $expiredTokensCount,
            'expiredTokensUrl' => AccessTokenResource::canViewAny()
                ? AccessTokenResource::getUrl('index', [
                    'filters' => [
                        'expiradas' => ['isActive' => true],
                    ],
                ], panel: 'admin')
                : null,
            'recentActivities' => $recentActivities,
        ];
    }
}
