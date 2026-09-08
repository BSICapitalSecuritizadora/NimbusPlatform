<?php

use App\DTOs\SalesBoards\SalesBoardBuilderDivergenceInput;
use App\Enums\SalesBoardBuilderDivergenceType;
use App\Enums\SalesBoardBuilderReviewSection as SectionEnum;
use App\Enums\SalesBoardBuilderReviewSectionStatus;
use App\Enums\SalesBoardBuilderReviewStatus;
use App\Enums\SalesBoardCycleStatus;
use App\Enums\SalesBoardRecalculationOutcome;
use App\Enums\SalesBoardUnitClassification;
use App\Exceptions\SalesBoardBuilderReviewException;
use App\Models\ContractInstallment;
use App\Models\SalesBoardBuilderDivergence;
use App\Models\SalesBoardBuilderReview;
use App\Services\SalesBoards\SalesBoardBuilderReviewApplicability;
use App\Services\SalesBoards\SalesBoardBuilderReviewEditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SalesBoards\BuilderReviewFixture;
use Tests\Support\SalesBoards\CycleFixture;

uses(RefreshDatabase::class);

it('supersedes a submitted review when a material recalculation lands', function () {
    $scenario = BuilderReviewFixture::generatedCycle();
    $review = BuilderReviewFixture::open($scenario['cycle']);
    BuilderReviewFixture::confirmAll($review);
    $submitted = BuilderReviewFixture::submit($review);

    expect($scenario['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::ManagementReview);

    $scenario['contracts']['financed']->update(['sale_value' => '910000.00']);
    $result = CycleFixture::recalculate($scenario['cycle'], 'Correção do valor de venda.');

    expect($result->baseline?->version)->toBe(2)
        ->and($submitted->fresh()->status)->toBe(SalesBoardBuilderReviewStatus::Superseded)
        ->and($submitted->fresh()->superseded_at)->not->toBeNull()
        ->and($submitted->fresh()->superseded_reason)->toBe('nova_versao_material')
        // A competência volta a depender da construtora.
        ->and($scenario['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::BuilderReview);
});

it('keeps everything the builder declared on a superseded review', function () {
    $scenario = BuilderReviewFixture::generatedCycle();
    $review = BuilderReviewFixture::open($scenario['cycle']);

    $line = BuilderReviewFixture::lineFor($review, $scenario['units']['financed']);
    BuilderReviewFixture::declare($review, SectionEnum::PositionFinanced, new SalesBoardBuilderDivergenceInput(
        type: SalesBoardBuilderDivergenceType::StockMismatch,
        reason: 'Deveria estar em estoque.',
        lineId: $line->id,
        declaredClassification: SalesBoardUnitClassification::Stock,
    ));
    BuilderReviewFixture::confirmAll($review);
    $submitted = BuilderReviewFixture::submit($review);

    $scenario['contracts']['financed']->update(['sale_value' => '910000.00']);
    CycleFixture::recalculate($scenario['cycle'], 'Correção do valor de venda.');

    $superseded = $submitted->fresh()->load(['sections', 'divergences']);

    expect($superseded->status)->toBe(SalesBoardBuilderReviewStatus::Superseded)
        ->and($superseded->sections)->toHaveCount(7)
        ->and($superseded->divergences)->toHaveCount(1)
        ->and($superseded->divergences->first()->reason)->toBe('Deveria estar em estoque.')
        ->and($superseded->reviewer_name)->not->toBeNull()
        ->and($superseded->submitted_at)->not->toBeNull()
        ->and(SalesBoardBuilderDivergence::query()->count())->toBe(1);
});

it('keeps a submitted review applicable when the recalculation only changed the source', function () {
    $scenario = BuilderReviewFixture::generatedCycle();
    $review = BuilderReviewFixture::open($scenario['cycle']);
    BuilderReviewFixture::confirmAll($review);
    $submitted = BuilderReviewFixture::submit($review);

    $baselineV1 = CycleFixture::currentBaseline($scenario['cycle']);

    // Parcela de contrato distratado: fonte material muda, quadro não.
    ContractInstallment::query()
        ->where('contract_id', $scenario['contracts']['cancelled']->id)
        ->firstOrFail()
        ->update(['expected_value' => '469000.00']);

    $result = CycleFixture::recalculate($scenario['cycle'], 'Correção do cronograma do contrato distratado.');

    expect($result->baseline?->version)->toBe(2)
        ->and($result->baseline?->snapshot_fingerprint)->toBe($baselineV1->snapshot_fingerprint)
        // A construtora viu exatamente o mesmo quadro: nada a revalidar.
        ->and($submitted->fresh()->status)->toBe(SalesBoardBuilderReviewStatus::Submitted)
        ->and($submitted->fresh()->superseded_at)->toBeNull()
        ->and($scenario['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::ManagementReview)
        ->and(app(SalesBoardBuilderReviewApplicability::class)->applicableReview($scenario['cycle']->fresh())?->id)
        ->toBe($submitted->id);
});

it('supersedes a draft review when a material recalculation lands', function () {
    $scenario = BuilderReviewFixture::generatedCycle();
    $review = BuilderReviewFixture::open($scenario['cycle']);

    $line = BuilderReviewFixture::lineFor($review, $scenario['units']['financed']);
    BuilderReviewFixture::declare($review, SectionEnum::PositionFinanced, new SalesBoardBuilderDivergenceInput(
        type: SalesBoardBuilderDivergenceType::StockMismatch,
        reason: 'Deveria estar em estoque.',
        lineId: $line->id,
        declaredClassification: SalesBoardUnitClassification::Stock,
    ));

    $scenario['contracts']['financed']->update(['sale_value' => '910000.00']);
    CycleFixture::recalculate($scenario['cycle'], 'Correção do valor de venda.');

    expect($review->fresh()->status)->toBe(SalesBoardBuilderReviewStatus::Superseded)
        ->and($scenario['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::BuilderReview);

    expect(fn () => BuilderReviewFixture::submit($review))
        ->toThrow(SalesBoardBuilderReviewException::class, 'já foi enviada e não pode mais ser alterada');
});

it('starts the next attempt clean, against the new version', function () {
    $scenario = BuilderReviewFixture::generatedCycle();
    $first = BuilderReviewFixture::open($scenario['cycle']);

    $line = BuilderReviewFixture::lineFor($first, $scenario['units']['financed']);
    BuilderReviewFixture::declare($first, SectionEnum::PositionFinanced, new SalesBoardBuilderDivergenceInput(
        type: SalesBoardBuilderDivergenceType::StockMismatch,
        reason: 'Deveria estar em estoque.',
        lineId: $line->id,
        declaredClassification: SalesBoardUnitClassification::Stock,
    ));

    $scenario['contracts']['financed']->update(['sale_value' => '910000.00']);
    CycleFixture::recalculate($scenario['cycle'], 'Correção do valor de venda.');

    $second = BuilderReviewFixture::open($scenario['cycle']);
    $v2 = CycleFixture::currentBaseline($scenario['cycle']);

    expect($second->id)->not->toBe($first->id)
        ->and($second->attempt)->toBe(2)
        ->and($second->sales_board_cycle_baseline_id)->toBe($v2->id)
        ->and($second->snapshot_fingerprint)->toBe($v2->snapshot_fingerprint)
        // Começa limpa: nada é copiado da tentativa anterior.
        ->and($second->divergences)->toHaveCount(0)
        ->and($second->sections->pluck('status')->unique()->all())
        ->toBe([SalesBoardBuilderReviewSectionStatus::Pending])
        ->and(SalesBoardBuilderReview::query()->count())->toBe(2)
        // A declaração anterior continua lá, referente à V1.
        ->and($first->fresh()->divergences)->toHaveCount(1);
});

it('does not supersede anything when the recalculation is a no-op', function () {
    $scenario = BuilderReviewFixture::generatedCycle();
    $review = BuilderReviewFixture::open($scenario['cycle']);
    BuilderReviewFixture::confirmAll($review);
    $submitted = BuilderReviewFixture::submit($review);

    $result = CycleFixture::recalculate($scenario['cycle'], 'Conferência de rotina.');

    expect($result->outcome)->toBe(SalesBoardRecalculationOutcome::Unchanged)
        ->and($submitted->fresh()->status)->toBe(SalesBoardBuilderReviewStatus::Submitted)
        ->and($scenario['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::ManagementReview);
});

it('blocks editing while the position has a pending material change', function () {
    $scenario = BuilderReviewFixture::generatedCycle();
    $review = BuilderReviewFixture::open($scenario['cycle']);

    $scenario['contracts']['financed']->update(['sale_value' => '910000.00']);
    CycleFixture::check($scenario['cycle']);

    $section = BuilderReviewFixture::section($review, SectionEnum::PositionStock);

    expect(fn () => app(SalesBoardBuilderReviewEditor::class)->confirmSection($section))
        ->toThrow(SalesBoardBuilderReviewException::class, 'alteração material pendente');

    // Continua consultável: só a escrita é barrada.
    expect($review->fresh()->status)->toBe(SalesBoardBuilderReviewStatus::Draft)
        ->and($review->fresh()->sections)->toHaveCount(7);
});

it('blocks submission while the position has a pending material change', function () {
    $scenario = BuilderReviewFixture::generatedCycle();
    $review = BuilderReviewFixture::open($scenario['cycle']);
    BuilderReviewFixture::confirmAll($review);

    $scenario['contracts']['financed']->update(['sale_value' => '910000.00']);

    expect(fn () => BuilderReviewFixture::submit($review))
        ->toThrow(SalesBoardBuilderReviewException::class, 'alteração material pendente');

    expect($review->fresh()->status)->toBe(SalesBoardBuilderReviewStatus::Draft)
        ->and($scenario['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::BuilderReview);
});

it('does not supersede a review of another cycle', function () {
    $a = BuilderReviewFixture::generatedCycle();
    $b = BuilderReviewFixture::generatedCycle();

    $reviewA = BuilderReviewFixture::open($a['cycle']);
    $reviewB = BuilderReviewFixture::open($b['cycle']);
    BuilderReviewFixture::confirmAll($reviewB);
    BuilderReviewFixture::submit($reviewB);

    $a['contracts']['financed']->update(['sale_value' => '910000.00']);
    CycleFixture::recalculate($a['cycle'], 'Correção no empreendimento A.');

    expect($reviewA->fresh()->status)->toBe(SalesBoardBuilderReviewStatus::Superseded)
        ->and($reviewB->fresh()->status)->toBe(SalesBoardBuilderReviewStatus::Submitted)
        ->and($b['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::ManagementReview);
});
