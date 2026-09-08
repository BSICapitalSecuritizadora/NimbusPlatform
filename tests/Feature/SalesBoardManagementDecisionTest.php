<?php

use App\DTOs\SalesBoards\SalesBoardBuilderDivergenceInput;
use App\Enums\SalesBoardBuilderDivergenceType;
use App\Enums\SalesBoardBuilderReviewSection as SectionEnum;
use App\Enums\SalesBoardNonconformityDecision;
use App\Enums\SalesBoardNonconformityOrigin;
use App\Enums\SalesBoardUnitClassification;
use App\Exceptions\SalesBoardManagementReviewException;
use App\Models\SalesBoardManagementNonconformity;
use App\Models\SalesBoardManagementReview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SalesBoards\BuilderReviewFixture;
use Tests\Support\SalesBoards\ManagementReviewFixture;

uses(RefreshDatabase::class);

/**
 * Uma análise aberta com uma pendência declarada pela construtora.
 *
 * @return array{review: SalesBoardManagementReview, item: SalesBoardManagementNonconformity, scenario: array}
 */
function declaredNonconformity(): array
{
    $scenario = BuilderReviewFixture::generatedCycle();
    $builderReview = BuilderReviewFixture::open($scenario['cycle']);

    BuilderReviewFixture::declare($builderReview, SectionEnum::PositionFinanced, new SalesBoardBuilderDivergenceInput(
        type: SalesBoardBuilderDivergenceType::StockMismatch,
        lineId: BuilderReviewFixture::lineFor($builderReview, $scenario['units']['financed'])->id,
        declaredClassification: SalesBoardUnitClassification::Stock,
        reason: 'A unidade foi distratada em junho e voltou ao estoque.',
    ));

    BuilderReviewFixture::confirmAll($builderReview);
    BuilderReviewFixture::submit($builderReview);

    $review = ManagementReviewFixture::open($scenario['cycle']);

    return [
        'review' => $review,
        'item' => ManagementReviewFixture::nonconformityOf($review, SalesBoardNonconformityOrigin::BuilderDeclared),
        'scenario' => $scenario,
    ];
}

/**
 * @return array{review: SalesBoardManagementReview, item: SalesBoardManagementNonconformity, scenario: array}
 */
function systemNonconformity(): array
{
    $scenario = ManagementReviewFixture::submittedCycleWithNonConformSale();
    $review = ManagementReviewFixture::open($scenario['cycle']);

    return [
        'review' => $review,
        'item' => ManagementReviewFixture::nonconformityOf($review, SalesBoardNonconformityOrigin::SystemSaleNonConform),
        'scenario' => $scenario,
    ];
}

it('lets the management dismiss a builder declaration', function () {
    $context = declaredNonconformity();
    $actor = User::factory()->create();

    $decided = ManagementReviewFixture::decide(
        $context['item'],
        SalesBoardNonconformityDecision::Dismissed,
        'O distrato citado é de julho e já entrou na competência seguinte.',
        $actor,
    );

    expect($decided->decision)->toBe(SalesBoardNonconformityDecision::Dismissed)
        ->and($decided->decision_reason)->toBe('O distrato citado é de julho e já entrou na competência seguinte.')
        ->and($decided->decided_by_user_id)->toBe($actor->id)
        ->and($decided->decided_at)->not->toBeNull();
});

it('lets the management require a source correction for a builder declaration', function () {
    $context = declaredNonconformity();

    $decided = ManagementReviewFixture::decide(
        $context['item'],
        SalesBoardNonconformityDecision::CorrectionRequired,
    );

    expect($decided->decision)->toBe(SalesBoardNonconformityDecision::CorrectionRequired)
        ->and($decided->blocksApproval())->toBeTrue();
});

it('refuses to accept a builder declaration as an authorized exception', function () {
    $context = declaredNonconformity();

    expect(fn () => ManagementReviewFixture::decide(
        $context['item'],
        SalesBoardNonconformityDecision::AcceptedException,
    ))->toThrow(SalesBoardManagementReviewException::class, 'declaração da construtora não pode terminar');

    expect($context['item']->fresh()->decision)->toBe(SalesBoardNonconformityDecision::Pending);
});

it('lets the management accept an exception for a non conform sale', function () {
    $context = systemNonconformity();

    $decided = ManagementReviewFixture::decide(
        $context['item'],
        SalesBoardNonconformityDecision::AcceptedException,
        'Desconto autorizado pela diretoria comercial na ata de 12/07.',
    );

    expect($decided->decision)->toBe(SalesBoardNonconformityDecision::AcceptedException)
        ->and($decided->blocksApproval())->toBeFalse();
});

it('lets the management require a source correction for a non conform sale', function () {
    $context = systemNonconformity();

    $decided = ManagementReviewFixture::decide(
        $context['item'],
        SalesBoardNonconformityDecision::CorrectionRequired,
    );

    expect($decided->decision)->toBe(SalesBoardNonconformityDecision::CorrectionRequired);
});

it('refuses to dismiss a non conform sale', function () {
    $context = systemNonconformity();

    expect(fn () => ManagementReviewFixture::decide(
        $context['item'],
        SalesBoardNonconformityDecision::Dismissed,
    ))->toThrow(SalesBoardManagementReviewException::class, 'venda fora da política não pode terminar');

    expect($context['item']->fresh()->decision)->toBe(SalesBoardNonconformityDecision::Pending);
});

it('refuses a decision without a usable reason', function (string $reason) {
    $context = systemNonconformity();

    expect(fn () => ManagementReviewFixture::decide(
        $context['item'],
        SalesBoardNonconformityDecision::AcceptedException,
        $reason,
    ))->toThrow(SalesBoardManagementReviewException::class, 'exige um motivo');

    expect($context['item']->fresh()->decision)->toBe(SalesBoardNonconformityDecision::Pending);
})->with(['', ' ', 'ok', '.', '-', '   ok   ', '<b></b>']);

it('lets the management change its mind while the review is a draft', function () {
    $context = declaredNonconformity();

    ManagementReviewFixture::decide(
        $context['item'],
        SalesBoardNonconformityDecision::Dismissed,
        'A princípio a declaração não se sustenta nos registros.',
    );

    $final = ManagementReviewFixture::decide(
        $context['item']->fresh(),
        SalesBoardNonconformityDecision::CorrectionRequired,
        'Revisto: o contrato precisa ser corrigido no cadastro.',
    );

    expect($final->decision)->toBe(SalesBoardNonconformityDecision::CorrectionRequired)
        ->and($final->decision_reason)->toBe('Revisto: o contrato precisa ser corrigido no cadastro.');
});

it('clears authorship and reason when a decision is taken back to pending', function () {
    $context = declaredNonconformity();
    $actor = User::factory()->create();

    ManagementReviewFixture::decide(
        $context['item'],
        SalesBoardNonconformityDecision::Dismissed,
        'A declaração não se sustenta nos registros do contrato.',
        $actor,
    );

    $reset = ManagementReviewFixture::decide(
        $context['item']->fresh(),
        SalesBoardNonconformityDecision::Pending,
        null,
    );

    expect($reset->decision)->toBe(SalesBoardNonconformityDecision::Pending)
        ->and($reset->decision_reason)->toBeNull()
        ->and($reset->decided_at)->toBeNull()
        ->and($reset->decided_by_user_id)->toBeNull();
});

it('refuses a reason when the decision is taken back to pending', function () {
    $context = declaredNonconformity();

    expect(fn () => ManagementReviewFixture::decide(
        $context['item'],
        SalesBoardNonconformityDecision::Pending,
        'Vou pensar melhor sobre esta pendência.',
    ))->toThrow(SalesBoardManagementReviewException::class, 'não aceita motivo');
});

it('freezes the decisions once the review is finished', function () {
    $context = declaredNonconformity();

    ManagementReviewFixture::decide(
        $context['item'],
        SalesBoardNonconformityDecision::Dismissed,
        'A declaração não se sustenta nos registros do contrato.',
    );

    ManagementReviewFixture::returnToBuilder($context['review']);

    expect(fn () => ManagementReviewFixture::decide(
        $context['item']->fresh(),
        SalesBoardNonconformityDecision::CorrectionRequired,
    ))->toThrow(SalesBoardManagementReviewException::class, 'já foi encerrada');
});

it('never deletes a nonconformity', function () {
    $context = systemNonconformity();

    expect(fn () => $context['item']->delete())
        ->toThrow(LogicException::class, 'cannot be deleted');
});

it('never lets a nonconformity change the fact it anchors', function () {
    $context = systemNonconformity();

    expect(fn () => $context['item']->forceFill(['sales_board_cycle_movement_id' => null])->save())
        ->toThrow(LogicException::class);
});

it('refuses a nonconformity that does not anchor exactly what its origin requires', function () {
    $context = systemNonconformity();

    expect(fn () => SalesBoardManagementNonconformity::query()->create([
        'sales_board_management_review_id' => $context['review']->id,
        'origin' => SalesBoardNonconformityOrigin::BuilderDeclared,
        'sales_board_builder_divergence_id' => null,
        'sales_board_cycle_movement_id' => null,
        'decision' => SalesBoardNonconformityDecision::Pending,
    ]))->toThrow(LogicException::class, 'anchor exactly the reference');
});
