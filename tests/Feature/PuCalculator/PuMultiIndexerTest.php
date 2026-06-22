<?php

use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Domain\PuCalculator\Services\PuCurvePrerequisiteService;
use App\Jobs\GeneratePuDailyCurveJob;
use App\Models\EmissionPuCurveVersion;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

it('passes prerequisites for a prefixed emission without any CDI', function () {
    $emission = makePrefixedEmission();

    $check = app(PuCurvePrerequisiteService::class)->handle($emission);

    expect($check->passes())->toBeTrue();
});

it('blocks generation for prefixed emissions missing the annual rate', function () {
    $emission = makePrefixedEmission();
    $emission->puParameter()->update(['annual_rate' => null]);

    $check = app(PuCurvePrerequisiteService::class)->handle($emission->fresh());

    expect($check->passes())->toBeFalse()
        ->and(implode(' ', $check->blockingMessages()))->toContain('taxa prefixada');
});

it('blocks IPCA generation as experimental with a clear message', function () {
    $emission = emissionWithIndexer('IPCA');

    $check = app(PuCurvePrerequisiteService::class)->handle($emission);

    expect($check->passes())->toBeFalse()
        ->and(implode(' ', $check->blockingMessages()))->toContain('IPCA');
});

it('generates a prefixed curve through the queued job and records the method in the snapshot', function () {
    $emission = makePrefixedEmission();

    app()->call([new GeneratePuDailyCurveJob($emission->id, null), 'handle']);

    $version = EmissionPuCurveVersion::query()->where('emission_id', $emission->id)->latest('id')->first();

    expect(Cache::get("pu_curve_generation_{$emission->id}_status"))->toMatchArray(['status' => 'completed'])
        ->and($version->status)->toBe(PuCurveStatus::Generated)
        ->and($version->parameters_snapshot['indexer'])->toBe('PREFIXED')
        ->and($version->parameters_snapshot['calculation_method'])->toBe('fixed_rate')
        ->and($version->parameters_snapshot['method_version'])->toBe('phase3-fixed-v1');
});

it('includes the indexer and calculation method in the CSV export', function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);

    $admin = User::factory()->withTwoFactor()->create(['email' => fake()->unique()->safeEmail()]);
    $admin->assignRole('admin');
    $this->actingAs($admin);

    $emission = makePrefixedEmission();
    app()->call([new GeneratePuDailyCurveJob($emission->id, $admin->id), 'handle']);

    $version = EmissionPuCurveVersion::query()->where('emission_id', $emission->id)->latest('id')->first();

    $response = $this->get(route('admin.emissions.pu-curves.export', [
        'emission' => $emission,
        'calculation_version' => $version->calculation_version,
    ]));

    $response->assertSuccessful();
    expect($response->streamedContent())
        ->toContain('indexador')
        ->toContain('metodo_calculo')
        ->toContain('PREFIXED')
        ->toContain('fixed_rate');
});
