<?php

use App\Enums\SalesBoardApprovalOutcome;
use App\Enums\SalesBoardCycleStatus;
use App\Enums\SalesBoardManagementReviewStatus;
use App\Enums\SalesBoardStaleImpact;
use App\Exceptions\SalesBoardManagementReviewException;
use App\Models\ContractInstallment;
use App\Models\SalesBoard;
use App\Models\SalesBoardManagementReview;
use App\Models\SalesBoardPublication;
use App\Models\User;
use App\Services\SalesBoards\SalesBoardManagementApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SalesBoards\CycleFixture;
use Tests\Support\SalesBoards\ManagementReviewFixture;

uses(RefreshDatabase::class);

/**
 * Uma análise aberta cuja fonte mudou sem mover nenhum número congelado.
 *
 * O gatilho é o mesmo que a Fase C usa para provar `SourceOnly`: corrigir o
 * valor esperado de uma parcela de um contrato já distratado. É mudança real de
 * fato material -- e a posição derivada dela continua idêntica.
 *
 * @return array{review: SalesBoardManagementReview, scenario: array}
 */
function sourceOnlyManagementReview(): array
{
    $scenario = ManagementReviewFixture::submittedCycle();
    $review = ManagementReviewFixture::open($scenario['cycle']);

    ContractInstallment::query()
        ->where('contract_id', $scenario['contracts']['cancelled']->id)
        ->sole()
        ->update(['expected_value' => '469000.00']);

    return ['review' => $review, 'scenario' => $scenario];
}

it('sets up a source-only state, not a material one', function () {
    $context = sourceOnlyManagementReview();

    $gate = app(SalesBoardManagementApprovalService::class)->gate($context['review']);

    expect($gate['impact'])->toBe(SalesBoardStaleImpact::SourceOnly)
        ->and($gate['ready'])->toBeTrue();
});

it('blocks a source-only approval that comes without a justification', function () {
    $context = sourceOnlyManagementReview();

    expect(fn () => ManagementReviewFixture::approve($context['review']))
        ->toThrow(SalesBoardManagementReviewException::class, 'Informe a justificativa');

    expect(SalesBoard::query()->count())->toBe(0)
        ->and(SalesBoardPublication::query()->count())->toBe(0)
        ->and($context['review']->fresh()->status)->toBe(SalesBoardManagementReviewStatus::Draft)
        ->and($context['scenario']['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::ManagementReview);
});

it('blocks a source-only approval whose justification says nothing', function (string $reason) {
    $context = sourceOnlyManagementReview();

    expect(fn () => ManagementReviewFixture::approve($context['review'], null, $reason))
        ->toThrow(SalesBoardManagementReviewException::class, 'Informe a justificativa');

    expect(SalesBoard::query()->count())->toBe(0);
})->with(['', ' ', 'ok', '-', '.']);

it('approves a source-only position with a justification and freezes the override', function () {
    $context = sourceOnlyManagementReview();
    $manager = User::factory()->create();
    $baseline = CycleFixture::currentBaseline($context['scenario']['cycle']);

    $result = ManagementReviewFixture::approve(
        $context['review'],
        $manager,
        'Ajuste de parcela de contrato distratado; a posição do mês não muda.',
    );

    $review = $context['review']->fresh();
    $publication = SalesBoardPublication::query()->sole();

    expect($result->outcome)->toBe(SalesBoardApprovalOutcome::Approved)
        ->and($review->status)->toBe(SalesBoardManagementReviewStatus::Approved)
        ->and($review->source_changed)->toBeTrue()
        ->and($review->source_change_reason)
        ->toBe('Ajuste de parcela de contrato distratado; a posição do mês não muda.')
        // Os dois lados da aprovação ficam congelados: a origem que produziu a
        // versão e a que existia quando ela foi aprovada.
        ->and($review->approved_source_fingerprint)->toBe($baseline->source_fingerprint)
        ->and($review->observed_source_fingerprint)->not->toBe($baseline->source_fingerprint)
        ->and($publication->source_changed)->toBeTrue()
        ->and($publication->source_change_reason)
        ->toBe('Ajuste de parcela de contrato distratado; a posição do mês não muda.')
        ->and($publication->source_fingerprint)->toBe($baseline->source_fingerprint)
        ->and($publication->observed_source_fingerprint)->toBe($review->observed_source_fingerprint)
        ->and($publication->snapshot_fingerprint)->toBe($baseline->snapshot_fingerprint);
});

it('refuses a justification when the source did not change at all', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $review = ManagementReviewFixture::open($scenario['cycle']);

    expect(fn () => ManagementReviewFixture::approve($review, null, 'Justificando algo que não aconteceu.'))
        ->toThrow(SalesBoardManagementReviewException::class, 'A fonte não mudou');

    expect(SalesBoard::query()->count())->toBe(0);
});

it('records no override on a clean approval', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $review = ManagementReviewFixture::open($scenario['cycle']);
    $baseline = CycleFixture::currentBaseline($scenario['cycle']);

    ManagementReviewFixture::approve($review);

    $approved = $review->fresh();
    $publication = SalesBoardPublication::query()->sole();

    expect($approved->source_changed)->toBeFalse()
        ->and($approved->source_change_reason)->toBeNull()
        ->and($approved->observed_source_fingerprint)->toBe($baseline->source_fingerprint)
        ->and($publication->source_changed)->toBeFalse()
        ->and($publication->source_change_reason)->toBeNull();
});

it('never lets a material change be overridden by a justification', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $review = ManagementReviewFixture::open($scenario['cycle']);

    $scenario['contracts']['financed']->update(['sale_value' => '910000.00']);

    expect(fn () => ManagementReviewFixture::approve($review, null, 'Quero publicar assim mesmo, ciente da diferença.'))
        ->toThrow(SalesBoardManagementReviewException::class, 'Recalcule antes da aprovação');

    expect(SalesBoard::query()->count())->toBe(0)
        ->and(SalesBoardPublication::query()->count())->toBe(0);
});

it('never lets a blocking source be overridden by a justification', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $review = ManagementReviewFixture::open($scenario['cycle']);

    ContractInstallment::query()
        ->where('contract_id', $scenario['contracts']['financed']->id)
        ->delete();

    expect(fn () => ManagementReviewFixture::approve($review, null, 'Publicar mesmo com a fonte incompleta.'))
        ->toThrow(SalesBoardManagementReviewException::class, 'Resolva os dados pendentes');

    expect(SalesBoard::query()->count())->toBe(0)
        ->and(SalesBoardPublication::query()->count())->toBe(0);
});

it('shows the blocking readiness reasons on the gate', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $review = ManagementReviewFixture::open($scenario['cycle']);

    ContractInstallment::query()
        ->where('contract_id', $scenario['contracts']['financed']->id)
        ->delete();

    $gate = app(SalesBoardManagementApprovalService::class)->gate($review);

    expect($gate['ready'])->toBeFalse()
        ->and($gate['impact'])->toBe(SalesBoardStaleImpact::Blocking)
        ->and(collect($gate['checks'])->firstWhere('label', 'Fonte sem alteração material')['passed'])->toBeFalse()
        ->and(collect($gate['checks'])->firstWhere('label', 'Fonte sem alteração material')['detail'])
        ->toBe(SalesBoardStaleImpact::Blocking->label());
});
