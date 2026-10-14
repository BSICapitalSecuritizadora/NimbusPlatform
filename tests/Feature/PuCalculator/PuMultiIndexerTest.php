<?php

use App\Domain\PuCalculator\Enums\PuCalculationMethod;
use App\Domain\PuCalculator\Enums\PuEventType;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Services\PuCurveGeneratorService;
use App\Domain\PuCalculator\Services\PuCurvePrerequisiteService;
use App\Jobs\GeneratePuDailyCurveJob;
use App\Models\EmissionPuCurveVersion;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Pu\PuObligationFixture;

uses(RefreshDatabase::class);

it('requires no CDI for a prefixed emission and blocks it only for lacking operational certification', function () {
    $emission = makePrefixedEmission();

    $check = app(PuCurvePrerequisiteService::class)->handle($emission);

    // Fase 7: os pré-requisitos de dado do prefixado passam sem CDI; o único
    // bloqueio é o indexador sem certificação operacional.
    expect($check->passes())->toBeFalse()
        ->and(array_map(fn ($issue): string => $issue->key, $check->blockingIssues()))->toBe(['indexer_not_operationally_homologated']);
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

it('refuses a prefixed curve through the queued job, keeping the fixed-rate engine available in memory', function () {
    $emission = makePrefixedEmission();

    app()->call([new GeneratePuDailyCurveJob($emission->id, null), 'handle']);

    // Fase 7: sem certificação operacional, o job não grava versão nenhuma.
    expect(Cache::get("pu_curve_generation_{$emission->id}_status"))->toMatchArray(['status' => 'failed'])
        ->and(Cache::get("pu_curve_generation_{$emission->id}_status")['error'])->toContain('INDEXER_NOT_OPERATIONALLY_HOMOLOGATED')
        ->and(EmissionPuCurveVersion::query()->where('emission_id', $emission->id)->exists())->toBeFalse()
        ->and(PuCalculationMethod::forIndexer(PuIndexer::Prefixed)->value)->toBe('fixed_rate')
        ->and(PuCalculationMethod::FixedRate->engineVersion())->toBe('phase3-fixed-v1')
        ->and(app(PuCurveGeneratorService::class)->handle($emission->fresh())->rows)->toHaveCount(3);
});

it('includes the indexer and calculation method in the CSV export', function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);

    $admin = User::factory()->withTwoFactor()->create(['email' => fake()->unique()->safeEmail()]);
    $admin->assignRole('admin');
    $this->actingAs($admin);

    // Fase 7: o prefixado não grava curva; a exportação é conferida numa curva CDI.
    $emission = PuObligationFixture::emission([[PuEventType::InterestPayment, '2026-03-09']]);
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
        ->toContain('CDI')
        ->toContain(PuCalculationMethod::forIndexer(PuIndexer::Cdi)->value);
});
