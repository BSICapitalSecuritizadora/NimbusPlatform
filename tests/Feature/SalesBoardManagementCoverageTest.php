<?php

use App\DTOs\SalesBoards\SalesBoardBuilderDivergenceInput;
use App\Enums\ContractStatus;
use App\Enums\SalesBoardApprovalOutcome;
use App\Enums\SalesBoardBuilderDivergenceType;
use App\Enums\SalesBoardBuilderReviewSection as SectionEnum;
use App\Enums\SalesBoardManagementReviewStatus;
use App\Enums\SalesBoardRecalculationOutcome;
use App\Enums\SalesBoardUnitClassification;
use App\Enums\SalesPriceConformityStatus;
use App\Exceptions\SalesBoardManagementReviewException;
use App\Models\ContractInstallment;
use App\Models\SalesBoard;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardManagementReview;
use App\Models\SalesBoardPublication;
use App\Services\SalesBoards\SalesBoardManagementApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SalesBoards\BuilderReviewFixture;
use Tests\Support\SalesBoards\CycleFixture;
use Tests\Support\SalesBoards\DerivationFixture;
use Tests\Support\SalesBoards\ManagementReviewFixture;

uses(RefreshDatabase::class);

/**
 * Se as pendências da análise cobrem tudo o que a versão vigente pede para
 * decidir -- e se o portão diz a mesma coisa que a aprovação faz.
 *
 * A cobertura das vendas é pela chave natural do movimento, não pelo id: um
 * recálculo que só troca a origem material cria uma versão com os mesmos fatos
 * em movimentos novos, e a análise decidida continua valendo para ela.
 */

/**
 * Uma venda do mês fora da política, já decidida pela Gestão, ao lado de um
 * contrato distratado cuja parcela pode mudar sem alterar a posição.
 *
 * @return array{cycle: SalesBoardCycle, review: SalesBoardManagementReview, cancelledContractId: int}
 */
function decidedAnalysisWithCancelledContract(): array
{
    [$construction, $units] = CycleFixture::readyConstruction(2);

    $soldInMonth = DerivationFixture::contract($units[1], '2026-07-15', '400000.00');
    DerivationFixture::installment($soldInMonth, '001', '2026-08-15', '400000.00');

    $cancelled = DerivationFixture::contract(
        $units[0],
        '2026-07-02',
        '470000.00',
        cancellationDate: '2026-07-20',
        status: ContractStatus::Cancelled,
    );
    DerivationFixture::installment($cancelled, '001', '2026-08-02', '470000.00');

    $cycle = CycleFixture::generate($construction)->cycle;

    $builderReview = BuilderReviewFixture::open($cycle);
    BuilderReviewFixture::confirmAll($builderReview);
    BuilderReviewFixture::submit($builderReview);

    $review = ManagementReviewFixture::open($cycle);
    ManagementReviewFixture::decideAll($review);

    return [
        'cycle' => $cycle->fresh(),
        'review' => $review->fresh(),
        'cancelledContractId' => $cancelled->id,
    ];
}

/**
 * @param  array{ready: bool, checks: list<array{label: string, passed: bool, detail: string|null}>}  $gate
 * @return array{label: string, passed: bool, detail: string|null}
 */
function coverageCheck(array $gate): array
{
    return collect($gate['checks'])->firstWhere('label', 'Pendências cobrem a versão vigente');
}

it('keeps a decided analysis approvable after a source-only recalculation', function () {
    $context = decidedAnalysisWithCancelledContract();
    $v1 = CycleFixture::currentBaseline($context['cycle']);

    expect($context['review']->nonconformities)->toHaveCount(1);

    // Só a fonte muda: a parcela do contrato distratado não entra na posição.
    ContractInstallment::query()
        ->where('contract_id', $context['cancelledContractId'])
        ->sole()
        ->update(['expected_value' => '469000.00']);

    $recalculation = CycleFixture::recalculate($context['cycle'], 'Correção da parcela do contrato distratado.');
    $v2 = CycleFixture::currentBaseline($context['cycle']);

    expect($recalculation->outcome)->toBe(SalesBoardRecalculationOutcome::Recalculated)
        ->and($v2->id)->not->toBe($v1->id)
        ->and($v2->snapshot_fingerprint)->toBe($v1->snapshot_fingerprint)
        ->and($context['review']->fresh()->status)->toBe(SalesBoardManagementReviewStatus::Draft);

    $gate = app(SalesBoardManagementApprovalService::class)->gate($context['review']->fresh());

    expect($gate['ready'])->toBeTrue()
        ->and(coverageCheck($gate)['passed'])->toBeTrue();

    $result = ManagementReviewFixture::approve($context['review']);

    expect($result->outcome)->toBe(SalesBoardApprovalOutcome::Approved)
        ->and(SalesBoardPublication::query()->sole()->sales_board_cycle_baseline_id)->toBe($v2->id)
        ->and(SalesBoard::query()->count())->toBe(1);
});

it('shows an uncovered sale in the gate and tells the manager the way out', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $review = ManagementReviewFixture::open($scenario['cycle']);

    // A venda entra na versão vigente depois da materialização, como um dado
    // escrito fora do fluxo apareceria.
    ManagementReviewFixture::writeSaleMovement(
        CycleFixture::currentBaseline($scenario['cycle']),
        '904',
        SalesPriceConformityStatus::NonConform,
        null,
    );

    $gate = app(SalesBoardManagementApprovalService::class)->gate($review->fresh());

    expect($gate['ready'])->toBeFalse()
        ->and(coverageCheck($gate)['passed'])->toBeFalse()
        ->and(coverageCheck($gate)['detail'])->toBe('1 venda(s) e 0 divergência(s) sem pendência nesta análise.');

    $message = null;

    try {
        ManagementReviewFixture::approve($review);
    } catch (SalesBoardManagementReviewException $exception) {
        $message = $exception->getMessage();
    }

    // Reabrir devolve a mesma análise; a saída real é a rodada seguinte.
    expect($message)->toContain('não cobre todas as vendas apontadas')
        ->toContain('Devolva a competência à construtora')
        ->not->toContain('Reabra')
        ->and(SalesBoardPublication::query()->count())->toBe(0);
});

it('refuses to publish when a declared divergence has no pendency', function () {
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
    ManagementReviewFixture::decideAll($review);

    // Uma divergência gravada por fora do fluxo, depois da materialização: o
    // model recusa, então ela só chega pelo banco.
    DB::table('sales_board_builder_divergences')->insert([
        'sales_board_builder_review_id' => $builderReview->id,
        'sales_board_builder_review_section_id' => BuilderReviewFixture::section($builderReview, SectionEnum::PositionStock)->id,
        'type' => SalesBoardBuilderDivergenceType::Other->value,
        'reason' => 'Declaração que nunca virou pendência.',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $gate = app(SalesBoardManagementApprovalService::class)->gate($review->fresh());

    expect($gate['ready'])->toBeFalse()
        ->and(coverageCheck($gate)['detail'])->toBe('0 venda(s) e 1 divergência(s) sem pendência nesta análise.');

    expect(fn () => ManagementReviewFixture::approve($review))
        ->toThrow(SalesBoardManagementReviewException::class, 'não cobre todas as divergências declaradas');

    expect(SalesBoardPublication::query()->count())->toBe(0)
        ->and($review->fresh()->status)->toBe(SalesBoardManagementReviewStatus::Draft);
});
