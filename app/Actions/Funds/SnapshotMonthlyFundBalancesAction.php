<?php

namespace App\Actions\Funds;

use App\Models\Fund;
use Carbon\CarbonInterface;

class SnapshotMonthlyFundBalancesAction
{
    public function handle(?CarbonInterface $referenceDate = null): int
    {
        $snapshots = 0;

        Fund::query()
            ->whereNotNull('balance')
            ->chunkById(100, function ($funds) use (&$snapshots, $referenceDate): void {
                foreach ($funds as $fund) {
                    if ($fund->snapshotPreviousMonthBalanceIfMissing($referenceDate)) {
                        $snapshots++;
                    }
                }
            });

        return $snapshots;
    }
}
