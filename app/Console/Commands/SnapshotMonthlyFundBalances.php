<?php

namespace App\Console\Commands;

use App\Actions\Funds\SnapshotMonthlyFundBalancesAction;
use Illuminate\Console\Command;

class SnapshotMonthlyFundBalances extends Command
{
    protected $signature = 'app:snapshot-monthly-fund-balances';

    protected $description = 'Store the previous monthly balance for funds that still need the current month update.';

    public function __construct(
        public SnapshotMonthlyFundBalancesAction $snapshotMonthlyFundBalancesAction,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $snapshots = $this->snapshotMonthlyFundBalancesAction->handle();

        $this->info("{$snapshots} historico(s) de saldo foram registrados.");

        return self::SUCCESS;
    }
}
