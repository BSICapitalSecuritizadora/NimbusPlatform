<?php

use App\Enums\AccessPermission;
use App\Enums\SalesBoardAutomationClosureReason;
use App\Enums\SalesBoardAutomationSatisfiedVia;
use App\Enums\SalesBoardAutomationTargetStatus;
use App\Enums\SalesBoardBuilderReviewStatus;
use App\Enums\SalesBoardCycleStatus;
use App\Enums\SalesBoardMovementTiming;
use App\Enums\SalesBoardMovementType;
use App\Enums\SalesBoardStaleImpact;
use App\Events\SalesBoards\SalesBoardPriorPositionChanged;
use App\Exceptions\SalesBoardCycleReopeningException;
use App\Exceptions\SalesBoardManagementReviewException;
use App\Filament\Resources\SalesBoardCycles\Pages\BuilderReviewWorkspace;
use App\Filament\Resources\SalesBoardCycles\Pages\ListSalesBoardCycles;
use App\Filament\Resources\SalesBoardCycles\Pages\ManagementReviewWorkspace;
use App\Filament\Resources\SalesBoardCycles\Pages\ViewSalesBoardCycle;
use App\Models\Construction;
use App\Models\Emission;
use App\Models\SalesBoard;
use App\Models\SalesBoardAutomationTarget;
use App\Models\SalesBoardBuilderReview;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardCycleMovement;
use App\Models\SalesBoardPublication;
use App\Models\User;
use App\Services\Reports\EmissionMonthlyReportService;
use App\Services\SalesBoards\SalesBoardAutomationService;
use App\Services\SalesBoards\SalesBoardCycleCancellationService;
use App\Services\SalesBoards\SalesBoardCycleReopeningService;
use App\Services\SalesBoards\SalesBoardRolloutActivationService;
use App\Support\SalesBoards\SalesBoardCycleNextAction;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\SalesBoards\AutomationFixture;
use Tests\Support\SalesBoards\BuilderReviewFixture;
use Tests\Support\SalesBoards\CycleFixture;
use Tests\Support\SalesBoards\ExtemporaneousFixture;
use Tests\Support\SalesBoards\GovernanceFixture;
use Tests\Support\SalesBoards\ManagementReviewFixture;
use Tests\Support\SalesBoards\RolloutFixture;

/**
 * "Reabrir competência": o cancelamento tem volta, e é o mesmo ciclo que volta.
 *
 * Só a Gestão reabre, com motivo, enquanto a competência estiver coberta pela
 * automação da Emissão, sem posição registrada no mês e sem competência
 * posterior publicada -- que já teria absorvido os fatos do mês cancelado.
 * Daí em diante vale o fluxo normal, até a publicação.
 */
uses(RefreshDatabase::class);

const REOPENING_CANCELLATION_REASON = 'Cancelada durante o piloto enquanto a fonte era revisada.';

const REOPENING_REASON = 'O cancelamento foi um engano: a fonte já estava correta.';

function cancelForReopening(SalesBoardCycle $cycle, ?User $actor = null): SalesBoardCycle
{
    return app(SalesBoardCycleCancellationService::class)
        ->cancel($cycle->fresh(), $actor ?? GovernanceFixture::approver(), REOPENING_CANCELLATION_REASON);
}

function reopenCompetenceFor(SalesBoardCycle $cycle, ?User $actor = null, string $reason = REOPENING_REASON): SalesBoardCycle
{
    return app(SalesBoardCycleReopeningService::class)
        ->reopen($cycle->fresh(), $actor ?? GovernanceFixture::approver(), $reason);
}

/**
 * Uma competência cancelada depois de entregue à Gestão: o caminho comum do
 * cancelamento, com a rodada da construtora já enviada.
 *
 * @return array{cycle: SalesBoardCycle, construction: Construction, builderReview: SalesBoardBuilderReview, canceller: User}
 */
function cancelledCompetence(): array
{
    $scenario = ManagementReviewFixture::submittedCycle();
    $canceller = GovernanceFixture::approver();

    cancelForReopening($scenario['cycle'], $canceller);

    return [...$scenario, 'cycle' => $scenario['cycle']->fresh(), 'canceller' => $canceller];
}

/**
 * Leva uma competência do empreendimento até a publicação, pelo fluxo inteiro.
 */
function publishCompetenceOf(Construction $construction, string $referenceMonth): SalesBoardCycle
{
    CycleFixture::generate($construction, $referenceMonth);

    $cycle = SalesBoardCycle::query()
        ->where('construction_id', $construction->id)
        ->whereDate('reference_month', $referenceMonth)
        ->sole();

    $review = BuilderReviewFixture::open($cycle);
    BuilderReviewFixture::confirmAll($review);
    BuilderReviewFixture::submit($review);

    $management = ManagementReviewFixture::open($cycle->fresh());
    ManagementReviewFixture::decideAll($management);
    ManagementReviewFixture::approve($management);

    return $cycle->fresh();
}

/**
 * Um ciclo em cada situação que não é "cancelado".
 */
function cycleInStatus(string $status): SalesBoardCycle
{
    return match ($status) {
        'gerado' => BuilderReviewFixture::generatedCycle()['cycle'],
        'validacao_construtora' => BuilderReviewFixture::open(BuilderReviewFixture::generatedCycle()['cycle'])->cycle,
        'analise_gestao' => ManagementReviewFixture::submittedCycle()['cycle'],
        'aprovado' => (function (): SalesBoardCycle {
            $scenario = ManagementReviewFixture::submittedCycle();
            ManagementReviewFixture::approve(ManagementReviewFixture::open($scenario['cycle']));

            return $scenario['cycle'];
        })(),
    };
}

it('reopens a cancelled competence back to generated, keeping the last cancellation on record', function () {
    $scenario = cancelledCompetence();
    $reopener = GovernanceFixture::approver();
    $cancelledAt = $scenario['cycle']->cancelled_at;

    $cycle = reopenCompetenceFor($scenario['cycle'], $reopener);

    expect($cycle->status)->toBe(SalesBoardCycleStatus::Generated)
        ->and($cycle->reopened_by_user_id)->toBe($reopener->id)
        ->and($cycle->reopened_at)->not->toBeNull()
        ->and($cycle->reopen_reason)->toBe(REOPENING_REASON)
        // O último cancelamento continua registrado ao lado da reabertura.
        ->and($cycle->cancelled_by_user_id)->toBe($scenario['canceller']->id)
        ->and($cycle->cancelled_at?->equalTo($cancelledAt))->toBeTrue()
        ->and($cycle->cancellation_reason)->toBe(REOPENING_CANCELLATION_REASON)
        // O mesmo ciclo, com a mesma versão: nada foi gerado de novo.
        ->and(SalesBoardCycle::query()->count())->toBe(1)
        ->and($cycle->current_baseline_id)->toBe($scenario['cycle']->current_baseline_id);

    $trail = Activity::query()
        ->where('subject_type', SalesBoardCycle::class)
        ->where('subject_id', $cycle->id)
        ->latest('id')
        ->first();

    expect($trail->log_name)->toBe('sales_board')
        ->and($trail->attribute_changes['attributes']['status'])->toBe(SalesBoardCycleStatus::Generated->value)
        ->and($trail->attribute_changes['attributes']['reopened_by_user_id'])->toBe($reopener->id)
        ->and($trail->attribute_changes['attributes']['reopen_reason'])->toBe(REOPENING_REASON);
});

it('returns the automation target to satisfied with the same cycle', function () {
    AutomationFixture::disable();
    Notification::fake();

    $scenario = RolloutFixture::emission(1, 'R');
    RolloutFixture::legacyBoard($scenario['constructions'][0]);
    RolloutFixture::activate($scenario['emission'], RolloutFixture::approvedHomologation($scenario['emission'], GovernanceFixture::operator()));
    RolloutFixture::enableGlobalAutomation();

    $this->travelTo(CarbonImmutable::parse('2026-09-13 13:00:00'));
    app(SalesBoardAutomationService::class)->run(asOf: CarbonImmutable::parse('2026-09-13'));

    $cycle = SalesBoardCycle::query()->sole();
    cancelForReopening($cycle);

    expect(SalesBoardAutomationTarget::query()->sole()->closure_reason)->toBe(SalesBoardAutomationClosureReason::CompetenceCancelled);

    reopenCompetenceFor($cycle);

    $target = SalesBoardAutomationTarget::query()->sole();

    expect($target->status)->toBe(SalesBoardAutomationTargetStatus::Satisfied)
        ->and($target->sales_board_cycle_id)->toBe($cycle->id)
        // Satisfeito como já tinha sido: a reabertura não reescreve o caminho.
        ->and($target->satisfied_via)->toBe(SalesBoardAutomationSatisfiedVia::Generated)
        ->and($target->next_attempt_at)->toBeNull();
});

it('returns a target closed before the due date to satisfied as already existing', function () {
    AutomationFixture::disable();
    Notification::fake();

    $construction = AutomationFixture::readyConstruction();
    CycleFixture::generate($construction, AutomationFixture::DEFAULT_MONTH);
    $cycle = cancelForReopening(SalesBoardCycle::query()->sole());

    AutomationFixture::enable([$construction]);
    AutomationFixture::run();

    expect(SalesBoardAutomationTarget::query()->sole()->status)->toBe(SalesBoardAutomationTargetStatus::Closed);

    reopenCompetenceFor($cycle);

    $target = SalesBoardAutomationTarget::query()->sole();

    expect($target->status)->toBe(SalesBoardAutomationTargetStatus::Satisfied)
        ->and($target->satisfied_via)->toBe(SalesBoardAutomationSatisfiedVia::Existing)
        ->and($target->sales_board_cycle_id)->toBe($cycle->id);
});

it('runs the whole flow again up to publication after reopening', function () {
    $scenario = cancelledCompetence();

    $cycle = reopenCompetenceFor($scenario['cycle']);

    $review = BuilderReviewFixture::open($cycle);

    expect($review->attempt)->toBe(2)
        ->and($cycle->fresh()->status)->toBe(SalesBoardCycleStatus::BuilderReview);

    BuilderReviewFixture::confirmAll($review);
    BuilderReviewFixture::submit($review);

    $management = ManagementReviewFixture::open($cycle->fresh());
    ManagementReviewFixture::decideAll($management);
    ManagementReviewFixture::approve($management);

    expect($cycle->fresh()->status)->toBe(SalesBoardCycleStatus::Approved)
        ->and(SalesBoard::query()->where('construction_id', $scenario['construction']->id)->count())->toBe(1)
        ->and(SalesBoardCycle::query()->count())->toBe(1)
        // A rodada encerrada pelo cancelamento continua no histórico.
        ->and(SalesBoardBuilderReview::query()->where('status', SalesBoardBuilderReviewStatus::Superseded)->count())->toBe(1);
});

it('takes a reopened competence from cancelled back to awaiting publication in the monthly report', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $construction = $scenario['construction'];
    $emission = Emission::query()->findOrFail($scenario['cycle']->emission_id);
    $month = CarbonImmutable::parse($scenario['cycle']->reference_month->toDateString());

    // Última posição conhecida, anterior ao início da automação: é ela que o
    // relatório transporta enquanto a competência não tem quadro próprio.
    SalesBoard::factory()->forEmissionAndConstruction($emission, $construction)->create([
        'reference_month' => '2025-12-01',
        'stock_units' => 3,
        'financed_units' => 0,
        'paid_units' => 0,
        'exchanged_units' => 0,
    ]);

    $coverage = fn (): array => app(EmissionMonthlyReportService::class)
        ->build($emission->fresh(), $month)['units']['coverage_summary'];

    cancelForReopening($scenario['cycle']);
    $whileCancelled = $coverage();

    reopenCompetenceFor($scenario['cycle']);
    $afterReopening = $coverage();

    expect($whileCancelled['cancelled'])->toBe([$construction->development_name])
        ->and($whileCancelled['awaiting_publication'])->toBeFalse()
        // Reaberta, a competência volta a ter ciclo aberto: a publicação está a
        // caminho de novo, e o rótulo de cancelada sai.
        ->and($afterReopening['cancelled'])->toBe([])
        ->and($afterReopening['awaiting'])->toBe([$construction->development_name])
        ->and($afterReopening['awaiting_publication'])->toBeTrue();
});

it('refuses a competence that is not cancelled', function (string $status) {
    $cycle = cycleInStatus($status);
    $before = $cycle->fresh()->status;

    expect($before->value)->toBe($status)
        ->and(fn () => reopenCompetenceFor($cycle))
        ->toThrow(SalesBoardCycleReopeningException::class, 'Só uma competência cancelada pode ser reaberta');

    expect($cycle->fresh()->status)->toBe($before)
        ->and($cycle->fresh()->reopened_at)->toBeNull();
})->with(['gerado', 'validacao_construtora', 'analise_gestao', 'aprovado']);

it('refuses without the approval permission, and refuses a reason shorter than ten characters', function () {
    $scenario = cancelledCompetence();

    expect(fn () => reopenCompetenceFor($scenario['cycle'], GovernanceFixture::operator()))
        ->toThrow(AuthorizationException::class);

    expect(fn () => reopenCompetenceFor($scenario['cycle'], GovernanceFixture::approver(), '  curto '))
        ->toThrow(SalesBoardCycleReopeningException::class, 'Informe o motivo da reabertura');

    expect($scenario['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::Cancelled)
        ->and($scenario['cycle']->fresh()->reopened_at)->toBeNull();
});

it('refuses when the competence is no longer covered by the automation', function () {
    $scenario = cancelledCompetence();

    app(SalesBoardRolloutActivationService::class)->returnToLegacy(
        Emission::query()->findOrFail($scenario['cycle']->emission_id),
        GovernanceFixture::approver(),
        'A Emissão volta ao registro manual até a revisão do contrato.',
    );

    expect(fn () => reopenCompetenceFor($scenario['cycle']))
        ->toThrow(SalesBoardCycleReopeningException::class, 'não está coberta pela automação');

    expect($scenario['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::Cancelled);
});

it('refuses when the construction moved to another emission after the generation', function () {
    $scenario = cancelledCompetence();

    // O model recusa a troca de Emissão de uma obra com ciclo; a troca chega
    // por uma carga feita por fora dele, que é exatamente o caso a recusar.
    DB::table('constructions')->where('id', $scenario['construction']->id)->update([
        'emission_id' => Emission::factory()->withAutomatedSalesBoard(CycleFixture::AUTOMATION_START)->create(['status' => 'active'])->id,
    ]);

    expect(fn () => reopenCompetenceFor($scenario['cycle']))
        ->toThrow(SalesBoardCycleReopeningException::class, 'O empreendimento mudou de Emissão');

    expect($scenario['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::Cancelled);
});

it('refuses when the emission went back to draft', function () {
    $scenario = cancelledCompetence();

    Emission::query()->whereKey($scenario['cycle']->emission_id)->update(['status' => Emission::STATUS_DRAFT]);

    expect(fn () => reopenCompetenceFor($scenario['cycle']))
        ->toThrow(SalesBoardCycleReopeningException::class, 'Em Elaboração');

    expect($scenario['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::Cancelled);
});

it('refuses when a sales board is already registered for the construction and month, even under another emission', function () {
    $scenario = cancelledCompetence();

    // Uma carga por fora do model, sob outra Emissão: a unique da tabela deixa
    // passar, e o leitor de posição a enxergaria do mesmo jeito.
    DB::table('sales_boards')->insert([
        'emission_id' => Emission::factory()->create(['status' => 'active'])->id,
        'construction_id' => $scenario['construction']->id,
        'reference_month' => $scenario['cycle']->reference_month->toDateString(),
        'stock_units' => 1, 'financed_units' => 0, 'paid_units' => 0, 'exchanged_units' => 0, 'total_units' => 1,
        'stock_value' => '500000.00', 'financed_value' => '0.00', 'paid_value' => '0.00', 'exchanged_value' => '0.00',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(fn () => reopenCompetenceFor($scenario['cycle']))
        ->toThrow(SalesBoardCycleReopeningException::class, 'Já existe Quadro de Vendas registrado');

    expect($scenario['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::Cancelled);
});

it('refuses when a later competence of the construction was already published', function () {
    $scenario = cancelledCompetence();

    publishCompetenceOf($scenario['construction'], '2026-08-01');

    expect(fn () => reopenCompetenceFor($scenario['cycle']))
        ->toThrow(
            SalesBoardCycleReopeningException::class,
            'A competência 07/2026 não pode ser reaberta: 08/2026 já foi publicada e absorveu os fatos dela.',
        );

    expect($scenario['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::Cancelled);
});

it('names the competence that absorbed the cancelled month, even when a later one was published after it', function () {
    $scenario = cancelledCompetence();

    publishCompetenceOf($scenario['construction'], '2026-08-01');
    publishCompetenceOf($scenario['construction'], '2026-09-01');

    expect(fn () => reopenCompetenceFor($scenario['cycle']))
        ->toThrow(
            SalesBoardCycleReopeningException::class,
            'A competência 07/2026 não pode ser reaberta: 08/2026 já foi publicada e absorveu os fatos dela.',
        );
});

/**
 * Sem movimento do mês cancelado na publicação posterior -- o mês não teve
 * fato --, a recusa não manda procurar movimentos que não existem: a posição
 * publicada já reflete o mês, e a correção é a retificação da última publicada.
 */
it('does not claim an absorption that did not happen when refusing the reopening', function () {
    $scenario = ExtemporaneousFixture::publishedJuly(2);
    $august = ExtemporaneousFixture::generateAugust($scenario['construction']);
    cancelForReopening($august);

    publishCompetenceOf($scenario['construction'], '2026-09-01');

    expect(fn () => reopenCompetenceFor($august))
        ->toThrow(
            SalesBoardCycleReopeningException::class,
            'A competência 08/2026 não pode ser reaberta: 09/2026 já foi publicada, e a posição publicada já reflete os fatos de 08/2026. '
                .'Para corrigir a posição publicada, use a retificação da última competência publicada (09/2026).',
        );

    expect($august->fresh()->status)->toBe(SalesBoardCycleStatus::Cancelled);
});

it('reopens when the later competence was only generated, not published', function () {
    $scenario = cancelledCompetence();

    CycleFixture::generate($scenario['construction'], '2026-08-01');

    expect(reopenCompetenceFor($scenario['cycle'])->status)->toBe(SalesBoardCycleStatus::Generated)
        ->and(SalesBoardCycle::query()->count())->toBe(2);
});

it('tells the following competences that the prior position changed when a competence is reopened', function () {
    $scenario = cancelledCompetence();

    Event::fake([SalesBoardPriorPositionChanged::class]);

    reopenCompetenceFor($scenario['cycle']);

    Event::assertDispatchedTimes(SalesBoardPriorPositionChanged::class, 1);
    Event::assertDispatched(
        SalesBoardPriorPositionChanged::class,
        fn (SalesBoardPriorPositionChanged $event): bool => ($event->reason === SalesBoardPriorPositionChanged::COMPETENCE_REOPENED)
            && ($event->constructionId === (int) $scenario['construction']->id)
            && ($event->referenceMonth->format('Y-m-d') === '2026-07-01'),
    );
});

/**
 * Agosto absorvia a venda de julho enquanto julho estava cancelado; reaberto
 * julho, agosto volta a partir dele, e o ouvinte do evento da reabertura marca
 * agosto como desatualizado antes de qualquer conferência manual.
 */
it('marks the next competence that absorbed the reopened month as materially changed', function () {
    $scenario = cancelledCompetence();
    $august = CycleFixture::generate($scenario['construction'], '2026-08-01')->cycle;

    expect(ExtemporaneousFixture::movements($august, SalesBoardMovementType::Sale, SalesBoardMovementTiming::WithoutPosition))->not->toBeEmpty();

    reopenCompetenceFor($scenario['cycle']);

    $baseline = CycleFixture::currentBaseline($august);

    expect($baseline->is_stale)->toBeTrue()
        ->and($baseline->stale_impact)->toBe(SalesBoardStaleImpact::Material);
});

/**
 * Junho reaberto com julho cancelado e agosto já gerado: agosto, que partia de
 * maio, passa a partir de junho -- pulando julho -- e fica retido até junho ser
 * publicada. A venda de junho termina numa publicação só.
 */
it('holds the competence after a cancelled month until the reopened anchor is published', function () {
    [$construction, $units] = CycleFixture::readyConstruction(3);
    publishCompetenceOf($construction, '2026-05-01');

    $sale = ExtemporaneousFixture::sale($units[0], '2026-06-10');

    $june = CycleFixture::generate($construction, '2026-06-01')->cycle;
    cancelForReopening($june);
    cancelForReopening(CycleFixture::generate($construction, '2026-07-01')->cycle);

    $august = ExtemporaneousFixture::generateAugust($construction);

    expect(ExtemporaneousFixture::movements($august, SalesBoardMovementType::Sale, SalesBoardMovementTiming::WithoutPosition)->pluck('contract_id')->all())->toBe([$sale->id]);

    reopenCompetenceFor($june);

    expect(CycleFixture::currentBaseline($august)->stale_impact)->toBe(SalesBoardStaleImpact::Material);

    CycleFixture::recalculate($august, 'Junho reaberto: agosto parte dele.');
    $review = ExtemporaneousFixture::analysis($august->fresh());
    ManagementReviewFixture::decideAll($review);

    expect(fn () => ManagementReviewFixture::approve($review))
        ->toThrow(SalesBoardManagementReviewException::class, 'Como 07/2026 foi cancelada, os movimentos de 08/2026 partem da posição de 06/2026');

    ExtemporaneousFixture::publish($june->fresh());
    ManagementReviewFixture::approve($review);

    $owners = SalesBoardPublication::query()
        ->whereDoesntHave('supersededBy')
        ->with('cycle')
        ->get()
        ->filter(fn (SalesBoardPublication $publication): bool => SalesBoardCycleMovement::query()
            ->where('sales_board_cycle_baseline_id', $publication->sales_board_cycle_baseline_id)
            ->where('contract_id', $sale->id)
            ->where('movement_type', SalesBoardMovementType::Sale->value)
            ->exists())
        ->map(fn (SalesBoardPublication $publication): string => $publication->cycle->reference_month->format('Y-m'))
        ->values()
        ->all();

    expect($august->fresh()->status)->toBe(SalesBoardCycleStatus::Approved)
        ->and($owners)->toBe(['2026-06']);
});

it('offers Reabrir competência on a cancelled competence: disabled for the operator, enabled for management', function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);

    $scenario = cancelledCompetence();
    $cycle = $scenario['cycle'];

    $this->actingAs(GovernanceFixture::operator());

    Livewire::test(ViewSalesBoardCycle::class, ['record' => $cycle->getKey()])
        ->assertActionVisible('reopenCompetence')
        ->assertActionDisabled('reopenCompetence')
        ->assertActionExists('reopenCompetence', fn (Action $action): bool => $action->getTooltip()
            === 'Reabrir a competência é da Gestão: exige a permissão de aprovação do Quadro de Vendas.');

    $this->actingAs(makeAdminUser());

    Livewire::test(ViewSalesBoardCycle::class, ['record' => $cycle->getKey()])
        ->assertActionEnabled('reopenCompetence')
        ->callAction('reopenCompetence', data: ['reason' => REOPENING_REASON])
        ->assertHasNoActionErrors()
        ->assertNotified('Competência reaberta')
        ->assertActionHidden('reopenCompetence')
        ->assertSee('Reaberta por')
        ->assertSee(REOPENING_REASON);

    expect($cycle->fresh()->status)->toBe(SalesBoardCycleStatus::Generated);
});

it('shows Reabrir competência enabled to the Gestão without sales-boards.update and hides it from a view-only profile', function () {
    $scenario = cancelledCompetence();
    $cycle = $scenario['cycle'];

    $profile = function (array $permissions): User {
        $user = User::factory()->withTwoFactor()->create(['email' => fake()->unique()->safeEmail()]);
        $user->givePermissionTo($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    };

    // Quem só consulta não encontra o botão nem o monta à força.
    $this->actingAs($profile([AccessPermission::SalesBoardsView->value, AccessPermission::EmissionsView->value]));

    Livewire::test(ViewSalesBoardCycle::class, ['record' => $cycle->getKey()])
        ->assertActionHidden('reopenCompetence')
        ->call('mountAction', 'reopenCompetence')
        ->assertSet('mountedActions', []);

    expect($cycle->fresh()->status)->toBe(SalesBoardCycleStatus::Cancelled);

    // A Gestão sem permissão de operar encontra o botão habilitado e reabre.
    $this->actingAs($profile([
        AccessPermission::SalesBoardsView->value,
        AccessPermission::EmissionsView->value,
        AccessPermission::SalesBoardsApprove->value,
    ]));

    Livewire::test(ViewSalesBoardCycle::class, ['record' => $cycle->getKey()])
        ->assertActionVisible('reopenCompetence')
        ->assertActionEnabled('reopenCompetence')
        ->callAction('reopenCompetence', data: ['reason' => REOPENING_REASON])
        ->assertHasNoActionErrors();

    expect($cycle->fresh()->status)->toBe(SalesBoardCycleStatus::Generated);
});

it('shows the last cancellation and the reopening in Brasília time', function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);

    $scenario = ManagementReviewFixture::submittedCycle();

    // 02:30 em UTC do dia 20 é 23:30 do dia 19 em Brasília.
    $this->travelTo(CarbonImmutable::parse('2026-09-20 02:30:00', 'UTC'));
    cancelForReopening($scenario['cycle']);

    $this->travelTo(CarbonImmutable::parse('2026-09-21 01:15:00', 'UTC'));
    reopenCompetenceFor($scenario['cycle']);

    $this->actingAs(makeAdminUser());

    Livewire::test(ViewSalesBoardCycle::class, ['record' => $scenario['cycle']->getKey()])
        ->assertOk()
        ->assertSee('Último cancelamento')
        ->assertSee('19/09/2026 às 23:30')
        ->assertSee('Reaberta por')
        ->assertSee('20/09/2026 às 22:15')
        ->assertSee('Motivo da reabertura');
});

it('refuses a forged reopen mount from a user without the approval permission', function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);

    $scenario = cancelledCompetence();
    $operator = GovernanceFixture::operator();

    $this->actingAs($operator);

    Livewire::test(ViewSalesBoardCycle::class, ['record' => $scenario['cycle']->getKey()])
        ->mountAction('reopenCompetence')
        ->assertActionNotMounted('reopenCompetence');

    expect(fn () => app(SalesBoardCycleReopeningService::class)->reopen($scenario['cycle']->fresh(), $operator, REOPENING_REASON))
        ->toThrow(AuthorizationException::class);

    expect($scenario['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::Cancelled);
});

it('points the freeze action and the command of a cancelled month to the reopening', function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(makeAdminUser());

    $scenario = cancelledCompetence();

    $page = Livewire::test(ListSalesBoardCycles::class)
        ->callAction(TestAction::make('generateCycle'), data: [
            'construction_id' => $scenario['construction']->id,
            'reference_month' => '2026-07-01 00:00:00',
        ]);

    $notification = session()->get('filament.claimed_notifications') ?? session()->get('filament.notifications');
    $body = (string) (collect($notification)->last()['body'] ?? '');

    expect($body)->toStartWith('A competência 07/2026 foi cancelada pela Gestão em ')
        ->and($body)->toContain('Para retomá-la, abra o ciclo e use “Reabrir competência”.');

    $page->assertNotified('Ciclo já existente');

    Artisan::call('sales-boards:generate-cycle', [
        '--construction' => $scenario['construction']->id,
        '--reference-month' => '07/2026',
    ]);

    expect(Artisan::output())->toContain('A competência foi cancelada; a volta é “Reabrir competência” na tela do ciclo (Gestão).')
        ->and(SalesBoardCycle::query()->count())->toBe(1);
});

it('tells on the review screens that a round was closed by the cancellation of a competence later reopened', function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);

    $scenario = ManagementReviewFixture::submittedCycle();
    ManagementReviewFixture::open($scenario['cycle']);
    cancelForReopening($scenario['cycle']);
    reopenCompetenceFor($scenario['cycle']);

    $this->actingAs(makeAdminUser());

    // Dizer "substituída por uma nova versão" contaria outra história: foi o
    // cancelamento que encerrou a rodada, e a competência voltou depois.
    Livewire::test(BuilderReviewWorkspace::class, ['record' => $scenario['cycle']->getKey()])
        ->assertOk()
        ->assertSee('Esta rodada foi encerrada pelo cancelamento da competência.')
        ->assertDontSee('Esta rodada foi substituída por uma nova versão da posição.');

    Livewire::test(ManagementReviewWorkspace::class, ['record' => $scenario['cycle']->getKey()])
        ->assertOk()
        ->assertSee('Esta análise foi encerrada pelo cancelamento da competência.')
        ->assertSee('Esta análise foi encerrada pelo cancelamento da competência, que depois foi reaberta.')
        ->assertDontSee('Esta análise se refere a uma versão que não é mais a vigente.');
});

it('says in the next action of a cancelled competence that management can reopen it', function () {
    $scenario = cancelledCompetence();

    $nextAction = SalesBoardCycleNextAction::for($scenario['cycle']->fresh());

    expect($nextAction->headline)->toBe('Competência cancelada.')
        ->and($nextAction->detail)->toContain('A Gestão pode reabri-la com “Reabrir competência”')
        ->and($nextAction->detail)->toContain('Motivo: '.REOPENING_CANCELLATION_REASON);
});
