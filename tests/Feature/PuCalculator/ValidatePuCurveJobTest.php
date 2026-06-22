<?php

use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Domain\PuCalculator\Services\PuSpreadsheetReferenceReader;
use App\Domain\PuCalculator\Services\PuValidationSpreadsheetLocatorService;
use App\Jobs\ValidatePuCurveJob;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

it('validates the curve in the queued job and marks the version validated', function () {
    $user = User::factory()->create();
    $emission = Emission::factory()->create([
        'type' => 'CRI',
        'status' => 'active',
        'issued_quantity' => 20000,
    ]);

    $spreadsheetPath = app(PuValidationSpreadsheetLocatorService::class)->findByKeyword('AMANI');
    $referenceRows = app(PuSpreadsheetReferenceReader::class)->read($spreadsheetPath)['rows'];
    persistOperationalReferenceRows($emission, $referenceRows, 'v1');

    $version = EmissionPuCurveVersion::factory()->create([
        'emission_id' => $emission->id,
        'calculation_version' => 'v1',
        'status' => PuCurveStatus::Generated->value,
    ]);

    $job = new ValidatePuCurveJob($emission->id, $spreadsheetPath, 'v1', 'display-scale', null, null, $user->id);
    app()->call([$job, 'handle']);

    $cache = Cache::get("pu_curve_validation_{$emission->id}_status");

    expect($version->fresh()->status)->toBe(PuCurveStatus::Validated)
        ->and($version->fresh()->validation_summary)->toBeArray()
        ->and($cache)->toMatchArray([
            'status' => 'completed',
            'validation_status' => 'approved',
        ]);
});
