<?php

use App\Actions\Emissions\HomologatePuCurve;
use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Domain\PuCalculator\Exceptions\PuMakerCheckerException;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

function makeGeneratedVersion(int $generatedBy): array
{
    $emission = Emission::factory()->create(['type' => 'CRI', 'status' => 'active']);
    EmissionPuCurveVersion::factory()->create([
        'emission_id' => $emission->id,
        'calculation_version' => 'v1',
        'status' => PuCurveStatus::Generated->value,
        'generated_by' => $generatedBy,
    ]);

    return [$emission, $generatedBy];
}

it('blocks homologation when the checker generated the curve', function () {
    $maker = User::factory()->create();
    [$emission] = makeGeneratedVersion($maker->id);

    expect(fn () => app(HomologatePuCurve::class)->handle($emission, 'v1', $maker->id))
        ->toThrow(PuMakerCheckerException::class);

    expect(EmissionPuCurveVersion::query()->where('emission_id', $emission->id)->first()->status)
        ->toBe(PuCurveStatus::Generated);
});

it('allows a different checker to homologate the curve', function () {
    $maker = User::factory()->create();
    $checker = User::factory()->create();
    [$emission] = makeGeneratedVersion($maker->id);

    app(HomologatePuCurve::class)->handle($emission, 'v1', $checker->id);

    expect(EmissionPuCurveVersion::query()->where('emission_id', $emission->id)->first()->status)
        ->toBe(PuCurveStatus::Homologated);
});

it('allows a super-admin to homologate a curve they generated', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super-admin');
    [$emission] = makeGeneratedVersion($superAdmin->id);

    app(HomologatePuCurve::class)->handle($emission, 'v1', $superAdmin->id);

    expect(EmissionPuCurveVersion::query()->where('emission_id', $emission->id)->first()->status)
        ->toBe(PuCurveStatus::Homologated);
});
