<?php

namespace App\Console\Commands;

use App\Actions\Emissions\GenerateObligationOccurrencesAction;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class GenerateObligationOccurrences extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'obligations:generate-occurrences
                            {--date= : Data de referência no formato YYYY-MM-DD}
                            {--series= : Limita a geração a uma série específica}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Materializa de forma idempotente as ocorrências futuras das séries de obrigações ativas.';

    /**
     * Execute the console command.
     */
    public function __construct(
        private readonly GenerateObligationOccurrencesAction $generateOccurrences,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $referenceDate = filled($this->option('date'))
            ? Carbon::createFromFormat('Y-m-d', (string) $this->option('date'))
            : null;
        $seriesId = filled($this->option('series')) ? (int) $this->option('series') : null;
        $result = $this->generateOccurrences->handle($referenceDate, $seriesId);

        $this->info("Séries analisadas: {$result['series_analyzed']}");
        $this->info("Ocorrências criadas: {$result['created']}");
        $this->info("Ocorrências já existentes: {$result['existing']}");
        $this->info("Regras ignoradas: {$result['skipped']}");

        return self::SUCCESS;
    }
}
