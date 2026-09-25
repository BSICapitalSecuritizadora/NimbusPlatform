<?php

use App\Enums\SalesBoardAutomationAlertType;
use App\Enums\SalesBoardAutomationClosureReason;
use App\Enums\SalesBoardAutomationTargetStatus;
use App\Enums\SalesBoardSource;
use App\Filament\Resources\SalesBoardAutomationTargets\Pages\ListSalesBoardAutomationTargets;
use App\Filament\Resources\SalesBoardRollouts\Pages\ManageSalesBoardRollout;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\Emission;
use App\Models\SalesBoardAutomationAlert;
use App\Models\SalesBoardAutomationAttempt;
use App\Models\SalesBoardAutomationTarget;
use App\Models\SalesBoardCycle;
use App\Models\User;
use App\Notifications\SalesBoardAutomationNotification;
use App\Services\SalesBoards\SalesBoardAutomationService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\SalesBoards\AutomationFixture;
use Tests\Support\SalesBoards\ManagementReviewFixture;
use Tests\Support\SalesBoards\RolloutFixture;

/**
 * O perímetro da automação: o que ela atende hoje, e o que acontece com o que
 * sai dele.
 *
 * Lembrete, badge de pendências e alvos abertos seguem o perímetro atual. Um
 * alvo de Emissão devolvida ao legado ou suspensa por mudança de escopo é
 * encerrado, com motivo e autor, e para de avisar. A suspensão por escopo é
 * avisada à Gestão, uma vez por situação.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    AutomationFixture::disable();
    Notification::fake();
});

function runAutomationOn(string $day): void
{
    test()->travelTo(CarbonImmutable::parse($day.' 13:00:00'));

    app(SalesBoardAutomationService::class)->run(asOf: CarbonImmutable::parse($day));
}

/**
 * Uma Emissão automatizada de verdade -- homologada e ativada --, com os dois
 * papéis de destinatário cobertos e o interruptor global ligado.
 *
 * @return array{emission: Emission, constructions: list<Construction>, people: array{operational: User, management: User}}
 */
function activatedEmission(int $constructions = 2): array
{
    $scenario = RolloutFixture::emission($constructions);

    foreach ($scenario['constructions'] as $construction) {
        RolloutFixture::legacyBoard($construction);
    }

    $actor = User::factory()->create();
    $homologation = RolloutFixture::open($scenario['emission'], $actor);
    $people = RolloutFixture::recipients($scenario['emission'], $actor);
    RolloutFixture::reviewImpacts($homologation, $actor);
    RolloutFixture::approve($homologation, $actor);
    RolloutFixture::activate($scenario['emission'], $homologation);
    RolloutFixture::enableGlobalAutomation();

    return [...$scenario, 'people' => $people];
}

/**
 * Uma unidade sem valor: a competência do empreendimento fica bloqueada.
 */
function blockConstruction(Construction $construction, string $unit = '950'): void
{
    ConstructionUnit::factory()->create([
        'construction_id' => $construction->id,
        'block' => '01', 'unit' => $unit,
        'base_value' => null, 'base_value_reference_date' => null,
    ]);
}

it('closes the open targets of an emission that returns to legacy, with reason and author', function () {
    $scenario = activatedEmission();
    blockConstruction($scenario['constructions'][0]);

    runAutomationOn('2026-09-13');

    $blocked = SalesBoardAutomationTarget::query()->where('construction_id', $scenario['constructions'][0]->id)->sole();
    $satisfied = SalesBoardAutomationTarget::query()->where('construction_id', $scenario['constructions'][1]->id)->sole();

    expect($blocked->status)->toBe(SalesBoardAutomationTargetStatus::Blocked)
        ->and($satisfied->status)->toBe(SalesBoardAutomationTargetStatus::Satisfied);

    $actor = User::factory()->create();
    RolloutFixture::returnToLegacy($scenario['emission'], $actor, 'Retorno ao legado para revisar o cadastro.');

    $blocked->refresh();

    expect($blocked->status)->toBe(SalesBoardAutomationTargetStatus::Closed)
        ->and($blocked->closure_reason)->toBe(SalesBoardAutomationClosureReason::ReturnedToLegacy)
        ->and($blocked->closed_by_user_id)->toBe($actor->id)
        ->and($blocked->closed_at)->not->toBeNull()
        ->and($blocked->closure_message)->toContain('Retorno ao legado para revisar o cadastro.')
        ->and($blocked->next_attempt_at)->toBeNull()
        ->and($blocked->currentReason())->toContain('modo legado')
        // O que já estava satisfeito continua satisfeito, e nenhum ciclo é tocado.
        ->and($satisfied->fresh()->status)->toBe(SalesBoardAutomationTargetStatus::Satisfied)
        ->and(SalesBoardCycle::query()->count())->toBe(1);
});

it('stops reminding about a blocked competence once its emission returns to legacy', function () {
    $scenario = activatedEmission(1);
    blockConstruction($scenario['constructions'][0]);
    config()->set('sales_board.automation.reminders.blocked_after_days', 0);

    runAutomationOn('2026-09-13');

    expect(SalesBoardAutomationAlert::query()->where('alert_type', SalesBoardAutomationAlertType::GenerationBlocked)->count())->toBe(1);

    RolloutFixture::returnToLegacy($scenario['emission']);

    foreach (['2026-09-14', '2026-09-15', '2026-11-30'] as $day) {
        runAutomationOn($day);
    }

    // Antes: um aviso por dia, para sempre, sobre uma competência fora da automação.
    expect(SalesBoardAutomationAlert::query()->where('alert_type', SalesBoardAutomationAlertType::GenerationBlocked)->count())->toBe(1)
        ->and(SalesBoardAutomationTarget::query()->sole()->status)->toBe(SalesBoardAutomationTargetStatus::Closed);

    Notification::assertSentToTimes($scenario['people']['operational'], SalesBoardAutomationNotification::class, 1);
});

it('never reminds about a manual cycle of a legacy emission', function () {
    Log::spy();

    $this->travelTo(CarbonImmutable::parse('2026-09-10 12:00:00'));
    $scenario = ManagementReviewFixture::submittedCycle();

    config()->set('sales_board.automation.enabled', true);
    config()->set('sales_board.automation.reminders.management_review_after_days', 0);

    foreach (['2026-09-13 13:00:00', '2026-09-13 14:00:00', '2026-09-13 15:00:00'] as $instant) {
        $this->travelTo(CarbonImmutable::parse($instant));
        app(SalesBoardAutomationService::class)->run(asOf: CarbonImmutable::parse('2026-09-13'));
    }

    // Antes: o ciclo manual entrava no lembrete da Gestão e, sem destinatário,
    // gerava um warning por hora.
    expect($scenario['cycle']->fresh()->emission->sales_board_source)->toBe(SalesBoardSource::Legacy)
        ->and(SalesBoardAutomationAlert::query()->count())->toBe(0);

    Log::shouldNotHaveReceived('warning', ['Sales board automation alert has no resolvable recipient', Mockery::any()]);
});

it('suspends a whole emission whose scope changed, closes its open targets and warns management once', function () {
    $scenario = activatedEmission();
    blockConstruction($scenario['constructions'][0]);

    runAutomationOn('2026-09-13');

    // Um empreendimento novo entra na Emissão já homologada.
    RolloutFixture::construction($scenario['emission'], 'Z');

    runAutomationOn('2026-09-14');

    $blocked = SalesBoardAutomationTarget::query()->where('construction_id', $scenario['constructions'][0]->id)->sole();

    expect($blocked->status)->toBe(SalesBoardAutomationTargetStatus::Closed)
        ->and($blocked->closure_reason)->toBe(SalesBoardAutomationClosureReason::ScopeSuspended)
        ->and($blocked->closed_by_user_id)->toBeNull();

    $alert = SalesBoardAutomationAlert::query()->where('alert_type', SalesBoardAutomationAlertType::ScopeSuspended)->sole();

    expect($alert->emission_id)->toBe($scenario['emission']->id)
        ->and($alert->recipient_user_id)->toBe($scenario['people']['management']->id);

    Notification::assertSentTo($scenario['people']['management'], SalesBoardAutomationNotification::class,
        fn (SalesBoardAutomationNotification $notification): bool => $notification->type === SalesBoardAutomationAlertType::ScopeSuspended
            && str_contains((string) $notification->url, '/sales-board-rollouts/'.$scenario['emission']->id));

    // A mesma situação no dia seguinte não repete o aviso.
    runAutomationOn('2026-09-15');
    runAutomationOn('2026-10-14');

    expect(SalesBoardAutomationAlert::query()->where('alert_type', SalesBoardAutomationAlertType::ScopeSuspended)->count())->toBe(1);
});

it('reopens a closed competence when a new activation covers it again', function () {
    $scenario = activatedEmission(1);
    $construction = $scenario['constructions'][0];
    blockConstruction($construction);

    runAutomationOn('2026-09-13');
    RolloutFixture::returnToLegacy($scenario['emission']);

    expect(SalesBoardAutomationTarget::query()->sole()->status)->toBe(SalesBoardAutomationTargetStatus::Closed);

    // O cadastro é corrigido e a Emissão volta, cobrindo a mesma competência.
    $construction->units()->whereNull('base_value')->update([
        'base_value' => '500000.00',
        'base_value_reference_date' => '2026-01-01',
    ]);
    $scenario['emission']->forceFill([
        'sales_board_source' => SalesBoardSource::Automated,
        'sales_board_automation_start_reference_month' => '2026-08-01',
        'sales_board_active_homologation_id' => $scenario['emission']->salesBoardRolloutHomologations()->value('id'),
    ])->save();

    runAutomationOn('2026-09-14');

    $target = SalesBoardAutomationTarget::query()->sole();

    expect($target->status)->toBe(SalesBoardAutomationTargetStatus::Satisfied)
        ->and(SalesBoardCycle::query()->count())->toBe(1)
        // A trilha inteira continua: bloqueio antes do retorno, geração depois.
        ->and(SalesBoardAutomationAttempt::query()->orderBy('id')->pluck('outcome')->map->value->all())
        ->toBe(['bloqueado', 'gerado']);
});

it('counts as pending action only what the automation still serves', function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(makeAdminUser());

    $scenario = activatedEmission();
    blockConstruction($scenario['constructions'][0]);
    runAutomationOn('2026-09-13');

    // Um alvo aberto de um empreendimento que nunca foi automatizado.
    SalesBoardAutomationTarget::factory()->create([
        'status' => SalesBoardAutomationTargetStatus::Blocked,
        'reference_month' => '2026-08-01',
    ]);

    $page = Livewire::test(ListSalesBoardAutomationTargets::class);

    expect($page->instance()->getTabs()['pendentes']->getBadge())->toBe('1');
    $page->assertCountTableRecords(1);

    RolloutFixture::returnToLegacy($scenario['emission']);

    $page = Livewire::test(ListSalesBoardAutomationTargets::class);

    // Antes: o badge contava tudo o que não estava satisfeito, e nunca zerava.
    expect($page->instance()->getTabs()['pendentes']->getBadge())->toBe('0');

    $page->assertCountTableRecords(0)
        ->set('activeTab', 'encerrados')
        ->assertCountTableRecords(1)
        ->assertSee('Encerrado (fora da automação)');
});

it('warns on the rollout screen that every reminder is off and that a liquidated emission is still automated', function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(makeAdminUser());

    $scenario = activatedEmission(1);
    $scenario['emission']->forceFill(['status' => 'closed'])->save();

    Livewire::test(ManageSalesBoardRollout::class, ['record' => $scenario['emission']->getKey()])
        ->assertOk()
        ->assertSee('lembretes de prazo da automação estão todos desligados')
        ->assertSee('Esta Emissão está liquidada e continua automatizada');

    Livewire::test(ListSalesBoardAutomationTargets::class)
        ->assertOk()
        ->assertSee('lembretes de prazo da automação estão todos desligados')
        ->assertSee('1 Emissão(ões) liquidada(s) continua(m) automatizada(s)');

    // Com um lembrete ligado e a Emissão em distribuição, os avisos somem.
    config()->set('sales_board.automation.reminders.blocked_after_days', 2);
    $scenario['emission']->forceFill(['status' => 'active'])->save();

    Livewire::test(ManageSalesBoardRollout::class, ['record' => $scenario['emission']->getKey()])
        ->assertOk()
        ->assertDontSee('lembretes de prazo da automação estão todos desligados')
        ->assertDontSee('Esta Emissão está liquidada');
});
