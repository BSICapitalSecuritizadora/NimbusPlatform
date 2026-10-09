<?php

use App\Enums\MeasurementPlanRevisionCategory;
use App\Models\MeasurementPlanVersion;
use App\Services\MeasurementPlanVersionService;
use App\Services\MeasurementRevisionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\MeasurementRevisionScenario as Scenario;

uses(RefreshDatabase::class);
pest()->group('parity');

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    Notification::fake();
});

it('keeps every revision on the plan version and Construction Fund frozen at the original submission', function () {
    $scenario = Scenario::plan();
    $may = Scenario::finalized($scenario, '2026-05', 10);
    $v1 = MeasurementPlanVersion::query()->findOrFail($may->assets()->sole()->plan_version_id);

    // V2 com o Fundo de Obra dobrado entra em vigor depois do envio de maio.
    $planner = makeAdminUser();
    $service = app(MeasurementPlanVersionService::class);
    $draft = $service->createRevision($scenario['planSet']->fresh(), $planner, [
        'revision_category' => MeasurementPlanRevisionCategory::Cost->value,
        'revision_reason' => 'Aditivo de custo da obra.',
    ]);
    $draft = $service->updateDraft($draft, $planner, ['construction_fund_amount' => '2000000.00'], null, (int) $draft->revision);
    $v2 = $service->activate($draft, $planner, (int) $draft->revision);

    expect($v2->isActive())->toBeTrue()
        ->and($v1->fresh()->isActive())->toBeFalse();

    $first = Scenario::effective($scenario, $may, 12);
    $second = Scenario::effective($scenario, $first, 11);

    foreach ([$first, $second] as $revision) {
        $asset = $revision->assets()->sole();
        $entry = $revision->fresh()->engineering_snapshot['plan_sets'][0];

        expect($asset->plan_version_id)->toBe($v1->id)
            ->and($asset->plan_line_id)->toBe($may->assets()->sole()->plan_line_id)
            ->and($entry['plan_version_id'])->toBe($v1->id)
            ->and($entry['construction_fund_amount'])->toBe('1000000.00');
    }

    $position = app(MeasurementRevisionService::class)->positions($second->fresh())[0];

    expect($position->previousApprovedAmount)->toBe('120000.00')
        ->and($position->revisedApprovedAmount)->toBe('110000.00')
        ->and($position->planVersionId)->toBe($v1->id);
});
