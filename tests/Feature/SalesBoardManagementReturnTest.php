<?php

use App\DTOs\SalesBoards\SalesBoardBuilderDivergenceInput;
use App\Enums\SalesBoardBuilderDivergenceType;
use App\Enums\SalesBoardBuilderReviewSection as SectionEnum;
use App\Enums\SalesBoardBuilderReviewSectionStatus;
use App\Enums\SalesBoardBuilderReviewStatus;
use App\Enums\SalesBoardCycleStatus;
use App\Enums\SalesBoardManagementReviewStatus;
use App\Enums\SalesBoardUnitClassification;
use App\Exceptions\SalesBoardManagementReviewException;
use App\Models\SalesBoard;
use App\Models\SalesBoardBuilderDivergence;
use App\Models\SalesBoardBuilderReview;
use App\Models\SalesBoardManagementReview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SalesBoards\BuilderReviewFixture;
use Tests\Support\SalesBoards\ManagementReviewFixture;

uses(RefreshDatabase::class);

it('returns the competence to the builder and opens a clean next round', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $review = ManagementReviewFixture::open($scenario['cycle']);
    $manager = User::factory()->create();

    $outcome = ManagementReviewFixture::returnToBuilder(
        $review,
        $manager,
        'Precisamos da confirmação do contrato da unidade 102.',
    );

    $returned = $review->fresh();
    $previous = $scenario['builderReview']->fresh();
    $next = $outcome['builderReview'];

    expect($returned->status)->toBe(SalesBoardManagementReviewStatus::Returned)
        ->and($returned->returned_by_user_id)->toBe($manager->id)
        ->and($returned->return_reason)->toBe('Precisamos da confirmação do contrato da unidade 102.')
        ->and($returned->returned_at)->not->toBeNull()
        // A validação enviada continua exatamente como estava.
        ->and($previous->status)->toBe(SalesBoardBuilderReviewStatus::Submitted)
        ->and($previous->attempt)->toBe(1)
        ->and($previous->submitted_at)->not->toBeNull()
        // A rodada seguinte nasce limpa, sobre o mesmo quadro.
        ->and($next->attempt)->toBe(2)
        ->and($next->status)->toBe(SalesBoardBuilderReviewStatus::Draft)
        ->and($next->snapshot_fingerprint)->toBe($previous->snapshot_fingerprint)
        ->and($next->sections)->toHaveCount(7)
        ->and($next->sections->pluck('status')->unique()->all())
        ->toBe([SalesBoardBuilderReviewSectionStatus::Pending])
        ->and($next->divergences)->toHaveCount(0)
        ->and($scenario['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::BuilderReview)
        ->and(SalesBoard::query()->count())->toBe(0);
});

it('never copies the previous declarations into the new round', function () {
    $scenario = BuilderReviewFixture::generatedCycle();
    $builderReview = BuilderReviewFixture::open($scenario['cycle']);

    BuilderReviewFixture::declare($builderReview, SectionEnum::PositionFinanced, new SalesBoardBuilderDivergenceInput(
        type: SalesBoardBuilderDivergenceType::StockMismatch,
        lineId: BuilderReviewFixture::lineFor($builderReview, $scenario['units']['financed'])->id,
        declaredClassification: SalesBoardUnitClassification::Stock,
        reason: 'A unidade deveria constar como estoque.',
    ));

    BuilderReviewFixture::confirmAll($builderReview);
    BuilderReviewFixture::submit($builderReview);

    $review = ManagementReviewFixture::open($scenario['cycle']);
    $outcome = ManagementReviewFixture::returnToBuilder($review);

    expect(SalesBoardBuilderDivergence::query()->count())->toBe(1)
        ->and($outcome['builderReview']->divergences)->toHaveCount(0)
        ->and($builderReview->fresh()->divergences)->toHaveCount(1);
});

it('refuses to return without a usable reason', function (string $reason) {
    $scenario = ManagementReviewFixture::submittedCycle();
    $review = ManagementReviewFixture::open($scenario['cycle']);

    expect(fn () => ManagementReviewFixture::returnToBuilder($review, null, $reason))
        ->toThrow(SalesBoardManagementReviewException::class, 'exige um motivo');

    expect($review->fresh()->status)->toBe(SalesBoardManagementReviewStatus::Draft)
        ->and($scenario['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::ManagementReview)
        ->and(SalesBoardBuilderReview::query()->count())->toBe(1);
})->with(['', '  ', 'ok', '-', 'rever']);

it('makes the returned round terminal: it can never be approved', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $review = ManagementReviewFixture::open($scenario['cycle']);

    ManagementReviewFixture::returnToBuilder($review);

    expect(fn () => ManagementReviewFixture::approve($review))
        ->toThrow(SalesBoardManagementReviewException::class, 'já foi encerrada');

    expect(SalesBoard::query()->count())->toBe(0);
});

it('refuses to return the same round twice', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $review = ManagementReviewFixture::open($scenario['cycle']);

    ManagementReviewFixture::returnToBuilder($review);

    expect(fn () => ManagementReviewFixture::returnToBuilder($review))
        ->toThrow(SalesBoardManagementReviewException::class, 'já foi encerrada');

    expect(SalesBoardBuilderReview::query()->count())->toBe(2);
});

it('opens a new management round when the builder submits again', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $first = ManagementReviewFixture::open($scenario['cycle']);

    $outcome = ManagementReviewFixture::returnToBuilder($first, null, 'Reenviar com o contrato conferido.');

    BuilderReviewFixture::confirmAll($outcome['builderReview']);
    BuilderReviewFixture::submit($outcome['builderReview']);

    expect($scenario['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::ManagementReview);

    $second = ManagementReviewFixture::open($scenario['cycle']);

    expect($second->id)->not->toBe($first->id)
        ->and($second->attempt)->toBe(2)
        ->and($second->status)->toBe(SalesBoardManagementReviewStatus::Draft)
        ->and($second->sales_board_builder_review_id)->toBe($outcome['builderReview']->id)
        ->and($first->fresh()->status)->toBe(SalesBoardManagementReviewStatus::Returned)
        ->and(SalesBoardManagementReview::query()->count())->toBe(2);
});

it('keeps the previous return reason readable for the new round', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $review = ManagementReviewFixture::open($scenario['cycle']);

    ManagementReviewFixture::returnToBuilder($review, null, 'Confirme a data de quitação da unidade 103.');

    $returned = SalesBoardManagementReview::query()
        ->where('sales_board_cycle_id', $scenario['cycle']->id)
        ->where('status', SalesBoardManagementReviewStatus::Returned)
        ->latest('attempt')
        ->sole();

    expect($returned->return_reason)->toBe('Confirme a data de quitação da unidade 103.');
});
