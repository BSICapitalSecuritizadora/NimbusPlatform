<?php

use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

function makeHomologatedVersion(): EmissionPuCurveVersion
{
    $emission = Emission::factory()->create([
        'name' => 'Emissao Homologada',
        'type' => 'CRI',
        'status' => 'active',
    ]);

    return EmissionPuCurveVersion::factory()->homologated()->create([
        'emission_id' => $emission->id,
        'calculation_version' => 'v3',
        'parameters_snapshot' => ['indexer' => 'CDI', 'spread_rate' => '6.50000000', 'initial_unit_value' => '1000.0000000000000000'],
        'validation_summary' => ['status' => 'approved', 'mode' => 'display-scale', 'total_rows_compared' => 10, 'total_divergences' => 0],
    ]);
}

it('downloads the homologation PDF and logs the action', function () {
    $user = makeAdminUser();
    $this->actingAs($user);

    $version = makeHomologatedVersion();

    $response = $this->get(route('admin.emissions.pu-homologation.pdf', [
        'emission' => $version->emission_id,
        'version' => $version->id,
    ]));

    $response->assertSuccessful();
    expect($response->headers->get('content-type'))->toContain('application/pdf')
        ->and(Activity::query()
            ->where('description', 'pu_homologation_report_downloaded')
            ->where('subject_id', $version->emission_id)
            ->exists())->toBeTrue();
});

it('blocks the PDF download for users without pu.curve.export', function () {
    $user = User::factory()->withTwoFactor()->create(['email' => fake()->unique()->safeEmail()]);
    $user->assignRole('commercial-representative');
    $this->actingAs($user);

    $version = makeHomologatedVersion();

    $this->get(route('admin.emissions.pu-homologation.pdf', [
        'emission' => $version->emission_id,
        'version' => $version->id,
    ]))->assertForbidden();
});

it('returns 404 when the version does not belong to the emission', function () {
    $this->actingAs(makeAdminUser());

    $version = makeHomologatedVersion();
    $otherEmission = Emission::factory()->create(['type' => 'CRI', 'status' => 'active']);

    $this->get(route('admin.emissions.pu-homologation.pdf', [
        'emission' => $otherEmission->id,
        'version' => $version->id,
    ]))->assertNotFound();
});
