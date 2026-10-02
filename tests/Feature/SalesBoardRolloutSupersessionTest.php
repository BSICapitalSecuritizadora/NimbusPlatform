<?php

use App\Enums\AccessPermission;
use App\Enums\SalesBoardRolloutHomologationStatus;
use App\Enums\SalesBoardRolloutRecipientRole;
use App\Enums\SalesBoardRolloutSupersessionReason;
use App\Enums\SalesBoardSource;
use App\Exceptions\SalesBoardRolloutException;
use App\Filament\Resources\SalesBoardRollouts\Pages\ListSalesBoardRollouts;
use App\Filament\Resources\SalesBoardRollouts\Pages\ManageSalesBoardRollout;
use App\Models\ConstructionUnit;
use App\Models\ConstructionUnitValue;
use App\Models\Contract;
use App\Models\ContractInstallment;
use App\Models\Emission;
use App\Models\SalesBoardRolloutHomologation;
use App\Models\SalesBoardRolloutRecipient;
use App\Models\User;
use App\Services\SalesBoards\SalesBoardRolloutHomologationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\SalesBoards\DerivationFixture;
use Tests\Support\SalesBoards\GovernanceFixture;
use Tests\Support\SalesBoards\RolloutFixture;

/**
 * Uma homologação aprovada que deixou de valer é gravada como substituída.
 *
 * Três momentos gravam a substituição: a ativação recusada por fonte ou escopo,
 * a conferência "Conferir se ainda vale" e a abertura de uma tentativa nova.
 * Quadro manual e responsável ausente continuam sendo recusas do momento, sem
 * substituição -- os dois se corrigem sem homologar de novo. O retrato aprovado
 * nunca é reescrito: só status, data e motivo da substituição mudam.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * Uma Emissão com um empreendimento coincidente com o legado e outro com uma
 * venda e uma tabela de preço -- as fontes que os casos alteram.
 *
 * @return array{emission: Emission, homologation: SalesBoardRolloutHomologation, contract: Contract, installment: ContractInstallment, unitValue: ConstructionUnitValue}
 */
function supersessionScenario(): array
{
    $scenario = RolloutFixture::emission(2);
    [$matched, $selling] = $scenario['constructions'];

    RolloutFixture::legacyBoard($matched);

    $soldUnit = DerivationFixture::unit($selling, 'B90');
    $contract = DerivationFixture::contract($soldUnit, '2026-07-15', '480000.00');
    $installment = DerivationFixture::installment($contract, '001', '2026-08-15', '480000.00');

    $unitValue = ConstructionUnitValue::factory()
        ->forUnit(ConstructionUnit::query()->where('construction_id', $selling->id)->orderBy('id')->firstOrFail())
        ->effectiveFrom('2026-01-01')
        ->worth('500000.00')
        ->create();

    RolloutFixture::recipients($scenario['emission']);

    return [
        'emission' => $scenario['emission'],
        'homologation' => approvedSupersessionAttempt($scenario['emission']),
        'contract' => $contract,
        'installment' => $installment,
        'unitValue' => $unitValue,
    ];
}

/**
 * Abre, analisa as diferenças, atesta e aprova uma tentativa, cada ato com
 * quem tem a permissão dele.
 */
function approvedSupersessionAttempt(Emission $emission): SalesBoardRolloutHomologation
{
    return approveSupersessionAttempt(RolloutFixture::open($emission, GovernanceFixture::operator()));
}

function approveSupersessionAttempt(SalesBoardRolloutHomologation $homologation): SalesBoardRolloutHomologation
{
    $operator = GovernanceFixture::operator();
    $approver = GovernanceFixture::approver();
    $service = app(SalesBoardRolloutHomologationService::class);

    foreach ($homologation->fresh()->constructions as $row) {
        if ($row->requiresAcknowledgement()) {
            $service->acceptDifference($row, 'Diferença entendida com a operação antes do rollout.', $operator);
        }
    }

    RolloutFixture::reviewImpacts($homologation->fresh(), $approver);

    return RolloutFixture::approve($homologation, $approver);
}

/**
 * As fontes que a homologação revisou, alteradas depois da aprovação. Por nome,
 * e não por closure no dataset: a closure tipada nunca rodaria.
 *
 * @param  array{emission: Emission, homologation: SalesBoardRolloutHomologation, contract: Contract, installment: ContractInstallment, unitValue: ConstructionUnitValue}  $scenario
 */
function changeSupersessionSource(string $change, array $scenario): void
{
    match ($change) {
        'contract sale value' => $scenario['contract']->update(['sale_value' => '470000.00']),
        'installment payment' => $scenario['installment']->update(['payment_date' => '2026-07-20', 'paid_value' => '480000.00']),
        'unit value' => $scenario['unitValue']->update(['value' => '550000.00']),
    };
}

/**
 * O retrato aprovado: tudo o que a substituição não pode mexer.
 *
 * @return array<string, mixed>
 */
function approvedPicture(SalesBoardRolloutHomologation $homologation): array
{
    $fresh = $homologation->fresh();

    return [
        'assessment_hash' => $fresh->assessment_hash,
        'construction_scope_hash' => $fresh->construction_scope_hash,
        'approved_at' => $fresh->approved_at?->toIso8601String(),
        'approved_by_user_id' => $fresh->approved_by_user_id,
        'rows' => $fresh->constructions()->orderBy('id')->get()
            ->map(fn ($row): array => [$row->id, $row->snapshot_fingerprint, $row->source_fingerprint, $row->accepted_difference])
            ->all(),
    ];
}

it('marks the approved homologation as superseded when activation finds the source changed', function (string $change) {
    $scenario = supersessionScenario();
    $homologation = $scenario['homologation'];
    $picture = approvedPicture($homologation);

    changeSupersessionSource($change, $scenario);

    expect(fn () => RolloutFixture::activate($scenario['emission'], $homologation))
        ->toThrow(SalesBoardRolloutException::class, SalesBoardRolloutException::homologationStale()->getMessage());

    $fresh = $homologation->fresh();

    expect($fresh->status)->toBe(SalesBoardRolloutHomologationStatus::Superseded)
        ->and($fresh->supersessionReason())->toBe(SalesBoardRolloutSupersessionReason::SourceChanged)
        ->and($fresh->superseded_at)->not->toBeNull()
        ->and($fresh->activated_at)->toBeNull()
        ->and(approvedPicture($homologation))->toBe($picture)
        ->and($scenario['emission']->fresh()->sales_board_source)->toBe(SalesBoardSource::Legacy);
})->with([
    'contract sale value',
    'installment payment',
    'unit value',
]);

it('marks it superseded with the scope reason when a construction joined the emission', function () {
    $scenario = supersessionScenario();
    $homologation = $scenario['homologation'];
    $picture = approvedPicture($homologation);

    RolloutFixture::construction($scenario['emission'], 'Z');

    expect(fn () => RolloutFixture::activate($scenario['emission'], $homologation))
        ->toThrow(SalesBoardRolloutException::class, SalesBoardRolloutException::scopeChanged()->getMessage());

    expect($homologation->fresh()->status)->toBe(SalesBoardRolloutHomologationStatus::Superseded)
        ->and($homologation->fresh()->supersessionReason())->toBe(SalesBoardRolloutSupersessionReason::ScopeChanged)
        ->and(approvedPicture($homologation))->toBe($picture)
        ->and($scenario['emission']->fresh()->sales_board_source)->toBe(SalesBoardSource::Legacy);
});

it('keeps it approved when activation is refused only by a manual board conflict or missing recipients', function (string $refusal) {
    $scenario = supersessionScenario();
    $homologation = $scenario['homologation'];
    $picture = approvedPicture($homologation);

    match ($refusal) {
        'manual board' => RolloutFixture::legacyBoard(
            $homologation->emission->constructions()->orderBy('id')->firstOrFail(),
            RolloutFixture::START_MONTH,
        ),
        'missing recipient' => SalesBoardRolloutRecipient::query()
            ->where('emission_id', $scenario['emission']->id)
            ->forRole(SalesBoardRolloutRecipientRole::Operational)
            ->sole()
            ->user
            ->update(['is_active' => false]),
    };

    expect(fn () => RolloutFixture::activate($scenario['emission'], $homologation))
        ->toThrow(SalesBoardRolloutException::class);

    expect($homologation->fresh()->status)->toBe(SalesBoardRolloutHomologationStatus::Approved)
        ->and($homologation->fresh()->superseded_reason)->toBeNull()
        ->and(approvedPicture($homologation))->toBe($picture);

    // O par: a mesma mecânica substitui quando o que mudou foi a fonte.
    changeSupersessionSource('contract sale value', $scenario);

    expect(app(SalesBoardRolloutHomologationService::class)->supersedeIfOutdated($homologation, GovernanceFixture::approver()))
        ->toBe(SalesBoardRolloutSupersessionReason::SourceChanged);
})->with([
    'manual board',
    'missing recipient',
]);

it('supersedes the previous approved attempt when a new homologation is opened', function () {
    $scenario = supersessionScenario();
    $first = $scenario['homologation'];
    $picture = approvedPicture($first);

    // O modal avisa antes de abrir.
    $this->actingAs(makeAdminUser());

    Livewire::test(ManageSalesBoardRollout::class, ['record' => $scenario['emission']->getKey()])
        ->assertActionVisible('openHomologation')
        ->mountAction('openHomologation')
        ->assertMountedActionModalSee('A homologação 1, aprovada e ainda não usada, será marcada como substituída.');

    $second = RolloutFixture::open($scenario['emission']);

    expect($second->attempt)->toBe(2)
        ->and($first->fresh()->status)->toBe(SalesBoardRolloutHomologationStatus::Superseded)
        ->and($first->fresh()->supersessionReason())->toBe(SalesBoardRolloutSupersessionReason::NewAttemptOpened)
        ->and(approvedPicture($first))->toBe($picture);

    // A tentativa que sustentou uma ativação nunca é tocada.
    $approvedSecond = approveSupersessionAttempt($second);

    RolloutFixture::activate($scenario['emission'], $approvedSecond);
    RolloutFixture::returnToLegacy($scenario['emission']);

    $third = RolloutFixture::open($scenario['emission'], null, '2026-10-01');

    expect($third->attempt)->toBe(3)
        ->and($approvedSecond->fresh()->status)->toBe(SalesBoardRolloutHomologationStatus::Approved)
        ->and($approvedSecond->fresh()->activated_at)->not->toBeNull()
        ->and($approvedSecond->fresh()->superseded_reason)->toBeNull();
});

it('checks validity on demand and supersedes only when outdated', function () {
    $scenario = supersessionScenario();
    $homologation = $scenario['homologation'];
    $service = app(SalesBoardRolloutHomologationService::class);
    $picture = approvedPicture($homologation);

    expect($service->supersedeIfOutdated($homologation, GovernanceFixture::operator()))->toBeNull()
        ->and($homologation->fresh()->status)->toBe(SalesBoardRolloutHomologationStatus::Approved)
        ->and($homologation->fresh()->updated_at->toIso8601String())->toBe($homologation->updated_at->toIso8601String());

    changeSupersessionSource('unit value', $scenario);

    expect($service->supersedeIfOutdated($homologation, GovernanceFixture::approver()))
        ->toBe(SalesBoardRolloutSupersessionReason::SourceChanged)
        ->and($homologation->fresh()->status)->toBe(SalesBoardRolloutHomologationStatus::Superseded)
        ->and(approvedPicture($homologation))->toBe($picture);
});

it('refuses the validity check to a user who only views the sales board', function () {
    $scenario = supersessionScenario();
    $homologation = $scenario['homologation'];

    $viewer = User::factory()->withTwoFactor()->create(['email' => fake()->unique()->safeEmail()]);
    $viewer->givePermissionTo([AccessPermission::SalesBoardsView->value, AccessPermission::EmissionsView->value]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs($viewer->fresh());

    changeSupersessionSource('contract sale value', $scenario);

    Livewire::test(ManageSalesBoardRollout::class, ['record' => $scenario['emission']->getKey()])
        ->assertOk()
        ->assertActionHidden('checkHomologationValidity')
        ->call('mountAction', 'checkHomologationValidity')
        ->assertSet('mountedActions', []);

    expect(fn () => app(SalesBoardRolloutHomologationService::class)->supersedeIfOutdated($homologation, $viewer->fresh()))
        ->toThrow(AuthorizationException::class);

    expect($homologation->fresh()->status)->toBe(SalesBoardRolloutHomologationStatus::Approved);

    // O par: quem opera a competência confere pela tela, e a fonte mudada substitui.
    $this->actingAs(GovernanceFixture::operator());

    Livewire::test(ManageSalesBoardRollout::class, ['record' => $scenario['emission']->getKey()])
        ->assertActionVisible('checkHomologationValidity')
        ->callAction('checkHomologationValidity')
        ->assertHasNoActionErrors()
        ->assertNotified('Homologação substituída');

    expect($homologation->fresh()->status)->toBe(SalesBoardRolloutHomologationStatus::Superseded);
});

it('refuses to check a homologation that is not approved or was already used', function (string $state) {
    $scenario = RolloutFixture::emission(1);
    RolloutFixture::legacyBoard($scenario['constructions'][0]);

    $homologation = match ($state) {
        'draft' => RolloutFixture::open($scenario['emission']),
        'activated' => (function () use ($scenario): SalesBoardRolloutHomologation {
            $approved = RolloutFixture::approvedHomologation($scenario['emission']);
            RolloutFixture::activate($scenario['emission'], $approved);

            return $approved;
        })(),
        'superseded' => (function () use ($scenario): SalesBoardRolloutHomologation {
            $approved = RolloutFixture::approvedHomologation($scenario['emission']);
            RolloutFixture::open($scenario['emission']);

            return $approved;
        })(),
    };

    $before = $homologation->fresh()->getAttributes();

    expect(fn () => app(SalesBoardRolloutHomologationService::class)->supersedeIfOutdated($homologation, GovernanceFixture::approver()))
        ->toThrow(SalesBoardRolloutException::class, SalesBoardRolloutException::homologationNotCheckable()->getMessage());

    expect($homologation->fresh()->getAttributes())->toBe($before);
})->with([
    'draft',
    'activated',
    'superseded',
]);

it('shows the superseded state on a fresh visit and offers only a new homologation', function () {
    $scenario = supersessionScenario();
    changeSupersessionSource('installment payment', $scenario);

    try {
        RolloutFixture::activate($scenario['emission'], $scenario['homologation']);
    } catch (SalesBoardRolloutException) {
        // A recusa é o esperado; o que importa é o que a próxima visita encontra.
    }

    $this->actingAs(makeAdminUser());

    Livewire::test(ManageSalesBoardRollout::class, ['record' => $scenario['emission']->getKey()])
        ->assertOk()
        ->assertSee('Homologação substituída')
        ->assertSee('Esta homologação não representa mais o estado atual das fontes: a fonte mudou depois da aprovação.')
        ->assertActionHidden('activate')
        ->assertActionHidden('checkHomologationValidity')
        ->assertActionVisible('openHomologation');

    Livewire::test(ListSalesBoardRollouts::class)
        ->assertOk()
        ->assertSee('Homologação substituída');
});
