<?php

use App\Domain\PuCalculator\Jobs\SyncIndexRatesFromBcbJob;
use App\Models\IndexRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('syncs CDI inline through the artisan command', function () {
    Http::fake(['api.bcb.gov.br/*' => Http::response([
        ['data' => '02/01/2024', 'valor' => '11.65'],
    ], 200)]);

    $this->artisan('pu:index-rates:sync', ['--indexer' => 'cdi', '--from' => '2024-01-01', '--to' => '2024-01-31'])
        ->assertSuccessful();

    expect(IndexRate::query()->where('source', 'bcb_sgs')->count())->toBe(1);
});

it('does not persist on --dry-run', function () {
    Http::fake(['api.bcb.gov.br/*' => Http::response([
        ['data' => '02/01/2024', 'valor' => '11.65'],
    ], 200)]);

    $this->artisan('pu:index-rates:sync', ['--indexer' => 'cdi', '--dry-run' => true])
        ->assertSuccessful();

    expect(IndexRate::query()->count())->toBe(0);
});

it('dispatches a job with --queue', function () {
    Queue::fake();

    $this->artisan('pu:index-rates:sync', ['--indexer' => 'cdi', '--queue' => true])
        ->assertSuccessful();

    Queue::assertPushed(SyncIndexRatesFromBcbJob::class);
});

it('rejects unsupported sources', function () {
    $this->artisan('pu:index-rates:sync', ['--indexer' => 'cdi', '--source' => 'anbima'])
        ->assertFailed();
});

it('runs the async job idempotently and stores a status', function () {
    Http::fake(['api.bcb.gov.br/*' => Http::response([
        ['data' => '02/01/2024', 'valor' => '11.65'],
    ], 200)]);

    app()->call([new SyncIndexRatesFromBcbJob('cdi', '2024-01-01', '2024-01-31'), 'handle']);
    app()->call([new SyncIndexRatesFromBcbJob('cdi', '2024-01-01', '2024-01-31'), 'handle']);

    expect(IndexRate::query()->where('indexer', 'CDI')->count())->toBe(1)
        ->and(\Illuminate\Support\Facades\Cache::get('pu_index_sync_cdi_status'))->toMatchArray(['status' => 'completed']);
});

it('registers the BCB sync schedules with the correct recurrence', function () {
    $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events());

    $cdi = $events->first(fn ($event) => $event->description === 'pu-index-sync-cdi');
    $ipca = $events->first(fn ($event) => $event->description === 'pu-index-sync-ipca');

    expect($cdi)->not->toBeNull()
        ->and($cdi->expression)->toBe('30 6 * * *')         // todos os dias 06:30
        ->and($ipca)->not->toBeNull()
        ->and($ipca->expression)->toBe('45 6 2 * *');       // todo dia 2 às 06:45
});

it('queries CDI in smaller blocks and surfaces a partial-block failure warning', function () {
    config(['pu_indexes.bcb.chunk_months' => 12, 'pu_indexes.bcb.retries' => 1, 'pu_indexes.bcb.retry_sleep_ms' => 0]);

    Http::fakeSequence('api.bcb.gov.br/*')
        ->push('error', 500)
        ->push([['data' => '02/01/2023', 'valor' => '13.65']])
        ->push([['data' => '02/01/2024', 'valor' => '11.65']]);

    $this->artisan('pu:index-rates:sync', ['--indexer' => 'cdi', '--from' => '2022-01-01', '--to' => '2024-01-31'])
        ->expectsOutputToContain('bloco com falha')
        ->assertSuccessful();

    expect(IndexRate::query()->where('source', 'bcb_sgs')->count())->toBe(2);
});

it('caps the query window to the configured maximum (10 years)', function () {
    Http::fake(['api.bcb.gov.br/*' => Http::response([
        ['data' => '02/01/2024', 'valor' => '11.65'],
    ], 200)]);

    $this->artisan('pu:index-rates:sync', ['--indexer' => 'cdi', '--from' => '2000-01-01', '--to' => '2024-01-01'])
        ->expectsOutputToContain('Janela limitada')
        ->assertSuccessful();
});
