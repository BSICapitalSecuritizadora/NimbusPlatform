<?php

use App\Actions\Emissions\GeneratePuDailyCurve;
use App\Domain\PuCalculator\Enums\PuCurveReviewStatus;
use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexRateLookupMode;
use App\Domain\PuCalculator\Services\IndexRateLookupService;
use App\Domain\PuCalculator\Services\IndexRateService;
use App\Domain\PuCalculator\Services\PuCurveExtensionService;
use App\Domain\PuCalculator\Services\PuCurveGeneratorService;
use App\Domain\PuCalculator\Services\PuOperationalMonitorService;
use App\Domain\PuCalculator\Services\PuPersistedCurveChecksumService;
use App\Jobs\ExtendPuDailyCurveJob;
use App\Jobs\GeneratePuDailyCurveJob;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuDailyCurve;
use App\Models\IndexRate;
use App\Models\PuHistory;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * CDI com defasagem exata de 1 dia útil no calendário B3: a curva só vai até o
 * último dia cujo CDI já foi publicado, como no Alto Bellevue.
 */
function extensionEmission(bool $legacyProjection = false): Emission
{
    $emission = Emission::factory()->active()->create(['type' => 'CRI']);
    $emission->integralizationHistories()->create([
        'date' => '2026-03-02',
        'quantity' => '100.0000',
        'unit_value' => '1000.00000000',
        'financial_value' => '100000.00',
        'investor_fund' => 'Head Invest',
    ]);
    $emission->puParameter()->create([
        'curve_start_date' => '2026-03-02',
        'curve_end_date' => '2026-12-31',
        'initial_unit_value' => '1000.0000000000000000',
        'spread_rate' => '6.00000000',
        'indexer' => PuIndexer::Cdi->value,
        'business_day_basis' => 252,
        'calendar_code' => 'B3',
        'index_rate_lookup_mode' => PuIndexRateLookupMode::BusinessDayLagExact->value,
        'index_rate_lag_business_days' => -1,
        'legacy_projection_enabled' => $legacyProjection,
    ]);

    return $emission->fresh();
}

function publishCdi(string $from, string $to, string $rateValue = '14.90000000'): void
{
    for ($date = CarbonImmutable::parse($from); $date->lte(CarbonImmutable::parse($to)); $date = $date->addDay()) {
        if ($date->isWeekend()) {
            continue;
        }

        IndexRate::factory()->create([
            'indexer' => PuIndexer::Cdi->value,
            'rate_date' => $date->toDateString(),
            'rate_value' => $rateValue,
            'source' => 'testing',
            'source_reference' => 'extension',
        ]);
    }

    flushIndexRateCaches();
}

function flushIndexRateCaches(): void
{
    app(IndexRateService::class)->flushCache();
    app(IndexRateLookupService::class)->flushCache();
}

function generatedExtensionEmission(bool $legacyProjection = false): Emission
{
    $emission = extensionEmission($legacyProjection);
    publishCdi('2026-02-27', '2026-03-13');
    app(GeneratePuDailyCurve::class)->handle($emission);

    return $emission->fresh();
}

function extendCurve(Emission $emission)
{
    return app(PuCurveExtensionService::class)->extend($emission->fresh());
}

function lastCurveDate(EmissionPuCurveVersion $version): string
{
    return CarbonImmutable::parse((string) $version->dailyCurves()->max('curve_date'))->toDateString();
}

it('appends only the newly published days to the same version', function () {
    $emission = generatedExtensionEmission();
    $version = $emission->currentPuCurveVersion();
    $originalRows = $version->dailyCurves()->count();
    $originalLastDate = lastCurveDate($version);

    publishCdi('2026-03-16', '2026-03-20');
    $result = extendCurve($emission);
    $version->refresh();

    expect($result->action)->toBe(PuCurveExtensionService::ACTION_EXTENDED)
        ->and($result->versionId)->toBe($version->id)
        ->and($result->fromDate)->toBeGreaterThan($originalLastDate)
        ->and($emission->puCurveVersions()->count())->toBe(1)
        ->and($version->dailyCurves()->count())->toBe($originalRows + $result->appendedRows)
        ->and($version->extended_rows_count)->toBe($result->appendedRows)
        ->and($version->last_extended_at)->not->toBeNull()
        ->and($version->dailyCurves()->whereNotNull('extended_at')->count())->toBe($result->appendedRows)
        ->and($version->dailyCurves()->whereNull('extended_at')->count())->toBe($originalRows);
});

it('produces exactly the curve a full generation would produce', function () {
    $emission = generatedExtensionEmission();
    publishCdi('2026-03-16', '2026-03-20');
    extendCurve($emission);
    $checksums = app(PuPersistedCurveChecksumService::class);

    $fullGeneration = app(PuCurveGeneratorService::class)->handle($emission->fresh())->rows;

    expect($checksums->checksum($emission->currentPuCurveVersion()))
        ->toBe($checksums->checksumForRows($fullGeneration));
});

it('writes nothing when no new CDI was published', function () {
    $emission = generatedExtensionEmission();
    $rows = EmissionPuDailyCurve::query()->count();

    $result = extendCurve($emission);

    expect($result->action)->toBe(PuCurveExtensionService::ACTION_UP_TO_DATE)
        ->and(EmissionPuDailyCurve::query()->count())->toBe($rows);
});

it('refuses to append over a past that changed and asks for a full generation', function () {
    $emission = generatedExtensionEmission();
    publishCdi('2026-03-16', '2026-03-20');
    $rows = EmissionPuDailyCurve::query()->count();
    IndexRate::query()->whereDate('rate_date', '2026-03-05')->update(['rate_value' => '15.40000000']);
    flushIndexRateCaches();

    $result = extendCurve($emission);

    expect($result->action)->toBe(PuCurveExtensionService::ACTION_DIVERGED)
        ->and($result->firstDivergentDate)->toBe('2026-03-06')
        ->and($result->requiresFullGeneration())->toBeTrue()
        ->and(EmissionPuDailyCurve::query()->count())->toBe($rows)
        ->and($emission->currentPuCurveVersion()->extension_diverged_at)->toBeNull();
});

it('suspends a governed curve whose past changed instead of replacing it', function (array $governance) {
    $emission = generatedExtensionEmission();
    $version = $emission->currentPuCurveVersion();
    $version->forceFill($governance)->save();
    publishCdi('2026-03-16', '2026-03-20');
    $rows = EmissionPuDailyCurve::query()->count();
    IndexRate::query()->whereDate('rate_date', '2026-03-05')->update(['rate_value' => '15.40000000']);
    flushIndexRateCaches();

    $result = extendCurve($emission);
    $version->refresh();

    expect($result->action)->toBe(PuCurveExtensionService::ACTION_DIVERGED_GOVERNED)
        ->and($result->requiresFullGeneration())->toBeFalse()
        ->and(EmissionPuDailyCurve::query()->count())->toBe($rows)
        ->and($version->status)->not->toBe(PuCurveStatus::Obsolete)
        ->and($version->extension_diverged_at)->not->toBeNull()
        ->and($version->extension_divergence['first_divergent_date'])->toBe('2026-03-06')
        ->and(app(PuOperationalMonitorService::class)->criticalSummary())
        ->toContain('1 curva(s) homologada(s) ou promovida(s) com o passado divergente: a extensao diaria esta suspensa ate o reprocessamento manual.');

    IndexRate::query()->whereDate('rate_date', '2026-03-05')->update(['rate_value' => '14.90000000']);
    flushIndexRateCaches();

    $resumed = extendCurve($emission);

    expect($resumed->action)->toBe(PuCurveExtensionService::ACTION_EXTENDED)
        ->and($version->fresh()->extension_diverged_at)->toBeNull();
})->with([
    'homologada' => [['status' => PuCurveStatus::Homologated->value, 'homologated_at' => now()]],
    'promovida pela revisão' => [['status' => PuCurveStatus::Validated->value, 'review_status' => PuCurveReviewStatus::Approved->value]],
]);

it('does not extend while a generation prerequisite is blocking', function () {
    $emission = generatedExtensionEmission();
    publishCdi('2026-03-16', '2026-03-20');
    $rows = EmissionPuDailyCurve::query()->count();
    $emission->integralizationHistories()->delete();

    $result = extendCurve($emission);

    expect($result->action)->toBe(PuCurveExtensionService::ACTION_PREREQUISITES_BLOCKED)
        ->and($result->reason)->toContain('integralizacao')
        ->and(EmissionPuDailyCurve::query()->count())->toBe($rows);
});

it('projects only the appended days into the legacy PU history', function () {
    $emission = generatedExtensionEmission(legacyProjection: true);
    $historyBefore = PuHistory::query()->whereBelongsTo($emission)->count();
    publishCdi('2026-03-16', '2026-03-20');

    $result = extendCurve($emission);
    $lastRow = $emission->currentPuCurveVersion()->dailyCurves()->orderByDesc('curve_date')->first();

    expect(PuHistory::query()->whereBelongsTo($emission)->count())->toBe($historyBefore + $result->appendedRows)
        ->and(bccomp((string) $emission->fresh()->current_pu, (string) $lastRow->residual_unit_value, 2))->toBe(0);
});

it('queues a full generation only when the extension cannot proceed on its own', function () {
    Queue::fake();
    $withoutCurve = extensionEmission();
    $governed = generatedExtensionEmission();
    $governed->currentPuCurveVersion()->forceFill(['status' => PuCurveStatus::Homologated->value])->save();
    IndexRate::query()->whereDate('rate_date', '2026-03-05')->update(['rate_value' => '15.40000000']);
    flushIndexRateCaches();

    // Com a fila falsa, dispatchSync só registraria o job: executa o handle.
    app()->call([new ExtendPuDailyCurveJob($withoutCurve->id), 'handle']);
    app()->call([new ExtendPuDailyCurveJob($governed->id), 'handle']);

    Queue::assertPushed(GeneratePuDailyCurveJob::class, 1);
    Queue::assertPushed(GeneratePuDailyCurveJob::class, fn (GeneratePuDailyCurveJob $job): bool => $job->emissionId === $withoutCurve->id);
});
