<?php

use App\Enums\SalesBoardBuilderReviewStatus;
use App\Filament\Resources\SalesBoardCycles\Pages\BuilderReviewWorkspace;
use App\Models\ContractInstallment;
use App\Models\SalesBoardBuilderReview;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\SalesBoards\BuilderReviewFixture;
use Tests\Support\SalesBoards\CycleFixture;
use Tests\Support\SalesBoards\ManagementReviewFixture;

/**
 * O pedido da Gestão aparece na rodada que a devolução abriu -- e só nela.
 *
 * "A rodada que a devolução abriu" é a seguinte à rodada devolvida, sobre o
 * mesmo quadro. Depois de um recálculo material, o pedido falava de uma versão
 * que não existe mais; depois de um recálculo só de origem, o quadro é o mesmo e
 * o pedido continua valendo. A rodada devolvida, por sua vez, é rodada
 * encerrada: um recálculo não a marca como substituída, como o cancelamento já
 * não marcava.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(makeAdminUser());
});

function returnReasonText(): string
{
    return 'Confirme com a construtora a data de quitação da unidade 103.';
}

/**
 * Uma competência enviada, devolvida pela Gestão com um motivo.
 *
 * @return array{scenario: array<string, mixed>, returned: SalesBoardBuilderReview, reopened: SalesBoardBuilderReview}
 */
function returnedCompetence(): array
{
    $scenario = ManagementReviewFixture::submittedCycle();
    $review = ManagementReviewFixture::open($scenario['cycle']);

    $result = ManagementReviewFixture::returnToBuilder($review, reason: returnReasonText());

    return [
        'scenario' => $scenario,
        'returned' => $scenario['builderReview']->fresh(),
        'reopened' => $result['builderReview']->fresh(),
    ];
}

it('shows the return reason on the round the return opened', function () {
    ['scenario' => $scenario, 'reopened' => $reopened] = returnedCompetence();

    expect($reopened->attempt)->toBe(2)
        ->and($reopened->originatingReturn()?->return_reason)->toBe(returnReasonText());

    Livewire::test(BuilderReviewWorkspace::class, ['record' => $scenario['cycle']->getKey()])
        ->assertOk()
        ->assertSee('Motivo da devolução da Gestão')
        ->assertSee(returnReasonText());
});

it('hides a return reason about a previous version after a material recalculation', function () {
    ['scenario' => $scenario, 'reopened' => $reopened] = returnedCompetence();

    // Recálculo material: a versão 2 tem outro quadro, e a rodada aberta pela
    // devolução, ainda em rascunho, é substituída.
    $scenario['contracts']['financed']->update(['sale_value' => '910000.00']);
    CycleFixture::recalculate($scenario['cycle'], 'Correção do valor de venda.');

    expect($reopened->fresh()->status)->toBe(SalesBoardBuilderReviewStatus::Superseded);

    $third = BuilderReviewFixture::open($scenario['cycle']);

    expect($third->attempt)->toBe(3)
        ->and($third->originatingReturn())->toBeNull();

    Livewire::test(BuilderReviewWorkspace::class, ['record' => $scenario['cycle']->getKey()])
        ->assertOk()
        ->assertSee('Tentativa 3')
        ->assertDontSee('Motivo da devolução da Gestão')
        ->assertDontSee(returnReasonText());
});

it('keeps showing the reason when the recalculation did not change the reviewed picture', function () {
    ['scenario' => $scenario, 'reopened' => $reopened] = returnedCompetence();
    $fingerprint = CycleFixture::currentBaseline($scenario['cycle'])->snapshot_fingerprint;

    // Só a origem muda: o cronograma do contrato distratado.
    ContractInstallment::query()
        ->where('contract_id', $scenario['contracts']['cancelled']->id)
        ->firstOrFail()
        ->update(['expected_value' => '469000.00']);

    $result = CycleFixture::recalculate($scenario['cycle'], 'Correção do cronograma do contrato distratado.');

    expect($result->baseline?->version)->toBe(2)
        ->and($result->baseline?->snapshot_fingerprint)->toBe($fingerprint)
        ->and($reopened->fresh()->status)->toBe(SalesBoardBuilderReviewStatus::Draft);

    Livewire::test(BuilderReviewWorkspace::class, ['record' => $scenario['cycle']->getKey()])
        ->assertOk()
        ->assertSee('Motivo da devolução da Gestão')
        ->assertSee(returnReasonText());
});

it('keeps a returned round as submitted when a material recalculation lands', function () {
    ['scenario' => $scenario, 'returned' => $returned, 'reopened' => $reopened] = returnedCompetence();

    $scenario['contracts']['financed']->update(['sale_value' => '910000.00']);
    CycleFixture::recalculate($scenario['cycle'], 'Correção do valor de venda.');

    expect($returned->fresh()->status)->toBe(SalesBoardBuilderReviewStatus::Submitted)
        ->and($returned->fresh()->superseded_at)->toBeNull()
        ->and($reopened->fresh()->status)->toBe(SalesBoardBuilderReviewStatus::Superseded);

    // O par: uma rodada enviada e não devolvida é substituída pelo mesmo recálculo.
    $other = ManagementReviewFixture::submittedCycle();
    $other['contracts']['financed']->update(['sale_value' => '910000.00']);
    CycleFixture::recalculate($other['cycle'], 'Correção do valor de venda.');

    expect($other['builderReview']->fresh()->status)->toBe(SalesBoardBuilderReviewStatus::Superseded)
        ->and($other['builderReview']->fresh()->superseded_reason)->toBe('nova_versao_material');
});
