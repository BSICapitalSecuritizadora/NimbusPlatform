<?php

use App\Actions\Emissions\HomologatePuCurve;
use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Domain\PuCalculator\Services\PuCurveVersionService;
use App\Jobs\GeneratePuDailyCurveJob;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuDailyCurve;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function seedReadyEmissionForVersioning(): Emission
{
    $emission = Emission::factory()->create([
        'type' => 'CRI',
        'status' => 'active',
    ]);

    seedBusinessCalendarForPuJob('2026-02-02', '2026-02-05');
    seedFixedCdiRatesForPuJob('2026-02-02', '2026-02-05', '14.90000000');

    $emission->integralizationHistories()->create([
        'date' => '2026-02-02',
        'quantity' => '100.0000',
        'unit_value' => '1000.00000000',
        'financial_value' => '100000.00',
        'investor_fund' => 'Head Invest',
    ]);

    $emission->puParameter()->create([
        'curve_start_date' => '2026-02-02',
        'curve_end_date' => '2026-02-05',
        'initial_unit_value' => '1000.0000000000000000',
        'spread_rate' => '6.50000000',
        'indexer' => 'CDI',
        'business_day_basis' => 252,
        'calendar_code' => 'B3',
        'legacy_projection_enabled' => false,
    ]);

    return $emission;
}

it('increments versions and marks the previous one obsolete on reprocess', function () {
    $user = User::factory()->create();
    $emission = seedReadyEmissionForVersioning();

    app()->call([new GeneratePuDailyCurveJob($emission->id, $user->id), 'handle']);
    app()->call([new GeneratePuDailyCurveJob($emission->id, $user->id), 'handle']);

    $versions = EmissionPuCurveVersion::query()
        ->where('emission_id', $emission->id)
        ->orderBy('id')
        ->get();

    expect($versions)->toHaveCount(2)
        ->and($versions[0]->calculation_version)->toBe('v1')
        ->and($versions[0]->status)->toBe(PuCurveStatus::Obsolete)
        ->and($versions[1]->calculation_version)->toBe('v2')
        ->and($versions[1]->status)->toBe(PuCurveStatus::Generated)
        ->and($emission->fresh()->currentPuCurveVersion()->calculation_version)->toBe('v2');
});

it('preserves a homologated version when reprocessing with confirmation', function () {
    $user = User::factory()->create();
    $emission = seedReadyEmissionForVersioning();

    app()->call([new GeneratePuDailyCurveJob($emission->id, $user->id), 'handle']);
    app(HomologatePuCurve::class)->handle($emission, 'v1', $user->id);

    app()->call([new GeneratePuDailyCurveJob($emission->id, $user->id, true), 'handle']);

    $v1 = EmissionPuCurveVersion::query()->where('emission_id', $emission->id)->where('calculation_version', 'v1')->first();
    $v2 = EmissionPuCurveVersion::query()->where('emission_id', $emission->id)->where('calculation_version', 'v2')->first();

    expect($v1->status)->toBe(PuCurveStatus::Homologated)
        ->and($v2->status)->toBe(PuCurveStatus::Generated);
});

it('records the obsolete reason when superseding a previous version', function () {
    $user = User::factory()->create();
    $emission = seedReadyEmissionForVersioning();

    app()->call([new GeneratePuDailyCurveJob($emission->id, $user->id), 'handle']);
    app()->call([new GeneratePuDailyCurveJob($emission->id, $user->id), 'handle']);

    $v1 = EmissionPuCurveVersion::query()->where('emission_id', $emission->id)->where('calculation_version', 'v1')->first();

    expect($v1->status)->toBe(PuCurveStatus::Obsolete)
        ->and($v1->obsolete_reason)->toBe('superseded');
});

it('derives the next version even when existing versions are not in the canonical vN pattern', function () {
    $emission = Emission::factory()->create(['type' => 'CRI', 'status' => 'active']);

    EmissionPuDailyCurve::factory()->create([
        'emission_id' => $emission->id,
        'calculation_version' => 'rev_5',
    ]);

    expect(app(PuCurveVersionService::class)->nextCalculationVersion($emission))->toBe('v6');
});

it('blocks reprocessing a homologated curve without explicit confirmation', function () {
    $user = User::factory()->create();
    $emission = seedReadyEmissionForVersioning();

    app()->call([new GeneratePuDailyCurveJob($emission->id, $user->id), 'handle']);
    app(HomologatePuCurve::class)->handle($emission, 'v1', $user->id);

    app()->call([new GeneratePuDailyCurveJob($emission->id, $user->id, false), 'handle']);

    expect(EmissionPuCurveVersion::query()->where('emission_id', $emission->id)->count())->toBe(1)
        ->and(\Illuminate\Support\Facades\Cache::get("pu_curve_generation_{$emission->id}_status"))
        ->toMatchArray(['status' => 'failed']);
});
