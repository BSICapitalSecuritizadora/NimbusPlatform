<?php

use App\DTOs\SalesBoards\SalesBoardBuilderDivergenceInput;
use App\Enums\SalesBoardBuilderDivergenceType;
use App\Enums\SalesBoardBuilderReviewSection as SectionEnum;
use App\Enums\SalesBoardBuilderReviewSectionStatus;
use App\Enums\SalesBoardUnitClassification;
use App\Models\SalesBoardBuilderDivergence;
use App\Models\SalesBoardBuilderReview;
use App\Models\SalesBoardBuilderReviewSection;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardCycleBaseline;
use App\Models\SalesBoardCycleLine;
use App\Models\User;
use App\Support\Money\IntegerMoney;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SalesBoards\BuilderReviewFixture;

uses(RefreshDatabase::class);

/**
 * As garantias da validação que dependem de o banco ser um banco específico:
 * unique, RESTRICT e o round-trip exato do que a construtora declarou.
 */
pest()->group('parity');

/**
 * @return array{review: SalesBoardBuilderReview, scenario: array}
 */
function parityReview(): array
{
    $scenario = BuilderReviewFixture::generatedCycle();
    $review = BuilderReviewFixture::open($scenario['cycle']);

    return ['review' => $review, 'scenario' => $scenario];
}

it('allows a single review attempt per cycle', function () {
    $context = parityReview();

    SalesBoardBuilderReview::factory()->create([
        'sales_board_cycle_id' => $context['review']->sales_board_cycle_id,
        'sales_board_cycle_baseline_id' => $context['review']->sales_board_cycle_baseline_id,
        'attempt' => 1,
    ]);
})->throws(UniqueConstraintViolationException::class);

it('allows a single row per section of a review', function () {
    $context = parityReview();

    SalesBoardBuilderReviewSection::factory()->create([
        'sales_board_builder_review_id' => $context['review']->id,
        'section' => SectionEnum::PositionStock,
    ]);
})->throws(UniqueConstraintViolationException::class);

it('refuses to delete a cycle that has a review', function () {
    $context = parityReview();

    SalesBoardCycle::query()->whereKey($context['review']->sales_board_cycle_id)->delete();
})->throws(QueryException::class);

it('refuses to delete a baseline that was reviewed', function () {
    $context = parityReview();

    SalesBoardCycleBaseline::query()
        ->whereKey($context['review']->sales_board_cycle_baseline_id)
        ->delete();
})->throws(QueryException::class);

it('refuses to delete a frozen line that a divergence points at', function () {
    $context = parityReview();
    $line = BuilderReviewFixture::lineFor($context['review'], $context['scenario']['units']['financed']);

    BuilderReviewFixture::declare($context['review'], SectionEnum::PositionFinanced, new SalesBoardBuilderDivergenceInput(
        type: SalesBoardBuilderDivergenceType::StockMismatch,
        reason: 'Deveria estar em estoque.',
        lineId: $line->id,
        declaredClassification: SalesBoardUnitClassification::Stock,
    ));

    SalesBoardCycleLine::query()->whereKey($line->id)->delete();
})->throws(QueryException::class);

it('reads back exactly what the builder declared, in either engine', function () {
    $context = parityReview();
    $line = BuilderReviewFixture::lineFor($context['review'], $context['scenario']['units']['stock']);

    $divergence = BuilderReviewFixture::declare($context['review'], SectionEnum::PositionStock, new SalesBoardBuilderDivergenceInput(
        type: SalesBoardBuilderDivergenceType::UnitValueMismatch,
        reason: 'A tabela vigente em julho era de R$ 512.345,67.',
        lineId: $line->id,
        declaredValueCents: 51_234_567,
        declaredDate: CarbonImmutable::parse('2026-07-15'),
    ));

    $fresh = $divergence->fresh();

    expect($fresh->declared_value)->toBe('512345.67')
        ->and(IntegerMoney::cents($fresh->declared_value))->toBe(51_234_567)
        ->and($fresh->declared_date->toDateString())->toBe('2026-07-15')
        ->and($fresh->type)->toBe(SalesBoardBuilderDivergenceType::UnitValueMismatch)
        ->and($fresh->reason)->toBe('A tabela vigente em julho era de R$ 512.345,67.');
});

it('reads back the frozen reviewer identity, in either engine', function () {
    $context = parityReview();
    $actor = User::factory()->create(['name' => 'Responsável Comercial']);

    BuilderReviewFixture::confirmAll($context['review']);
    $submitted = BuilderReviewFixture::submit($context['review'], $actor);

    $fresh = $submitted->fresh();

    expect($fresh->reviewer_name)->toBe('Responsável Comercial')
        ->and($fresh->reviewer_key)->toBe('user:'.$actor->id)
        ->and($fresh->reviewer_email)->toBe($actor->email)
        ->and($fresh->snapshot_fingerprint)->toHaveLength(64)
        ->and($fresh->submitted_at)->not->toBeNull();
});

it('stores the seven sections and their statuses, in either engine', function () {
    $context = parityReview();
    $line = BuilderReviewFixture::lineFor($context['review'], $context['scenario']['units']['financed']);

    BuilderReviewFixture::declare($context['review'], SectionEnum::PositionFinanced, new SalesBoardBuilderDivergenceInput(
        type: SalesBoardBuilderDivergenceType::StockMismatch,
        reason: 'Deveria estar em estoque.',
        lineId: $line->id,
        declaredClassification: SalesBoardUnitClassification::Stock,
    ));
    BuilderReviewFixture::confirmAll($context['review']);

    $sections = $context['review']->fresh()->sections;

    expect($sections)->toHaveCount(7)
        ->and($sections->pluck('section')->all())->toBe(SectionEnum::ordered())
        ->and($sections->firstWhere('section', SectionEnum::PositionFinanced)->status)
        ->toBe(SalesBoardBuilderReviewSectionStatus::Divergent)
        ->and($sections->firstWhere('section', SectionEnum::PositionStock)->status)
        ->toBe(SalesBoardBuilderReviewSectionStatus::Confirmed)
        ->and(SalesBoardBuilderDivergence::query()->count())->toBe(1);
});
