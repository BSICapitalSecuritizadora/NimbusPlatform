<?php

use App\DTOs\SalesBoards\SalesBoardBuilderDivergenceInput;
use App\Enums\SalesBoardApprovalOutcome;
use App\Enums\SalesBoardBuilderDivergenceType;
use App\Enums\SalesBoardBuilderReviewSection as SectionEnum;
use App\Enums\SalesBoardCycleStatus;
use App\Enums\SalesBoardManagementReviewStatus;
use App\Enums\SalesBoardNonconformityDecision;
use App\Enums\SalesBoardNonconformityOrigin;
use App\Enums\SalesBoardStaleImpact;
use App\Enums\SalesBoardUnitClassification;
use App\Exceptions\SalesBoardManagementReviewException;
use App\Models\ContractInstallment;
use App\Models\SalesBoard;
use App\Models\SalesBoardPublication;
use App\Models\User;
use App\Services\SalesBoards\SalesBoardManagementApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SalesBoards\BuilderReviewFixture;
use Tests\Support\SalesBoards\CycleFixture;
use Tests\Support\SalesBoards\ManagementReviewFixture;

uses(RefreshDatabase::class);

it('approves and publishes a clean position', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $review = ManagementReviewFixture::open($scenario['cycle']);
    $manager = User::factory()->create();

    $baseline = CycleFixture::currentBaseline($scenario['cycle']);
    $result = ManagementReviewFixture::approve($review, $manager);

    $salesBoard = SalesBoard::query()->sole();
    $publication = SalesBoardPublication::query()->sole();

    expect($result->outcome)->toBe(SalesBoardApprovalOutcome::Approved)
        ->and($review->fresh()->status)->toBe(SalesBoardManagementReviewStatus::Approved)
        ->and($review->fresh()->approved_by_user_id)->toBe($manager->id)
        ->and($review->fresh()->approval_declaration_version)
        ->toBe(SalesBoardManagementApprovalService::DECLARATION_VERSION)
        ->and($scenario['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::Approved)
        ->and($publication->sales_board_id)->toBe($salesBoard->id)
        ->and($publication->sales_board_cycle_id)->toBe($scenario['cycle']->id)
        ->and($publication->sales_board_cycle_baseline_id)->toBe($baseline->id)
        ->and($publication->sales_board_builder_review_id)->toBe($scenario['builderReview']->id)
        ->and($publication->sales_board_management_review_id)->toBe($review->id)
        ->and($publication->snapshot_fingerprint)->toBe($baseline->snapshot_fingerprint)
        ->and($publication->source_fingerprint)->toBe($baseline->source_fingerprint)
        ->and($publication->source_changed)->toBeFalse()
        ->and($publication->source_change_reason)->toBeNull()
        ->and($publication->published_by_user_id)->toBe($manager->id);
});

it('approves when every builder declaration was dismissed with a reason', function () {
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

    ManagementReviewFixture::decide(
        ManagementReviewFixture::nonconformityOf($review, SalesBoardNonconformityOrigin::BuilderDeclared),
        SalesBoardNonconformityDecision::Dismissed,
        'O distrato citado é de agosto e não afeta esta competência.',
    );

    $result = ManagementReviewFixture::approve($review);

    expect($result->outcome)->toBe(SalesBoardApprovalOutcome::Approved)
        ->and(SalesBoard::query()->count())->toBe(1)
        ->and($review->fresh()->nonconformities->first()->decision)
        ->toBe(SalesBoardNonconformityDecision::Dismissed);
});

it('approves an authorized exception and keeps the decision in the trail', function () {
    $scenario = ManagementReviewFixture::submittedCycleWithNonConformSale();
    $review = ManagementReviewFixture::open($scenario['cycle']);

    ManagementReviewFixture::decide(
        ManagementReviewFixture::nonconformityOf($review, SalesBoardNonconformityOrigin::SystemSaleNonConform),
        SalesBoardNonconformityDecision::AcceptedException,
        'Desconto autorizado pela diretoria comercial na ata de 12/07.',
    );

    $result = ManagementReviewFixture::approve($review);
    $item = $review->fresh()->nonconformities->sole();

    expect($result->outcome)->toBe(SalesBoardApprovalOutcome::Approved)
        ->and($item->decision)->toBe(SalesBoardNonconformityDecision::AcceptedException)
        ->and($item->decision_reason)->toBe('Desconto autorizado pela diretoria comercial na ata de 12/07.')
        ->and($item->decided_at)->not->toBeNull()
        ->and(SalesBoardPublication::query()->sole()->sales_board_management_review_id)->toBe($review->id);
});

it('blocks approval while a nonconformity is still pending', function () {
    $scenario = ManagementReviewFixture::submittedCycleWithNonConformSale();
    $review = ManagementReviewFixture::open($scenario['cycle']);

    expect(fn () => ManagementReviewFixture::approve($review))
        ->toThrow(SalesBoardManagementReviewException::class, 'sem decisão da Gestão');

    expect(SalesBoard::query()->count())->toBe(0)
        ->and(SalesBoardPublication::query()->count())->toBe(0)
        ->and($scenario['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::ManagementReview)
        ->and($review->fresh()->status)->toBe(SalesBoardManagementReviewStatus::Draft);
});

it('blocks approval while any nonconformity requires a source correction', function () {
    $scenario = ManagementReviewFixture::submittedCycleWithNonConformSale();
    $review = ManagementReviewFixture::open($scenario['cycle']);

    ManagementReviewFixture::decide(
        ManagementReviewFixture::nonconformityOf($review, SalesBoardNonconformityOrigin::SystemSaleNonConform),
        SalesBoardNonconformityDecision::CorrectionRequired,
        'O valor de tabela da unidade está desatualizado no cadastro.',
    );

    expect(fn () => ManagementReviewFixture::approve($review))
        ->toThrow(SalesBoardManagementReviewException::class, 'exigem correção da fonte');

    expect(SalesBoard::query()->count())->toBe(0)
        ->and(SalesBoardPublication::query()->count())->toBe(0)
        ->and($scenario['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::ManagementReview);
});

it('blocks approval when the source changed materially', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $review = ManagementReviewFixture::open($scenario['cycle']);

    $scenario['contracts']['financed']->update(['sale_value' => '910000.00']);

    expect(fn () => ManagementReviewFixture::approve($review))
        ->toThrow(SalesBoardManagementReviewException::class, 'alteraram materialmente a posição');

    expect(SalesBoard::query()->count())->toBe(0)
        ->and(SalesBoardPublication::query()->count())->toBe(0)
        ->and($review->fresh()->status)->toBe(SalesBoardManagementReviewStatus::Draft);
});

it('blocks approval when the current source no longer passes readiness', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $review = ManagementReviewFixture::open($scenario['cycle']);

    ContractInstallment::query()
        ->where('contract_id', $scenario['contracts']['financed']->id)
        ->delete();

    expect(fn () => ManagementReviewFixture::approve($review))
        ->toThrow(SalesBoardManagementReviewException::class, 'fonte atual está incompleta');

    expect(SalesBoard::query()->count())->toBe(0)
        ->and(SalesBoardPublication::query()->count())->toBe(0);
});

it('blocks approval without the declaration', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $review = ManagementReviewFixture::open($scenario['cycle']);

    expect(fn () => ManagementReviewFixture::approve($review, declaration: false))
        ->toThrow(SalesBoardManagementReviewException::class, 'confirmar a declaração');

    expect(SalesBoard::query()->count())->toBe(0);
});

it('blocks approval without an identified manager', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $review = ManagementReviewFixture::open($scenario['cycle']);

    expect(fn () => app(SalesBoardManagementApprovalService::class)->approve($review, null, true))
        ->toThrow(SalesBoardManagementReviewException::class, 'identificar quem está conduzindo');

    expect(SalesBoard::query()->count())->toBe(0);
});

it('is idempotent: approving twice publishes exactly one board', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $review = ManagementReviewFixture::open($scenario['cycle']);
    $manager = User::factory()->create();

    $first = ManagementReviewFixture::approve($review, $manager);
    $second = ManagementReviewFixture::approve($review, $manager);

    expect($first->outcome)->toBe(SalesBoardApprovalOutcome::Approved)
        ->and($second->outcome)->toBe(SalesBoardApprovalOutcome::AlreadyApproved)
        ->and($second->salesBoard->id)->toBe($first->salesBoard->id)
        ->and($second->publication->id)->toBe($first->publication->id)
        ->and(SalesBoard::query()->count())->toBe(1)
        ->and(SalesBoardPublication::query()->count())->toBe(1)
        ->and($scenario['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::Approved);
});

it('refuses to approve a superseded review', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $review = ManagementReviewFixture::open($scenario['cycle']);

    $scenario['contracts']['financed']->update(['sale_value' => '910000.00']);
    CycleFixture::recalculate($scenario['cycle'], 'Correção do valor de venda do contrato.');

    expect($review->fresh()->status)->toBe(SalesBoardManagementReviewStatus::Superseded);

    expect(fn () => ManagementReviewFixture::approve($review))
        ->toThrow(SalesBoardManagementReviewException::class, 'substituída por uma nova versão');

    expect(SalesBoard::query()->count())->toBe(0);
});

it('exposes the gate as a read model of the real state', function () {
    $scenario = ManagementReviewFixture::submittedCycleWithNonConformSale();
    $review = ManagementReviewFixture::open($scenario['cycle']);

    $blocked = app(SalesBoardManagementApprovalService::class)->gate($review);

    expect($blocked['ready'])->toBeFalse()
        ->and($blocked['impact'])->toBe(SalesBoardStaleImpact::None)
        ->and(collect($blocked['checks'])->firstWhere('label', 'Não conformidades decididas')['passed'])->toBeFalse();

    ManagementReviewFixture::decideAll($review);

    $ready = app(SalesBoardManagementApprovalService::class)->gate($review->fresh());

    expect($ready['ready'])->toBeTrue()
        ->and(collect($ready['checks'])->every(fn (array $check): bool => $check['passed']))->toBeTrue();
});
