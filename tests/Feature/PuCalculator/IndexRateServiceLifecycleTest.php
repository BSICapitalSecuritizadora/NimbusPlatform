<?php

use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Services\IndexRateLookupService;
use App\Domain\PuCalculator\Services\IndexRateService;
use App\Models\IndexRate;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Fase 3 (P1-01) -- a linha do tempo de índices não sobrevive de um job para o
 * outro no mesmo worker.
 *
 * O `queue:work` vive por horas. Com o serviço de índices registrado como
 * singleton, o primeiro job carregava o CDI em memória e todos os seguintes o
 * reaproveitavam: o CDI que a sincronização gravava depois, em outro processo,
 * não aparecia para a extensão da curva oficial até o worker reiniciar.
 */
uses(RefreshDatabase::class);

/**
 * Job de sondagem: lê pelo serviço a última observação realizada de CDI e, se
 * pedido, simula OUTRO processo gravando o CDI seguinte direto no banco -- sem
 * passar pelo Eloquent deste processo, como a sincronização rodando no scheduler.
 */
final class Phase3IndexTimelineProbeJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $label,
        public readonly ?string $foreignInsertDate = null,
    ) {}

    public function handle(IndexRateService $rates): void
    {
        Cache::put(
            'phase3-index-probe-'.$this->label,
            $rates->latestRealizedRateDate(PuIndexer::Cdi)?->toDateString(),
        );

        if ($this->foreignInsertDate !== null) {
            DB::table('index_rates')->insert([
                'indexer' => PuIndexer::Cdi->value,
                'rate_date' => $this->foreignInsertDate.' 00:00:00',
                'rate_value' => '14.90000000',
                'source' => 'bcb_sgs',
                'source_reference' => 'bcb_sgs:4389',
                'is_projected' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}

function p3cCdi(string $date): IndexRate
{
    return IndexRate::factory()->create([
        'indexer' => PuIndexer::Cdi->value,
        'rate_date' => $date,
        'rate_value' => '14.90000000',
        'source' => 'bcb_sgs',
        'source_reference' => 'bcb_sgs:4389',
    ]);
}

it('lets the next job of the same long-lived worker see CDI persisted by another process', function () {
    p3cCdi('2026-09-30');
    config(['queue.default' => 'database']);

    Phase3IndexTimelineProbeJob::dispatch('first-job', foreignInsertDate: '2026-10-01');
    Phase3IndexTimelineProbeJob::dispatch('second-job');

    // Um único worker em laço processa os dois jobs, como o `queue:work` de produção.
    // O teto de memória alto só impede o worker de parar entre os dois jobs quando a
    // suíte inteira roda no mesmo processo PHP (o padrão é 128 MB).
    Artisan::call('queue:work', [
        'connection' => 'database',
        '--stop-when-empty' => true,
        '--sleep' => 0,
        '--tries' => 1,
        '--memory' => 4096,
    ]);

    expect(Cache::get('phase3-index-probe-first-job'))->toBe('2026-09-30')
        ->and(Cache::get('phase3-index-probe-second-job'))->toBe('2026-10-01')
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('hands each job a fresh index service instead of a process-wide singleton', function () {
    $first = app(IndexRateService::class);

    app()->forgetScopedInstances();

    expect(app(IndexRateService::class))->not->toBe($first)
        ->and(app(IndexRateLookupService::class))->toBe(app(IndexRateService::class));
});

it('refreshes the in-memory timeline when CDI is written through the model in the same process', function () {
    p3cCdi('2026-09-30');
    $rates = app(IndexRateService::class);

    expect($rates->latestRealizedRateDate(PuIndexer::Cdi)?->toDateString())->toBe('2026-09-30');

    p3cCdi('2026-10-01');

    expect($rates->latestRealizedRateDate(PuIndexer::Cdi)?->toDateString())->toBe('2026-10-01')
        ->and($rates->realizedRateForDate(PuIndexer::Cdi, CarbonImmutable::parse('2026-10-01'))?->value)->toBe('14.90000000');
});
