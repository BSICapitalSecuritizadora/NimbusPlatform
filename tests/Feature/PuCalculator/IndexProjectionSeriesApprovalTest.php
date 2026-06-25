<?php

use App\Domain\PuCalculator\Enums\IndexProjectionSeriesStatus;
use App\Domain\PuCalculator\Exceptions\PuMakerCheckerException;
use App\Domain\PuCalculator\Services\IndexProjectionSeriesService;
use App\Models\IndexProjectionSeries;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('lets a different checker approve an imported series', function () {
    $importer = User::factory()->create();
    $checker = User::factory()->create();
    $series = IndexProjectionSeries::factory()->create([
        'status' => IndexProjectionSeriesStatus::Imported->value,
        'imported_by' => $importer->id,
    ]);

    app(IndexProjectionSeriesService::class)->approve($series, $checker->id);

    expect($series->fresh()->status)->toBe(IndexProjectionSeriesStatus::Approved)
        ->and($series->fresh()->approved_by)->toBe($checker->id);
});

it('blocks approval when the checker is the importer', function () {
    $importer = User::factory()->create();
    $series = IndexProjectionSeries::factory()->create([
        'status' => IndexProjectionSeriesStatus::Imported->value,
        'imported_by' => $importer->id,
    ]);

    expect(fn () => app(IndexProjectionSeriesService::class)->approve($series, $importer->id))
        ->toThrow(PuMakerCheckerException::class);

    expect($series->fresh()->status)->toBe(IndexProjectionSeriesStatus::Imported);
});

it('allows a super-admin to approve a series they imported', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super-admin');
    $series = IndexProjectionSeries::factory()->create([
        'status' => IndexProjectionSeriesStatus::Imported->value,
        'imported_by' => $superAdmin->id,
    ]);

    app(IndexProjectionSeriesService::class)->approve($series, $superAdmin->id);

    expect($series->fresh()->status)->toBe(IndexProjectionSeriesStatus::Approved);
});

it('rejects a series with a reason and cannot approve it afterwards', function () {
    $importer = User::factory()->create();
    $checker = User::factory()->create();
    $series = IndexProjectionSeries::factory()->create([
        'status' => IndexProjectionSeriesStatus::Imported->value,
        'imported_by' => $importer->id,
    ]);

    app(IndexProjectionSeriesService::class)->reject($series, $checker->id, 'Curva desatualizada');

    expect($series->fresh()->status)->toBe(IndexProjectionSeriesStatus::Rejected)
        ->and($series->fresh()->rejection_reason)->toBe('Curva desatualizada');

    expect(fn () => app(IndexProjectionSeriesService::class)->approve($series->fresh(), $checker->id))
        ->toThrow(InvalidArgumentException::class);
});
