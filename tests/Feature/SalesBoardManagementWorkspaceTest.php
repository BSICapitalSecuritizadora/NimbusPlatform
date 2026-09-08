<?php

use App\DTOs\SalesBoards\SalesBoardBuilderDivergenceInput;
use App\Enums\SalesBoardBuilderDivergenceType;
use App\Enums\SalesBoardBuilderReviewSection as SectionEnum;
use App\Enums\SalesBoardMovementType;
use App\Enums\SalesBoardNonconformityDecision;
use App\Enums\SalesBoardNonconformityOrigin;
use App\Enums\SalesBoardStaleImpact;
use App\Enums\SalesBoardUnitClassification;
use App\Services\SalesBoards\SalesBoardManagementReviewWorkspaceBuilder;
use App\Support\Money\IntegerMoney;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SalesBoards\BuilderReviewFixture;
use Tests\Support\SalesBoards\CycleFixture;
use Tests\Support\SalesBoards\ManagementReviewFixture;

uses(RefreshDatabase::class);

it('builds the header, the four buckets and the builder summary from what was frozen', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $review = ManagementReviewFixture::open($scenario['cycle']);
    $baseline = CycleFixture::currentBaseline($scenario['cycle']);

    $workspace = app(SalesBoardManagementReviewWorkspaceBuilder::class)->build($review);

    expect($workspace->constructionName)->toBe($scenario['construction']->development_name)
        ->and($workspace->referenceMonth)->toBe($scenario['cycle']->fresh()->reference_month->format('m/Y'))
        ->and($workspace->baselineLabel)->toBe($baseline->versionLabel())
        ->and($workspace->builderAttempt)->toBe(1)
        ->and($workspace->managementAttempt)->toBe(1)
        ->and($workspace->isApplicable)->toBeTrue()
        ->and($workspace->unitsTotal)->toBe((int) $baseline->units_total)
        ->and($workspace->buckets)->toHaveCount(4)
        ->and($workspace->buckets[0]->units)->toBe((int) $baseline->stock_units)
        ->and($workspace->buckets[2]->units)->toBe((int) $baseline->settled_units)
        ->and($workspace->buckets[2]->valueCents)->toBe(IntegerMoney::cents($baseline->settled_value))
        ->and($workspace->builderSections)->toHaveCount(7)
        ->and($workspace->builderFullyConfirmed)->toBeTrue()
        ->and($workspace->builderDivergenceCount)->toBe(0)
        ->and($workspace->builderSubmittedAt)->not->toBeNull();
});

it('opens a non conform sale by the numbers frozen in the movement', function () {
    $scenario = ManagementReviewFixture::submittedCycleWithNonConformSale();
    $review = ManagementReviewFixture::open($scenario['cycle']);

    $workspace = app(SalesBoardManagementReviewWorkspaceBuilder::class)->build($review);
    $row = $workspace->nonconformitiesOf(SalesBoardNonconformityOrigin::SystemSaleNonConform)[0];

    $labels = collect($row->facts)->pluck('label')->all();

    expect($labels)->toBe([
        'Data da venda',
        'Valor da venda',
        'Valor de referência',
        'Desconto autorizado',
        'Preço mínimo',
        'Desconto praticado',
        'Diferença',
        'Conformidade',
    ])
        ->and(collect($row->facts)->firstWhere('label', 'Valor da venda')['value'])->toBe('R$ 400.000,00')
        ->and(collect($row->facts)->firstWhere('label', 'Valor de referência')['value'])->toBe('R$ 500.000,00')
        ->and(collect($row->facts)->firstWhere('label', 'Preço mínimo')['value'])->toBe('R$ 450.000,00')
        ->and(collect($row->facts)->firstWhere('label', 'Conformidade')['value'])->toBe('Não conforme')
        ->and($row->builderStatement)->toBeNull()
        // Uma venda fora da política admite exceção ou correção -- nunca "não procede".
        ->and($row->allowedDecisions())->toBe([
            SalesBoardNonconformityDecision::AcceptedException,
            SalesBoardNonconformityDecision::CorrectionRequired,
        ]);
});

it('puts the two versions of a declared fact side by side', function () {
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
    $workspace = app(SalesBoardManagementReviewWorkspaceBuilder::class)->build($review);
    $row = $workspace->nonconformitiesOf(SalesBoardNonconformityOrigin::BuilderDeclared)[0];

    expect($row->systemStatement)->toBe(SalesBoardUnitClassification::Financed->label())
        ->and($row->builderStatement)->toBe(SalesBoardUnitClassification::Stock->label())
        ->and($row->builderReason)->toBe('A unidade foi distratada em junho e voltou ao estoque.')
        ->and($row->typeLabel)->toBe(SalesBoardBuilderDivergenceType::StockMismatch->label())
        // Uma declaração admite "não procede" ou correção -- nunca exceção.
        ->and($row->allowedDecisions())->toBe([
            SalesBoardNonconformityDecision::Dismissed,
            SalesBoardNonconformityDecision::CorrectionRequired,
        ]);
});

it('keeps both origins separate when they point at the same sale', function () {
    $scenario = BuilderReviewFixture::generatedCycleWithNonConformSale();
    $builderReview = BuilderReviewFixture::open($scenario['cycle']);

    BuilderReviewFixture::declare($builderReview, SectionEnum::MovementSales, new SalesBoardBuilderDivergenceInput(
        type: SalesBoardBuilderDivergenceType::SaleValueMismatch,
        movementId: BuilderReviewFixture::movementFor(
            $builderReview,
            SalesBoardMovementType::Sale,
            $scenario['contracts']['soldInMonth'],
        )->id,
        declaredValueCents: IntegerMoney::cents('460000.00'),
        reason: 'O valor correto da venda é 460.000, houve erro de digitação.',
    ));

    BuilderReviewFixture::confirmAll($builderReview);
    BuilderReviewFixture::submit($builderReview);

    $review = ManagementReviewFixture::open($scenario['cycle']);
    $workspace = app(SalesBoardManagementReviewWorkspaceBuilder::class)->build($review);

    $declared = $workspace->nonconformitiesOf(SalesBoardNonconformityOrigin::BuilderDeclared);
    $system = $workspace->nonconformitiesOf(SalesBoardNonconformityOrigin::SystemSaleNonConform);

    // Dois fatos diferentes sobre a mesma venda: a tela pode agrupá-los, o
    // domínio nunca os funde.
    expect($declared)->toHaveCount(1)
        ->and($system)->toHaveCount(1)
        ->and($declared[0]->groupKey())->toBe($system[0]->groupKey())
        ->and($declared[0]->id)->not->toBe($system[0]->id);
});

it('never reads the live source to build the historical facts', function () {
    $scenario = ManagementReviewFixture::submittedCycleWithNonConformSale();
    $review = ManagementReviewFixture::open($scenario['cycle']);

    // A fonte muda depois do congelamento. O que a tela mostra não pode mudar
    // junto: a Gestão decide sobre o quadro que foi apurado.
    $scenario['contracts']['soldInMonth']->update(['sale_value' => '499999.00']);

    $workspace = app(SalesBoardManagementReviewWorkspaceBuilder::class)->build($review->fresh());
    $row = $workspace->nonconformitiesOf(SalesBoardNonconformityOrigin::SystemSaleNonConform)[0];

    expect(collect($row->facts)->firstWhere('label', 'Valor da venda')['value'])->toBe('R$ 400.000,00')
        ->and($workspace->staleImpact())->toBe(SalesBoardStaleImpact::Material)
        ->and($workspace->isBlockedBySource())->toBeTrue()
        ->and($workspace->isReadyToPublish())->toBeFalse();
});

it('never queries contracts, installments, units or price policies to render the facts', function () {
    $scenario = ManagementReviewFixture::submittedCycleWithNonConformSale();
    $review = ManagementReviewFixture::open($scenario['cycle']);
    $builder = app(SalesBoardManagementReviewWorkspaceBuilder::class);
    $baseline = CycleFixture::currentBaseline($scenario['cycle']);

    // O portão consulta a fonte de propósito -- é a pergunta "isto ainda pode
    // ser publicado?". Os fatos, não: eles vêm do que foi congelado.
    $workspace = $builder->build($review->fresh());

    $tables = [];

    DB::listen(function ($query) use (&$tables): void {
        foreach (['contracts', 'contract_installments', 'construction_units', 'sales_discount_policies'] as $table) {
            if (str_contains(strtolower($query->sql), $table)) {
                $tables[] = $table;
            }
        }
    });

    $rows = collect($workspace->nonconformities)
        ->map(fn ($row): array => $row->facts)
        ->all();

    expect($tables)->toBe([])
        ->and($rows)->not->toBeEmpty()
        ->and($workspace->publicationPreview?->stockValueCents)
        ->toBe(IntegerMoney::cents($baseline->stock_value));
});

it('previews exactly what will be written to the legacy board', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $review = ManagementReviewFixture::open($scenario['cycle']);
    $baseline = CycleFixture::currentBaseline($scenario['cycle']);

    $preview = app(SalesBoardManagementReviewWorkspaceBuilder::class)->build($review)->publicationPreview;

    $result = ManagementReviewFixture::approve($review);
    $published = $result->salesBoard;

    expect($preview)->not->toBeNull()
        ->and($preview->toSalesBoardAttributes()['stock_value'])->toBe((string) $published->stock_value)
        ->and($preview->toSalesBoardAttributes()['paid_value'])->toBe((string) $published->paid_value)
        ->and($preview->paidUnits)->toBe((int) $baseline->settled_units)
        ->and($preview->totalUnits)->toBe($published->total_units)
        ->and($preview->totalValueCents())->toBe($baseline->totalValueCents());
});
