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

it('registers the BCB sync schedules', function () {
    $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
        ->map(fn ($event) => $event->description)
        ->filter()
        ->values()
        ->all();

    expect($events)->toContain('pu-index-sync-cdi')
        ->and($events)->toContain('pu-index-sync-ipca');
});
