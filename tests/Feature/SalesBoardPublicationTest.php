<?php

use App\Enums\SalesBoardCycleStatus;
use App\Enums\SalesBoardManagementReviewStatus;
use App\Enums\SalesBoardPositionStatus;
use App\Exceptions\SalesBoardManagementReviewException;
use App\Models\Emission;
use App\Models\SalesBoard;
use App\Models\SalesBoardHistory;
use App\Models\SalesBoardPublication;
use App\Models\User;
use App\Services\SalesBoards\SalesBoardPositionReader;
use App\Services\SalesBoards\SalesBoardPublicationProjection;
use App\Support\Money\IntegerMoney;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SalesBoards\CycleFixture;
use Tests\Support\SalesBoards\ManagementReviewFixture;

uses(RefreshDatabase::class);

it('projects every baseline bucket onto its legacy column, to the cent', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $cycle = $scenario['cycle']->fresh();
    $baseline = CycleFixture::currentBaseline($cycle);

    $payload = app(SalesBoardPublicationProjection::class)->project($cycle, $baseline);

    expect($payload->emissionId)->toBe($cycle->emission_id)
        ->and($payload->constructionId)->toBe($cycle->construction_id)
        ->and($payload->referenceMonth->toDateString())->toBe($cycle->reference_month->toDateString())
        ->and($payload->stockUnits)->toBe((int) $baseline->stock_units)
        ->and($payload->financedUnits)->toBe((int) $baseline->financed_units)
        // A tradução do vocabulário: `settled` da V2 é `paid` no quadro legado.
        ->and($payload->paidUnits)->toBe((int) $baseline->settled_units)
        ->and($payload->exchangedUnits)->toBe((int) $baseline->exchanged_units)
        ->and($payload->stockValueCents)->toBe(IntegerMoney::cents($baseline->stock_value))
        ->and($payload->financedValueCents)->toBe(IntegerMoney::cents($baseline->financed_value))
        ->and($payload->paidValueCents)->toBe(IntegerMoney::cents($baseline->settled_value))
        ->and($payload->exchangedValueCents)->toBe(IntegerMoney::cents($baseline->exchanged_value))
        ->and($payload->totalUnits)->toBe((int) $baseline->units_total);
});

it('persists the projected values without passing through a float', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $cycle = $scenario['cycle']->fresh();
    $baseline = CycleFixture::currentBaseline($cycle);

    $review = ManagementReviewFixture::open($cycle);
    ManagementReviewFixture::approve($review);

    $salesBoard = SalesBoard::query()->sole();

    expect((string) $salesBoard->stock_value)->toBe((string) $baseline->stock_value)
        ->and((string) $salesBoard->financed_value)->toBe((string) $baseline->financed_value)
        ->and((string) $salesBoard->paid_value)->toBe((string) $baseline->settled_value)
        ->and((string) $salesBoard->exchanged_value)->toBe((string) $baseline->exchanged_value)
        ->and($salesBoard->stock_units)->toBe((int) $baseline->stock_units)
        ->and($salesBoard->financed_units)->toBe((int) $baseline->financed_units)
        ->and($salesBoard->paid_units)->toBe((int) $baseline->settled_units)
        ->and($salesBoard->exchanged_units)->toBe((int) $baseline->exchanged_units)
        ->and($salesBoard->total_units)->toBe((int) $baseline->units_total)
        ->and($salesBoard->reference_month->toDateString())->toBe($cycle->reference_month->toDateString())
        ->and($salesBoard->emission_id)->toBe($cycle->emission_id)
        ->and($salesBoard->construction_id)->toBe($cycle->construction_id);
});

it('keeps the cents exact for a value whose float representation would drift', function () {
    // 0,1 + 0,2 em ponto flutuante não é 0,3; em centavos inteiros é.
    expect(IntegerMoney::decimalString(IntegerMoney::cents('1000000.10') + IntegerMoney::cents('0.20')))
        ->toBe('1000000.30');
});

it('records exactly one history version when the position is published', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $review = ManagementReviewFixture::open($scenario['cycle']);

    expect(SalesBoardHistory::query()->count())->toBe(0);

    ManagementReviewFixture::approve($review);

    $salesBoard = SalesBoard::query()->sole();
    $history = SalesBoardHistory::query()->sole();

    expect($history->sales_board_id)->toBe($salesBoard->id)
        ->and($history->is_initial)->toBeFalse()
        ->and($history->change_reason)->toBeNull()
        ->and((string) $history->stock_value)->toBe((string) $salesBoard->stock_value)
        ->and($history->total_units)->toBe($salesBoard->total_units);
});

it('refuses to publish over a position registered by hand for the same competence', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $cycle = $scenario['cycle']->fresh();

    $manual = SalesBoard::factory()->create([
        'emission_id' => $cycle->emission_id,
        'construction_id' => $cycle->construction_id,
        'reference_month' => $cycle->reference_month->toDateString(),
        'stock_units' => 7,
        'financed_units' => 0,
        'paid_units' => 0,
        'exchanged_units' => 0,
        'stock_value' => '123456.78',
        'financed_value' => '0.00',
        'paid_value' => '0.00',
        'exchanged_value' => '0.00',
    ]);

    $review = ManagementReviewFixture::open($cycle);

    expect(fn () => ManagementReviewFixture::approve($review))
        ->toThrow(SalesBoardManagementReviewException::class, SalesBoardManagementReviewException::LEGACY_POSITION_EXISTS);

    $manual->refresh();

    expect(SalesBoard::query()->count())->toBe(1)
        ->and($manual->stock_units)->toBe(7)
        ->and((string) $manual->stock_value)->toBe('123456.78')
        ->and(SalesBoardPublication::query()->count())->toBe(0)
        ->and($review->fresh()->status)->toBe(SalesBoardManagementReviewStatus::Draft)
        ->and($cycle->fresh()->status)->toBe(SalesBoardCycleStatus::ManagementReview);
});

it('detects the conflict even when the manual board belongs to another emission', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $cycle = $scenario['cycle']->fresh();

    // O leitor da posição consulta por empreendimento, sem filtrar emissão:
    // publicar ao lado criaria duas posições para o mesmo mês.
    $otherEmission = Emission::factory()->create(['status' => 'active']);

    SalesBoard::factory()->create([
        'emission_id' => $otherEmission->id,
        'construction_id' => $cycle->construction_id,
        'reference_month' => $cycle->reference_month->toDateString(),
    ]);

    $review = ManagementReviewFixture::open($cycle);

    expect(fn () => ManagementReviewFixture::approve($review))
        ->toThrow(SalesBoardManagementReviewException::class, SalesBoardManagementReviewException::LEGACY_POSITION_EXISTS);

    expect(SalesBoardPublication::query()->count())->toBe(0);
});

it('lets the position reader see the approved position after publication', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $cycle = $scenario['cycle']->fresh();
    $baseline = CycleFixture::currentBaseline($cycle);

    $review = ManagementReviewFixture::open($cycle);
    ManagementReviewFixture::approve($review);

    $position = app(SalesBoardPositionReader::class)->forConstruction(
        $scenario['construction']->fresh(),
        CarbonImmutable::parse($cycle->reference_month->toDateString()),
    );

    expect($position->status)->toBe(SalesBoardPositionStatus::Current)
        ->and($position->stockUnits)->toBe((int) $baseline->stock_units)
        ->and($position->financedUnits)->toBe((int) $baseline->financed_units)
        ->and($position->paidUnits)->toBe((int) $baseline->settled_units)
        ->and($position->exchangedUnits)->toBe((int) $baseline->exchanged_units)
        ->and($position->totalUnits)->toBe((int) $baseline->units_total)
        ->and(IntegerMoney::cents((string) $position->stockValue))->toBe(IntegerMoney::cents($baseline->stock_value))
        ->and(IntegerMoney::cents((string) $position->financedValue))->toBe(IntegerMoney::cents($baseline->financed_value))
        ->and(IntegerMoney::cents((string) $position->paidValue))->toBe(IntegerMoney::cents($baseline->settled_value))
        ->and(IntegerMoney::cents((string) $position->exchangedValue))->toBe(IntegerMoney::cents($baseline->exchanged_value));
});

it('sums both constructions of an emission after both are published', function () {
    $emission = Emission::factory()->create(['status' => 'active']);

    $first = ManagementReviewFixture::submittedCycleOn($emission, '1');
    $second = ManagementReviewFixture::submittedCycleOn($emission, '2');

    ManagementReviewFixture::approve(ManagementReviewFixture::open($first['cycle']));
    ManagementReviewFixture::approve(ManagementReviewFixture::open($second['cycle']));

    $firstBaseline = CycleFixture::currentBaseline($first['cycle']);
    $secondBaseline = CycleFixture::currentBaseline($second['cycle']);

    $position = app(SalesBoardPositionReader::class)->forEmission(
        $emission->fresh(),
        CarbonImmutable::parse($first['cycle']->fresh()->reference_month->toDateString()),
    );

    // A emissão é a soma dos empreendimentos; nenhum quadro sintético da
    // emissão é criado.
    expect(SalesBoard::query()->count())->toBe(2)
        ->and($position->stockUnits)->toBe((int) $firstBaseline->stock_units + (int) $secondBaseline->stock_units)
        ->and($position->financedUnits)->toBe((int) $firstBaseline->financed_units + (int) $secondBaseline->financed_units)
        ->and($position->totalUnits)->toBe((int) $firstBaseline->units_total + (int) $secondBaseline->units_total);
});

it('never lets a publication be edited or deleted', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $review = ManagementReviewFixture::open($scenario['cycle']);
    ManagementReviewFixture::approve($review);

    $publication = SalesBoardPublication::query()->sole();

    expect(fn () => $publication->forceFill(['source_changed' => true])->save())
        ->toThrow(LogicException::class, 'immutable')
        ->and(fn () => $publication->delete())
        ->toThrow(LogicException::class, 'immutable');
});

it('never lets an approved management review be edited or deleted', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $review = ManagementReviewFixture::open($scenario['cycle']);
    ManagementReviewFixture::approve($review, User::factory()->create());

    $approved = $review->fresh();

    expect(fn () => $approved->forceFill(['overall_comment' => 'reescrevendo'])->save())
        ->toThrow(LogicException::class, 'immutable')
        ->and(fn () => $approved->delete())
        ->toThrow(LogicException::class, 'cannot be deleted');
});
