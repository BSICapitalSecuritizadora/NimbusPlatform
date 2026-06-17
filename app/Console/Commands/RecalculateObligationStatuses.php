<?php

namespace App\Console\Commands;

use App\Actions\Emissions\RecalculateObligationStatusesAction;
use Illuminate\Console\Command;

class RecalculateObligationStatuses extends Command
{
    protected $signature = 'obligations:recalculate-statuses';

    protected $description = 'Recalcula o status das obrigações (a vencer / vencida) com base na data de vencimento.';

    public function __construct(
        public RecalculateObligationStatusesAction $recalculateObligationStatusesAction,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $result = $this->recalculateObligationStatusesAction->handle();

        $this->info("Obrigações analisadas: {$result['analyzed']}");
        $this->info("Atualizadas para 'A vencer': {$result['marked_a_vencer']}");
        $this->info("Atualizadas para 'Vencida': {$result['marked_vencida']}");
        $this->info("Sem alteração necessária: {$result['unchanged']}");

        return self::SUCCESS;
    }
}
