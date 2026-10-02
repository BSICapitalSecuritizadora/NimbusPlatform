<?php

use App\DTOs\SalesBoards\SalesBoardApprovalResult;
use App\DTOs\SalesBoards\SalesBoardCompetenceBridge;
use App\Enums\SalesBoardBuilderReviewStatus;
use App\Enums\SalesBoardCycleStatus;
use App\Enums\SalesBoardManagementReviewStatus;
use App\Enums\SalesBoardNonconformityDecision;
use App\Enums\SalesBoardNonconformityOrigin;
use App\Enums\SalesBoardStaleImpact;
use App\Exceptions\SalesBoardManagementReviewException;
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
use App\Services\SalesBoards\SalesBoardManagementApprovalService;
use App\Services\SalesBoards\SalesBoardManagementReturnService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\SalesBoards\BuilderReviewFixture;
use Tests\Support\SalesBoards\ExtemporaneousFixture;
use Tests\Support\SalesBoards\GovernanceFixture;
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

    // Digitado enquanto a Emissão ainda era legada.
    $manual = ManagementReviewFixture::manualBoardBeforeAutomation($cycle, [
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

    $page = Livewire::test(ManagementReviewWorkspace::class, ['record' => $scenario['cycle']->getKey()])
        ->assertOk()
        ->assertSee('Encerramento desta rodada')
        ->assertSee('Aprovada por')
        // Encerrada é somente leitura: nem aprovar nem devolver seguem oferecidos.
        ->assertActionHidden('returnToBuilder')
        ->assertDontSee('Editar quadro');

    // "Aprovar e publicar" depende só da permissão (o estado é conferido ao
    // abrir e no envio): a tela não o imprime, e um clique forjado é recusado
    // com o motivo, sem publicar de novo.
    expect(managementUiRendersApproveButton($page->html()))->toBeFalse();

    $page->mountAction('approve');

    expect(managementUiNotificationBody())->toContain('Esta análise não está mais em andamento');

    $page->assertNotified('Não foi possível publicar')
        ->assertActionNotMounted('approve');

    expect(SalesBoardPublication::query()->count())->toBe(1);
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

    $page = Livewire::test(ManagementReviewWorkspace::class, ['record' => $scenario['cycle']->getKey()])
        ->assertSee('Aprovar e publicar indisponível:');

    expect(managementUiRendersApproveButton($page->html()))->toBeFalse();

    // Um clique forjado também não publica: o portão é refeito na execução.
    $page->callAction('approve', data: ['declaration' => true])
        ->assertNotified('Não foi possível publicar');

    expect(SalesBoard::query()->count())->toBe(0);
});

/**
 * O corpo da última notificação enviada, lido da sessão antes de
 * `assertNotified()` -- que só compara o título e esvazia a sessão.
 */
function managementUiNotificationBody(): string
{
    $notifications = session()->get('filament.claimed_notifications') ?? session()->get('filament.notifications') ?? [];

    return (string) (collect($notifications)->last()['body'] ?? '');
}

/**
 * O botão "Aprovar e publicar" está impresso na página? O Blade só o imprime
 * com o portão aberto; a ação continua resolvível para quem decide.
 */
function managementUiRendersApproveButton(string $html): bool
{
    return preg_match('/wire:click="mountAction\((?:\'|&#0?39;)approve(?:\'|&#0?39;)[,)]/', $html) === 1;
}

/**
 * O portão fecha com o modal de aprovação aberto. O Filament reavalia a
 * visibilidade da ação no envio, e a visibilidade ligada ao portão descartava o
 * clique calado: o modal ficava aberto, sem aviso, e o motivo só aparecia ao
 * recarregar. A ação depende só da permissão e da situação da análise, e o
 * portão refeito na execução diz o que falhou.
 */
it('says why nothing was published when the source changes with the approval modal open', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    ManagementReviewFixture::open($scenario['cycle']);

    $page = Livewire::test(ManagementReviewWorkspace::class, ['record' => $scenario['cycle']->getKey()])
        ->assertSee('Pronto para publicação');

    expect(managementUiRendersApproveButton($page->html()))->toBeTrue();

    $page->mountAction('approve')
        ->setActionData(['declaration' => true]);

    // Com o modal aberto, alguém corrige o valor da venda do mês na fonte.
    $scenario['contracts']['soldInMonth']->update(['sale_value' => '499999.00']);

    $page->callMountedAction();

    expect(managementUiNotificationBody())
        ->toContain('O portão de publicação fechou depois que a confirmação foi aberta. Nada foi publicado.')
        ->toContain('Fonte sem alteração material — Alterações materiais');

    $page->assertNotified('Não foi possível publicar')
        ->assertActionNotMounted('approve')
        ->assertSee('Publicação bloqueada');

    expect(managementUiRendersApproveButton($page->html()))->toBeFalse()
        ->and(SalesBoardPublication::query()->count())->toBe(0)
        ->and(SalesBoard::query()->count())->toBe(0)
        ->and($scenario['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::ManagementReview)
        ->and(SalesBoardManagementReview::sole()->status)->toBe(SalesBoardManagementReviewStatus::Draft);
});

it('says why nothing was published when the previous competence enters rectification with the approval modal open', function () {
    $scenario = ExtemporaneousFixture::publishedJuly();
    $august = ExtemporaneousFixture::generateAugust($scenario['construction']);
    ExtemporaneousFixture::analysis($august);

    $page = Livewire::test(ManagementReviewWorkspace::class, ['record' => $august->getKey()])
        ->assertSee('Pronto para publicação')
        ->mountAction('approve')
        ->setActionData(['declaration' => true]);

    // Com o modal aberto, outra pessoa da Gestão abre a retificação de julho. A
    // venda corrigida também muda a fonte de agosto: o aviso lista todos os
    // itens do portão que falharam, e a retificação aberta está entre eles.
    $scenario['financed']->forceFill(['sale_value' => '650000.00'])->save();
    ExtemporaneousFixture::rectify($scenario['july']);

    $page->callMountedAction();

    expect(managementUiNotificationBody())
        ->toContain('Nada foi publicado.')
        ->toContain('Competência anterior encerrada — A competência anterior (07/2026) está em retificação');

    $page->assertNotified('Não foi possível publicar')
        ->assertActionNotMounted('approve');

    expect(SalesBoardPublication::query()->where('sales_board_cycle_id', $august->getKey())->count())->toBe(0)
        ->and($august->fresh()->status)->toBe(SalesBoardCycleStatus::ManagementReview);
});

it('does not open the confirmation over a stale page whose gate already closed', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    ManagementReviewFixture::open($scenario['cycle']);

    $page = Livewire::test(ManagementReviewWorkspace::class, ['record' => $scenario['cycle']->getKey()])
        ->assertSee('Pronto para publicação');

    // A fonte muda com a página aberta, antes do clique em "Aprovar e publicar":
    // a tela ainda mostra o botão, mas a confirmação não chega a abrir.
    $scenario['contracts']['soldInMonth']->update(['sale_value' => '499999.00']);

    $page->mountAction('approve');

    expect(managementUiNotificationBody())
        ->toContain('A publicação não está liberada. Nada foi publicado.')
        ->toContain('Fonte sem alteração material — Alterações materiais')
        ->not->toContain('depois que a confirmação foi aberta');

    $page->assertNotified('Não foi possível publicar')
        ->assertActionNotMounted('approve');

    expect(SalesBoardPublication::query()->count())->toBe(0)
        ->and($scenario['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::ManagementReview);
});

it('says why nothing was published when another person closes the analysis with the approval modal open', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $review = ManagementReviewFixture::open($scenario['cycle']);

    $page = Livewire::test(ManagementReviewWorkspace::class, ['record' => $scenario['cycle']->getKey()])
        ->mountAction('approve')
        ->setActionData(['declaration' => true]);

    // Com o modal aberto, outra pessoa da Gestão devolve a rodada à construtora.
    app(SalesBoardManagementReturnService::class)->returnToBuilder(
        $review,
        GovernanceFixture::approver(),
        'Precisamos da confirmação do contrato da unidade 102.',
    );

    $page->callMountedAction();

    expect(managementUiNotificationBody())
        ->toContain('Esta análise não está mais em andamento');

    $page->assertNotified('Não foi possível publicar')
        ->assertActionNotMounted('approve');

    expect(SalesBoardPublication::query()->count())->toBe(0)
        ->and($review->fresh()->status)->toBe(SalesBoardManagementReviewStatus::Returned);
});

it('still turns a refusal of the approval service into a message once the gate passes', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    ManagementReviewFixture::open($scenario['cycle']);

    /**
     * O portão da tela passa e a fonte muda no intervalo até a transação: quem
     * recusa é o serviço, que confere tudo de novo. O portão continua sendo o
     * do serviço de verdade.
     */
    $real = app(SalesBoardManagementApprovalService::class);

    app()->instance(SalesBoardManagementApprovalService::class, new class($real) extends SalesBoardManagementApprovalService
    {
        public function __construct(private readonly SalesBoardManagementApprovalService $real) {}

        public function gate(SalesBoardManagementReview $review, ?SalesBoardCompetenceBridge $bridge = null): array
        {
            return $this->real->gate($review, $bridge);
        }

        public function approve(SalesBoardManagementReview $review, ?User $actor, bool $declarationAccepted, ?string $sourceChangeReason = null): SalesBoardApprovalResult
        {
            throw SalesBoardManagementReviewException::staleBlocksApproval(SalesBoardStaleImpact::Material);
        }
    });

    $page = Livewire::test(ManagementReviewWorkspace::class, ['record' => $scenario['cycle']->getKey()])
        ->assertSee('Pronto para publicação')
        ->callAction('approve', data: ['declaration' => true]);

    expect(managementUiNotificationBody())->toBe('Os dados de origem alteraram materialmente a posição. Recalcule antes da aprovação.');

    $page->assertNotified('Não foi possível concluir');

    expect(SalesBoardPublication::query()->count())->toBe(0)
        ->and($scenario['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::ManagementReview);
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
