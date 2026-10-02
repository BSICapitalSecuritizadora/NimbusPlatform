<?php

use App\Enums\AccessPermission;
use App\Filament\Resources\Constructions\ConstructionResource;
use App\Filament\Resources\Constructions\Pages\EditConstruction;
use App\Filament\Resources\Constructions\RelationManagers\SalesDiscountPoliciesRelationManager;
use App\Filament\Resources\ConstructionUnits\ConstructionUnitResource;
use App\Filament\Resources\ConstructionUnits\Pages\ViewConstructionUnit;
use App\Filament\Resources\ConstructionUnits\RelationManagers\ConstructionUnitExchangesRelationManager;
use App\Filament\Resources\ConstructionUnits\RelationManagers\ConstructionUnitRetirementsRelationManager;
use App\Filament\Resources\ConstructionUnits\RelationManagers\ConstructionUnitValuesRelationManager;
use App\Filament\Resources\Contracts\ContractResource;
use App\Filament\Resources\Contracts\Pages\ViewContract;
use App\Filament\Resources\Contracts\RelationManagers\ContractInstallmentsRelationManager;
use App\Filament\Resources\SalesBoardCycles\Pages\ViewSalesBoardCycle;
use App\Filament\Resources\SalesBoardCycles\RelationManagers\SalesBoardCycleBaselinesRelationManager;
use App\Filament\Resources\SalesBoardCycles\RelationManagers\SalesBoardCycleBuilderReviewsRelationManager;
use App\Filament\Resources\SalesBoardCycles\RelationManagers\SalesBoardCycleLinesRelationManager;
use App\Filament\Resources\SalesBoardCycles\RelationManagers\SalesBoardCycleMovementsRelationManager;
use App\Filament\Resources\SalesBoardCycles\SalesBoardCycleResource;
use App\Filament\Resources\SalesBoards\Pages\ViewSalesBoard;
use App\Filament\Resources\SalesBoards\RelationManagers\SalesBoardHistoriesRelationManager;
use App\Filament\Resources\SalesBoards\SalesBoardResource;
use App\Filament\Support\GuardsRelationManagerAccess;
use App\Models\Construction;
use App\Models\Contract;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Pages\Dashboard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\ForgedLivewireRequest;
use Tests\Support\SalesBoards\BuilderReviewFixture;
use Tests\Support\SalesBoards\DerivationFixture;
use Tests\Support\SalesBoards\RolloutFixture;

/**
 * Os RelationManagers do Quadro e das fontes dele têm portão próprio: só lê
 * quem pode ver o registro dono pela página que os hospeda.
 *
 * O Filament confere o acesso só no hydrate, e sem policy no model relacionado
 * responde "permitido". Quem perdia a permissão continuava lendo a tabela por
 * três caminhos -- o lazy-load reaproveitado, o replay comum e o lazy-load
 * renderless seguido de `getTableRecords`, que devolvia a paginação inteira
 * sem renderizar. Os testes HTTP passam pela rota real do Livewire, com os
 * middlewares do painel, e cada recusa tem o par que aceita.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * @param  list<string>  $permissions
 */
function relationManagerUser(array $permissions): User
{
    $user = User::factory()->withTwoFactor()->create(['email' => fake()->unique()->safeEmail()]);
    $user->givePermissionTo($permissions);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->fresh();
}

function relationManagerRevoke(User $user, string $permission): User
{
    $user->revokePermissionTo($permission);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->fresh();
}

/**
 * O RelationManager, o registro dono, a página que o hospeda, quem pode vê-lo
 * e a permissão cuja perda fecha a porta.
 *
 * @return array{manager: class-string, owner: Model, page: class-string, permissions: list<string>, revoke: string}
 */
function relationManagerCase(string $case): array
{
    $boardView = [AccessPermission::SalesBoardsView->value, AccessPermission::EmissionsView->value];

    return match ($case) {
        'cycle-lines', 'cycle-movements', 'cycle-baselines', 'cycle-builder-reviews' => [
            'manager' => match ($case) {
                'cycle-lines' => SalesBoardCycleLinesRelationManager::class,
                'cycle-movements' => SalesBoardCycleMovementsRelationManager::class,
                'cycle-baselines' => SalesBoardCycleBaselinesRelationManager::class,
                'cycle-builder-reviews' => SalesBoardCycleBuilderReviewsRelationManager::class,
            },
            'owner' => BuilderReviewFixture::generatedCycle()['cycle'],
            'page' => ViewSalesBoardCycle::class,
            'permissions' => $boardView,
            'revoke' => AccessPermission::SalesBoardsView->value,
        ],
        'board-histories' => [
            'manager' => SalesBoardHistoriesRelationManager::class,
            'owner' => RolloutFixture::legacyBoard(RolloutFixture::emission(1)['constructions'][0]),
            'page' => ViewSalesBoard::class,
            'permissions' => $boardView,
            'revoke' => AccessPermission::SalesBoardsView->value,
        ],
        'unit-exchanges', 'unit-values', 'unit-retirements' => [
            'manager' => match ($case) {
                'unit-exchanges' => ConstructionUnitExchangesRelationManager::class,
                'unit-values' => ConstructionUnitValuesRelationManager::class,
                'unit-retirements' => ConstructionUnitRetirementsRelationManager::class,
            },
            'owner' => DerivationFixture::unit(Construction::factory()->create(), '101'),
            'page' => ViewConstructionUnit::class,
            'permissions' => ['constructions.view'],
            'revoke' => 'constructions.view',
        ],
        'discount-policies' => [
            'manager' => SalesDiscountPoliciesRelationManager::class,
            'owner' => Construction::factory()->create(),
            'page' => EditConstruction::class,
            'permissions' => [AccessPermission::EmissionsView->value],
            'revoke' => AccessPermission::EmissionsView->value,
        ],
        'contract-installments' => [
            'manager' => ContractInstallmentsRelationManager::class,
            'owner' => Contract::factory()->create(),
            'page' => ViewContract::class,
            'permissions' => ['contracts.view', 'contract-installments.view'],
            'revoke' => 'contracts.view',
        ],
    };
}

/**
 * Quem vê a competência, com o placeholder das unidades dela na mão.
 *
 * @return array{viewer: User, snapshot: string, lazy: string, contract: string}
 */
function relationManagerLazyLines(): array
{
    $scenario = BuilderReviewFixture::generatedCycle();
    $viewer = relationManagerUser([AccessPermission::SalesBoardsView->value, AccessPermission::EmissionsView->value]);

    test()->actingAs($viewer);

    $html = test()->get(ViewSalesBoardCycle::getUrl(['record' => $scenario['cycle']]))->assertOk()->getContent();

    [$snapshot, $lazy] = ForgedLivewireRequest::lazySnapshotOf($html, SalesBoardCycleLinesRelationManager::class);

    return [
        'viewer' => $viewer,
        'snapshot' => $snapshot,
        'lazy' => $lazy,
        'contract' => (string) $scenario['contracts']['financed']->code,
    ];
}

it('guards every relation manager of the Sales Board and of its sources', function () {
    $managers = collect([
        SalesBoardCycleResource::class,
        SalesBoardResource::class,
        ConstructionUnitResource::class,
        ConstructionResource::class,
        ContractResource::class,
    ])->flatMap(fn (string $resource): array => $resource::getRelations())->values();

    // A âncora impede que uma varredura vazia deixe a regra verde sem conferir nada.
    expect($managers->all())->toContain(
        SalesBoardCycleLinesRelationManager::class,
        SalesBoardCycleMovementsRelationManager::class,
        SalesBoardCycleBaselinesRelationManager::class,
        SalesBoardCycleBuilderReviewsRelationManager::class,
        SalesBoardHistoriesRelationManager::class,
        ConstructionUnitExchangesRelationManager::class,
        ConstructionUnitValuesRelationManager::class,
        ConstructionUnitRetirementsRelationManager::class,
        SalesDiscountPoliciesRelationManager::class,
        ContractInstallmentsRelationManager::class,
    );

    expect($managers->reject(fn (string $manager): bool => in_array(GuardsRelationManagerAccess::class, class_uses_recursive($manager), true))->all())
        ->toBe([]);
});

it('refuses a relation manager to whoever can no longer view the owner record', function (string $case) {
    $scenario = relationManagerCase($case);
    $user = relationManagerUser($scenario['permissions']);
    $this->actingAs($user);

    $parameters = ['ownerRecord' => $scenario['owner'], 'pageClass' => $scenario['page']];

    expect($scenario['manager']::canViewForRecord($scenario['owner'], $scenario['page']))->toBeTrue();

    Livewire::test($scenario['manager'], $parameters)->assertOk();

    $this->actingAs(relationManagerRevoke($user, $scenario['revoke']));

    expect($scenario['manager']::canViewForRecord($scenario['owner'], $scenario['page']))->toBeFalse();

    Livewire::test($scenario['manager'], $parameters)->assertForbidden();
})->with([
    'cycle-lines',
    'cycle-movements',
    'cycle-baselines',
    'cycle-builder-reviews',
    'board-histories',
    'unit-exchanges',
    'unit-values',
    'unit-retirements',
    'discount-policies',
    'contract-installments',
]);

it('keeps the installment permission in force inside the contract', function () {
    $contract = Contract::factory()->create();
    $parameters = ['ownerRecord' => $contract, 'pageClass' => ViewContract::class];

    $this->actingAs(relationManagerUser(['contracts.view']));

    expect(ContractInstallmentsRelationManager::canViewForRecord($contract, ViewContract::class))->toBeFalse();

    Livewire::test(ContractInstallmentsRelationManager::class, $parameters)->assertForbidden();

    $this->actingAs(relationManagerUser(['contracts.view', 'contract-installments.view']));

    expect(ContractInstallmentsRelationManager::canViewForRecord($contract, ViewContract::class))->toBeTrue();

    Livewire::test(ContractInstallmentsRelationManager::class, $parameters)->assertOk();
});

it('fails closed when the host is not a resource page', function () {
    $cycle = BuilderReviewFixture::generatedCycle()['cycle'];
    $this->actingAs(makeAdminUser());

    expect(SalesBoardCycleLinesRelationManager::canViewForRecord($cycle, ViewSalesBoardCycle::class))->toBeTrue()
        ->and(SalesBoardCycleLinesRelationManager::canViewForRecord($cycle, Dashboard::class))->toBeFalse()
        ->and(SalesBoardCycleLinesRelationManager::canViewForRecord($cycle, 'App\\Filament\\Pages\\NaoExiste'))->toBeFalse();

    Livewire::test(SalesBoardCycleLinesRelationManager::class, ['ownerRecord' => $cycle, 'pageClass' => Dashboard::class])
        ->assertForbidden();
});

it('loads the cycle units lazily for whoever can still see the board', function () {
    $lines = relationManagerLazyLines();

    $response = ForgedLivewireRequest::post($this, $lines['snapshot'], [
        ForgedLivewireRequest::call('__lazyLoad', [$lines['lazy']]),
    ])->assertOk();

    expect(ForgedLivewireRequest::renderedText($response))->toContain($lines['contract']);
});

it('refuses a replayed lazy load of the cycle units after sales-boards.view is revoked', function () {
    $lines = relationManagerLazyLines();

    $this->actingAs(relationManagerRevoke($lines['viewer'], AccessPermission::SalesBoardsView->value));

    $response = ForgedLivewireRequest::post($this, $lines['snapshot'], [
        ForgedLivewireRequest::call('__lazyLoad', [$lines['lazy']]),
    ]);

    $response->assertForbidden();

    expect($response->getContent())->not->toContain($lines['contract']);
});

it('refuses a renderless lazy load that asks for the table records', function () {
    $lines = relationManagerLazyLines();
    $calls = [
        ForgedLivewireRequest::call('__lazyLoad', [$lines['lazy']], renderless: true),
        ForgedLivewireRequest::call('getTableRecords', renderless: true),
    ];

    // O par: quem ainda vê o quadro recebe as linhas por este mesmo caminho,
    // sem nada renderizado -- é o vetor que a revogação precisa fechar.
    $allowed = ForgedLivewireRequest::post($this, $lines['snapshot'], $calls)->assertOk();

    expect(json_encode($allowed->json('components.0.effects.returns')))->toContain($lines['contract']);

    $this->actingAs(relationManagerRevoke($lines['viewer'], AccessPermission::SalesBoardsView->value));

    $refused = ForgedLivewireRequest::post($this, $lines['snapshot'], $calls);

    $refused->assertForbidden();

    expect($refused->json('components.0.effects.returns'))->toBeNull()
        ->and($refused->getContent())->not->toContain($lines['contract']);
});

it('refuses a replayed request of the loaded cycle units after the revocation', function () {
    $lines = relationManagerLazyLines();

    $loaded = ForgedLivewireRequest::post($this, $lines['snapshot'], [
        ForgedLivewireRequest::call('__lazyLoad', [$lines['lazy']]),
    ])->assertOk();

    expect(ForgedLivewireRequest::renderedText($loaded))->toContain($lines['contract']);

    $this->actingAs(relationManagerRevoke($lines['viewer'], AccessPermission::SalesBoardsView->value));

    $replay = ForgedLivewireRequest::post($this, ForgedLivewireRequest::nextSnapshot($loaded), [
        ForgedLivewireRequest::call('$refresh'),
    ]);

    $replay->assertForbidden();

    expect($replay->getContent())->not->toContain($lines['contract']);
});
