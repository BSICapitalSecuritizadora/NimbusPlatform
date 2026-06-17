<?php

namespace App\Console\Commands;

use App\Actions\Emissions\SendObligationDueNotificationsAction;
use Illuminate\Console\Command;

class SendObligationDueNotifications extends Command
{
    protected $signature = 'obligations:send-due-notifications';

    protected $description = 'Envia notificações por e-mail para obrigações próximas do vencimento, vencendo hoje ou vencidas.';

    public function __construct(
        public SendObligationDueNotificationsAction $sendObligationDueNotificationsAction,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $result = $this->sendObligationDueNotificationsAction->handle();

        $this->info("Obrigações analisadas: {$result['analyzed']}");
        $this->info("Elegíveis para notificação: {$result['eligible']}");
        $this->info("Notificações enviadas: {$result['sent']}");
        $this->info("Ignoradas: {$result['ignored']}");
        $this->info("Falhas de envio: {$result['failed']}");

        return self::SUCCESS;
    }
}
