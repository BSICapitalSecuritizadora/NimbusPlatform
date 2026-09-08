<?php

use App\DTOs\SalesBoards\SalesBoardBuilderDivergenceInput;
use App\Enums\SalesBoardBuilderDivergenceType;
use App\Enums\SalesBoardBuilderReviewSection as SectionEnum;
use App\Enums\SalesBoardCycleStatus;
use App\Enums\SalesBoardManagementReviewStatus;
use App\Enums\SalesBoardMovementType;
use App\Enums\SalesBoardNonconformityDecision;
use App\Enums\SalesBoardNonconformityOrigin;
use App\Enums\SalesBoardUnitClassification;
use App\Enums\SalesPriceConformityStatus;
use App\Exceptions\SalesBoardManagementReviewException;
use App\Models\SalesBoardCycleMovement;
use App\Models\SalesBoardManagementNonconformity;
use App\Models\SalesBoardManagementReview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SalesBoards\BuilderReviewFixture;
use Tests\Support\SalesBoards\CycleFixture;
use Tests\Support\SalesBoards\ManagementReviewFixture;

uses(RefreshDatabase::class);

it('opens a draft management review anchored on the current baseline and the submitted builder review', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $actor = User::factory()->create();

    $review = ManagementReviewFixture::open($scenario['cycle'], $actor);
    $baseline = CycleFixture::currentBaseline($scenario['cycle']);

    expect($review->status)->toBe(SalesBoardManagementReviewStatus::Draft)
        ->and($review->attempt)->toBe(1)
        ->and($review->sales_board_cycle_baseline_id)->toBe($baseline->id)
        ->and($review->sales_board_builder_review_id)->toBe($scenario['builderReview']->id)
        ->and($review->snapshot_fingerprint)->toBe($baseline->snapshot_fingerprint)
        ->and($review->opened_by_user_id)->toBe($actor->id)
        ->and($review->approved_at)->toBeNull()
        ->and($review->returned_at)->toBeNull()
        ->and($review->source_changed)->toBeFalse();
});

it('leaves the cycle in management review when opening', function () {
    $scenario = ManagementReviewFixture::submittedCycle();

    expect($scenario['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::ManagementReview);

    ManagementReviewFixture::open($scenario['cycle']);

    expect($scenario['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::ManagementReview);
});

it('materializes one nonconformity per builder divergence', function () {
    $scenario = BuilderReviewFixture::generatedCycle();
    $review = BuilderReviewFixture::open($scenario['cycle']);

    BuilderReviewFixture::declare($review, SectionEnum::PositionFinanced, new SalesBoardBuilderDivergenceInput(
        type: SalesBoardBuilderDivergenceType::StockMismatch,
        lineId: BuilderReviewFixture::lineFor($review, $scenario['units']['financed'])->id,
        declaredClassification: SalesBoardUnitClassification::Stock,
        reason: 'A unidade foi distratada em junho e voltou ao estoque.',
    ));

    BuilderReviewFixture::declare($review, SectionEnum::PositionSettled, new SalesBoardBuilderDivergenceInput(
        type: SalesBoardBuilderDivergenceType::SettlementMismatch,
        lineId: BuilderReviewFixture::lineFor($review, $scenario['units']['settled'])->id,
        reason: 'A última parcela ainda não foi compensada no banco.',
    ));

    BuilderReviewFixture::confirmAll($review);
    BuilderReviewFixture::submit($review);

    $management = ManagementReviewFixture::open($scenario['cycle']);

    $declared = $management->nonconformities
        ->where('origin', SalesBoardNonconformityOrigin::BuilderDeclared);

    expect($declared)->toHaveCount(2)
        ->and($declared->pluck('sales_board_builder_divergence_id')->sort()->values()->all())
        ->toBe($review->fresh()->divergences->pluck('id')->sort()->values()->all())
        ->and($declared->pluck('sales_board_cycle_movement_id')->unique()->all())->toBe([null])
        ->and($declared->pluck('decision')->unique()->all())->toBe([SalesBoardNonconformityDecision::Pending]);
});

it('materializes one nonconformity per non conform sale and none for conform sales', function () {
    $scenario = ManagementReviewFixture::submittedCycleWithNonConformSale();

    $review = ManagementReviewFixture::open($scenario['cycle']);
    $baseline = CycleFixture::currentBaseline($scenario['cycle']);

    $sales = SalesBoardCycleMovement::query()
        ->where('sales_board_cycle_baseline_id', $baseline->id)
        ->where('movement_type', SalesBoardMovementType::Sale)
        ->get();

    $nonConform = $sales->where('conformity_status', SalesPriceConformityStatus::NonConform);

    expect($sales)->toHaveCount(1)
        ->and($nonConform)->toHaveCount(1);

    $system = $review->nonconformities->where('origin', SalesBoardNonconformityOrigin::SystemSaleNonConform);

    expect($system)->toHaveCount(1)
        ->and($system->first()->sales_board_cycle_movement_id)->toBe($nonConform->first()->id)
        ->and($system->first()->sales_board_builder_divergence_id)->toBeNull()
        ->and($system->first()->decision)->toBe(SalesBoardNonconformityDecision::Pending);
});

it('creates no nonconformity when every sale is conform and nothing was declared', function () {
    $scenario = ManagementReviewFixture::submittedCycle();

    $review = ManagementReviewFixture::open($scenario['cycle']);

    expect($review->nonconformities)->toHaveCount(0)
        ->and(SalesBoardManagementNonconformity::query()->count())->toBe(0);
});

it('returns the same draft when opening twice, without duplicating nonconformities', function () {
    $scenario = ManagementReviewFixture::submittedCycleWithNonConformSale();

    $first = ManagementReviewFixture::open($scenario['cycle']);
    $second = ManagementReviewFixture::open($scenario['cycle']);

    expect($second->id)->toBe($first->id)
        ->and(SalesBoardManagementReview::query()->count())->toBe(1)
        ->and(SalesBoardManagementNonconformity::query()->count())->toBe(1);
});

it('refuses to open when the cycle is not in management review', function () {
    $scenario = BuilderReviewFixture::generatedCycle();

    expect($scenario['cycle']->status)->toBe(SalesBoardCycleStatus::Generated);

    expect(fn () => ManagementReviewFixture::open($scenario['cycle']))
        ->toThrow(SalesBoardManagementReviewException::class, 'não está em análise da Gestão');

    expect(SalesBoardManagementReview::query()->count())->toBe(0);
});

it('refuses to open when the submitted builder review no longer applies to the current baseline', function () {
    $scenario = ManagementReviewFixture::submittedCycle();

    // Uma versão material nova substitui a validação e devolve o ciclo à
    // construtora: não há mais submissão aplicável para a Gestão analisar.
    $scenario['contracts']['financed']->update(['sale_value' => '910000.00']);
    CycleFixture::recalculate($scenario['cycle'], 'Correção do valor de venda.');

    expect(fn () => ManagementReviewFixture::open($scenario['cycle']))
        ->toThrow(SalesBoardManagementReviewException::class);

    expect(SalesBoardManagementReview::query()->count())->toBe(0);
});

it('numbers management attempts independently of builder attempts', function () {
    $scenario = ManagementReviewFixture::submittedCycle();

    $first = ManagementReviewFixture::open($scenario['cycle']);
    ManagementReviewFixture::returnToBuilder($first);

    $newBuilderReview = $scenario['cycle']->fresh()->builderReviews()->first();
    BuilderReviewFixture::confirmAll($newBuilderReview);
    BuilderReviewFixture::submit($newBuilderReview);

    $second = ManagementReviewFixture::open($scenario['cycle']);

    expect($first->fresh()->attempt)->toBe(1)
        ->and($second->attempt)->toBe(2)
        ->and($first->fresh()->status)->toBe(SalesBoardManagementReviewStatus::Returned)
        ->and($second->status)->toBe(SalesBoardManagementReviewStatus::Draft);
});
