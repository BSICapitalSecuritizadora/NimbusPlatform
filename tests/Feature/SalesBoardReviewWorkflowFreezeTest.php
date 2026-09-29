<?php

use App\DTOs\SalesBoards\SalesBoardBuilderDivergenceInput;
use App\Enums\SalesBoardBuilderDivergenceType;
use App\Enums\SalesBoardBuilderReviewSection as SectionEnum;
use App\Enums\SalesBoardBuilderReviewSectionStatus;
use App\Enums\SalesBoardManagementReviewStatus;
use App\Enums\SalesBoardNonconformityDecision;
use App\Enums\SalesBoardNonconformityOrigin;
use App\Enums\SalesBoardUnitClassification;
use App\Models\SalesBoardBuilderDivergence;
use App\Models\SalesBoardBuilderReviewSection;
use App\Models\SalesBoardCycleMovement;
use App\Models\SalesBoardManagementNonconformity;
use App\Models\SalesBoardManagementReview;
use App\Models\SalesBoardPublication;
use App\Services\SalesBoards\SalesBoardManagementApprovalService;
use App\Services\SalesBoards\SalesBoardManagementDecisionService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SalesBoards\BuilderReviewFixture;
use Tests\Support\SalesBoards\GovernanceFixture;
use Tests\Support\SalesBoards\ManagementReviewFixture;

uses(RefreshDatabase::class);

/**
 * O que congela junto com a revisão, e contra o quê.
 *
 * Pendência da Gestão, seção e divergência da construtora são linhas filhas de
 * uma revisão. Encerrada a revisão -- aprovada, devolvida, enviada --, nenhuma
 * delas grava mais nada, e a pergunta "a revisão ainda é rascunho?" é feita ao
 * banco, não à relação que veio carregada: uma relação carregada antes do
 * encerramento diria "rascunho" para sempre.
 *
 * A serialização real entre decidir e aprovar, e entre editar e enviar, só
 * aparece com duas conexões MySQL e está em
 * `SalesBoardReviewWorkflowMysqlConcurrencyTest`. Aqui fica a última linha de
 * defesa, que vale em qualquer banco.
 */
it('freezes the decision of a nonconformity once the analysis is approved', function () {
    $scenario = ManagementReviewFixture::submittedCycleWithNonConformSale();
    $review = ManagementReviewFixture::open($scenario['cycle']);
    ManagementReviewFixture::decideAll($review);
    ManagementReviewFixture::approve($review);

    $item = ManagementReviewFixture::nonconformityOf($review, SalesBoardNonconformityOrigin::SystemSaleNonConform);

    expect(fn () => $item->forceFill([
        'decision' => SalesBoardNonconformityDecision::CorrectionRequired,
        'decision_reason' => 'Alterado depois da publicação do quadro.',
    ])->save())->toThrow(LogicException::class, 'frozen once its management review is finished');

    expect($item->fresh()->decision)->toBe(SalesBoardNonconformityDecision::AcceptedException);
});

it('refuses a new nonconformity on an analysis that was returned to the builder', function () {
    $scenario = ManagementReviewFixture::submittedCycleWithNonConformSale();
    $review = ManagementReviewFixture::open($scenario['cycle']);
    ManagementReviewFixture::returnToBuilder($review);

    expect(fn () => SalesBoardManagementNonconformity::factory()
        ->forMovement(SalesBoardCycleMovement::factory()->create())
        ->create(['sales_board_management_review_id' => $review->id]))
        ->toThrow(LogicException::class, 'frozen once its management review is finished');

    expect($review->fresh()->nonconformities)->toHaveCount(1);
});

it('never lets a decision that read the analysis as a draft land after the approval', function () {
    $scenario = ManagementReviewFixture::submittedCycleWithNonConformSale();
    $review = ManagementReviewFixture::open($scenario['cycle']);
    ManagementReviewFixture::decideAll($review);

    $item = ManagementReviewFixture::nonconformityOf($review, SalesBoardNonconformityOrigin::SystemSaleNonConform);
    // Duas pessoas da Gestão: uma decide, a outra aprova.
    $approver = GovernanceFixture::approver();
    $fired = false;

    /**
     * O SQLite serializa escritores, então a intercalação que o MySQL admitia
     * é simulada: a aprovação commita logo depois de a decisão ter lido a
     * análise como rascunho. A decisão não pode gravar por cima.
     */
    DB::listen(function (QueryExecuted $query) use (&$fired, $review, $approver): void {
        if ($fired || DB::transactionLevel() !== 2 || ! str_contains($query->sql, 'from "sales_board_management_reviews"')) {
            return;
        }

        $fired = true;

        app(SalesBoardManagementApprovalService::class)->approve(
            SalesBoardManagementReview::query()->findOrFail($review->id),
            $approver,
            true,
        );
    });

    $decided = true;

    try {
        app(SalesBoardManagementDecisionService::class)->decide(
            $item,
            SalesBoardNonconformityDecision::CorrectionRequired,
            'A tabela de preços da unidade precisa de correção antes de publicar.',
            GovernanceFixture::approver(),
        );
    } catch (Throwable) {
        $decided = false;
    }

    $finalReview = $review->fresh();
    $finalItem = $item->fresh();

    expect($fired)->toBeTrue()
        ->and($decided)->toBeFalse()
        ->and($finalItem->decision)->toBe(SalesBoardNonconformityDecision::AcceptedException)
        // Nunca uma publicação ao lado de uma pendência que exige corrigir a fonte.
        ->and($finalReview->status === SalesBoardManagementReviewStatus::Approved
            && $finalItem->decision === SalesBoardNonconformityDecision::CorrectionRequired)->toBeFalse()
        ->and(SalesBoardPublication::query()->count())->toBe($finalReview->isApproved() ? 1 : 0);
});

it('refuses to change a section after submission even through a relation loaded before it', function () {
    $scenario = BuilderReviewFixture::generatedCycle();
    $review = BuilderReviewFixture::open($scenario['cycle']);
    BuilderReviewFixture::confirmAll($review);

    // Como a edição carregava: seção e revisão lidas antes de o envio commitar.
    $section = SalesBoardBuilderReviewSection::query()
        ->whereKey(BuilderReviewFixture::section($review, SectionEnum::ordered()[0])->id)
        ->with('review')
        ->firstOrFail();

    BuilderReviewFixture::submit($review);

    expect(fn () => $section->forceFill([
        'status' => SalesBoardBuilderReviewSectionStatus::Pending,
        'confirmed_at' => null,
    ])->save())->toThrow(LogicException::class, 'immutable');

    expect($section->fresh()->status)->toBe(SalesBoardBuilderReviewSectionStatus::Confirmed);
});

it('refuses a new divergence on a submitted review', function () {
    $scenario = BuilderReviewFixture::generatedCycle();
    $review = BuilderReviewFixture::open($scenario['cycle']);
    BuilderReviewFixture::confirmAll($review);
    BuilderReviewFixture::submit($review);

    expect(fn () => SalesBoardBuilderDivergence::query()->create([
        'sales_board_builder_review_id' => $review->id,
        'sales_board_builder_review_section_id' => BuilderReviewFixture::section($review, SectionEnum::ordered()[0])->id,
        'type' => SalesBoardBuilderDivergenceType::Other,
        'reason' => 'Registrada depois do envio.',
    ]))->toThrow(LogicException::class, 'immutable');

    expect(SalesBoardBuilderDivergence::query()->count())->toBe(0);
});

it('refuses to edit a divergence after submission even through a relation loaded before it', function () {
    $scenario = BuilderReviewFixture::generatedCycle();
    $review = BuilderReviewFixture::open($scenario['cycle']);

    $declared = BuilderReviewFixture::declare($review, SectionEnum::PositionFinanced, new SalesBoardBuilderDivergenceInput(
        type: SalesBoardBuilderDivergenceType::StockMismatch,
        lineId: BuilderReviewFixture::lineFor($review, $scenario['units']['financed'])->id,
        declaredClassification: SalesBoardUnitClassification::Stock,
        reason: 'A unidade foi distratada em junho e voltou ao estoque.',
    ));

    BuilderReviewFixture::confirmAll($review);

    $divergence = SalesBoardBuilderDivergence::query()->with('review')->findOrFail($declared->id);

    BuilderReviewFixture::submit($review);

    expect(fn () => $divergence->forceFill(['reason' => 'Reescrita depois do envio.'])->save())
        ->toThrow(LogicException::class, 'immutable');

    expect(fn () => $divergence->delete())->toThrow(LogicException::class, 'immutable');

    expect($divergence->fresh()->reason)->toBe('A unidade foi distratada em junho e voltou ao estoque.');
});
