<?php

use App\Enums\SalesBoardAutomationClosureReason;
use App\Enums\SalesBoardAutomationTargetStatus;
use App\Enums\SalesBoardBuilderReviewStatus;
use App\Enums\SalesBoardCycleStatus;
use App\Enums\SalesBoardManagementReviewStatus;
use App\Exceptions\SalesBoardCycleCancellationException;
use App\Filament\Resources\SalesBoardCycles\Pages\ViewSalesBoardCycle;
use App\Models\Emission;
use App\Models\SalesBoardAutomationAttempt;
use App\Models\SalesBoardAutomationTarget;
use App\Models\SalesBoardBuilderReview;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardCycleBaseline;
use App\Models\SalesBoardManagementReview;
use App\Models\User;
use App\Services\SalesBoards\SalesBoardAutomationService;
use App\Services\SalesBoards\SalesBoardCycleCancellationService;
use App\Services\SalesBoards\SalesBoardRolloutActivationService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\SalesBoards\AutomationFixture;
use Tests\Support\SalesBoards\GovernanceFixture;
use Tests\Support\SalesBoards\ManagementReviewFixture;
use Tests\Support\SalesBoards\RolloutFixture;

/**
 * "Cancelar competência": o fim de um ciclo que não vai ser publicado.
 *
 * Decisão da Gestão: só ciclo não aprovado, com motivo, autor e trilha; as
 * rodadas abertas são substituídas e o alvo da automação é encerrado sem que a
 * descoberta o reabra.
 */
uses(RefreshDatabase::class);

const CANCELLATION_REASON = 'A Emissão voltou ao registro manual e esta competência não será publicada pelo ciclo.';

function cancelCompetence(SalesBoardCycle $cycle, ?User $actor = null, string $reason = CANCELLATION_REASON): SalesBoardCycle
{
    return app(SalesBoardCycleCancellationService::class)
        ->cancel($cycle->fresh(), $actor ?? GovernanceFixture::approver(), $reason);
}

it('cancels a competence stuck outside the automation, superseding the open rounds', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $review = ManagementReviewFixture::open($scenario['cycle']);

    app(SalesBoardRolloutActivationService::class)->returnToLegacy(
        Emission::query()->findOrFail($scenario['cycle']->emission_id),
        GovernanceFixture::approver(),
        'A Emissão volta ao registro manual até a revisão do contrato.',
    );

    $approver = GovernanceFixture::approver();
    $baselines = SalesBoardCycleBaseline::query()->count();

    $cycle = cancelCompetence($scenario['cycle'], $approver);

    expect($cycle->status)->toBe(SalesBoardCycleStatus::Cancelled)
        ->and($cycle->cancelled_by_user_id)->toBe($approver->id)
        ->and($cycle->cancelled_at)->not->toBeNull()
        ->and($cycle->cancellation_reason)->toBe(CANCELLATION_REASON)
        ->and($review->fresh()->status)->toBe(SalesBoardManagementReviewStatus::Superseded)
        ->and($review->fresh()->superseded_reason)->toBe(SalesBoardCycleCancellationService::SUPERSEDED_REASON)
        ->and($scenario['builderReview']->fresh()->status)->toBe(SalesBoardBuilderReviewStatus::Superseded)
        // Nada do que foi apurado some.
        ->and(SalesBoardCycleBaseline::query()->count())->toBe($baselines)
        ->and($cycle->current_baseline_id)->toBe($scenario['cycle']->current_baseline_id);

    $trail = Activity::query()
        ->where('subject_type', SalesBoardCycle::class)
        ->where('subject_id', $cycle->id)
        ->latest('id')
        ->first();

    expect($trail->log_name)->toBe('sales_board')
        ->and($trail->properties['attributes']['cancelled_by_user_id'])->toBe($approver->id)
        ->and($trail->properties['attributes']['cancellation_reason'])->toBe(CANCELLATION_REASON);
});

it('keeps a returned round as it was and supersedes only the round still open', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $review = ManagementReviewFixture::open($scenario['cycle']);
    $returned = ManagementReviewFixture::returnToBuilder($review);

    cancelCompetence($scenario['cycle']);

    expect($returned['review']->fresh()->status)->toBe(SalesBoardManagementReviewStatus::Returned)
        ->and($scenario['builderReview']->fresh()->status)->toBe(SalesBoardBuilderReviewStatus::Submitted)
        ->and($returned['builderReview']->fresh()->status)->toBe(SalesBoardBuilderReviewStatus::Superseded);
});

it('refuses to cancel an approved competence', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    ManagementReviewFixture::approve(ManagementReviewFixture::open($scenario['cycle']));

    expect(fn () => cancelCompetence($scenario['cycle']))
        ->toThrow(SalesBoardCycleCancellationException::class, 'já foi aprovada e publicada');

    expect($scenario['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::Approved);
});

it('refuses to cancel twice', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    cancelCompetence($scenario['cycle']);

    expect(fn () => cancelCompetence($scenario['cycle']))
        ->toThrow(SalesBoardCycleCancellationException::class, 'já está cancelada');
});

it('requires the approval permission and a reason', function () {
    $scenario = ManagementReviewFixture::submittedCycle();

    expect(fn () => cancelCompetence($scenario['cycle'], GovernanceFixture::operator()))
        ->toThrow(AuthorizationException::class);

    expect(fn () => cancelCompetence($scenario['cycle'], GovernanceFixture::approver(), '  curto '))
        ->toThrow(SalesBoardCycleCancellationException::class, 'Informe o motivo do cancelamento');

    expect($scenario['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::ManagementReview);
});

it('closes the automation target and the discovery does not reopen it', function () {
    AutomationFixture::disable();
    Notification::fake();

    $scenario = RolloutFixture::emission(1);
    RolloutFixture::legacyBoard($scenario['constructions'][0]);

    $opener = User::factory()->create();
    $approver = GovernanceFixture::approver();
    $homologation = RolloutFixture::open($scenario['emission'], $opener);
    RolloutFixture::recipients($scenario['emission'], $opener);
    RolloutFixture::reviewImpacts($homologation, $approver);
    RolloutFixture::approve($homologation, $approver);
    RolloutFixture::activate($scenario['emission'], $homologation, $approver);
    RolloutFixture::enableGlobalAutomation();

    $runOn = function (string $day): void {
        $this->travelTo(CarbonImmutable::parse($day.' 13:00:00'));
        app(SalesBoardAutomationService::class)->run(asOf: CarbonImmutable::parse($day));
    };

    $runOn('2026-09-13');

    $target = SalesBoardAutomationTarget::query()->sole();
    expect($target->status)->toBe(SalesBoardAutomationTargetStatus::Satisfied);

    $canceller = GovernanceFixture::approver();
    cancelCompetence(SalesBoardCycle::query()->sole(), $canceller);

    $target->refresh();

    expect($target->status)->toBe(SalesBoardAutomationTargetStatus::Closed)
        ->and($target->closure_reason)->toBe(SalesBoardAutomationClosureReason::CompetenceCancelled)
        ->and($target->closed_by_user_id)->toBe($canceller->id)
        ->and($target->closure_message)->toContain(CANCELLATION_REASON);

    $attempts = SalesBoardAutomationAttempt::query()->count();

    $runOn('2026-09-14');

    expect($target->fresh()->status)->toBe(SalesBoardAutomationTargetStatus::Closed)
        ->and(SalesBoardAutomationAttempt::query()->count())->toBe($attempts)
        ->and(SalesBoardCycle::query()->count())->toBe(1);
});

it('offers the cancellation on the competence screen to management only', function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);

    $scenario = ManagementReviewFixture::submittedCycle();
    $cycle = $scenario['cycle'];

    $this->actingAs(GovernanceFixture::operator());

    Livewire::test(ViewSalesBoardCycle::class, ['record' => $cycle->getKey()])
        ->assertActionVisible('cancelCompetence')
        ->assertActionDisabled('cancelCompetence');

    $this->actingAs(makeAdminUser());

    Livewire::test(ViewSalesBoardCycle::class, ['record' => $cycle->getKey()])
        ->assertActionEnabled('cancelCompetence')
        ->callAction('cancelCompetence', data: ['reason' => CANCELLATION_REASON])
        ->assertHasNoActionErrors()
        ->assertActionHidden('cancelCompetence')
        ->assertSee('Competência cancelada')
        ->assertSee(CANCELLATION_REASON);

    expect($cycle->fresh()->status)->toBe(SalesBoardCycleStatus::Cancelled)
        ->and(SalesBoardBuilderReview::query()->where('status', SalesBoardBuilderReviewStatus::Superseded)->count())->toBe(1)
        ->and(SalesBoardManagementReview::query()->count())->toBe(0);
});
