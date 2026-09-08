<?php

use App\DTOs\SalesBoards\SalesBoardBuilderDivergenceInput;
use App\Enums\SalesBoardBuilderDivergenceType;
use App\Enums\SalesBoardBuilderReviewSection as SectionEnum;
use App\Enums\SalesBoardBuilderReviewSectionStatus;
use App\Enums\SalesBoardBuilderReviewStatus;
use App\Enums\SalesBoardCycleStatus;
use App\Enums\SalesBoardUnitClassification;
use App\Filament\Resources\SalesBoardCycles\Pages\BuilderReviewWorkspace;
use App\Filament\Resources\SalesBoardCycles\Pages\ViewSalesBoardCycle;
use App\Filament\Resources\SalesBoardCycles\RelationManagers\SalesBoardCycleBuilderReviewsRelationManager;
use App\Models\SalesBoard;
use App\Models\SalesBoardBuilderDivergence;
use App\Models\SalesBoardBuilderReview;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\SalesBoards\BuilderReviewFixture;
use Tests\Support\SalesBoards\CycleFixture;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(makeAdminUser());
});

it('opens the builder review from the cycle screen', function () {
    $scenario = BuilderReviewFixture::generatedCycle();

    Livewire::test(ViewSalesBoardCycle::class, ['record' => $scenario['cycle']->getKey()])
        ->callAction('openBuilderReview')
        ->assertHasNoActionErrors()
        ->assertNotified();

    $review = SalesBoardBuilderReview::sole();

    expect($review->status)->toBe(SalesBoardBuilderReviewStatus::Draft)
        ->and($review->sections)->toHaveCount(7)
        ->and($scenario['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::BuilderReview);
});

it('reports why the review cannot be opened instead of failing', function () {
    $scenario = BuilderReviewFixture::generatedCycle();
    $scenario['contracts']['financed']->update(['sale_value' => '910000.00']);

    Livewire::test(ViewSalesBoardCycle::class, ['record' => $scenario['cycle']->getKey()])
        ->callAction('openBuilderReview')
        ->assertNotified();

    expect(SalesBoardBuilderReview::query()->count())->toBe(0);
});

it('renders the workspace with the position, the movements and the progress', function () {
    $scenario = BuilderReviewFixture::generatedCycle();
    BuilderReviewFixture::open($scenario['cycle']);

    $this->get(BuilderReviewWorkspace::getUrl(['record' => $scenario['cycle']]))
        ->assertOk()
        ->assertSee('Posição no fechamento')
        ->assertSee('Movimentações do mês')
        ->assertSee('0 de 7 seções revisadas')
        ->assertSee('Estoque')
        ->assertSee('Vendas do mês')
        ->assertSee('Divergências declaradas')
        ->assertSee('Nenhuma divergência registrada');
});

it('confirms a section from the workspace', function () {
    $scenario = BuilderReviewFixture::generatedCycle();
    $review = BuilderReviewFixture::open($scenario['cycle']);
    $section = BuilderReviewFixture::section($review, SectionEnum::PositionStock);

    Livewire::test(BuilderReviewWorkspace::class, ['record' => $scenario['cycle']->getKey()])
        ->callAction('confirmSection', ['comment' => 'Confere.'], ['section' => $section->id])
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect($section->fresh()->status)->toBe(SalesBoardBuilderReviewSectionStatus::Confirmed);
});

it('declares a divergence from the workspace without touching the snapshot', function () {
    $scenario = BuilderReviewFixture::generatedCycle();
    $review = BuilderReviewFixture::open($scenario['cycle']);
    $section = BuilderReviewFixture::section($review, SectionEnum::PositionFinanced);
    $line = BuilderReviewFixture::lineFor($review, $scenario['units']['financed']);

    Livewire::test(BuilderReviewWorkspace::class, ['record' => $scenario['cycle']->getKey()])
        ->callAction('declareDivergence', [
            'type' => SalesBoardBuilderDivergenceType::StockMismatch->value,
            'sales_board_cycle_line_id' => $line->id,
            'declared_classification' => SalesBoardUnitClassification::Stock->value,
            'reason' => 'A unidade foi distratada em junho.',
        ], ['section' => $section->id])
        ->assertHasNoActionErrors()
        ->assertNotified();

    $divergence = SalesBoardBuilderDivergence::sole();

    expect($divergence->declared_classification)->toBe(SalesBoardUnitClassification::Stock)
        ->and($section->fresh()->status)->toBe(SalesBoardBuilderReviewSectionStatus::Divergent)
        ->and($line->fresh()->classification)->toBe(SalesBoardUnitClassification::Financed);
});

it('blocks an incomplete declaration in the form itself', function () {
    $scenario = BuilderReviewFixture::generatedCycle();
    $review = BuilderReviewFixture::open($scenario['cycle']);
    $section = BuilderReviewFixture::section($review, SectionEnum::MovementSales);

    // Venda ausente exige unidade, data e valor: o formulário recusa antes de o
    // domínio precisar recusar.
    Livewire::test(BuilderReviewWorkspace::class, ['record' => $scenario['cycle']->getKey()])
        ->callAction('declareDivergence', [
            'type' => SalesBoardBuilderDivergenceType::SaleMissing->value,
            'declared_unit' => '404',
            'reason' => 'Falta uma venda.',
        ], ['section' => $section->id])
        ->assertHasActionErrors(['declared_date', 'declared_value']);

    expect(SalesBoardBuilderDivergence::query()->count())->toBe(0);
});

it('surfaces a domain refusal as a message, not an error', function () {
    $scenario = BuilderReviewFixture::generatedCycle();
    $review = BuilderReviewFixture::open($scenario['cycle']);
    $section = BuilderReviewFixture::section($review, SectionEnum::PositionFinanced);
    $line = BuilderReviewFixture::lineFor($review, $scenario['units']['financed']);

    BuilderReviewFixture::declare($review, SectionEnum::PositionFinanced, new SalesBoardBuilderDivergenceInput(
        type: SalesBoardBuilderDivergenceType::StockMismatch,
        reason: 'Deveria estar em estoque.',
        lineId: $line->id,
        declaredClassification: SalesBoardUnitClassification::Stock,
    ));

    // Regra de domínio que nenhum formulário conhece: a seção tem divergência e
    // por isso não pode ser confirmada.
    Livewire::test(BuilderReviewWorkspace::class, ['record' => $scenario['cycle']->getKey()])
        ->callAction('confirmSection', [], ['section' => $section->id])
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect($section->fresh()->status)->toBe(SalesBoardBuilderReviewSectionStatus::Divergent);
});

it('submits the review from the workspace and hands the cycle to management', function () {
    $scenario = BuilderReviewFixture::generatedCycle();
    $review = BuilderReviewFixture::open($scenario['cycle']);
    BuilderReviewFixture::confirmAll($review);

    Livewire::test(BuilderReviewWorkspace::class, ['record' => $scenario['cycle']->getKey()])
        ->callAction('submitReview', [
            'overall_comment' => 'Conferido pela equipe comercial.',
            'declaration' => true,
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect($review->fresh()->status)->toBe(SalesBoardBuilderReviewStatus::Submitted)
        ->and($scenario['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::ManagementReview)
        ->and(SalesBoard::query()->count())->toBe(0);
});

it('requires the declaration to be accepted before submitting', function () {
    $scenario = BuilderReviewFixture::generatedCycle();
    $review = BuilderReviewFixture::open($scenario['cycle']);
    BuilderReviewFixture::confirmAll($review);

    Livewire::test(BuilderReviewWorkspace::class, ['record' => $scenario['cycle']->getKey()])
        ->callAction('submitReview', ['declaration' => false])
        ->assertHasActionErrors(['declaration']);

    expect($review->fresh()->status)->toBe(SalesBoardBuilderReviewStatus::Draft);
});

it('offers no editing actions once the review is submitted', function () {
    $scenario = BuilderReviewFixture::generatedCycle();
    $review = BuilderReviewFixture::open($scenario['cycle']);
    BuilderReviewFixture::confirmAll($review);
    BuilderReviewFixture::submit($review);

    $page = Livewire::test(BuilderReviewWorkspace::class, ['record' => $scenario['cycle']->getKey()]);

    expect($page->instance()->canEdit())->toBeFalse();

    $this->get(BuilderReviewWorkspace::getUrl(['record' => $scenario['cycle']]))
        ->assertOk()
        ->assertSee('Enviada para análise')
        ->assertDontSee('Confirmar seção')
        ->assertDontSee('Apontar divergência');
});

it('shows a superseded review as history and lets it be reopened read only', function () {
    $scenario = BuilderReviewFixture::generatedCycle();
    $first = BuilderReviewFixture::open($scenario['cycle']);
    BuilderReviewFixture::confirmAll($first);
    BuilderReviewFixture::submit($first);

    $scenario['contracts']['financed']->update(['sale_value' => '910000.00']);
    CycleFixture::recalculate($scenario['cycle'], 'Correção do valor de venda.');

    Livewire::test(SalesBoardCycleBuilderReviewsRelationManager::class, [
        'ownerRecord' => $scenario['cycle']->fresh(),
        'pageClass' => ViewSalesBoardCycle::class,
    ])
        ->assertCanSeeTableRecords([$first])
        ->assertSee('Substituída por nova versão')
        ->assertTableActionDoesNotExist('edit')
        ->assertTableActionDoesNotExist('delete');

    $this->get(BuilderReviewWorkspace::getUrl(['record' => $scenario['cycle']]).'?review='.$first->id)
        ->assertOk()
        ->assertSee('Substituída por nova versão');
});

it('never offers an approve, reject or publish button', function () {
    $scenario = BuilderReviewFixture::generatedCycle();
    $review = BuilderReviewFixture::open($scenario['cycle']);
    BuilderReviewFixture::confirmAll($review);
    BuilderReviewFixture::submit($review);

    Livewire::test(ViewSalesBoardCycle::class, ['record' => $scenario['cycle']->getKey()])
        ->assertActionDoesNotExist('approve')
        ->assertActionDoesNotExist('reject')
        ->assertActionDoesNotExist('returnToBuilder')
        ->assertActionDoesNotExist('publish');

    Livewire::test(BuilderReviewWorkspace::class, ['record' => $scenario['cycle']->getKey()])
        ->assertActionDoesNotExist('approve')
        ->assertActionDoesNotExist('reject')
        ->assertActionDoesNotExist('publish');
});

it('keeps the workspace behind the sales board permission', function () {
    $scenario = BuilderReviewFixture::generatedCycle();
    BuilderReviewFixture::open($scenario['cycle']);

    $this->actingAs(User::factory()->withTwoFactor()->create());

    $this->get(BuilderReviewWorkspace::getUrl(['record' => $scenario['cycle']]))->assertForbidden();
});
