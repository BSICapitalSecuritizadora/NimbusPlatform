<?php

namespace App\Console\Commands;

use App\Actions\FundAlerts\SendFundMinimumBalanceAlertsAction;
use Illuminate\Console\Command;

class SendFundMinimumBalanceAlerts extends Command
{
    protected $signature = 'app:send-fund-minimum-balance-alerts';

    protected $description = 'Send alert emails for funds with balances below the configured minimum.';

    public function __construct(
        public SendFundMinimumBalanceAlertsAction $sendFundMinimumBalanceAlertsAction,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $alertsSent = $this->sendFundMinimumBalanceAlertsAction->handle();

        $this->info("{$alertsSent} alerta(s) de saldo minimo enviados.");

        return self::SUCCESS;
    }
}
