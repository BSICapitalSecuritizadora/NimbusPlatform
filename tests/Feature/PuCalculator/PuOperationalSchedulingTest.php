<?php

use App\Domain\PuCalculator\Enums\PuEventType;
use App\Domain\PuCalculator\Enums\PuObligationRefreshStatus;
use App\Domain\PuCalculator\Services\PuObligationRefreshRecovery;
use App\Domain\PuCalculator\Services\PuOperationalHealthService;
use App\Domain\PuCalculator\Services\PuOperationalMonitor;
use App\Models\PuMonitorRun;
use App\Models\PuObligationRefreshRequest;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Pu\PuObligationFixture as Fx;

/**
 * Fase 6 -- agenda, parâmetros operacionais e custo do monitoramento.
 */
uses(RefreshDatabase::class);

function p6gEvent(string $name): Event
{
    return collect(app(Schedule::class)->events())->first(fn (Event $event): bool => $event->description === $name)
        ?? throw new RuntimeException("Agendamento {$name} ausente.");
}

it('schedules the monitor, the refresh scanner and a CDI pass after the expected publication, in the business timezone', function () {
    $sync = p6gEvent('pu-index-sync-cdi-post-publication');
    $extension = p6gEvent('pu-curves-generate-realized-post-publication');

    expect(p6gEvent('pu-operations-monitor')->expression)->toBe('*/15 * * * *')
        ->and(p6gEvent('pu-operations-monitor')->withoutOverlapping)->toBeTrue()
        ->and(p6gEvent('pu-obligations-recover')->expression)->toBe('*/5 * * * *')
        ->and($sync->timezone)->toBe('America/Sao_Paulo')
        ->and($sync->expression)->toBe('30 7 * * *')
        ->and((string) $sync->command)->toContain('pu:index-rates:sync --indexer=cdi')
        ->and((string) $sync->command)->not->toContain('--queue')
        ->and($extension->timezone)->toBe('America/Sao_Paulo')
        ->and($extension->expression)->toBe('15 8 * * *')
        // Depois da hora de divulgação configurada.
        ->and((string) config('pu_indexes.bcb.series.cdi.available_after') < '07:30')->toBeTrue()
        // A passada da madrugada continua como estava.
        ->and(p6gEvent('pu-index-sync-cdi')->expression)->toBe('30 6 * * *');
});

it('reads operational thresholds fail-closed: invalid, empty or zero values fall back to the defaults', function (string $value) {
    $_ENV['PU_OBLIGATION_REFRESH_MAX_ATTEMPTS'] = $value;
    $_ENV['PU_MONITOR_STALE_GRACE_MINUTES'] = $value;
    $_ENV['PU_OBLIGATION_REFRESH_BACKOFF_SECONDS'] = $value;
    $_ENV['PU_CDI_POST_PUBLICATION_SYNC_AT'] = $value;
    putenv("PU_OBLIGATION_REFRESH_MAX_ATTEMPTS={$value}");
    putenv("PU_MONITOR_STALE_GRACE_MINUTES={$value}");
    putenv("PU_OBLIGATION_REFRESH_BACKOFF_SECONDS={$value}");
    putenv("PU_CDI_POST_PUBLICATION_SYNC_AT={$value}");

    try {
        $calculator = require config_path('pu_calculator.php');
        $indexes = require config_path('pu_indexes.php');
    } finally {
        foreach (['PU_OBLIGATION_REFRESH_MAX_ATTEMPTS', 'PU_MONITOR_STALE_GRACE_MINUTES', 'PU_OBLIGATION_REFRESH_BACKOFF_SECONDS', 'PU_CDI_POST_PUBLICATION_SYNC_AT'] as $key) {
            unset($_ENV[$key]);
            putenv($key);
        }
    }

    expect($calculator['obligation_refresh']['max_attempts'])->toBe(5)
        ->and($calculator['monitoring']['stale_grace_minutes'])->toBe(360)
        ->and($calculator['obligation_refresh']['backoff_seconds'])->toBe([60, 300, 900, 3600])
        ->and($indexes['bcb']['series']['cdi']['post_publication_sync_at'])->toBe('07:30');
})->with(['off', 'abc', '0', '-3', '25:99']);

it('prunes only diagnostic history: completed refresh requests and old monitor runs, never open work', function () {
    $emission = Fx::emission();
    $recovery = app(PuObligationRefreshRecovery::class);
    $old = $recovery->request($emission->id, 'informed_schedule_changed');
    $old->forceFill(['status' => PuObligationRefreshStatus::Succeeded, 'completed_at' => now()->subDays(120)])->save();
    $exhausted = $recovery->request($emission->id, 'index_rate_corrected');
    $exhausted->forceFill(['status' => PuObligationRefreshStatus::Exhausted, 'completed_at' => null, 'updated_at' => now()->subDays(400)])->save();
    PuMonitorRun::query()->create(['status' => 'succeeded', 'trigger' => 'test', 'started_at' => now()->subDays(45), 'finished_at' => now()->subDays(45)]);

    $this->artisan('pu:obligations:recover')->assertSuccessful();
    app(PuOperationalMonitor::class)->run('test');

    expect(PuObligationRefreshRequest::query()->whereKey($old->id)->exists())->toBeFalse()
        ->and(PuObligationRefreshRequest::query()->whereKey($exhausted->id)->exists())->toBeTrue()
        ->and(PuMonitorRun::query()->where('started_at', '<', now()->subDays(30))->exists())->toBeFalse()
        ->and(PuMonitorRun::query()->count())->toBe(1);
});

it('keeps the snapshot cost per monitored emission bounded: obligations, settlements and requests are read in aggregate', function () {
    $count = function (int $emissions): int {
        DB::flushQueryLog();

        foreach (range(1, $emissions) as $ignored) {
            $emission = Fx::emission([[PuEventType::InterestPayment, '2026-03-09'], [PuEventType::InterestPayment, '2026-03-20']]);
            Fx::official($emission);
        }

        DB::enableQueryLog();
        app(PuOperationalHealthService::class)->snapshot();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $two = $count(2);
    $six = $count(4);
    $perEmission = ($six - $two) / 4;

    // A atualidade da oficial é por emissão (retrato aprovado + caminho do índice);
    // o resto é agregado. O teto pega um N+1 de obrigações, liquidações ou pedidos.
    expect($perEmission)->toBeLessThan(80);
});
