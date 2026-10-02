<?php

use App\Enums\AccessPermission;
use App\Enums\SalesBoardApprovalOutcome;
use App\Enums\SalesBoardCycleStatus;
use App\Enums\SalesBoardRectificationStatus;
use App\Filament\Resources\SalesBoardCycles\Pages\BuilderReviewWorkspace;
use App\Filament\Resources\SalesBoardCycles\Pages\ManagementReviewWorkspace;
use App\Filament\Resources\SalesBoardCycles\Pages\ViewSalesBoardCycle;
use App\Models\SalesBoardCycleRectification;
use App\Models\SalesBoardManagementReview;
use App\Models\User;
use App\Services\SalesBoards\SalesBoardManagementApprovalService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Action;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\SalesBoards\BuilderReviewFixture;
use Tests\Support\SalesBoards\ExtemporaneousFixture;
use Tests\Support\SalesBoards\GovernanceFixture;
use Tests\Support\SalesBoards\ManagementReviewFixture;

/**
 * "Retificar competência" e "Desistir da retificação" na tela da competência, e
 * a retificação como quem valida e quem analisa a vê.
 *
 * As duas ações seguem o portão das ações da Gestão: aparecem para quem conduz a
 * competência e ficam desabilitadas, dizendo por quê, para quem não tem a
 * permissão de aprovação -- e desabilitadas nem montam.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

const RECTIFICATION_UI_REASON = 'Venda da unidade 101 lançada com valor errado.';

it('offers Retificar competência to the operator disabled, with the Gestão tooltip, and refuses a forged mount', function () {
    $scenario = ExtemporaneousFixture::rectifiableJuly();

    $this->actingAs(GovernanceFixture::operator());

    Livewire::test(ViewSalesBoardCycle::class, ['record' => $scenario['july']->getKey()])
        ->assertActionVisible('rectifyCompetence')
        ->assertActionDisabled('rectifyCompetence')
        ->assertActionExists('rectifyCompetence', fn (Action $action): bool => $action->getTooltip()
            === 'Retificar a competência é da Gestão: exige a permissão de aprovação do Quadro de Vendas.')
        ->mountAction('rectifyCompetence')
        ->assertActionNotMounted('rectifyCompetence');

    expect(SalesBoardCycleRectification::query()->exists())->toBeFalse()
        ->and($scenario['july']->fresh()->status)->toBe(SalesBoardCycleStatus::Approved);
});

it('hides both actions from a view-only profile, refuses its forged mounts, and lets the Gestão without sales-boards.update use them', function () {
    $scenario = ExtemporaneousFixture::rectifiableJuly();
    $july = $scenario['july'];

    $profile = function (array $permissions): User {
        $user = User::factory()->withTwoFactor()->create(['email' => fake()->unique()->safeEmail()]);
        $user->givePermissionTo($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    };

    $viewer = $profile([AccessPermission::SalesBoardsView->value, AccessPermission::EmissionsView->value]);
    $management = $profile([
        AccessPermission::SalesBoardsView->value,
        AccessPermission::EmissionsView->value,
        AccessPermission::SalesBoardsApprove->value,
    ]);

    $this->actingAs($viewer);

    Livewire::test(ViewSalesBoardCycle::class, ['record' => $july->getKey()])
        ->assertActionHidden('rectifyCompetence')
        ->call('mountAction', 'rectifyCompetence')
        ->assertSet('mountedActions', []);

    expect(SalesBoardCycleRectification::query()->exists())->toBeFalse();

    $this->actingAs($management);

    Livewire::test(ViewSalesBoardCycle::class, ['record' => $july->getKey()])
        ->assertActionVisible('rectifyCompetence')
        ->assertActionEnabled('rectifyCompetence')
        ->callAction('rectifyCompetence', data: ['reason' => RECTIFICATION_UI_REASON])
        ->assertHasNoActionErrors();

    $this->actingAs($viewer);

    Livewire::test(ViewSalesBoardCycle::class, ['record' => $july->getKey()])
        ->assertActionHidden('abandonRectification')
        ->call('mountAction', 'abandonRectification')
        ->assertSet('mountedActions', []);

    expect(SalesBoardCycleRectification::query()->sole()->status)->toBe(SalesBoardRectificationStatus::Open);
});

it('disables it with the reason on a competence that is no longer the last published one', function () {
    $scenario = ExtemporaneousFixture::rectifiableJuly();
    $august = ExtemporaneousFixture::generateAugust($scenario['construction']);
    ExtemporaneousFixture::publish($august);

    $this->actingAs(makeAdminUser());

    Livewire::test(ViewSalesBoardCycle::class, ['record' => $scenario['july']->getKey()])
        ->assertActionVisible('rectifyCompetence')
        ->assertActionDisabled('rectifyCompetence')
        ->assertActionExists('rectifyCompetence', fn (Action $action): bool => $action->getTooltip()
            === 'Só a última competência publicada do empreendimento (08/2026) pode ser retificada. Esta se corrige para frente, pelos movimentos extemporâneos da competência seguinte.');

    Livewire::test(ViewSalesBoardCycle::class, ['record' => $august->getKey()])
        ->assertActionVisible('rectifyCompetence')
        ->assertActionEnabled('rectifyCompetence');
});

it('shows the differences against the published position and the next competence in progress, and opens the rectification', function () {
    $scenario = ExtemporaneousFixture::rectifiableJuly();
    ExtemporaneousFixture::generateAugust($scenario['construction']);
    $admin = makeAdminUser();

    $this->actingAs($admin);

    Livewire::test(ViewSalesBoardCycle::class, ['record' => $scenario['july']->getKey()])
        ->assertActionEnabled('rectifyCompetence')
        ->mountAction('rectifyCompetence')
        ->assertActionMounted('rectifyCompetence')
        ->assertMountedActionModalSee('Diferenças contra a posição publicada:')
        ->assertMountedActionModalSee('A competência 08/2026 está em andamento')
        ->setActionData(['reason' => RECTIFICATION_UI_REASON])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified('Retificação aberta')
        ->assertActionHidden('rectifyCompetence')
        ->assertActionVisible('abandonRectification');

    $rectification = SalesBoardCycleRectification::query()->where('sales_board_cycle_id', $scenario['july']->id)->sole();

    expect($rectification->status)->toBe(SalesBoardRectificationStatus::Open)
        ->and($rectification->reason)->toBe(RECTIFICATION_UI_REASON)
        ->and($rectification->requested_by_user_id)->toBe($admin->id)
        ->and($scenario['july']->fresh()->status)->toBe(SalesBoardCycleStatus::Generated);
});

it('shows Desistir da retificação only while a rectification is open, and only the Gestão concludes it', function () {
    $scenario = ExtemporaneousFixture::rectifiableJuly();
    $july = $scenario['july'];

    $this->actingAs(makeAdminUser());

    Livewire::test(ViewSalesBoardCycle::class, ['record' => $july->getKey()])
        ->assertActionHidden('abandonRectification');

    ExtemporaneousFixture::rectify($july);

    $this->actingAs(GovernanceFixture::operator());

    Livewire::test(ViewSalesBoardCycle::class, ['record' => $july->getKey()])
        ->assertActionHidden('rectifyCompetence')
        ->assertActionVisible('abandonRectification')
        ->assertActionDisabled('abandonRectification')
        ->assertActionExists('abandonRectification', fn (Action $action): bool => $action->getTooltip()
            === 'Desistir da retificação é da Gestão: exige a permissão de aprovação do Quadro de Vendas.');

    $this->actingAs(makeAdminUser());

    Livewire::test(ViewSalesBoardCycle::class, ['record' => $july->getKey()])
        ->assertActionEnabled('abandonRectification')
        ->callAction('abandonRectification', data: ['reason' => 'A construtora confirmou o valor publicado.'])
        ->assertHasNoActionErrors()
        ->assertNotified('Retificação desistida')
        ->assertActionHidden('abandonRectification');

    expect($july->fresh()->status)->toBe(SalesBoardCycleStatus::Approved)
        ->and(SalesBoardCycleRectification::query()->sole()->status)->toBe(SalesBoardRectificationStatus::Abandoned);
});

it('shows the source of the restored published version checked again right after Desistir da retificação', function () {
    $scenario = ExtemporaneousFixture::rectifiableJuly();
    $july = $scenario['july'];
    ExtemporaneousFixture::rectify($july);

    $this->actingAs(makeAdminUser());

    // A fonte mudou depois da publicação -- foi o que motivou a retificação --, e
    // a tela não pode voltar a "Sem alterações" com a constatação de antes dela.
    Livewire::test(ViewSalesBoardCycle::class, ['record' => $july->getKey()])
        ->callAction('abandonRectification', data: ['reason' => 'A construtora confirmou o valor publicado.'])
        ->assertHasNoActionErrors()
        ->assertSee('Fatos posteriores à publicação')
        ->assertDontSee('A fonte material continua exatamente igual à que produziu esta versão.');
});

it('tells the validation and the analysis that the round rectifies the published position', function () {
    $scenario = ExtemporaneousFixture::rectifiableJuly();
    $july = $scenario['july'];
    ExtemporaneousFixture::rectify($july, reason: RECTIFICATION_UI_REASON);
    $builderReview = BuilderReviewFixture::open($july->fresh());

    $this->actingAs(makeAdminUser());

    Livewire::test(BuilderReviewWorkspace::class, ['record' => $july->getKey()])
        ->assertOk()
        ->assertSee('Retificação da competência publicada')
        ->assertSee(RECTIFICATION_UI_REASON)
        ->assertSee('O que muda contra a posição publicada:');

    BuilderReviewFixture::confirmAll($builderReview);
    BuilderReviewFixture::submit($builderReview);
    ManagementReviewFixture::open($july->fresh());

    $page = Livewire::test(ManagementReviewWorkspace::class, ['record' => $july->getKey()])
        ->assertOk()
        ->assertSee('Retificação da competência publicada')
        ->assertSee('Quem abriu a retificação não aprova a publicação dela.')
        ->assertSee('O que muda contra a posição publicada:')
        ->assertActionVisible('approve')
        ->assertActionExists('approve', fn (Action $action): bool => $action->getLabel() === 'Aprovar e publicar retificação')
        ->mountAction('approve')
        ->assertMountedActionModalSee('Aprovar a retificação e publicar de novo o Quadro de Vendas')
        ->assertMountedActionModalSee('e que a posição retificada substitui a publicada em')
        ->setActionData(['declaration' => true])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    // O corpo sai da sessão antes de assertNotified, que a esvazia.
    $body = (string) collect(session()->get('filament.claimed_notifications') ?? session()->get('filament.notifications') ?? [])
        ->pluck('body')
        ->implode(' ');

    expect($body)->toContain('Retificação publicada: o Quadro de Vendas')
        ->and($body)->toContain('na competência 07/2026 foi atualizado (retificação 1)');

    $page->assertNotified(SalesBoardApprovalOutcome::Approved->label());

    expect(SalesBoardManagementReview::query()->where('sales_board_cycle_id', $july->id)->latest('id')->first()->approval_declaration_version)
        ->toBe(SalesBoardManagementApprovalService::DECLARATION_VERSION)
        ->and($july->fresh()->status)->toBe(SalesBoardCycleStatus::Approved);
});
