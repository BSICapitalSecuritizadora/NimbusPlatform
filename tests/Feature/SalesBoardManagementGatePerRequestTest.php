<?php

use App\Enums\SalesBoardCycleStatus;
use App\Enums\SalesBoardNonconformityDecision;
use App\Enums\SalesBoardNonconformityOrigin;
use App\Filament\Resources\SalesBoardCycles\Pages\ManagementReviewWorkspace;
use App\Models\SalesBoardCycle;
use App\Services\SalesBoards\SalesBoardStaleDetectionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\SalesBoards\CountingStaleDetectionService;
use Tests\Support\SalesBoards\ManagementReviewFixture;

/**
 * Quantas vezes a tela da Análise apura o portão.
 *
 * Cada apuração é uma derivação da obra inteira. Antes do memo por requisição,
 * a visibilidade da aprovação, o formulário, a prévia, o Blade e as permissões
 * refaziam a mesma conta: duas no GET, três no clique, quatro no envio. Agora:
 * uma para renderizar, uma para abrir o modal e duas para aprovar -- a
 * visibilidade e a verificação dentro da transação, que é a garantia da fase. A
 * resposta mostra o que foi publicado sem apurar a fonte de novo: a análise
 * aprovada não tem portão ao vivo.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(makeAdminUser());

    app()->bind(SalesBoardStaleDetectionService::class, CountingStaleDetectionService::class);
});

/**
 * Uma análise aberta e pronta para publicar.
 *
 * @return array{cycle: SalesBoardCycle}
 */
function gatePerRequestScenario(): array
{
    $scenario = ManagementReviewFixture::submittedCycle();
    ManagementReviewFixture::open($scenario['cycle']);

    CountingStaleDetectionService::reset();

    return ['cycle' => $scenario['cycle']];
}

it('computes the approval gate once to render the workspace', function () {
    ['cycle' => $cycle] = gatePerRequestScenario();

    Livewire::test(ManagementReviewWorkspace::class, ['record' => $cycle->getKey()])
        ->assertOk()
        ->assertSee('Pronto para publicação');

    expect(CountingStaleDetectionService::$assessments)->toBe(1);
});

it('computes it once more to open the approval modal', function () {
    ['cycle' => $cycle] = gatePerRequestScenario();

    $page = Livewire::test(ManagementReviewWorkspace::class, ['record' => $cycle->getKey()]);

    CountingStaleDetectionService::reset();

    $page->mountAction('approve')
        ->assertActionMounted('approve');

    expect(CountingStaleDetectionService::$assessments)->toBe(1);
});

it('stays at two computations to approve and publish', function () {
    ['cycle' => $cycle] = gatePerRequestScenario();

    $page = Livewire::test(ManagementReviewWorkspace::class, ['record' => $cycle->getKey()])
        ->mountAction('approve')
        ->setActionData(['declaration' => true]);

    CountingStaleDetectionService::reset();

    $page->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertSee('Aprovada e publicada em')
        ->assertDontSee('Pronto para publicação');

    expect(CountingStaleDetectionService::$assessments)->toBe(2)
        ->and($cycle->fresh()->status)->toBe(SalesBoardCycleStatus::Approved);
});

it('shows fresh state after a decision', function () {
    $scenario = ManagementReviewFixture::submittedCycleWithNonConformSale();
    $review = ManagementReviewFixture::open($scenario['cycle']);
    $item = ManagementReviewFixture::nonconformityOf($review, SalesBoardNonconformityOrigin::SystemSaleNonConform);

    Livewire::test(ManagementReviewWorkspace::class, ['record' => $scenario['cycle']->getKey()])
        ->assertSee('0 de 1 não conformidade(s) decidida(s)')
        ->callAction('decide', [
            'decision' => SalesBoardNonconformityDecision::AcceptedException->value,
            'decision_reason' => ManagementReviewFixture::REASON,
        ], ['nonconformity' => $item->id])
        ->assertHasNoActionErrors()
        // O memo foi esquecido depois da ação: a mesma resposta já mostra a
        // decisão gravada e o portão liberado.
        ->assertSee('1 de 1 não conformidade(s) decidida(s)')
        ->assertSee(ManagementReviewFixture::REASON)
        ->assertSee('Pronto para publicação');
});
