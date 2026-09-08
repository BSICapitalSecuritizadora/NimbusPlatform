<?php

use App\Enums\SalesBoardBuilderReviewSection as SectionEnum;
use App\Enums\SalesBoardBuilderReviewSectionStatus;
use App\Enums\SalesBoardBuilderReviewStatus;
use App\Enums\SalesBoardCycleStatus;
use App\Enums\SalesBoardStaleImpact;
use App\Exceptions\SalesBoardBuilderReviewException;
use App\Models\ContractInstallment;
use App\Models\SalesBoardBuilderReview;
use App\Models\SalesBoardBuilderReviewSection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SalesBoards\BuilderReviewFixture;
use Tests\Support\SalesBoards\CycleFixture;

uses(RefreshDatabase::class);

it('opens a draft review anchored on the current baseline', function () {
    $scenario = BuilderReviewFixture::generatedCycle();
    $actor = User::factory()->create();

    $review = BuilderReviewFixture::open($scenario['cycle'], $actor);
    $baseline = CycleFixture::currentBaseline($scenario['cycle']);

    expect($review->status)->toBe(SalesBoardBuilderReviewStatus::Draft)
        ->and($review->attempt)->toBe(1)
        ->and($review->sales_board_cycle_baseline_id)->toBe($baseline->id)
        ->and($review->snapshot_fingerprint)->toBe($baseline->snapshot_fingerprint)
        ->and($review->opened_by_user_id)->toBe($actor->id)
        ->and($review->submitted_at)->toBeNull();
});

it('creates exactly the seven sections, all pending', function () {
    $scenario = BuilderReviewFixture::generatedCycle();

    $review = BuilderReviewFixture::open($scenario['cycle']);

    expect($review->sections)->toHaveCount(7)
        ->and($review->sections->pluck('section')->all())->toEqualCanonicalizing(SectionEnum::ordered())
        ->and($review->sections->pluck('status')->unique()->all())
        ->toBe([SalesBoardBuilderReviewSectionStatus::Pending]);
});

it('moves the cycle into builder review', function () {
    $scenario = BuilderReviewFixture::generatedCycle();

    expect($scenario['cycle']->status)->toBe(SalesBoardCycleStatus::Generated);

    BuilderReviewFixture::open($scenario['cycle']);

    expect($scenario['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::BuilderReview);
});

it('returns the same draft when opening twice', function () {
    $scenario = BuilderReviewFixture::generatedCycle();

    $first = BuilderReviewFixture::open($scenario['cycle']);
    $second = BuilderReviewFixture::open($scenario['cycle']);

    expect($second->id)->toBe($first->id)
        ->and(SalesBoardBuilderReview::query()->count())->toBe(1)
        ->and(SalesBoardBuilderReviewSection::query()->count())->toBe(7);
});

it('refuses to open when the position has a pending material change', function () {
    $scenario = BuilderReviewFixture::generatedCycle();

    $scenario['contracts']['financed']->update(['sale_value' => '910000.00']);

    expect(fn () => BuilderReviewFixture::open($scenario['cycle']))
        ->toThrow(SalesBoardBuilderReviewException::class, 'alteração material pendente');

    expect(SalesBoardBuilderReview::query()->count())->toBe(0)
        ->and($scenario['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::Generated)
        ->and(CycleFixture::currentBaseline($scenario['cycle'])->stale_impact)->toBe(SalesBoardStaleImpact::Material);
});

it('refuses to open when the current source no longer passes readiness', function () {
    $scenario = BuilderReviewFixture::generatedCycle();

    ContractInstallment::query()
        ->where('contract_id', $scenario['contracts']['financed']->id)
        ->delete();

    expect(fn () => BuilderReviewFixture::open($scenario['cycle']))
        ->toThrow(SalesBoardBuilderReviewException::class, 'fonte da competência está incompleta');

    expect(SalesBoardBuilderReview::query()->count())->toBe(0);
});

it('opens when the only change was to the material source, with the same position', function () {
    $scenario = BuilderReviewFixture::generatedCycle();

    // Parcela de contrato distratado na competência: fato material que esta
    // posição não usa, então o quadro apresentado continua idêntico.
    ContractInstallment::query()
        ->where('contract_id', $scenario['contracts']['cancelled']->id)
        ->firstOrFail()
        ->update(['expected_value' => '469000.00']);

    $review = BuilderReviewFixture::open($scenario['cycle']);

    expect($review->status)->toBe(SalesBoardBuilderReviewStatus::Draft)
        ->and(CycleFixture::currentBaseline($scenario['cycle'])->stale_impact)->toBe(SalesBoardStaleImpact::SourceOnly);
});

it('refuses to open a review for a cycle already under management analysis', function () {
    $scenario = BuilderReviewFixture::generatedCycle();

    $review = BuilderReviewFixture::open($scenario['cycle']);
    BuilderReviewFixture::confirmAll($review);
    BuilderReviewFixture::submit($review);

    expect(fn () => BuilderReviewFixture::open($scenario['cycle']))
        ->toThrow(SalesBoardBuilderReviewException::class, 'Em análise da Gestão');
});

it('never touches the snapshot when opening a review', function () {
    $scenario = BuilderReviewFixture::generatedCycle();
    $baseline = CycleFixture::currentBaseline($scenario['cycle']);

    $before = [
        $baseline->snapshot_fingerprint,
        $baseline->lines->map(fn ($line): string => $line->snapshot_fingerprint)->sort()->values()->all(),
        $baseline->movements->map(fn ($movement): string => $movement->snapshot_fingerprint)->sort()->values()->all(),
    ];

    BuilderReviewFixture::open($scenario['cycle']);

    $after = $baseline->fresh()->load(['lines', 'movements']);

    expect($after->snapshot_fingerprint)->toBe($before[0])
        ->and($after->lines->map(fn ($line): string => $line->snapshot_fingerprint)->sort()->values()->all())->toBe($before[1])
        ->and($after->movements->map(fn ($movement): string => $movement->snapshot_fingerprint)->sort()->values()->all())->toBe($before[2]);
});
