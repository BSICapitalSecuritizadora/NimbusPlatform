<?php

use App\DTOs\SalesBoards\ResolvedUnitValue;
use App\Enums\ImportRowWarningCode;
use App\Support\Imports\PlausibilityVerdict;
use App\Support\Imports\SpreadsheetPlausibility;
use App\Support\SalesBoards\SalesBoardPlausibility;
use Carbon\CarbonImmutable;

/**
 * As regras de plausibilidade das importações, nas fronteiras exatas, em
 * centavos. Aviso no caso duvidoso, erro de linha no claramente impossível.
 *
 * A venda e o valor de unidade usam os fatores da derivação do Quadro
 * ({@see SalesBoardPlausibility}), com fronteiras inclusivas: a importação nunca
 * aceita com aviso o que a derivação bloqueia.
 */

/**
 * @return list<string>
 */
function plausibilityWarningCodes(PlausibilityVerdict $verdict): array
{
    return array_map(fn (array $warning): string => $warning['code']->value, $verdict->warnings);
}

it('errs on an installment above twice the sale, one cent past the boundary', function (string $field) {
    $sale = 50_000_000;

    $exactlyTwice = $field === 'previsto'
        ? SpreadsheetPlausibility::installment(2 * $sale, null, $sale)
        : SpreadsheetPlausibility::installment(2 * $sale, 2 * $sale, $sale);
    $oneCentMore = $field === 'previsto'
        ? SpreadsheetPlausibility::installment((2 * $sale) + 1, null, $sale)
        : SpreadsheetPlausibility::installment(1_000_000, (2 * $sale) + 1, $sale);

    expect($exactlyTwice->error)->toBeNull()
        ->and($oneCentMore->error)->toContain('maior que o dobro do valor da venda do contrato (R$ 500.000,00)');
})->with(['previsto', 'pago']);

it('warns on an installment above the sale value, one cent past the boundary', function () {
    $sale = 50_000_000;

    expect(plausibilityWarningCodes(SpreadsheetPlausibility::installment($sale, null, $sale)))->toBe([])
        ->and(plausibilityWarningCodes(SpreadsheetPlausibility::installment($sale + 1, null, $sale)))
        ->toBe([ImportRowWarningCode::InstallmentAboveSaleValue->value]);
});

it('warns on an expected value below 0.01% of the sale', function () {
    $sale = 50_000_000;

    expect(plausibilityWarningCodes(SpreadsheetPlausibility::installment(5_000, null, $sale)))->toBe([])
        ->and(plausibilityWarningCodes(SpreadsheetPlausibility::installment(4_999, null, $sale)))
        ->toBe([ImportRowWarningCode::InstallmentFarBelowSaleValue->value]);
});

it('warns on a payment at twice the expected value or more', function () {
    expect(plausibilityWarningCodes(SpreadsheetPlausibility::installment(1_000_000, 1_999_999, 50_000_000)))->toBe([])
        ->and(plausibilityWarningCodes(SpreadsheetPlausibility::installment(1_000_000, 2_000_000, 50_000_000)))
        ->toBe([ImportRowWarningCode::PaidFarAboveExpected->value]);
});

it('errs on a sale ten times the table or a tenth of it, and warns at twice or half', function () {
    $table = 40_000_000;

    expect(SpreadsheetPlausibility::sale(10 * $table, $table, '2026-03-10')->error)
        ->toBe('Valor da venda (R$ 4.000.000,00) é cerca de 10 vezes o valor de tabela da unidade em 10/03/2026 (R$ 400.000,00): um dos dois foi lido errado. Confira a planilha e o valor da unidade.')
        ->and(SpreadsheetPlausibility::sale((10 * $table) - 1, $table, '2026-03-10')->error)->toBeNull()
        ->and(SpreadsheetPlausibility::sale(intdiv($table, 10), $table, '2026-03-10')->error)->toContain('cerca de 1/10 do valor de tabela')
        ->and(SpreadsheetPlausibility::sale(intdiv($table, 10) + 1, $table, '2026-03-10')->error)->toBeNull()
        ->and(plausibilityWarningCodes(SpreadsheetPlausibility::sale(2 * $table, $table, '2026-03-10')))->toBe([ImportRowWarningCode::SaleValueOffTable->value])
        ->and(plausibilityWarningCodes(SpreadsheetPlausibility::sale((2 * $table) - 1, $table, '2026-03-10')))->toBe([])
        ->and(plausibilityWarningCodes(SpreadsheetPlausibility::sale(intdiv($table, 2), $table, '2026-03-10')))->toBe([ImportRowWarningCode::SaleValueOffTable->value])
        ->and(plausibilityWarningCodes(SpreadsheetPlausibility::sale(intdiv($table, 2) + 1, $table, '2026-03-10')))->toBe([]);
});

/**
 * A fronteira comum com a derivação: exatamente dez vezes já está fora de escala
 * nos dois lados.
 */
it('agrees with the derivation on the scale boundary', function () {
    expect(SalesBoardPlausibility::isOutOfScale(1_000_000, 100_000))->toBeTrue()
        ->and(SpreadsheetPlausibility::sale(1_000_000, 100_000, null)->isImpossible())->toBeTrue()
        ->and(SpreadsheetPlausibility::unitValue(1_000_000, 100_000)->isImpossible())->toBeTrue()
        ->and(SalesBoardPlausibility::isOutOfScale(999_999, 100_000))->toBeFalse()
        ->and(SpreadsheetPlausibility::unitValue(999_999, 100_000)->isImpossible())->toBeFalse();
});

it('errs on a unit value ten times the current one and warns at twice', function () {
    $current = 40_000_000;

    expect(SpreadsheetPlausibility::unitValue(10 * $current, $current)->error)->toContain('cerca de 10 vezes o valor vigente da unidade (R$ 400.000,00)')
        ->and(SpreadsheetPlausibility::unitValue(intdiv($current, 10), $current)->isImpossible())->toBeTrue()
        ->and(plausibilityWarningCodes(SpreadsheetPlausibility::unitValue(2 * $current, $current)))->toBe([ImportRowWarningCode::UnitValueFarFromCurrent->value])
        ->and(plausibilityWarningCodes(SpreadsheetPlausibility::unitValue((2 * $current) - 1, $current)))->toBe([]);
});

it('compares a base value with the median, strictly beyond five and a hundred times', function () {
    $median = 40_000_000;

    expect(plausibilityWarningCodes(SpreadsheetPlausibility::unitBaseValue(5 * $median, $median)))->toBe([])
        ->and(plausibilityWarningCodes(SpreadsheetPlausibility::unitBaseValue((5 * $median) + 1, $median)))->toBe([ImportRowWarningCode::UnitBaseValueFarFromPeers->value])
        ->and(SpreadsheetPlausibility::unitBaseValue(100 * $median, $median)->isImpossible())->toBeFalse()
        ->and(SpreadsheetPlausibility::unitBaseValue((100 * $median) + 1, $median)->error)->toContain('mediana das unidades do empreendimento');
});

it('evaluates nothing without a reference', function () {
    expect(SpreadsheetPlausibility::installment(999_999_999, 999_999_999, null))->toEqual(PlausibilityVerdict::plausible())
        ->and(SpreadsheetPlausibility::sale(999_999_999, null, '2026-03-10'))->toEqual(PlausibilityVerdict::plausible())
        ->and(SpreadsheetPlausibility::unitValue(999_999_999, null))->toEqual(PlausibilityVerdict::plausible())
        ->and(SpreadsheetPlausibility::median([100, 200, 300, 400]))->toBeNull()
        ->and(SpreadsheetPlausibility::unitBaseValue(999_999_999, SpreadsheetPlausibility::median([100, 200, 300, 400])))->toEqual(PlausibilityVerdict::plausible())
        ->and(SpreadsheetPlausibility::median([500, 100, 300, 200, 400]))->toBe(300);
});

/**
 * A referência da escala da venda é escolhida num lugar só, para a derivação e
 * para a importação: a tabela da data da venda e, sem ela -- ausente ou zero --,
 * a da data da posição.
 */
it('picks the table of the sale day and, without it or with zero, the table of the position', function () {
    $atSale = ResolvedUnitValue::fromHistory(1, CarbonImmutable::parse('2026-02-15'), 40_000_000, CarbonImmutable::parse('2026-01-01'));
    $zeroAtSale = ResolvedUnitValue::fromHistory(1, CarbonImmutable::parse('2026-02-15'), 0, CarbonImmutable::parse('2026-01-01'));
    $absentAtSale = ResolvedUnitValue::absent(1, CarbonImmutable::parse('2026-02-15'));
    $atPosition = ResolvedUnitValue::fromHistory(1, CarbonImmutable::parse('2026-08-31'), 48_000_000, CarbonImmutable::parse('2026-06-01'));

    expect(SalesBoardPlausibility::saleScaleReference($atSale, $atPosition))->toBe($atSale)
        ->and(SalesBoardPlausibility::saleScaleReference($zeroAtSale, $atPosition))->toBe($atPosition)
        ->and(SalesBoardPlausibility::saleScaleReference($absentAtSale, $atPosition))->toBe($atPosition)
        ->and(SalesBoardPlausibility::saleScaleReference(null, null))->toBeNull()
        ->and(SalesBoardPlausibility::saleScaleReference($absentAtSale, ResolvedUnitValue::absent(1, CarbonImmutable::parse('2026-08-31'))))->toBeNull();
});

it('errs on a sale with no table on its day at ten times the table of a position, without the warning at twice', function () {
    $absentAtSale = ResolvedUnitValue::absent(1, CarbonImmutable::parse('2026-02-15'));
    $positions = [
        '2026-08-31' => ResolvedUnitValue::fromHistory(1, CarbonImmutable::parse('2026-08-31'), 48_000_000, CarbonImmutable::parse('2026-06-01')),
        '2026-09-20' => ResolvedUnitValue::fromHistory(1, CarbonImmutable::parse('2026-09-20'), 48_000_000, CarbonImmutable::parse('2026-06-01')),
    ];

    expect(SpreadsheetPlausibility::saleAgainstReference(480_000_000, '2026-02-15', $absentAtSale, $positions)->error)
        ->toBe('Valor da venda (R$ 4.800.000,00) é cerca de 10 vezes o valor de tabela da unidade em 31/08/2026 (R$ 480.000,00), a referência do Quadro de Vendas quando não há tabela na data da venda: um dos dois foi lido errado. Confira a planilha e o valor da unidade.')
        ->and(SpreadsheetPlausibility::saleAgainstReference(479_999_999, '2026-02-15', $absentAtSale, $positions))->toEqual(PlausibilityVerdict::plausible())
        ->and(SpreadsheetPlausibility::saleAgainstReference(96_000_000, '2026-02-15', $absentAtSale, $positions))->toEqual(PlausibilityVerdict::plausible())
        // Sem as datas da posição -- o distrato, que não ocupa a unidade --, nada é medido.
        ->and(SpreadsheetPlausibility::saleAgainstReference(480_000_000, '2026-02-15', $absentAtSale, []))->toEqual(PlausibilityVerdict::plausible());
});

it('errs on a first unit value ten times the sale or a tenth of it, and warns at twice', function () {
    $sale = 60_000_000;

    expect(SpreadsheetPlausibility::unitValueAgainstSale(intdiv($sale, 10), $sale, 'ALFA-101', '2026-01-10')->error)
        ->toBe('Valor atualizado (R$ 60.000,00) é cerca de 1/10 do valor da venda do contrato ALFA-101 (R$ 600.000,00, vendido em 10/01/2026): um dos dois foi lido errado. A unidade não tem valor cadastrado nesta data, e este valor passaria a ser a referência da venda no Quadro de Vendas. Confira a planilha e o contrato.')
        ->and(SpreadsheetPlausibility::unitValueAgainstSale(intdiv($sale, 10) + 1, $sale, 'ALFA-101', '2026-01-10')->isImpossible())->toBeFalse()
        ->and(SpreadsheetPlausibility::unitValueAgainstSale(10 * $sale, $sale, 'ALFA-101', '2026-01-10')->isImpossible())->toBeTrue()
        ->and(plausibilityWarningCodes(SpreadsheetPlausibility::unitValueAgainstSale(2 * $sale, $sale, 'ALFA-101', '2026-01-10')))->toBe([ImportRowWarningCode::SaleValueOffTable->value])
        ->and(plausibilityWarningCodes(SpreadsheetPlausibility::unitValueAgainstSale((2 * $sale) - 1, $sale, 'ALFA-101', '2026-01-10')))->toBe([]);
});

it('errs on a unit value that becomes the position table of a sale with no table on its day, without the warning at twice', function () {
    $sale = 60_000_000;

    expect(SpreadsheetPlausibility::unitValueAgainstSaleAtPosition(intdiv($sale, 10), $sale, 'ALFA-101', '2026-01-10', '2026-09-30')->error)
        ->toBe('Valor atualizado (R$ 60.000,00) é cerca de 1/10 do valor da venda do contrato ALFA-101 (R$ 600.000,00, vendido em 10/01/2026): um dos dois foi lido errado. A venda não tem valor de tabela na data dela, e este valor passaria a ser a referência dela no Quadro de Vendas, como valor da unidade em 30/09/2026. Confira a planilha e o contrato.')
        ->and(SpreadsheetPlausibility::unitValueAgainstSaleAtPosition(intdiv($sale, 10) + 1, $sale, 'ALFA-101', '2026-01-10', '2026-09-30'))->toEqual(PlausibilityVerdict::plausible())
        ->and(SpreadsheetPlausibility::unitValueAgainstSaleAtPosition(10 * $sale, $sale, 'ALFA-101', null, '2026-09-30')->isImpossible())->toBeTrue()
        // O ágio duvidoso da derivação só olha a tabela da data da venda.
        ->and(SpreadsheetPlausibility::unitValueAgainstSaleAtPosition(2 * $sale, $sale, 'ALFA-101', '2026-01-10', '2026-09-30'))->toEqual(PlausibilityVerdict::plausible());
});
