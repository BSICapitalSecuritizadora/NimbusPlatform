<?php

use App\Domain\PuCalculator\Enums\IndexProjectionSeriesStatus;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Services\IndexRateImportService;
use App\Models\IndexRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function writeIndexCsv(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'idx_').'.csv';
    file_put_contents($path, $contents);

    return $path;
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/idx_*.csv') ?: [] as $file) {
        @unlink($file);
    }
});

it('imports published CDI rates from a CSV keeping the exact date', function () {
    $user = User::factory()->create();
    $path = writeIndexCsv("rate_date,rate_value\n2026-02-02,14.90\n2026-02-03,14.91\n");

    $result = app(IndexRateImportService::class)->importPublished(PuIndexer::Cdi, $path, 'B3', $user->id);

    expect($result['imported'])->toBe(2)
        ->and(IndexRate::query()->where('indexer', 'CDI')->where('is_projected', false)->count())->toBe(2)
        ->and(IndexRate::query()->whereDate('rate_date', '2026-02-02')->value('rate_value'))->toEqual('14.90000000');

    // Reimportar é idempotente (upsert), não duplica nem viola unique.
    app(IndexRateImportService::class)->importPublished(PuIndexer::Cdi, $path, 'B3', $user->id);
    expect(IndexRate::query()->where('indexer', 'CDI')->count())->toBe(2);
});

it('imports published IPCA rates normalizing the date to the first of the month', function () {
    $path = writeIndexCsv("rate_date,rate_value\n2023-09,6500.00\n2023-10,6550.00\n");

    $result = app(IndexRateImportService::class)->importPublished(PuIndexer::Ipca, $path, 'IBGE', null);

    expect($result['imported'])->toBe(2)
        ->and(IndexRate::query()->where('indexer', 'IPCA')->whereDate('rate_date', '2023-09-01')->exists())->toBeTrue()
        ->and(IndexRate::query()->where('indexer', 'IPCA')->where('is_projected', true)->count())->toBe(0);
});

it('imports a projected IPCA series as is_projected linked to an imported (not approved) series', function () {
    $user = User::factory()->create();
    $path = writeIndexCsv("rate_date,rate_value\n2024-01,6600.00\n2024-02,6650.00\n");

    $result = app(IndexRateImportService::class)->importProjectedSeries(
        PuIndexer::Ipca,
        $path,
        ['name' => 'IPCA mercado', 'projection_source' => 'ANBIMA', 'projection_policy' => 'market', 'version' => 'v1'],
        $user->id,
    );

    expect($result['imported'])->toBe(2)
        ->and($result['series']->status)->toBe(IndexProjectionSeriesStatus::Imported)
        ->and($result['series']->imported_by)->toBe($user->id)
        ->and(IndexRate::query()->where('indexer', 'IPCA')->where('is_projected', true)->count())->toBe(2)
        ->and(IndexRate::query()->whereDate('rate_date', '2024-01-01')->value('index_projection_series_id'))->toBe($result['series']->id);
});
