<?php

use App\DTOs\SalesBoards\SalesBoardBuilderDivergenceInput;
use App\DTOs\SalesBoards\SalesBoardBuilderReviewWorkspace;
use App\DTOs\SalesBoards\SalesBoardBuilderWorkspaceMovementRow;
use App\DTOs\SalesBoards\SalesBoardBuilderWorkspaceUnitRow;
use App\Enums\SalesBoardBuilderDivergenceType;
use App\Enums\SalesBoardBuilderReviewSection as SectionEnum;
use App\Enums\SalesBoardBuilderReviewSectionStatus;
use App\Enums\SalesBoardMovementType;
use App\Enums\SalesBoardUnitClassification;
use App\Models\Client;
use App\Services\SalesBoards\SalesBoardBuilderReviewEditor;
use App\Services\SalesBoards\SalesBoardBuilderReviewWorkspaceBuilder;
use App\Support\Money\IntegerMoney;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SalesBoards\BuilderReviewFixture;
use Tests\Support\SalesBoards\CycleFixture;

uses(RefreshDatabase::class);

function workspaceFor($review): SalesBoardBuilderReviewWorkspace
{
    return app(SalesBoardBuilderReviewWorkspaceBuilder::class)->build($review->fresh());
}

it('presents the header, the four buckets and the seven sections', function () {
    $scenario = BuilderReviewFixture::generatedCycle();
    $review = BuilderReviewFixture::open($scenario['cycle']);

    $workspace = workspaceFor($review);

    expect($workspace->constructionName)->toBe($scenario['construction']->development_name)
        ->and($workspace->referenceMonth)->toBe('07/2026')
        ->and($workspace->positionDate->toDateString())->toBe('2026-07-31')
        ->and($workspace->attempt)->toBe(1)
        ->and($workspace->unitsTotal)->toBe(5)
        ->and($workspace->buckets)->toHaveCount(4)
        ->and(collect($workspace->buckets)->pluck('label')->all())
        ->toBe(['Estoque', 'Financiado', 'Quitado', 'Permutado'])
        ->and($workspace->sections)->toHaveCount(7)
        ->and($workspace->positionSections())->toHaveCount(4)
        ->and($workspace->movementSections())->toHaveCount(3);
});

it('lists the units of each position section and the movements of each movement section', function () {
    $scenario = BuilderReviewFixture::generatedCycle();
    $review = BuilderReviewFixture::open($scenario['cycle']);

    $workspace = workspaceFor($review);

    $stock = $workspace->section(SectionEnum::PositionStock);
    $financed = $workspace->section(SectionEnum::PositionFinanced);
    $sales = $workspace->section(SectionEnum::MovementSales);
    $settlements = $workspace->section(SectionEnum::MovementSettlements);
    $cancellations = $workspace->section(SectionEnum::MovementCancellations);

    expect($stock->rows)->toHaveCount(1)
        ->and($stock->rows[0])->toBeInstanceOf(SalesBoardBuilderWorkspaceUnitRow::class)
        ->and($stock->rows[0]->classification)->toBe(SalesBoardUnitClassification::Stock)
        ->and($financed->rows)->toHaveCount(2)
        ->and($sales->rows)->toHaveCount(2)
        ->and($sales->rows[0])->toBeInstanceOf(SalesBoardBuilderWorkspaceMovementRow::class)
        ->and($settlements->rows)->toHaveCount(1)
        ->and($cancellations->rows)->toHaveCount(1)
        ->and($settlements->rows[0]->type)->toBe(SalesBoardMovementType::Settlement)
        // A quitação não tem dia apurável: a tela não inventa um.
        ->and($settlements->rows[0]->eventDate)->toBeNull();
});

it('never reads the live source to build the workspace', function () {
    $scenario = BuilderReviewFixture::generatedCycle();
    $review = BuilderReviewFixture::open($scenario['cycle']);

    // A fonte muda depois de a revisão ser aberta.
    $scenario['contracts']['financed']->update(['sale_value' => '910000.00']);

    $review = $review->fresh();
    $review->load(['cycle.construction', 'baseline.lines', 'baseline.movements', 'sections', 'divergences']);

    DB::enableQueryLog();
    DB::flushQueryLog();

    $workspace = app(SalesBoardBuilderReviewWorkspaceBuilder::class)->build($review);

    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    $touchesLiveSource = $queries->contains(fn (string $q): bool => (bool) preg_match(
        '/\b(contracts|contract_installments|construction_units|construction_unit_values|construction_unit_exchanges|sales_discount_policies|clients)\b/i',
        $q,
    ));

    $financed = $workspace->section(SectionEnum::PositionFinanced);
    $frozen = collect($financed->rows)->firstWhere('contractCode', $scenario['contracts']['financed']->code);

    expect($touchesLiveSource)->toBeFalse()
        // A tela continua mostrando o quadro sobre o qual a construtora foi perguntada.
        ->and($frozen->saleValueCents)->toBe(90_000_000)
        ->and($scenario['contracts']['financed']->fresh()->sale_value)->toBe('910000.00');
});

it('does not expose the buyer of a contract', function () {
    $scenario = BuilderReviewFixture::generatedCycle();

    $client = Client::factory()->create([
        'name' => 'Comprador Confidencial',
        'email' => 'comprador@exemplo.com',
        'phone' => '(11) 90000-0000',
    ]);
    $scenario['contracts']['financed']->clients()->attach($client->id);

    $review = BuilderReviewFixture::open($scenario['cycle']);
    $serialized = json_encode(workspaceFor($review), JSON_UNESCAPED_UNICODE);

    expect($serialized)->not->toContain('Comprador Confidencial')
        ->and($serialized)->not->toContain('comprador@exemplo.com')
        ->and($serialized)->not->toContain('90000-0000');
});

it('does not expose the internal discount policy nor the conformity verdict', function () {
    $scenario = BuilderReviewFixture::generatedCycle();
    $review = BuilderReviewFixture::open($scenario['cycle']);

    $sale = collect(workspaceFor($review)->section(SectionEnum::MovementSales)->rows)->first();
    $frozen = BuilderReviewFixture::movementFor($review, SalesBoardMovementType::Sale, $scenario['contracts']['soldInMonth']);

    // O snapshot continua guardando tudo; a tela da construtora é que não mostra.
    expect($frozen->authorized_discount_basis_points)->not->toBeNull()
        ->and($frozen->minimum_authorized_value)->not->toBeNull()
        ->and($frozen->conformity_status)->not->toBeNull();

    $exposed = array_keys(get_object_vars($sale));

    expect($exposed)->not->toContain('authorizedDiscountBasisPoints')
        ->and($exposed)->not->toContain('minimumAuthorizedValueCents')
        ->and($exposed)->not->toContain('effectiveDiscountBasisPoints')
        ->and($exposed)->not->toContain('conformityStatus')
        ->and($exposed)->not->toContain('salesDiscountPolicyId')
        ->and($exposed)->toContain('contractCode')
        ->and($exposed)->toContain('eventDate')
        ->and($exposed)->toContain('saleValueCents');
});

it('does not expose fingerprints or internal identifiers', function () {
    $scenario = BuilderReviewFixture::generatedCycle();
    $review = BuilderReviewFixture::open($scenario['cycle']);
    $baseline = CycleFixture::currentBaseline($scenario['cycle']);

    $serialized = json_encode(workspaceFor($review), JSON_UNESCAPED_UNICODE);

    expect($serialized)->not->toContain($baseline->snapshot_fingerprint)
        ->and($serialized)->not->toContain($baseline->source_fingerprint)
        ->and($serialized)->not->toContain('fingerprint')
        ->and($serialized)->not->toContain('source_only')
        ->and($serialized)->not->toContain('somente_fonte');
});

it('reports the review progress in plain language', function () {
    $scenario = BuilderReviewFixture::generatedCycle();
    $review = BuilderReviewFixture::open($scenario['cycle']);

    expect(workspaceFor($review)->progressLabel())->toBe('0 de 7 seções revisadas')
        ->and(workspaceFor($review)->progressPercent())->toBe(0)
        ->and(workspaceFor($review)->canSubmit())->toBeFalse();

    app(SalesBoardBuilderReviewEditor::class)
        ->confirmSection(BuilderReviewFixture::section($review, SectionEnum::PositionStock));

    expect(workspaceFor($review)->progressLabel())->toBe('1 de 7 seções revisadas')
        ->and(workspaceFor($review)->progressPercent())->toBe(14)
        ->and(workspaceFor($review)->pendingSections())->toHaveCount(6);

    BuilderReviewFixture::confirmAll($review);

    expect(workspaceFor($review)->canSubmit())->toBeTrue()
        ->and(workspaceFor($review)->pendingSections())->toBe([]);
});

it('carries the declared divergences and their counts per section', function () {
    $scenario = BuilderReviewFixture::generatedCycle();
    $review = BuilderReviewFixture::open($scenario['cycle']);

    $line = BuilderReviewFixture::lineFor($review, $scenario['units']['financed']);
    BuilderReviewFixture::declare($review, SectionEnum::PositionFinanced, new SalesBoardBuilderDivergenceInput(
        type: SalesBoardBuilderDivergenceType::StockMismatch,
        reason: 'Deveria estar em estoque.',
        lineId: $line->id,
        declaredClassification: SalesBoardUnitClassification::Stock,
    ));

    $workspace = workspaceFor($review);

    expect($workspace->divergenceCount())->toBe(1)
        ->and($workspace->section(SectionEnum::PositionFinanced)->divergenceCount)->toBe(1)
        ->and($workspace->section(SectionEnum::PositionFinanced)->status)->toBe(SalesBoardBuilderReviewSectionStatus::Divergent)
        ->and($workspace->section(SectionEnum::PositionStock)->divergenceCount)->toBe(0);
});

it('shows the section totals with the same rule as the position', function () {
    $scenario = BuilderReviewFixture::generatedCycle();
    $review = BuilderReviewFixture::open($scenario['cycle']);
    $baseline = CycleFixture::currentBaseline($scenario['cycle']);

    $workspace = workspaceFor($review);

    expect($workspace->section(SectionEnum::PositionFinanced)->totalValueCents)
        ->toBe(IntegerMoney::cents($baseline->financed_value))
        ->and($workspace->section(SectionEnum::PositionExchanged)->totalValueCents)
        ->toBe(IntegerMoney::cents($baseline->exchanged_value))
        ->and($workspace->section(SectionEnum::MovementSales)->headline())
        ->toBe('2 lançamento(s) na competência');
});

it('keeps showing the version the builder reviewed after a newer one exists', function () {
    $scenario = BuilderReviewFixture::generatedCycle();
    $review = BuilderReviewFixture::open($scenario['cycle']);
    BuilderReviewFixture::confirmAll($review);
    $submitted = BuilderReviewFixture::submit($review);

    $scenario['contracts']['financed']->update(['sale_value' => '910000.00']);
    CycleFixture::recalculate($scenario['cycle'], 'Correção do valor de venda.');

    $workspace = workspaceFor($submitted);
    $financed = collect($workspace->section(SectionEnum::PositionFinanced)->rows)
        ->firstWhere('contractCode', $scenario['contracts']['financed']->code);

    // A revisão foi substituída, mas o que ela mostrava continua sendo o que a
    // construtora viu.
    expect($financed->saleValueCents)->toBe(90_000_000)
        ->and(CycleFixture::currentBaseline($scenario['cycle'])->financed_value)->toBe('1390000.00');
});
