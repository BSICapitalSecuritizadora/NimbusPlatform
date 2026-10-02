<?php

use App\Enums\SalesBoardRolloutHomologationStatus;
use App\Enums\SalesBoardRolloutRecipientRole;
use App\Filament\Resources\SalesBoardRollouts\Pages\ListSalesBoardRollouts;
use App\Filament\Resources\SalesBoardRollouts\Pages\ManageSalesBoardRollout;
use App\Filament\Resources\SalesBoardRollouts\SalesBoardRolloutResource;
use App\Models\Emission;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardRolloutRecipient;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Action;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\SalesBoards\GovernanceFixture;
use Tests\Support\SalesBoards\RolloutFixture;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(makeAdminUser());
});

it('lists emissions with their current mode', function () {
    $legacy = RolloutFixture::emission(1, 'L');
    $automated = RolloutFixture::emission(1, 'A');

    RolloutFixture::legacyBoard($automated['constructions'][0]);
    RolloutFixture::activate(
        $automated['emission'],
        RolloutFixture::approvedHomologation($automated['emission']),
    );

    Livewire::test(ListSalesBoardRollouts::class)
        ->assertOk()
        ->assertCanSeeTableRecords(Emission::query()->get())
        ->assertSee('Modo legado')
        ->assertSee('Automatizada');
});

it('warns when the global scheduler is off', function () {
    config()->set('sales_board.automation.enabled', false);

    Livewire::test(ListSalesBoardRollouts::class)
        ->assertOk()
        ->assertSee('automação global está desligada');
});

it('explains that automating is not a switch', function () {
    $scenario = RolloutFixture::emission(1);

    Livewire::test(ManageSalesBoardRollout::class, ['record' => $scenario['emission']->getKey()])
        ->assertOk()
        ->assertSee('Nenhuma homologação registrada')
        ->assertSee('não é ligar uma chave');
});

it('presents the rollout as an executive summary with the mode next to the title', function () {
    $scenario = RolloutFixture::emission(1);

    $page = Livewire::test(ManageSalesBoardRollout::class, ['record' => $scenario['emission']->getKey()])
        ->assertOk()
        ->assertSee('Resumo executivo')
        ->assertSee('Próxima ação')
        ->assertSee('Abrir homologação')
        ->assertSee('Nenhum responsável definido');

    // O modo virou badge junto ao título, e a abertura é a ação do estado vazio.
    expect($page->html())
        ->toMatch('/bsi-rollout-title[\s\S]*fi-badge[\s\S]*Legado/')
        ->and(substr_count($page->html(), 'Abrir homologação'))->toBe(1);
});

it('shows the legacy versus derived comparison per construction', function () {
    $scenario = RolloutFixture::emission(1);
    RolloutFixture::legacyBoard($scenario['constructions'][0], stockUnits: 5, stockValue: '3000000.00');

    RolloutFixture::open($scenario['emission']);

    Livewire::test(ManageSalesBoardRollout::class, ['record' => $scenario['emission']->getKey()])
        ->assertOk()
        ->assertSee('Posição do legado')
        ->assertSee('Diverge do legado')
        ->assertSee('Estoque')
        // O delta aparece com sinal, e não como número solto.
        ->assertSee('-3')
        ->assertSee('Portão da homologação')
        ->assertSee('Aprovação bloqueada');
});

it('says plainly when there is no legacy position, instead of showing zero', function () {
    $scenario = RolloutFixture::emission(1);
    RolloutFixture::open($scenario['emission']);

    Livewire::test(ManageSalesBoardRollout::class, ['record' => $scenario['emission']->getKey()])
        ->assertOk()
        ->assertSee('Sem posição legada disponível para comparação')
        ->assertSee('comparar contra zero afirmaria que a posição era zero');
});

it('records an accepted difference from the screen', function () {
    $scenario = RolloutFixture::emission(1);
    RolloutFixture::legacyBoard($scenario['constructions'][0], stockUnits: 5);

    $homologation = RolloutFixture::open($scenario['emission']);
    $row = $homologation->constructions->sole();

    Livewire::test(ManageSalesBoardRollout::class, ['record' => $scenario['emission']->getKey()])
        ->callAction('acceptDifference', arguments: ['row' => $row->id], data: [
            'reason' => 'O quadro legado somava um bloco que foi desmembrado em 2025.',
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect($row->fresh()->accepted_difference)->toBeTrue()
        ->and($row->fresh()->accepted_by_user_id)->toBe(auth()->id());
});

it('records both impact reviews from the screen', function () {
    $scenario = RolloutFixture::emission(1);
    RolloutFixture::legacyBoard($scenario['constructions'][0]);
    $homologation = RolloutFixture::open($scenario['emission']);

    Livewire::test(ManageSalesBoardRollout::class, ['record' => $scenario['emission']->getKey()])
        ->callAction('markGuaranteesReviewed')
        ->callAction('markMonthlyReportReviewed')
        ->assertHasNoActionErrors();

    expect($homologation->fresh()->guaranteesReviewed())->toBeTrue()
        ->and($homologation->fresh()->monthlyReportReviewed())->toBeTrue()
        ->and($homologation->fresh()->guarantees_reviewed_by_user_id)->toBe(auth()->id());
});

it('never claims the report was homologated automatically', function () {
    $scenario = RolloutFixture::emission(1);
    RolloutFixture::legacyBoard($scenario['constructions'][0]);
    RolloutFixture::open($scenario['emission']);

    Livewire::test(ManageSalesBoardRollout::class, ['record' => $scenario['emission']->getKey()])
        ->assertOk()
        ->assertSee('Impacto sobre o Relatório Mensal')
        ->assertDontSee('Relatório homologado automaticamente');
});

it('adds and removes recipients from the screen', function () {
    $scenario = RolloutFixture::emission(1);
    $user = RolloutFixture::operationalUser();

    Livewire::test(ManageSalesBoardRollout::class, ['record' => $scenario['emission']->getKey()])
        ->callAction('addRecipient',
            arguments: ['role' => SalesBoardRolloutRecipientRole::Operational->value],
            data: ['user_id' => $user->id],
        )
        ->assertHasNoActionErrors();

    $recipient = SalesBoardRolloutRecipient::query()->sole();

    expect($recipient->user_id)->toBe($user->id)
        ->and($recipient->role)->toBe(SalesBoardRolloutRecipientRole::Operational);

    Livewire::test(ManageSalesBoardRollout::class, ['record' => $scenario['emission']->getKey()])
        ->callAction('removeRecipient', arguments: ['recipient' => $recipient->id])
        ->assertHasNoActionErrors();

    expect(SalesBoardRolloutRecipient::query()->count())->toBe(0);
});

it('hides approval until the gate is green, then approves without activating', function () {
    $scenario = RolloutFixture::emission(1);
    RolloutFixture::legacyBoard($scenario['constructions'][0]);
    $homologation = RolloutFixture::open($scenario['emission']);

    Livewire::test(ManageSalesBoardRollout::class, ['record' => $scenario['emission']->getKey()])
        ->assertActionHidden('approve');

    RolloutFixture::recipients($scenario['emission']);
    RolloutFixture::reviewImpacts($homologation);

    Livewire::test(ManageSalesBoardRollout::class, ['record' => $scenario['emission']->getKey()])
        ->assertActionVisible('approve')
        ->callAction('approve', data: ['reason' => 'Homologação revisada com a operação.'])
        ->assertHasNoActionErrors();

    // Aprovar não ativa.
    expect($homologation->fresh()->status)
        ->toBe(SalesBoardRolloutHomologationStatus::Approved)
        ->and($homologation->fresh()->approved_by_user_id)->toBe(auth()->id())
        ->and($scenario['emission']->fresh()->usesAutomatedSalesBoard())->toBeFalse();
});

it('activates from the screen and generates nothing', function () {
    $scenario = RolloutFixture::emission(1);
    RolloutFixture::legacyBoard($scenario['constructions'][0]);
    RolloutFixture::approvedHomologation($scenario['emission']);

    Livewire::test(ManageSalesBoardRollout::class, ['record' => $scenario['emission']->getKey()])
        ->callAction('activate', data: ['reason' => 'Ativação acordada com a operação.'])
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect($scenario['emission']->fresh()->usesAutomatedSalesBoard())->toBeTrue()
        ->and(SalesBoardCycle::query()->count())->toBe(0);
});

it('shows the scope drift warning and hides nothing else', function () {
    $scenario = RolloutFixture::emission(1);
    RolloutFixture::legacyBoard($scenario['constructions'][0]);
    RolloutFixture::activate($scenario['emission'], RolloutFixture::approvedHomologation($scenario['emission']));

    RolloutFixture::construction($scenario['emission'], 'Z');

    Livewire::test(ManageSalesBoardRollout::class, ['record' => $scenario['emission']->getKey()])
        ->assertOk()
        ->assertSee('Automação suspensa por alteração de escopo')
        ->assertSee('automatizar só os antigos deixaria metade da Emissão sem competência');
});

it('returns to legacy from the screen, saying what is preserved', function () {
    $scenario = RolloutFixture::emission(1);
    RolloutFixture::legacyBoard($scenario['constructions'][0]);
    RolloutFixture::activate($scenario['emission'], RolloutFixture::approvedHomologation($scenario['emission']));

    Livewire::test(ManageSalesBoardRollout::class, ['record' => $scenario['emission']->getKey()])
        ->assertActionVisible('returnToLegacy')
        ->callAction('returnToLegacy', data: ['reason' => 'Cadastro precisa ser revisado antes de seguir.'])
        ->assertHasNoActionErrors();

    expect($scenario['emission']->fresh()->usesAutomatedSalesBoard())->toBeFalse();
});

it('disables opening a homologation, with the reason, for an emission in draft', function () {
    $scenario = RolloutFixture::emission(1);
    $scenario['emission']->forceFill(['status' => Emission::STATUS_DRAFT])->save();

    $reason = 'A Emissão está em "Em Elaboração": o rollout do Quadro de Vendas só começa depois da elaboração, '
        .'quando a posição inicial deixa de ser composta. Conclua a elaboração antes de homologar ou ativar.';

    Livewire::test(ManageSalesBoardRollout::class, ['record' => $scenario['emission']->getKey()])
        ->assertOk()
        ->assertActionVisible('openHomologation')
        ->assertActionDisabled('openHomologation')
        ->assertActionExists('openHomologation', fn (Action $action): bool => $action->getTooltip() === $reason)
        ->assertSee('O rollout do Quadro não se aplica a esta Emissão agora.')
        ->mountAction('openHomologation')
        ->assertActionNotMounted('openHomologation');

    expect($scenario['emission']->salesBoardRolloutHomologations()->count())->toBe(0);
});

it('disables the activation with the same reason when the emission went back to draft', function () {
    $scenario = RolloutFixture::emission(1);
    RolloutFixture::legacyBoard($scenario['constructions'][0]);
    RolloutFixture::approvedHomologation($scenario['emission'], GovernanceFixture::operator());

    $scenario['emission']->forceFill(['status' => Emission::STATUS_DRAFT])->save();

    Livewire::test(ManageSalesBoardRollout::class, ['record' => $scenario['emission']->getKey()])
        ->assertOk()
        ->assertActionVisible('activate')
        ->assertActionDisabled('activate')
        ->assertActionExists('activate', fn (Action $action): bool => str_starts_with(
            (string) $action->getTooltip(),
            'A Emissão está em "Em Elaboração": o rollout do Quadro de Vendas só começa depois da elaboração',
        ));

    expect($scenario['emission']->fresh()->usesAutomatedSalesBoard())->toBeFalse();
});

it('says that the brake of an emission is the return to legacy while the global switch is off', function () {
    config()->set('sales_board.automation.enabled', false);

    $scenario = RolloutFixture::emission(1);
    RolloutFixture::legacyBoard($scenario['constructions'][0]);
    RolloutFixture::activate($scenario['emission'], RolloutFixture::approvedHomologation($scenario['emission'], GovernanceFixture::operator()));

    Livewire::test(ListSalesBoardRollouts::class)
        ->assertOk()
        ->assertSee('o agendador não gera competências nem envia lembretes')
        ->assertSee('“Congelar competência” segue disponível para Emissões ativadas', escape: false)
        ->assertSee('Para parar uma Emissão, use “Retornar ao modo legado” na tela dela.', escape: false);

    Livewire::test(ManageSalesBoardRollout::class, ['record' => $scenario['emission']->getKey()])
        ->assertOk()
        ->assertSee('mas “Congelar competência” continua disponível para esta Emissão', escape: false)
        ->assertSee('Para interromper a Emissão, use “Retornar ao modo legado”.', escape: false)
        ->assertSee('O freio de uma Emissão é “Retornar ao modo legado”.', escape: false);
});

it('links the readiness preview from the list and from the emission screen', function () {
    $scenario = RolloutFixture::emission(1);
    $url = SalesBoardRolloutResource::getUrl('readiness', ['record' => $scenario['emission']]);

    Livewire::test(ListSalesBoardRollouts::class)
        ->assertTableActionHasUrl('readinessPreview', $url, $scenario['emission']);

    Livewire::test(ManageSalesBoardRollout::class, ['record' => $scenario['emission']->getKey()])
        ->assertActionVisible('readinessPreview')
        ->assertActionHasUrl('readinessPreview', $url);
});

it('offers no create, edit or delete on the rollout resource', function () {
    $scenario = RolloutFixture::emission(1);

    expect(SalesBoardRolloutResource::canCreate())->toBeFalse()
        ->and(SalesBoardRolloutResource::canEdit($scenario['emission']))->toBeFalse()
        ->and(SalesBoardRolloutResource::canDelete($scenario['emission']))->toBeFalse()
        ->and(array_keys(SalesBoardRolloutResource::getPages()))->toBe(['index', 'manage', 'readiness']);
});

it('denies the rollout screen to a user without sales board permission', function () {
    $this->actingAs(User::factory()->create());

    expect(SalesBoardRolloutResource::canViewAny())->toBeFalse()
        ->and(SalesBoardRolloutResource::canManageRollout())->toBeFalse();
});
