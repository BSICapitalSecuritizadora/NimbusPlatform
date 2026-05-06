<?php

namespace App\Actions\FundAlerts;

use App\Models\Fund;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class SendFundMinimumBalanceAlertsAction
{
    public function __construct(
        public SendFundMinimumBalanceAlertAction $sendFundMinimumBalanceAlertAction,
    ) {}

    public function handle(?CarbonInterface $checkedAt = null): int
    {
        $checkedAt ??= now();

        $alertsSent = 0;

        Fund::query()
            ->with(['emission.investors'])
            ->where(function (Builder $query): void {
                $query
                    ->whereNotNull('minimum_balance')
                    ->orWhereNotNull('minimum_balance_alert_sent_at');
            })
            ->chunkById(100, function (Collection $funds) use (&$alertsSent, $checkedAt): void {
                foreach ($funds as $fund) {
                    if ($this->sendFundMinimumBalanceAlertAction->handle($fund, $checkedAt)) {
                        $alertsSent++;
                    }
                }
            });

        return $alertsSent;
    }
}
