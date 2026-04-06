<?php

namespace App\Concerns;

use App\Models\Investor;

trait HasInvestorSession
{
    public ?string $previousPortalSeenAt = null;

    public function mountHasInvestorSession(): void
    {
        $investor = $this->resolveInvestor();

        $this->previousPortalSeenAt = $investor->last_portal_seen_at?->toDateTimeString();

        $investor->forceFill([
            'last_portal_seen_at' => now(),
        ])->save();
    }

    protected function resolveInvestor(): Investor
    {
        $investor = auth('investor')->user();

        abort_unless($investor instanceof Investor, 403);

        return $investor;
    }

    protected function portalSeenReference(): string
    {
        return $this->previousPortalSeenAt ?? '1970-01-01 00:00:00';
    }
}
