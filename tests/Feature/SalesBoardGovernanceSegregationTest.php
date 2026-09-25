<?php

use App\Enums\AccessPermission;
use App\Enums\SalesBoardApprovalOutcome;
use App\Enums\SalesBoardManagementReviewStatus;
use App\Enums\SalesBoardNonconformityDecision;
use App\Enums\SalesBoardRolloutHomologationStatus;
use App\Exceptions\SalesBoardMakerCheckerException;
use App\Filament\Resources\SalesBoardCycles\Pages\ManagementReviewWorkspace;
use App\Filament\Resources\SalesBoardCycles\SalesBoardCycleResource;
use App\Filament\Resources\SalesBoardRollouts\Pages\ManageSalesBoardRollout;
use App\Filament\Resources\SalesBoardRollouts\SalesBoardRolloutResource;
use App\Models\Emission;
use App\Models\SalesBoard;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardManagementReview;
use App\Models\SalesBoardPublication;
use App\Models\SalesBoardRolloutHomologation;
use App\Models\User;
use App\Services\SalesBoards\SalesBoardManagementDecisionService;
use App\Services\SalesBoards\SalesBoardRolloutHomologationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\SalesBoards\BuilderReviewFixture;
use Tests\Support\SalesBoards\GovernanceFixture;
use Tests\Support\SalesBoards\ManagementReviewFixture;
use Tests\Support\SalesBoards\RolloutFixture;

/**
 * Segregação de funções do Quadro de Vendas (decisão do dono, 2026-09-25).
 *
 * `sales-boards.update` opera a competência; `sales-boards.approve` -- só da
 * Gestão (admin, super-admin), nunca do editor -- decide, devolve, aprova,
 * publica, atesta, aprova a homologação, ativa e retorna ao legado. E, mesmo
 * com a permissão, quem preparou não conclui: quem enviou a validação da
 * construtora não aprova aquela rodada, e quem abriu a homologação não a aprova
 * nem ativa. Super admin é isento, como no PU.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

function segregationUserWithRole(string $role): User
{
    $user = User::factory()->withTwoFactor()->create();
    $user->assignRole($role);

    return $user;
}

/**
 * Uma análise da Gestão aberta sobre uma validação enviada por `$submitter`,
 * com a venda do mês abaixo do mínimo esperando decisão.
 *
 * @return array{cycle: SalesBoardCycle, review: SalesBoardManagementReview}
 */
function segregationManagementReview(User $submitter): array
{
    $scenario = BuilderReviewFixture::generatedCycleWithNonConformSale();

    $builderReview = BuilderReviewFixture::open($scenario['cycle'], $submitter);
    BuilderReviewFixture::confirmAll($builderReview);
    BuilderReviewFixture::submit($builderReview, $submitter);

    return [
        'cycle' => $scenario['cycle'],
        'review' => ManagementReviewFixture::open($scenario['cycle'], $submitter),
    ];
}

/**
 * Uma homologação aberta por `$opener`, com responsáveis definidos e impactos
 * atestados pela Gestão: o portão está verde e só falta aprovar.
 *
 * @return array{emission: Emission, homologation: SalesBoardRolloutHomologation}
 */
function segregationReadyHomologation(User $opener): array
{
    $scenario = RolloutFixture::emission(1);
    RolloutFixture::legacyBoard($scenario['constructions'][0]);

    $homologation = RolloutFixture::open($scenario['emission'], $opener);
    RolloutFixture::recipients($scenario['emission'], $opener);
    RolloutFixture::reviewImpacts($homologation->fresh());

    return ['emission' => $scenario['emission'], 'homologation' => $homologation->fresh()];
}

/*
|--------------------------------------------------------------------------
| A permissão
|--------------------------------------------------------------------------
*/

it('grants sales-boards.approve to admin and super-admin, never to the editor', function () {
    foreach (['super-admin' => true, 'admin' => true, 'editor' => false, 'commercial-representative' => false] as $role => $expected) {
        expect([$role => Role::findByName($role)->hasPermissionTo(AccessPermission::SalesBoardsApprove->value)])
            ->toBe([$role => $expected]);
    }

    $editor = segregationUserWithRole('editor');
    $admin = segregationUserWithRole('admin');

    $this->actingAs($editor);
    expect(SalesBoardCycleResource::canRecalculate())->toBeTrue()
        ->and(SalesBoardCycleResource::canApprove())->toBeFalse()
        ->and(SalesBoardRolloutResource::canManageRollout())->toBeTrue()
        ->and(SalesBoardRolloutResource::canApproveRollout())->toBeFalse();

    $this->actingAs($admin);
    expect(SalesBoardCycleResource::canApprove())->toBeTrue()
        ->and(SalesBoardRolloutResource::canApproveRollout())->toBeTrue();
});

it('keeps the approve grant stable when the seeder runs again', function () {
    Role::findByName('editor')->givePermissionTo(AccessPermission::SalesBoardsApprove->value);

    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(RolesAndPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(Role::findByName('admin')->hasPermissionTo(AccessPermission::SalesBoardsApprove->value))->toBeTrue()
        ->and(Role::findByName('super-admin')->hasPermissionTo(AccessPermission::SalesBoardsApprove->value))->toBeTrue()
        ->and(Role::findByName('editor')->hasPermissionTo(AccessPermission::SalesBoardsApprove->value))->toBeFalse();
});

it('creates the permission and grants it only to the administrative roles when migrating an existing database', function () {
    Permission::query()->where('name', AccessPermission::SalesBoardsApprove->value)->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $migration = require database_path('migrations/2026_09_25_141236_grant_sales_boards_approve_permission.php');
    $migration->up();
    $migration->up();

    expect(Permission::query()->where('name', AccessPermission::SalesBoardsApprove->value)->count())->toBe(1)
        ->and(Role::findByName('admin')->hasPermissionTo(AccessPermission::SalesBoardsApprove->value))->toBeTrue()
        ->and(Role::findByName('super-admin')->hasPermissionTo(AccessPermission::SalesBoardsApprove->value))->toBeTrue()
        ->and(Role::findByName('editor')->hasPermissionTo(AccessPermission::SalesBoardsApprove->value))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Análise da Gestão -- serviços
|--------------------------------------------------------------------------
*/

it('refuses decisions, returns and approvals from the operational editor', function () {
    $editor = segregationUserWithRole('editor');
    $scenario = segregationManagementReview(GovernanceFixture::operator());
    $item = $scenario['review']->fresh()->nonconformities->sole();

    expect(fn () => ManagementReviewFixture::decide($item, SalesBoardNonconformityDecision::AcceptedException, actor: $editor))
        ->toThrow(AuthorizationException::class);

    expect($item->fresh()->decision)->toBe(SalesBoardNonconformityDecision::Pending);

    expect(fn () => ManagementReviewFixture::returnToBuilder($scenario['review'], $editor))
        ->toThrow(AuthorizationException::class);

    ManagementReviewFixture::decideAll($scenario['review']);

    expect(fn () => ManagementReviewFixture::approve($scenario['review'], $editor))
        ->toThrow(AuthorizationException::class);

    expect($scenario['review']->fresh()->status)->toBe(SalesBoardManagementReviewStatus::Draft)
        ->and(SalesBoardPublication::query()->count())->toBe(0)
        ->and(SalesBoard::query()->count())->toBe(0);
});

it('refuses a decision without an identified actor', function () {
    $scenario = segregationManagementReview(GovernanceFixture::operator());
    $item = $scenario['review']->fresh()->nonconformities->sole();

    expect(fn () => app(SalesBoardManagementDecisionService::class)
        ->decide($item, SalesBoardNonconformityDecision::AcceptedException, ManagementReviewFixture::REASON))
        ->toThrow(AuthorizationException::class);

    expect($item->fresh()->decision)->toBe(SalesBoardNonconformityDecision::Pending);
});

it('refuses the approval by whoever submitted the builder validation, even an admin', function () {
    $admin = segregationUserWithRole('admin');
    $scenario = segregationManagementReview($admin);

    ManagementReviewFixture::decideAll($scenario['review'], $admin);

    expect(fn () => ManagementReviewFixture::approve($scenario['review'], $admin))
        ->toThrow(SalesBoardMakerCheckerException::class, 'quem enviou a validação da construtora');

    expect($scenario['review']->fresh()->status)->toBe(SalesBoardManagementReviewStatus::Draft)
        ->and(SalesBoardPublication::query()->count())->toBe(0);

    $result = ManagementReviewFixture::approve($scenario['review'], segregationUserWithRole('admin'));

    expect($result->outcome)->toBe(SalesBoardApprovalOutcome::Approved)
        ->and(SalesBoard::query()->count())->toBe(1);
});

it('lets a super-admin approve a round they submitted themselves', function () {
    $superAdmin = GovernanceFixture::superAdmin();
    $scenario = segregationManagementReview($superAdmin);

    ManagementReviewFixture::decideAll($scenario['review'], $superAdmin);

    $result = ManagementReviewFixture::approve($scenario['review'], $superAdmin);

    expect($result->outcome)->toBe(SalesBoardApprovalOutcome::Approved)
        ->and($result->review->approved_by_user_id)->toBe($superAdmin->getKey());
});

/*
|--------------------------------------------------------------------------
| Análise da Gestão -- tela
|--------------------------------------------------------------------------
*/

it('hides every Gestão action from the editor and ignores forged calls', function () {
    $scenario = segregationManagementReview(GovernanceFixture::operator());
    $item = $scenario['review']->fresh()->nonconformities->sole();

    $this->actingAs(segregationUserWithRole('editor'));

    $page = Livewire::test(ManagementReviewWorkspace::class, ['record' => $scenario['cycle']->getKey()])
        ->assertOk()
        ->assertActionHidden('decide', ['nonconformity' => $item->getKey()])
        ->assertActionHidden('resetDecision', ['nonconformity' => $item->getKey()])
        ->assertActionHidden('returnToBuilder')
        ->assertActionHidden('approve')
        ->assertSee('Decidir, devolver e publicar são da Gestão');

    expect($page->instance()->canDecide())->toBeFalse();

    $page->call('mountAction', 'decide', ['nonconformity' => $item->getKey()])
        ->assertSet('mountedActions', [])
        ->call('mountAction', 'returnToBuilder')
        ->assertSet('mountedActions', []);

    expect($item->fresh()->decision)->toBe(SalesBoardNonconformityDecision::Pending)
        ->and($scenario['review']->fresh()->status)->toBe(SalesBoardManagementReviewStatus::Draft);
});

it('lets an admin decide and publish a round someone else submitted', function () {
    $scenario = segregationManagementReview(GovernanceFixture::operator());
    $item = $scenario['review']->fresh()->nonconformities->sole();
    $admin = segregationUserWithRole('admin');

    $this->actingAs($admin);

    Livewire::test(ManagementReviewWorkspace::class, ['record' => $scenario['cycle']->getKey()])
        ->assertDontSee('Decidir, devolver e publicar são da Gestão')
        ->callAction('decide', arguments: ['nonconformity' => $item->getKey()], data: [
            'decision' => SalesBoardNonconformityDecision::AcceptedException->value,
            'decision_reason' => ManagementReviewFixture::REASON,
        ])
        ->assertHasNoActionErrors();

    Livewire::test(ManagementReviewWorkspace::class, ['record' => $scenario['cycle']->getKey()])
        ->assertActionEnabled('approve')
        ->callAction('approve', data: ['declaration' => true])
        ->assertHasNoActionErrors();

    expect($scenario['review']->fresh()->status)->toBe(SalesBoardManagementReviewStatus::Approved)
        ->and($scenario['review']->fresh()->approved_by_user_id)->toBe($admin->getKey())
        ->and($item->fresh()->decided_by_user_id)->toBe($admin->getKey());
});

it('shows the approval disabled, with the reason, to the admin who submitted the round', function () {
    $admin = segregationUserWithRole('admin');
    $scenario = segregationManagementReview($admin);
    ManagementReviewFixture::decideAll($scenario['review']);

    $this->actingAs($admin);

    $page = Livewire::test(ManagementReviewWorkspace::class, ['record' => $scenario['cycle']->getKey()])
        ->assertActionVisible('approve')
        ->assertActionDisabled('approve');

    expect($page->instance()->approvalConflict())
        ->toBe(SalesBoardMakerCheckerException::approverSubmittedBuilderReview()->getMessage());

    $page->call('mountAction', 'approve')->assertSet('mountedActions', []);

    expect($scenario['review']->fresh()->status)->toBe(SalesBoardManagementReviewStatus::Draft)
        ->and(SalesBoardPublication::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Rollout -- serviços
|--------------------------------------------------------------------------
*/

it('refuses attestation, approval, activation and return to legacy from the editor', function () {
    $editor = segregationUserWithRole('editor');
    $scenario = RolloutFixture::emission(1);
    RolloutFixture::legacyBoard($scenario['constructions'][0]);

    $homologation = RolloutFixture::open($scenario['emission'], $editor);
    RolloutFixture::recipients($scenario['emission'], $editor);

    $service = app(SalesBoardRolloutHomologationService::class);

    expect(fn () => $service->markGuaranteesReviewed($homologation, $editor))->toThrow(AuthorizationException::class)
        ->and(fn () => $service->markMonthlyReportReviewed($homologation, $editor))->toThrow(AuthorizationException::class);

    RolloutFixture::reviewImpacts($homologation->fresh());

    expect(fn () => RolloutFixture::approve($homologation, $editor))->toThrow(AuthorizationException::class);
    expect($homologation->fresh()->status)->toBe(SalesBoardRolloutHomologationStatus::Draft);

    $approved = RolloutFixture::approve($homologation);

    expect(fn () => RolloutFixture::activate($scenario['emission'], $approved, $editor))->toThrow(AuthorizationException::class);
    expect($scenario['emission']->fresh()->usesAutomatedSalesBoard())->toBeFalse();

    RolloutFixture::activate($scenario['emission'], $approved);

    expect(fn () => RolloutFixture::returnToLegacy($scenario['emission'], $editor))->toThrow(AuthorizationException::class);
    expect($scenario['emission']->fresh()->usesAutomatedSalesBoard())->toBeTrue();
});

it('refuses approval and activation by whoever opened the homologation, even an admin', function () {
    $admin = segregationUserWithRole('admin');
    $scenario = segregationReadyHomologation($admin);

    expect(fn () => RolloutFixture::approve($scenario['homologation'], $admin))
        ->toThrow(SalesBoardMakerCheckerException::class, 'quem abriu a homologação');
    expect($scenario['homologation']->fresh()->status)->toBe(SalesBoardRolloutHomologationStatus::Draft);

    $approved = RolloutFixture::approve($scenario['homologation'], segregationUserWithRole('admin'));

    expect(fn () => RolloutFixture::activate($scenario['emission'], $approved, $admin))
        ->toThrow(SalesBoardMakerCheckerException::class, 'quem abriu a homologação');
    expect($scenario['emission']->fresh()->usesAutomatedSalesBoard())->toBeFalse();

    RolloutFixture::activate($scenario['emission'], $approved, segregationUserWithRole('admin'));

    expect($scenario['emission']->fresh()->usesAutomatedSalesBoard())->toBeTrue();
});

it('lets a super-admin approve and activate a homologation they opened', function () {
    $superAdmin = GovernanceFixture::superAdmin();
    $scenario = segregationReadyHomologation($superAdmin);

    $approved = RolloutFixture::approve($scenario['homologation'], $superAdmin);
    RolloutFixture::activate($scenario['emission'], $approved, $superAdmin);

    expect($approved->approved_by_user_id)->toBe($superAdmin->getKey())
        ->and($scenario['emission']->fresh()->usesAutomatedSalesBoard())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Rollout -- tela
|--------------------------------------------------------------------------
*/

it('lets the editor prepare the homologation but not conclude it', function () {
    $editor = segregationUserWithRole('editor');
    $scenario = segregationReadyHomologation($editor);
    $id = $scenario['emission']->getKey();

    $this->actingAs($editor);

    Livewire::test(ManageSalesBoardRollout::class, ['record' => $id])
        ->assertOk()
        ->assertActionVisible('reassess')
        ->assertActionVisible('reject')
        ->assertActionHidden('markGuaranteesReviewed')
        ->assertActionHidden('markMonthlyReportReviewed')
        ->assertActionHidden('approve')
        ->assertSee('Aprovar e ativar são da Gestão')
        ->call('mountAction', 'approve')
        ->assertSet('mountedActions', [])
        ->call('mountAction', 'markGuaranteesReviewed')
        ->assertSet('mountedActions', []);

    expect($scenario['homologation']->fresh()->status)->toBe(SalesBoardRolloutHomologationStatus::Draft);

    RolloutFixture::approve($scenario['homologation']);

    Livewire::test(ManageSalesBoardRollout::class, ['record' => $id])
        ->assertActionHidden('activate')
        ->call('mountAction', 'activate')
        ->assertSet('mountedActions', []);

    expect($scenario['emission']->fresh()->usesAutomatedSalesBoard())->toBeFalse();

    RolloutFixture::activate($scenario['emission'], $scenario['homologation']);

    Livewire::test(ManageSalesBoardRollout::class, ['record' => $id])
        ->assertActionHidden('returnToLegacy')
        ->call('mountAction', 'returnToLegacy')
        ->assertSet('mountedActions', []);

    expect($scenario['emission']->fresh()->usesAutomatedSalesBoard())->toBeTrue();
});

it('shows approval and activation disabled, with the reason, to the admin who opened the homologation', function () {
    $admin = segregationUserWithRole('admin');
    $scenario = segregationReadyHomologation($admin);
    $id = $scenario['emission']->getKey();

    $this->actingAs($admin);

    $page = Livewire::test(ManageSalesBoardRollout::class, ['record' => $id])
        ->assertActionVisible('approve')
        ->assertActionDisabled('approve')
        ->call('mountAction', 'approve')
        ->assertSet('mountedActions', []);

    expect($page->instance()->getAction('approve', isMounting: false)->getTooltip())
        ->toBe(SalesBoardMakerCheckerException::approverOpenedHomologation()->getMessage())
        ->and($scenario['homologation']->fresh()->status)->toBe(SalesBoardRolloutHomologationStatus::Draft);

    RolloutFixture::approve($scenario['homologation']);

    $page = Livewire::test(ManageSalesBoardRollout::class, ['record' => $id])
        ->assertActionVisible('activate')
        ->assertActionDisabled('activate')
        ->call('mountAction', 'activate')
        ->assertSet('mountedActions', []);

    expect($page->instance()->getAction('activate', isMounting: false)->getTooltip())
        ->toBe(SalesBoardMakerCheckerException::activatorOpenedHomologation()->getMessage())
        ->and($scenario['emission']->fresh()->usesAutomatedSalesBoard())->toBeFalse();
});

it('lets another admin attest, approve and activate through the screen', function () {
    $scenario = RolloutFixture::emission(1);
    RolloutFixture::legacyBoard($scenario['constructions'][0]);
    $homologation = RolloutFixture::open($scenario['emission'], GovernanceFixture::operator());
    RolloutFixture::recipients($scenario['emission']);
    $id = $scenario['emission']->getKey();
    $admin = segregationUserWithRole('admin');

    $this->actingAs($admin);

    Livewire::test(ManageSalesBoardRollout::class, ['record' => $id])
        ->callAction('markGuaranteesReviewed')
        ->callAction('markMonthlyReportReviewed')
        ->assertHasNoActionErrors();

    Livewire::test(ManageSalesBoardRollout::class, ['record' => $id])
        ->assertActionEnabled('approve')
        ->callAction('approve', data: ['reason' => RolloutFixture::REASON])
        ->assertHasNoActionErrors();

    Livewire::test(ManageSalesBoardRollout::class, ['record' => $id])
        ->assertActionEnabled('activate')
        ->callAction('activate', data: ['reason' => 'Ativação acordada com a operação.'])
        ->assertHasNoActionErrors();

    $homologation->refresh();

    expect($homologation->guarantees_reviewed_by_user_id)->toBe($admin->getKey())
        ->and($homologation->approved_by_user_id)->toBe($admin->getKey())
        ->and($scenario['emission']->fresh()->usesAutomatedSalesBoard())->toBeTrue();
});
