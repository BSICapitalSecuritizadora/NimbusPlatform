<?php

use App\DTOs\Measurements\MeasurementPhysicalProgress;
use App\Enums\MeasurementRevisionStatus;
use App\Exceptions\MeasurementWorkflowException;
use App\Services\MeasurementPhysicalProgressService;
use App\Services\MeasurementRevisionService;
use App\Services\MeasurementWorkflow;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\MeasurementPhysicalProgressScenario;
use Tests\Support\MeasurementRevisionScenario as Scenario;

uses(RefreshDatabase::class);
pest()->group('parity');

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    Notification::fake();
});

function revisionCurrentPercent(array $scenario): string
{
    return MeasurementPhysicalProgressScenario::progress($scenario)->currentPercent();
}

/**
 * As medições que contam no avanço do cenário: uma por medição lógica.
 *
 * @return list<int>
 */
function revisionContributors(array $scenario): array
{
    return collect(MeasurementPhysicalProgressScenario::progress($scenario)->contributions)
        ->map(fn ($contribution): int => $contribution->measurementId)
        ->sort()
        ->values()
        ->all();
}

function revisionErrorMessage(callable $attempt): string
{
    try {
        $attempt();
    } catch (ValidationException $exception) {
        return (string) collect($exception->errors())->flatten()->first();
    }

    throw new RuntimeException('A tentativa não foi recusada.');
}

it('replaces the contribution of the revised measurement only when the revision becomes effective (30 + 10 + 8 = 48 → 46)', function () {
    $scenario = Scenario::plan(initialPercent: '30.00');
    $may = MeasurementPhysicalProgressScenario::measured($scenario, '2026-05', 10);
    $june = Scenario::finalized($scenario, '2026-06', 8);

    expect(revisionCurrentPercent($scenario))->toBe('48.00');

    // Aprovada pela Engenharia, a revisão ainda não conta: a R0 continua sendo
    // a contribuição de junho.
    $revision = Scenario::engineeringApproved($scenario, $june, 6);

    expect(revisionCurrentPercent($scenario))->toBe('48.00')
        ->and(revisionContributors($scenario))->toBe([$may->id, $june->id]);

    Scenario::approveManagementAndCompliance($scenario, $revision);

    expect(revisionCurrentPercent($scenario))->toBe('46.00')
        ->and(revisionContributors($scenario))->toBe([$may->id, $revision->id])
        ->and($june->fresh()->revisionStatus())->toBe(MeasurementRevisionStatus::Superseded);
});

it('refuses a revision that would take the development past 100% (96 − 3 + 8 = 101)', function () {
    $scenario = Scenario::plan(initialPercent: '89.00');
    $may = Scenario::finalized($scenario, '2026-05', 3);
    MeasurementPhysicalProgressScenario::measured($scenario, '2026-06', 4);
    $revision = Scenario::submit($scenario, Scenario::revise($scenario, $may));

    expect(revisionCurrentPercent($scenario))->toBe('96.00');

    $message = revisionErrorMessage(fn () => MeasurementPhysicalProgressScenario::approveEngineering($scenario, $revision, 8));

    expect($message)->toContain('100%')
        ->and($revision->fresh()->hasApprovedEngineering())->toBeFalse();

    // 96 − 3 + 7 = 100: cabe.
    MeasurementPhysicalProgressScenario::approveEngineering($scenario, $revision, 7);

    expect($revision->fresh()->hasApprovedEngineering())->toBeTrue()
        ->and(revisionCurrentPercent($scenario))->toBe('96.00');
});

it('checks the ceiling again when the revision becomes effective, after other approvals', function () {
    $scenario = Scenario::plan(initialPercent: '80.00');
    $may = Scenario::finalized($scenario, '2026-05', 10);
    // 80 − 10 + 15 = 85 na Engenharia da revisão.
    $revision = Scenario::engineeringApproved($scenario, $may, 15);
    // Junho aprovado depois: 80 + 10 (R0 ainda vigente) + 9 = 99.
    MeasurementPhysicalProgressScenario::measured($scenario, '2026-06', 9);
    app(MeasurementWorkflow::class)->approve($revision->fresh(), $scenario['actor']);

    // Na Compliance: 80 + 9 + 15 = 104.
    $message = revisionErrorMessage(fn () => app(MeasurementWorkflow::class)->approve($revision->fresh(), $scenario['actor']));

    expect($message)->toContain('não cabe mais no limite de 100%')
        ->and($revision->fresh()->revisionStatus())->toBe(MeasurementRevisionStatus::UnderReview)
        ->and($revision->fresh()->current_stage)->toBe(3)
        ->and($may->fresh()->revisionStatus())->toBe(MeasurementRevisionStatus::Effective)
        ->and(revisionCurrentPercent($scenario))->toBe('99.00');
});

it('never counts superseded, rejected or cancelled revisions', function () {
    $scenario = Scenario::plan();
    $may = Scenario::finalized($scenario, '2026-05', 10);
    $rejected = Scenario::engineeringApproved($scenario, $may, 12);
    $workflow = app(MeasurementWorkflow::class);
    // Gestão devolve à Engenharia; a Engenharia recusa: a revisão encerra.
    $workflow->reject($rejected->fresh(), $scenario['actor'], 'Gestão devolve.');
    $workflow->reject($rejected->fresh(), $scenario['actor'], 'Percentual sem lastro.');
    $cancelled = Scenario::revise($scenario, $may);
    app(MeasurementRevisionService::class)->cancel($cancelled->fresh(), $scenario['actor'], 'Desnecessária.');

    expect($rejected->fresh()->revisionStatus())->toBe(MeasurementRevisionStatus::Rejected)
        ->and(revisionContributors($scenario))->toBe([$may->id])
        ->and(revisionCurrentPercent($scenario))->toBe('10.00');

    $effective = Scenario::effective($scenario, $may, 9);

    expect(revisionContributors($scenario))->toBe([$effective->id])
        ->and(revisionCurrentPercent($scenario))->toBe('9.00')
        ->and(app(MeasurementPhysicalProgressService::class)->sources((int) $scenario['operation']->id)['snapshots'])
        ->toHaveKeys([$effective->id])
        ->not->toHaveKeys([$may->id, $rejected->id]);
});

it('records the revision snapshot from the validated values while the schedule line still shows the effective revision', function () {
    $scenario = Scenario::plan(initialPercent: '30.00');
    $may = Scenario::finalized($scenario, '2026-05', 10);
    $revision = Scenario::engineeringApproved($scenario, $may, 6);
    $entry = $revision->fresh()->engineering_snapshot['plan_sets'][0];
    $line = $scenario['lines']['2026-05']->fresh();

    expect($entry['realized_monthly_percent'])->toBe('6.00')
        ->and($entry['realized_cumulative_percent'])->toBe('36.00')
        ->and($entry['plan_version_id'])->toBe($may->assets()->sole()->plan_version_id)
        ->and($revision->fresh()->engineering_snapshot['revision_number'])->toBe(1)
        ->and($revision->fresh()->engineering_snapshot['previous_revision_id'])->toBe($may->id)
        ->and((string) $line->realized_monthly_percent)->toBe('10.00')
        ->and($line->measurement_id)->toBe($may->id);

    Scenario::approveManagementAndCompliance($scenario, $revision);
    $line->refresh();

    expect((string) $line->realized_monthly_percent)->toBe('6.00')
        ->and((string) $line->realized_cumulative_percent)->toBe('36.00')
        ->and($line->measurement_id)->toBe($revision->id);
});

it('shows the progress without the effective revision in the Engineering context of a pending revision', function () {
    $scenario = Scenario::plan(initialPercent: '30.00');
    $may = Scenario::finalized($scenario, '2026-05', 10);
    $revision = Scenario::submit($scenario, Scenario::revise($scenario, $may));
    $exclusion = app(MeasurementRevisionService::class)->progressExclusionId($revision->fresh());
    $progress = app(MeasurementPhysicalProgressService::class)->forOperation((int) $scenario['operation']->id, $exclusion)[$scenario['planSet']->id];

    expect($exclusion)->toBe($may->id)
        ->and($progress->currentPercent())->toBe('30.00')
        ->and(MeasurementPhysicalProgress::format($progress->remainingBasisPoints()))->toBe('70,00%');
});

it('stops counting the effective revision returned to Engineering without bringing back the superseded one', function () {
    $scenario = Scenario::plan(initialPercent: '30.00');
    $may = Scenario::finalized($scenario, '2026-05', 10);
    $revision = Scenario::effective($scenario, $may, 12);
    $workflow = app(MeasurementWorkflow::class);

    expect(revisionCurrentPercent($scenario))->toBe('42.00');

    // Pagamento → Compliance → Gestão → Engenharia: a aprovação deixa de valer.
    $workflow->reject($revision->fresh(), $scenario['actor'], 'Rever.');
    $workflow->reject($revision->fresh(), $scenario['actor'], 'Rever.');
    $workflow->reject($revision->fresh(), $scenario['actor'], 'Rever.');

    expect(revisionCurrentPercent($scenario))->toBe('30.00')
        ->and(revisionContributors($scenario))->toBe([])
        ->and($may->fresh()->revisionStatus())->toBe(MeasurementRevisionStatus::Superseded)
        ->and($revision->fresh()->assets()->sole()->line_claim_key)->toBe($scenario['lines']['2026-05']->lineage_key);

    MeasurementPhysicalProgressScenario::approveEngineering($scenario, $revision, 11);

    expect(revisionCurrentPercent($scenario))->toBe('41.00')
        ->and(revisionContributors($scenario))->toBe([$revision->id]);
});

it('only revises the effective revision of a measurement', function () {
    $scenario = Scenario::plan();
    $may = Scenario::finalized($scenario, '2026-05', 10);
    Scenario::effective($scenario, $may, 12);
    $service = app(MeasurementRevisionService::class);

    expect($service->creationBlockReason($may->fresh()))->toBe('Só a revisão vigente de uma medição pode ser revisada.')
        ->and(fn () => $service->create($may->fresh(), $scenario['actor'], 'Revisar a substituída.'))
        ->toThrow(MeasurementWorkflowException::class, MeasurementRevisionService::STALE_REVISION_REFUSAL);
});
