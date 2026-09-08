<?php

use App\Enums\SalesBoardBuilderReviewStatus;
use App\Enums\SalesBoardCycleStatus;
use App\Enums\SalesBoardManagementReviewStatus;
use App\Enums\SalesBoardNonconformityDecision;
use App\Enums\SalesBoardNonconformityOrigin;
use App\Enums\SalesBoardRecalculationOutcome;
use App\Exceptions\SalesBoardManagementReviewException;
use App\Models\ContractInstallment;
use App\Models\SalesBoardCycleLine;
use App\Models\SalesBoardCycleMovement;
use App\Models\SalesBoardManagementNonconformity;
use App\Models\SalesBoardManagementReview;
use App\Services\SalesBoards\SalesBoardManagementReviewSupersedingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SalesBoards\CycleFixture;
use Tests\Support\SalesBoards\ManagementReviewFixture;

uses(RefreshDatabase::class);

it('supersedes the management review when a material version is created', function () {
    $scenario = ManagementReviewFixture::submittedCycleWithNonConformSale();
    $review = ManagementReviewFixture::open($scenario['cycle']);

    ManagementReviewFixture::decideAll($review);

    $scenario['contracts']['financed']->update(['sale_value' => '910000.00']);
    $result = CycleFixture::recalculate($scenario['cycle'], 'Correção do valor de venda do contrato.');

    $superseded = $review->fresh();

    expect($result->outcome)->toBe(SalesBoardRecalculationOutcome::Recalculated)
        ->and($superseded->status)->toBe(SalesBoardManagementReviewStatus::Superseded)
        ->and($superseded->superseded_reason)
        ->toBe(SalesBoardManagementReviewSupersedingService::REASON_MATERIAL_RECALCULATION)
        ->and($superseded->superseded_at)->not->toBeNull()
        // A validação da construtora segue a mesma regra, na frente dela.
        ->and($scenario['builderReview']->fresh()->status)->toBe(SalesBoardBuilderReviewStatus::Superseded)
        // E a competência volta a depender da construtora.
        ->and($scenario['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::BuilderReview);
});

it('carries no decision into the new round', function () {
    $scenario = ManagementReviewFixture::submittedCycleWithNonConformSale();
    $review = ManagementReviewFixture::open($scenario['cycle']);

    ManagementReviewFixture::decideAll($review);

    $scenario['contracts']['financed']->update(['sale_value' => '910000.00']);
    CycleFixture::recalculate($scenario['cycle'], 'Correção do valor de venda do contrato.');

    // As decisões da rodada substituída continuam consultáveis, e nenhuma
    // rodada nova nasce herdando-as.
    expect($review->fresh()->nonconformities->pluck('decision')->unique()->all())
        ->toBe([SalesBoardNonconformityDecision::AcceptedException])
        ->and(SalesBoardManagementReview::query()->count())->toBe(1)
        ->and(SalesBoardManagementNonconformity::query()->count())->toBe(1);
});

it('keeps the management review applicable when only the source changed', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $review = ManagementReviewFixture::open($scenario['cycle']);

    // Corrigir o valor esperado de uma parcela de um contrato já distratado muda
    // a fonte material e não move nenhum número congelado.
    ContractInstallment::query()
        ->where('contract_id', $scenario['contracts']['cancelled']->id)
        ->sole()
        ->update(['expected_value' => '469000.00']);

    $result = CycleFixture::recalculate($scenario['cycle'], 'Ajuste do valor esperado da parcela.');

    expect($result->outcome)->toBe(SalesBoardRecalculationOutcome::Recalculated);

    $baseline = CycleFixture::currentBaseline($scenario['cycle']);
    $applicable = $review->fresh();

    expect($applicable->status)->toBe(SalesBoardManagementReviewStatus::Draft)
        ->and($applicable->snapshot_fingerprint)->toBe($baseline->snapshot_fingerprint)
        ->and($applicable->appliesTo($baseline))->toBeTrue()
        ->and($scenario['builderReview']->fresh()->status)->toBe(SalesBoardBuilderReviewStatus::Submitted)
        ->and($scenario['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::ManagementReview);
});

it('never supersedes a finished round, so the trail keeps saying what happened', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $review = ManagementReviewFixture::open($scenario['cycle']);

    ManagementReviewFixture::returnToBuilder($review, null, 'Reenviar com o contrato conferido.');

    $scenario['contracts']['financed']->update(['sale_value' => '910000.00']);
    CycleFixture::recalculate($scenario['cycle'], 'Correção do valor de venda do contrato.');

    // Sobrescrever "devolvida" com "substituída" apagaria o fato de que a
    // Gestão devolveu.
    expect($review->fresh()->status)->toBe(SalesBoardManagementReviewStatus::Returned)
        ->and($review->fresh()->return_reason)->toBe('Reenviar com o contrato conferido.');
});

it('refuses to recalculate an approved cycle', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $review = ManagementReviewFixture::open($scenario['cycle']);

    ManagementReviewFixture::approve($review);

    $scenario['contracts']['financed']->update(['sale_value' => '910000.00']);

    expect(fn () => CycleFixture::recalculate($scenario['cycle'], 'Tentativa após a aprovação.'))
        ->toThrow(RuntimeException::class, 'não admite nova versão');

    expect($scenario['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::Approved)
        ->and($scenario['cycle']->fresh()->baselines()->count())->toBe(1);
});

it('leaves the frozen snapshot untouched through the whole management flow', function () {
    $scenario = ManagementReviewFixture::submittedCycleWithNonConformSale();
    $baseline = CycleFixture::currentBaseline($scenario['cycle']);

    $before = [
        'source' => $baseline->source_fingerprint,
        'snapshot' => $baseline->snapshot_fingerprint,
        'lines' => SalesBoardCycleLine::query()
            ->where('sales_board_cycle_baseline_id', $baseline->id)
            ->orderBy('id')
            ->pluck('snapshot_fingerprint')
            ->all(),
        'movements' => SalesBoardCycleMovement::query()
            ->where('sales_board_cycle_baseline_id', $baseline->id)
            ->orderBy('id')
            ->pluck('snapshot_fingerprint')
            ->all(),
    ];

    $review = ManagementReviewFixture::open($scenario['cycle']);
    ManagementReviewFixture::decideAll($review);
    ManagementReviewFixture::approve($review);

    $after = $baseline->fresh();

    expect($after->source_fingerprint)->toBe($before['source'])
        ->and($after->snapshot_fingerprint)->toBe($before['snapshot'])
        ->and(SalesBoardCycleLine::query()
            ->where('sales_board_cycle_baseline_id', $baseline->id)
            ->orderBy('id')
            ->pluck('snapshot_fingerprint')
            ->all())->toBe($before['lines'])
        ->and(SalesBoardCycleMovement::query()
            ->where('sales_board_cycle_baseline_id', $baseline->id)
            ->orderBy('id')
            ->pluck('snapshot_fingerprint')
            ->all())->toBe($before['movements'])
        ->and($after->units_total)->toBe($baseline->units_total)
        ->and((string) $after->stock_value)->toBe((string) $baseline->stock_value);
});

it('refuses to decide on a review whose baseline changed underneath it', function () {
    $scenario = ManagementReviewFixture::submittedCycleWithNonConformSale();
    $review = ManagementReviewFixture::open($scenario['cycle']);

    $item = ManagementReviewFixture::nonconformityOf($review, SalesBoardNonconformityOrigin::SystemSaleNonConform);

    $scenario['contracts']['financed']->update(['sale_value' => '910000.00']);
    CycleFixture::recalculate($scenario['cycle'], 'Correção do valor de venda do contrato.');

    expect(fn () => ManagementReviewFixture::decide($item, SalesBoardNonconformityDecision::AcceptedException))
        ->toThrow(SalesBoardManagementReviewException::class, 'substituída por uma nova versão');
});
