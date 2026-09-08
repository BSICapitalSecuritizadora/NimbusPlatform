<?php

use App\Enums\SalesBoardBuilderReviewStatus;
use App\Enums\SalesBoardCycleStatus;
use App\Enums\SalesBoardManagementReviewStatus;
use App\Enums\SalesBoardNonconformityDecision;
use App\Enums\SalesBoardNonconformityOrigin;
use App\Filament\Resources\SalesBoardCycles\Pages\BuilderReviewWorkspace;
use App\Filament\Resources\SalesBoardCycles\Pages\ManagementReviewWorkspace;
use App\Filament\Resources\SalesBoardCycles\Pages\ViewSalesBoardCycle;
use App\Models\ContractInstallment;
use App\Models\SalesBoard;
use App\Models\SalesBoardBuilderReview;
use App\Models\SalesBoardHistory;
use App\Models\SalesBoardManagementNonconformity;
use App\Models\SalesBoardManagementReview;
use App\Models\SalesBoardPublication;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\SalesBoards\BuilderReviewFixture;
use Tests\Support\SalesBoards\ManagementReviewFixture;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(makeAdminUser());
});

it('opens the management review from the cycle screen', function () {
    $scenario = ManagementReviewFixture::submittedCycleWithNonConformSale();

    Livewire::test(ViewSalesBoardCycle::class, ['record' => $scenario['cycle']->getKey()])
        ->callAction('openManagementReview')
        ->assertHasNoActionErrors();

    $review = SalesBoardManagementReview::sole();

    expect($review->status)->toBe(SalesBoardManagementReviewStatus::Draft)
        ->and($review->nonconformities)->toHaveCount(1)
        ->and($scenario['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::ManagementReview);
});

it('hides the management action while the competence is still with the builder', function () {
    $scenario = BuilderReviewFixture::generatedCycle();

    Livewire::test(ViewSalesBoardCycle::class, ['record' => $scenario['cycle']->getKey()])
        ->assertActionHidden('openManagementReview');
});

it('renders the workspace with the position, the builder validation and the gate', function () {
    $scenario = ManagementReviewFixture::submittedCycleWithNonConformSale();
    ManagementReviewFixture::open($scenario['cycle']);

    Livewire::test(ManagementReviewWorkspace::class, ['record' => $scenario['cycle']->getKey()])
        ->assertOk()
        ->assertSee('Resumo da posição')
        ->assertSee('Validação da construtora')
        ->assertSee('Não conformidades do sistema')
        ->assertSee('Venda abaixo do mínimo autorizado')
        ->assertSee('Publicação bloqueada')
        ->assertSee('O que será registrado no Quadro de Vendas')
        // A área interna mostra a política comercial; a da construtora, nunca.
        ->assertSee('Preço mínimo')
        // E não existe caminho para editar o quadro a partir daqui.
        ->assertDontSee('Editar quadro');
});

it('registers a decision from the workspace', function () {
    $scenario = ManagementReviewFixture::submittedCycleWithNonConformSale();
    $review = ManagementReviewFixture::open($scenario['cycle']);
    $item = ManagementReviewFixture::nonconformityOf($review, SalesBoardNonconformityOrigin::SystemSaleNonConform);

    Livewire::test(ManagementReviewWorkspace::class, ['record' => $scenario['cycle']->getKey()])
        ->callAction('decide', arguments: ['nonconformity' => $item->getKey()], data: [
            'decision' => SalesBoardNonconformityDecision::AcceptedException->value,
            'decision_reason' => 'Desconto autorizado pela diretoria comercial em ata.',
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect($item->fresh()->decision)->toBe(SalesBoardNonconformityDecision::AcceptedException)
        ->and($item->fresh()->decided_by_user_id)->toBe(auth()->id());
});

it('refuses a decision the origin does not admit', function () {
    $scenario = ManagementReviewFixture::submittedCycleWithNonConformSale();
    $review = ManagementReviewFixture::open($scenario['cycle']);
    $item = ManagementReviewFixture::nonconformityOf($review, SalesBoardNonconformityOrigin::SystemSaleNonConform);

    Livewire::test(ManagementReviewWorkspace::class, ['record' => $scenario['cycle']->getKey()])
        ->callAction('decide', arguments: ['nonconformity' => $item->getKey()], data: [
            'decision' => SalesBoardNonconformityDecision::Dismissed->value,
            'decision_reason' => 'A venda parece estar correta de qualquer forma.',
        ])
        ->assertHasActionErrors(['decision']);

    expect($item->fresh()->decision)->toBe(SalesBoardNonconformityDecision::Pending);
});

it('hides the approval action while the gate is closed', function () {
    $scenario = ManagementReviewFixture::submittedCycleWithNonConformSale();
    ManagementReviewFixture::open($scenario['cycle']);

    Livewire::test(ManagementReviewWorkspace::class, ['record' => $scenario['cycle']->getKey()])
        ->assertOk()
        ->assertSee('Publicação bloqueada')
        ->assertSee('1 pendente(s) de decisão.');
});

it('approves and publishes from the workspace', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    ManagementReviewFixture::open($scenario['cycle']);

    Livewire::test(ManagementReviewWorkspace::class, ['record' => $scenario['cycle']->getKey()])
        ->assertSee('Pronto para publicação')
        ->callAction('approve', data: ['declaration' => true])
        ->assertHasNoActionErrors()
        ->assertNotified();

    $salesBoard = SalesBoard::query()->sole();

    expect($salesBoard)->not->toBeNull()
        ->and(SalesBoardPublication::query()->sole()->published_by_user_id)->toBe(auth()->id())
        ->and($scenario['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::Approved)
        // O observer do quadro legado continua fazendo o que sempre fez: toda
        // criação vira a primeira versão do histórico, com o autor autenticado.
        // A publicação não silencia esse evento.
        ->and(SalesBoardHistory::query()->where('sales_board_id', $salesBoard->id)->count())->toBe(1)
        ->and(SalesBoardHistory::query()->where('sales_board_id', $salesBoard->id)->sole()->changed_by_id)
        ->toBe(auth()->id());
});

it('requires the declaration before publishing', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    ManagementReviewFixture::open($scenario['cycle']);

    Livewire::test(ManagementReviewWorkspace::class, ['record' => $scenario['cycle']->getKey()])
        ->callAction('approve', data: ['declaration' => false])
        ->assertHasActionErrors(['declaration']);

    expect(SalesBoard::query()->count())->toBe(0);
});

it('asks for a justification only when the source changed without moving the position', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    ManagementReviewFixture::open($scenario['cycle']);

    Livewire::test(ManagementReviewWorkspace::class, ['record' => $scenario['cycle']->getKey()])
        ->assertActionVisible('approve')
        ->assertDontSee('Os dados de origem foram alterados');

    ContractInstallment::query()
        ->where('contract_id', $scenario['contracts']['cancelled']->id)
        ->sole()
        ->update(['expected_value' => '469000.00']);

    Livewire::test(ManagementReviewWorkspace::class, ['record' => $scenario['cycle']->getKey()])
        ->assertSee('Os dados de origem foram alterados')
        ->callAction('approve', data: ['declaration' => true])
        ->assertHasActionErrors(['source_change_reason']);

    expect(SalesBoard::query()->count())->toBe(0);
});

it('reports the legacy conflict instead of failing', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $cycle = $scenario['cycle']->fresh();

    $manual = SalesBoard::factory()->create([
        'emission_id' => $cycle->emission_id,
        'construction_id' => $cycle->construction_id,
        'reference_month' => $cycle->reference_month->toDateString(),
        'stock_units' => 9,
    ]);

    ManagementReviewFixture::open($cycle);

    Livewire::test(ManagementReviewWorkspace::class, ['record' => $cycle->getKey()])
        ->assertSee('Publicação bloqueada')
        ->assertSee('Já existe quadro');

    expect($manual->fresh()->stock_units)->toBe(9)
        ->and(SalesBoardPublication::query()->count())->toBe(0);
});

it('returns the competence to the builder from the workspace', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    ManagementReviewFixture::open($scenario['cycle']);

    Livewire::test(ManagementReviewWorkspace::class, ['record' => $scenario['cycle']->getKey()])
        ->callAction('returnToBuilder', data: ['reason' => 'Confirme a data de quitação da unidade 103.'])
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect(SalesBoardManagementReview::sole()->status)->toBe(SalesBoardManagementReviewStatus::Returned)
        ->and(SalesBoardBuilderReview::query()->count())->toBe(2)
        ->and(SalesBoardBuilderReview::query()->where('status', SalesBoardBuilderReviewStatus::Draft)->sole()->attempt)->toBe(2)
        ->and($scenario['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::BuilderReview);
});

it('shows an approved round as read only', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $review = ManagementReviewFixture::open($scenario['cycle']);
    ManagementReviewFixture::approve($review);

    Livewire::test(ManagementReviewWorkspace::class, ['record' => $scenario['cycle']->getKey()])
        ->assertOk()
        ->assertSee('Encerramento desta rodada')
        ->assertSee('Aprovada por')
        // Encerrada é somente leitura: nem aprovar nem devolver seguem oferecidos.
        ->assertActionHidden('approve')
        ->assertActionHidden('returnToBuilder')
        ->assertDontSee('Editar quadro');
});

it('never offers a decision action on a finished round', function () {
    $scenario = ManagementReviewFixture::submittedCycleWithNonConformSale();
    $review = ManagementReviewFixture::open($scenario['cycle']);
    ManagementReviewFixture::returnToBuilder($review);

    Livewire::test(ManagementReviewWorkspace::class, ['record' => $scenario['cycle']->getKey()])
        ->assertOk()
        ->assertSee('Devolvida à construtora');

    expect(SalesBoardManagementNonconformity::query()->sole()->decision)
        ->toBe(SalesBoardNonconformityDecision::Pending);
});

it('denies the workspace to a user without sales board permission', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    ManagementReviewFixture::open($scenario['cycle']);

    $this->actingAs(User::factory()->create());

    Livewire::test(ManagementReviewWorkspace::class, ['record' => $scenario['cycle']->getKey()])
        ->assertForbidden();
});

it('shows the return reason on the new builder round, without copying declarations', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $review = ManagementReviewFixture::open($scenario['cycle']);

    ManagementReviewFixture::returnToBuilder($review, null, 'Confirme a data de quitação da unidade 103.');

    Livewire::test(BuilderReviewWorkspace::class, [
        'record' => $scenario['cycle']->getKey(),
    ])
        ->assertOk()
        ->assertSee('Motivo da devolução da Gestão')
        ->assertSee('Confirme a data de quitação da unidade 103.');
});

it('stops showing the return reason once the new round is submitted', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $review = ManagementReviewFixture::open($scenario['cycle']);

    $outcome = ManagementReviewFixture::returnToBuilder($review, null, 'Confirme a data de quitação da unidade 103.');

    BuilderReviewFixture::confirmAll($outcome['builderReview']);
    BuilderReviewFixture::submit($outcome['builderReview']);

    Livewire::test(BuilderReviewWorkspace::class, [
        'record' => $scenario['cycle']->getKey(),
    ])
        ->assertOk()
        ->assertDontSee('Motivo da devolução da Gestão');
});

it('shows an undetermined sale with its cause and only the correction action', function () {
    $scenario = ManagementReviewFixture::submittedCycleWithUndeterminedSale();
    ManagementReviewFixture::open($scenario['cycle']);

    Livewire::test(ManagementReviewWorkspace::class, ['record' => $scenario['cycle']->getKey()])
        ->assertOk()
        ->assertSee('Vendas sem conformidade determinável')
        ->assertSee('Conformidade não determinada pelo Nimbus')
        // A causa congelada, em linguagem funcional.
        ->assertSee('O empreendimento não tinha política de desconto vigente na data da venda.')
        ->assertSee('não existe limite conhecido contra o qual uma exceção pudesse ser concedida')
        ->assertSee('Publicação bloqueada');
});

/**
 * A tela monta as opções de decisão a partir de `allowedDecisions()` da própria
 * pendência -- provado em SalesBoardUndeterminedConformityTest, que confere a
 * lista item a item. O que falta garantir é o outro lado: que a recusa não
 * dependa do formulário. É o que este teste faz, submetendo a decisão proibida
 * direto na ação.
 */
it('refuses an exception on an undetermined sale even when the form is bypassed', function () {
    $scenario = ManagementReviewFixture::submittedCycleWithUndeterminedSale();
    $review = ManagementReviewFixture::open($scenario['cycle']);
    $item = ManagementReviewFixture::nonconformityOf($review, SalesBoardNonconformityOrigin::SystemSaleUndetermined);

    Livewire::test(ManagementReviewWorkspace::class, ['record' => $scenario['cycle']->getKey()])
        ->callAction('decide', arguments: ['nonconformity' => $item->getKey()], data: [
            'decision' => SalesBoardNonconformityDecision::AcceptedException->value,
            'decision_reason' => 'Quero aprovar como exceção mesmo sem saber o limite.',
        ])
        ->assertHasActionErrors(['decision']);

    expect($item->fresh()->decision)->toBe(SalesBoardNonconformityDecision::Pending);
});

it('registers the correction for an undetermined sale from the workspace', function () {
    $scenario = ManagementReviewFixture::submittedCycleWithUndeterminedSale();
    $review = ManagementReviewFixture::open($scenario['cycle']);
    $item = ManagementReviewFixture::nonconformityOf($review, SalesBoardNonconformityOrigin::SystemSaleUndetermined);

    Livewire::test(ManagementReviewWorkspace::class, ['record' => $scenario['cycle']->getKey()])
        ->callAction('decide', arguments: ['nonconformity' => $item->getKey()], data: [
            'decision' => SalesBoardNonconformityDecision::CorrectionRequired->value,
            'decision_reason' => 'Política comercial aplicável à data da venda não está cadastrada.',
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect($item->fresh()->decision)->toBe(SalesBoardNonconformityDecision::CorrectionRequired)
        ->and($item->fresh()->decided_by_user_id)->toBe(auth()->id());
});

it('hides the approval action while an undetermined sale is unresolved', function () {
    $scenario = ManagementReviewFixture::submittedCycleWithUndeterminedSale();
    ManagementReviewFixture::open($scenario['cycle']);

    Livewire::test(ManagementReviewWorkspace::class, ['record' => $scenario['cycle']->getKey()])
        ->assertActionHidden('approve');

    expect(SalesBoard::query()->count())->toBe(0);
});

it('never exposes conformity or policy to the builder workspace', function () {
    $scenario = ManagementReviewFixture::submittedCycleWithUndeterminedSale();
    ManagementReviewFixture::open($scenario['cycle']);

    // A construtora valida o quadro; a conformidade comercial é interna e
    // continua fora da tela dela, com ou sem venda indeterminada.
    Livewire::test(BuilderReviewWorkspace::class, [
        'record' => $scenario['cycle']->getKey(),
    ])
        ->assertOk()
        ->assertDontSee('Conformidade não determinada')
        ->assertDontSee('Vendas sem conformidade determinável')
        ->assertDontSee('Preço mínimo')
        ->assertDontSee('Desconto autorizado')
        ->assertDontSee('Correção necessária');
});
