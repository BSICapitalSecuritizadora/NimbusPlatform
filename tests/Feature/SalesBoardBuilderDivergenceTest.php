<?php

use App\DTOs\SalesBoards\SalesBoardBuilderDivergenceInput;
use App\Enums\SalesBoardBuilderDivergenceType;
use App\Enums\SalesBoardBuilderReviewSection as SectionEnum;
use App\Enums\SalesBoardBuilderReviewSectionStatus;
use App\Enums\SalesBoardMovementType;
use App\Enums\SalesBoardUnitClassification;
use App\Exceptions\SalesBoardBuilderReviewException;
use App\Models\ConstructionUnit;
use App\Models\ConstructionUnitExchange;
use App\Models\ConstructionUnitValue;
use App\Models\Contract;
use App\Models\SalesBoardBuilderDivergence;
use App\Services\SalesBoards\SalesBoardBuilderReviewEditor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SalesBoards\BuilderReviewFixture;

uses(RefreshDatabase::class);

function reviewUnderReview(): array
{
    $scenario = BuilderReviewFixture::generatedCycle();
    $scenario['review'] = BuilderReviewFixture::open($scenario['cycle']);

    return $scenario;
}

function editor(): SalesBoardBuilderReviewEditor
{
    return app(SalesBoardBuilderReviewEditor::class);
}

it('confirms a section that has no divergences', function () {
    $scenario = reviewUnderReview();
    $section = BuilderReviewFixture::section($scenario['review'], SectionEnum::PositionStock);

    $confirmed = editor()->confirmSection($section, 'Estoque confere com o nosso controle.');

    expect($confirmed->status)->toBe(SalesBoardBuilderReviewSectionStatus::Confirmed)
        ->and($confirmed->confirmed_at)->not->toBeNull()
        ->and($confirmed->comment)->toBe('Estoque confere com o nosso controle.');
});

it('turns the section divergent as soon as the first divergence lands', function () {
    $scenario = reviewUnderReview();
    $line = BuilderReviewFixture::lineFor($scenario['review'], $scenario['units']['financed']);

    BuilderReviewFixture::declare($scenario['review'], SectionEnum::PositionFinanced, new SalesBoardBuilderDivergenceInput(
        type: SalesBoardBuilderDivergenceType::StockMismatch,
        reason: 'Esta unidade foi distratada em junho e deveria estar em estoque.',
        lineId: $line->id,
        declaredClassification: SalesBoardUnitClassification::Stock,
    ));

    expect(BuilderReviewFixture::section($scenario['review'], SectionEnum::PositionFinanced)->status)
        ->toBe(SalesBoardBuilderReviewSectionStatus::Divergent);
});

it('returns the section to pending when the last divergence is removed, never to confirmed', function () {
    $scenario = reviewUnderReview();
    $line = BuilderReviewFixture::lineFor($scenario['review'], $scenario['units']['financed']);

    $divergence = BuilderReviewFixture::declare($scenario['review'], SectionEnum::PositionFinanced, new SalesBoardBuilderDivergenceInput(
        type: SalesBoardBuilderDivergenceType::StockMismatch,
        reason: 'Unidade distratada.',
        lineId: $line->id,
        declaredClassification: SalesBoardUnitClassification::Stock,
    ));

    editor()->removeDivergence($divergence);

    expect(BuilderReviewFixture::section($scenario['review'], SectionEnum::PositionFinanced)->status)
        ->toBe(SalesBoardBuilderReviewSectionStatus::Pending)
        ->and(SalesBoardBuilderDivergence::query()->count())->toBe(0);
});

it('refuses to confirm a section that has divergences', function () {
    $scenario = reviewUnderReview();
    $line = BuilderReviewFixture::lineFor($scenario['review'], $scenario['units']['financed']);

    BuilderReviewFixture::declare($scenario['review'], SectionEnum::PositionFinanced, new SalesBoardBuilderDivergenceInput(
        type: SalesBoardBuilderDivergenceType::StockMismatch,
        reason: 'Unidade distratada.',
        lineId: $line->id,
        declaredClassification: SalesBoardUnitClassification::Stock,
    ));

    $section = BuilderReviewFixture::section($scenario['review'], SectionEnum::PositionFinanced);

    expect(fn () => editor()->confirmSection($section))
        ->toThrow(SalesBoardBuilderReviewException::class, 'não pode ser confirmada');
});

it('records a sale the builder says is missing, with no contract and no unit in the Nimbus', function () {
    $scenario = reviewUnderReview();

    $divergence = BuilderReviewFixture::declare($scenario['review'], SectionEnum::MovementSales, new SalesBoardBuilderDivergenceInput(
        type: SalesBoardBuilderDivergenceType::SaleMissing,
        reason: 'Venda fechada em 22/07 e ainda não repassada ao Nimbus.',
        declaredBlock: 'B',
        declaredUnit: '404',
        declaredContractCode: 'CT-EXTERNO-99',
        declaredValueCents: 88_000_000,
        declaredDate: CarbonImmutable::parse('2026-07-22'),
    ));

    expect($divergence->type)->toBe(SalesBoardBuilderDivergenceType::SaleMissing)
        ->and($divergence->sales_board_cycle_movement_id)->toBeNull()
        ->and($divergence->sales_board_cycle_line_id)->toBeNull()
        ->and($divergence->construction_unit_id)->toBeNull()
        ->and($divergence->contract_id)->toBeNull()
        ->and($divergence->declared_unit)->toBe('404')
        ->and($divergence->declared_value)->toBe('880000.00')
        ->and($divergence->declared_date->toDateString())->toBe('2026-07-22')
        ->and($divergence->unitLabel())->toBe('B / 404')
        // Nada foi criado no cadastro a partir de uma alegação.
        ->and(ConstructionUnit::query()->where('unit', '404')->count())->toBe(0)
        ->and(Contract::query()->where('code', 'CT-EXTERNO-99')->count())->toBe(0);
});

it('demands the full description of a missing sale', function () {
    $scenario = reviewUnderReview();

    expect(fn () => BuilderReviewFixture::declare($scenario['review'], SectionEnum::MovementSales, new SalesBoardBuilderDivergenceInput(
        type: SalesBoardBuilderDivergenceType::SaleMissing,
        reason: 'Falta uma venda.',
        declaredUnit: '404',
    )))->toThrow(SalesBoardBuilderReviewException::class, 'exige informar: data, valor');
});

it('anchors an extra sale on the frozen movement without changing it', function () {
    $scenario = reviewUnderReview();
    $movement = BuilderReviewFixture::movementFor($scenario['review'], SalesBoardMovementType::Sale, $scenario['contracts']['soldInMonth']);
    $before = $movement->snapshot_fingerprint;

    $divergence = BuilderReviewFixture::declare($scenario['review'], SectionEnum::MovementSales, new SalesBoardBuilderDivergenceInput(
        type: SalesBoardBuilderDivergenceType::SaleExtra,
        reason: 'Esta venda foi cancelada antes da assinatura e não deveria constar.',
        movementId: $movement->id,
    ));

    expect($divergence->sales_board_cycle_movement_id)->toBe($movement->id)
        ->and($movement->fresh()->snapshot_fingerprint)->toBe($before)
        ->and($movement->fresh()->sale_value)->toBe('480000.00');
});

it('records a sale value mismatch against the frozen sale', function () {
    $scenario = reviewUnderReview();
    $movement = BuilderReviewFixture::movementFor($scenario['review'], SalesBoardMovementType::Sale, $scenario['contracts']['soldInMonth']);

    $divergence = BuilderReviewFixture::declare($scenario['review'], SectionEnum::MovementSales, new SalesBoardBuilderDivergenceInput(
        type: SalesBoardBuilderDivergenceType::SaleValueMismatch,
        reason: 'O contrato assinado é de R$ 495.000,00.',
        movementId: $movement->id,
        declaredValueCents: 49_500_000,
    ));

    expect($divergence->declared_value)->toBe('495000.00')
        ->and($movement->fresh()->sale_value)->toBe('480000.00');
});

it('demands a value for a sale value mismatch and a date for a date mismatch', function () {
    $scenario = reviewUnderReview();
    $movement = BuilderReviewFixture::movementFor($scenario['review'], SalesBoardMovementType::Sale, $scenario['contracts']['soldInMonth']);

    expect(fn () => BuilderReviewFixture::declare($scenario['review'], SectionEnum::MovementSales, new SalesBoardBuilderDivergenceInput(
        type: SalesBoardBuilderDivergenceType::SaleValueMismatch,
        reason: 'Valor errado.',
        movementId: $movement->id,
    )))->toThrow(SalesBoardBuilderReviewException::class, 'exige informar: valor');

    expect(fn () => BuilderReviewFixture::declare($scenario['review'], SectionEnum::MovementSales, new SalesBoardBuilderDivergenceInput(
        type: SalesBoardBuilderDivergenceType::SaleDateMismatch,
        reason: 'Data errada.',
        movementId: $movement->id,
    )))->toThrow(SalesBoardBuilderReviewException::class, 'exige informar: data');
});

it('records a settlement mismatch anchored on the settlement movement', function () {
    $scenario = reviewUnderReview();
    $movement = BuilderReviewFixture::movementFor($scenario['review'], SalesBoardMovementType::Settlement, $scenario['contracts']['settled']);

    $divergence = BuilderReviewFixture::declare($scenario['review'], SectionEnum::MovementSettlements, new SalesBoardBuilderDivergenceInput(
        type: SalesBoardBuilderDivergenceType::SettlementMismatch,
        reason: 'O contrato ainda tem saldo devedor; não foi quitado em julho.',
        movementId: $movement->id,
    ));

    expect($divergence->sales_board_cycle_movement_id)->toBe($movement->id)
        ->and($divergence->declared_date)->toBeNull();
});

it('records a cancellation mismatch anchored on the cancellation movement', function () {
    $scenario = reviewUnderReview();
    $movement = BuilderReviewFixture::movementFor($scenario['review'], SalesBoardMovementType::Cancellation, $scenario['contracts']['cancelled']);

    $divergence = BuilderReviewFixture::declare($scenario['review'], SectionEnum::MovementCancellations, new SalesBoardBuilderDivergenceInput(
        type: SalesBoardBuilderDivergenceType::CancellationMismatch,
        reason: 'O distrato foi assinado em 25/07, não em 20/07.',
        movementId: $movement->id,
        declaredDate: CarbonImmutable::parse('2026-07-25'),
    ));

    expect($divergence->declared_date->toDateString())->toBe('2026-07-25')
        ->and($movement->fresh()->event_date->toDateString())->toBe('2026-07-20');
});

it('records a classification mismatch without touching the frozen line', function () {
    $scenario = reviewUnderReview();
    $line = BuilderReviewFixture::lineFor($scenario['review'], $scenario['units']['stock']);

    expect($line->classification)->toBe(SalesBoardUnitClassification::Stock);

    $divergence = BuilderReviewFixture::declare($scenario['review'], SectionEnum::PositionStock, new SalesBoardBuilderDivergenceInput(
        type: SalesBoardBuilderDivergenceType::StockMismatch,
        reason: 'A unidade foi revendida em 28/07 e deveria estar financiada.',
        lineId: $line->id,
        declaredClassification: SalesBoardUnitClassification::Financed,
    ));

    expect($divergence->declared_classification)->toBe(SalesBoardUnitClassification::Financed)
        ->and($line->fresh()->classification)->toBe(SalesBoardUnitClassification::Stock);
});

it('records an exchange mismatch without touching the exchange registry', function () {
    $scenario = reviewUnderReview();
    $line = BuilderReviewFixture::lineFor($scenario['review'], $scenario['units']['exchanged']);

    $divergence = BuilderReviewFixture::declare($scenario['review'], SectionEnum::PositionExchanged, new SalesBoardBuilderDivergenceInput(
        type: SalesBoardBuilderDivergenceType::ExchangeMismatch,
        reason: 'O valor acordado na permuta foi de R$ 720.000,00.',
        lineId: $line->id,
        declaredValueCents: 72_000_000,
    ));

    expect($divergence->declared_value)->toBe('720000.00')
        ->and($line->fresh()->exchange_value)->toBe('700000.00')
        ->and(ConstructionUnitExchange::query()->where('exchange_value', '720000.00')->count())->toBe(0);
});

it('demands a classification or a value for an exchange mismatch', function () {
    $scenario = reviewUnderReview();
    $line = BuilderReviewFixture::lineFor($scenario['review'], $scenario['units']['exchanged']);

    expect(fn () => BuilderReviewFixture::declare($scenario['review'], SectionEnum::PositionExchanged, new SalesBoardBuilderDivergenceInput(
        type: SalesBoardBuilderDivergenceType::ExchangeMismatch,
        reason: 'Permuta errada.',
        lineId: $line->id,
    )))->toThrow(SalesBoardBuilderReviewException::class, 'exige informar');
});

it('records a unit value mismatch without touching the value history', function () {
    $scenario = reviewUnderReview();
    $line = BuilderReviewFixture::lineFor($scenario['review'], $scenario['units']['stock']);

    $divergence = BuilderReviewFixture::declare($scenario['review'], SectionEnum::PositionStock, new SalesBoardBuilderDivergenceInput(
        type: SalesBoardBuilderDivergenceType::UnitValueMismatch,
        reason: 'A tabela vigente em julho era de R$ 520.000,00.',
        lineId: $line->id,
        declaredValueCents: 52_000_000,
    ));

    expect($divergence->declared_value)->toBe('520000.00')
        ->and($line->fresh()->unit_reference_value)->toBe('500000.00')
        ->and(ConstructionUnitValue::query()->where('value', '520000.00')->count())->toBe(0);
});

it('accepts Other only with a reason', function () {
    $scenario = reviewUnderReview();

    $divergence = BuilderReviewFixture::declare($scenario['review'], SectionEnum::PositionStock, new SalesBoardBuilderDivergenceInput(
        type: SalesBoardBuilderDivergenceType::Other,
        reason: 'A unidade A-101 está reservada para permuta a ser formalizada.',
    ));

    expect($divergence->type)->toBe(SalesBoardBuilderDivergenceType::Other);

    expect(fn () => BuilderReviewFixture::declare($scenario['review'], SectionEnum::PositionStock, new SalesBoardBuilderDivergenceInput(
        type: SalesBoardBuilderDivergenceType::Other,
        reason: '   ',
    )))->toThrow(SalesBoardBuilderReviewException::class, 'Descreva o motivo');
});

it('refuses a divergence filed under a section that does not accept its type', function () {
    $scenario = reviewUnderReview();
    $movement = BuilderReviewFixture::movementFor($scenario['review'], SalesBoardMovementType::Sale, $scenario['contracts']['soldInMonth']);

    expect(fn () => BuilderReviewFixture::declare($scenario['review'], SectionEnum::PositionStock, new SalesBoardBuilderDivergenceInput(
        type: SalesBoardBuilderDivergenceType::SaleExtra,
        reason: 'Venda inexistente.',
        movementId: $movement->id,
    )))->toThrow(SalesBoardBuilderReviewException::class, 'não pertence à seção');
});

it('refuses an anchor that belongs to a different section', function () {
    $scenario = reviewUnderReview();
    $financedLine = BuilderReviewFixture::lineFor($scenario['review'], $scenario['units']['financed']);

    // Linha financiada apontada dentro da seção de estoque: a divergência ficaria
    // escondida numa seção que a construtora confirmou.
    expect(fn () => BuilderReviewFixture::declare($scenario['review'], SectionEnum::PositionStock, new SalesBoardBuilderDivergenceInput(
        type: SalesBoardBuilderDivergenceType::StockMismatch,
        reason: 'Classificação errada.',
        lineId: $financedLine->id,
        declaredClassification: SalesBoardUnitClassification::Stock,
    )))->toThrow(SalesBoardBuilderReviewException::class, 'não pertence à seção');
});

it('refuses an anchor from another baseline', function () {
    $scenario = reviewUnderReview();
    $other = BuilderReviewFixture::generatedCycle();
    $foreignLine = $other['cycle']->currentBaseline->lines()->first();

    expect(fn () => BuilderReviewFixture::declare($scenario['review'], SectionEnum::PositionStock, new SalesBoardBuilderDivergenceInput(
        type: SalesBoardBuilderDivergenceType::StockMismatch,
        reason: 'Classificação errada.',
        lineId: $foreignLine->id,
        declaredClassification: SalesBoardUnitClassification::Financed,
    )))->toThrow(SalesBoardBuilderReviewException::class, 'não pertence à versão');
});

it('lets the builder edit a divergence while the review is a draft', function () {
    $scenario = reviewUnderReview();
    $movement = BuilderReviewFixture::movementFor($scenario['review'], SalesBoardMovementType::Sale, $scenario['contracts']['soldInMonth']);

    $divergence = BuilderReviewFixture::declare($scenario['review'], SectionEnum::MovementSales, new SalesBoardBuilderDivergenceInput(
        type: SalesBoardBuilderDivergenceType::SaleValueMismatch,
        reason: 'Valor divergente.',
        movementId: $movement->id,
        declaredValueCents: 49_500_000,
    ));

    $updated = editor()->updateDivergence($divergence, new SalesBoardBuilderDivergenceInput(
        type: SalesBoardBuilderDivergenceType::SaleValueMismatch,
        reason: 'Valor divergente — corrigido após conferir o contrato.',
        movementId: $movement->id,
        declaredValueCents: 49_900_000,
    ));

    expect($updated->declared_value)->toBe('499000.00')
        ->and($updated->reason)->toContain('corrigido após conferir');
});
