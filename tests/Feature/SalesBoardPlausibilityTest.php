<?php

use App\DTOs\SalesBoards\SalesBoardDerivedPosition;
use App\Enums\ContractStatus;
use App\Enums\SalesBoardIssueCode;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\ConstructionUnitExchange;
use App\Models\ConstructionUnitValue;
use App\Models\SalesDiscountPolicy;
use App\Support\Imports\SpreadsheetPlausibility;
use App\Support\SalesBoards\SalesBoardPlausibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SalesBoards\DerivationFixture;

/**
 * A plausibilidade que a derivação confere no que decide a posição, com os
 * limiares de {@see SalesBoardPlausibility}: a uma ordem de grandeza (×10, ÷10)
 * ou com data anterior a 1990, bloqueia; a duas vezes, avisa. Cada caso que
 * bloqueia tem o par que fica de fora, para provar o limiar e não só a regra.
 */
uses(RefreshDatabase::class);

/**
 * @return list<string>
 */
function plausibilityCodes(SalesBoardDerivedPosition $position, SalesBoardIssueCode $code): array
{
    return collect($position->issues)
        ->filter(fn ($issue): bool => $issue->code === $code)
        ->map(fn ($issue): string => (string) $issue->contractCode)
        ->values()
        ->all();
}

/**
 * Um contrato financiado (vendido em janeiro, com parcela em aberto) numa
 * unidade de referência R$ 500.000,00 desde 01/01/2026.
 */
function plausibilityFinancedSale(Construction $construction, string $unit, string $saleValue, string $code, string $saleDate = '2026-01-10', ?string $baseValue = '500000.00'): ConstructionUnit
{
    $constructionUnit = DerivationFixture::unit($construction, $unit, $baseValue);
    $contract = DerivationFixture::contract($constructionUnit, $saleDate, $saleValue);
    $contract->forceFill(['code' => $code])->save();
    DerivationFixture::installment($contract, '001', '2026-12-10', '100000.00');

    return $constructionUnit;
}

it('blocks a sale an order of magnitude away from the unit reference', function (string $case) {
    $construction = DerivationFixture::activeConstruction();

    match ($case) {
        'venda ×1000' => plausibilityFinancedSale($construction, '101', '600000000.00', 'CASO'),
        'venda ÷1000 (texto americano lido errado)' => plausibilityFinancedSale($construction, '101', '600.00', 'CASO'),
        'um zero a mais' => plausibilityFinancedSale($construction, '101', '5000000.00', 'CASO'),
        'referência placeholder de R$ 1,00' => plausibilityFinancedSale($construction, '101', '600000.00', 'CASO', baseValue: '1.00'),
        'referência só na data da posição' => (function () use ($construction): void {
            $unit = plausibilityFinancedSale($construction, '101', '600000.00', 'CASO', baseValue: null);
            ConstructionUnitValue::factory()->forUnit($unit)->effectiveFrom('2026-06-01')->worth('50000.00')->create();
        })(),
    };

    $position = DerivationFixture::derive($construction);
    $issue = collect($position->issues)->firstWhere('code', SalesBoardIssueCode::SaleValueOutOfScale);

    expect(plausibilityCodes($position, SalesBoardIssueCode::SaleValueOutOfScale))->toBe(['CASO'])
        ->and($issue->isBlocker())->toBeTrue()
        ->and($issue->message)->toContain('um dos dois foi lido ou digitado errado')
        ->and($position->isComplete())->toBeFalse();
})->with([
    'venda ×1000',
    'venda ÷1000 (texto americano lido errado)',
    'um zero a mais',
    'referência placeholder de R$ 1,00',
    'referência só na data da posição',
]);

it('describes the scale of a sale a thousand times the reference', function () {
    $construction = DerivationFixture::activeConstruction();
    plausibilityFinancedSale($construction, '101', '500000000.00', 'X1000');

    $issue = collect(DerivationFixture::derive($construction)->issues)->firstWhere('code', SalesBoardIssueCode::SaleValueOutOfScale);

    expect($issue->message)->toBe('O valor da venda do contrato X1000 (R$ 500.000.000,00) é cerca de 1.000 vezes o valor de referência da unidade em 10/01/2026 (R$ 500.000,00): um dos dois foi lido ou digitado errado.');
});

it('does not block a sale just inside the scale', function () {
    $construction = DerivationFixture::activeConstruction();
    plausibilityFinancedSale($construction, '101', '4999999.99', 'QUASE10');
    plausibilityFinancedSale($construction, '102', '50000.01', 'QUASE01');

    // O par: dez vezes e um décimo, exatos, já estão fora da escala.
    plausibilityFinancedSale($construction, '103', '5000000.00', 'DEZ');
    plausibilityFinancedSale($construction, '104', '50000.00', 'DECIMO');

    expect(plausibilityCodes(DerivationFixture::derive($construction), SalesBoardIssueCode::SaleValueOutOfScale))
        ->toBe(['DEZ', 'DECIMO']);
});

it('agrees with the import on the scale boundary: exactly ten times errs there and blocks here', function () {
    $construction = DerivationFixture::activeConstruction();
    plausibilityFinancedSale($construction, '101', '5000000.00', 'DEZ');

    expect(SpreadsheetPlausibility::sale(500_000_000, 50_000_000, '2026-01-10')->isImpossible())->toBeTrue()
        ->and(plausibilityCodes(DerivationFixture::derive($construction), SalesBoardIssueCode::SaleValueOutOfScale))->toBe(['DEZ'])
        ->and(SpreadsheetPlausibility::sale(499_999_999, 50_000_000, '2026-01-10')->isImpossible())->toBeFalse();
});

it('warns about a sale of the competência at twice its reference or more, and not about an old sale', function () {
    $construction = DerivationFixture::activeConstruction();

    SalesDiscountPolicy::factory()
        ->forConstruction($construction)
        ->effectiveFrom('2020-01-01')
        ->closedPeriod()
        ->allowing('10.00')
        ->create();

    plausibilityFinancedSale($construction, '101', '1000000.00', 'DOBRO', '2026-07-10');
    plausibilityFinancedSale($construction, '102', '999999.99', 'QUASE', '2026-07-12');
    plausibilityFinancedSale($construction, '103', '1250000.00', 'ANTIGA');

    $position = DerivationFixture::derive($construction);
    $warning = collect($position->issues)->firstWhere('code', SalesBoardIssueCode::SaleValueAtypical);

    expect(plausibilityCodes($position, SalesBoardIssueCode::SaleValueAtypical))->toBe(['DOBRO'])
        ->and($warning->isBlocker())->toBeFalse()
        ->and($warning->message)->toContain('cerca de 2 vezes o valor de referência da unidade na data da venda (R$ 500.000,00)')
        ->and($position->hasBlockingIssue())->toBeFalse();
});

it('blocks installments an order of magnitude above the sale value', function () {
    $construction = DerivationFixture::activeConstruction();

    $expected = DerivationFixture::contract(DerivationFixture::unit($construction, '101'), '2026-01-10', '600000.00');
    $expected->forceFill(['code' => 'PREVISTO'])->save();
    DerivationFixture::installment($expected, '001', '2026-12-10', '600000000.00');

    $paid = DerivationFixture::contract(DerivationFixture::unit($construction, '102'), '2026-01-10', '600000.00');
    $paid->forceFill(['code' => 'PAGO'])->save();
    DerivationFixture::installment($paid, '001', '2026-03-10', '600000.00', '2026-03-10', '600000000.00');
    DerivationFixture::installment($paid, '002', '2026-12-10', '1000.00');

    $position = DerivationFixture::derive($construction);
    $issues = collect($position->issues)->where('code', SalesBoardIssueCode::InstallmentValueOutOfScale);

    expect(plausibilityCodes($position, SalesBoardIssueCode::InstallmentValueOutOfScale))->toBe(['PREVISTO', 'PAGO'])
        ->and($issues->first()->message)->toBe('A parcela 001 do contrato PREVISTO tem valor previsto de R$ 600.000.000,00, cerca de 1.000 vezes o valor da venda (R$ 600.000,00): confira a leitura do valor.')
        ->and($issues->every(fn ($issue): bool => $issue->isBlocker()))->toBeTrue()
        ->and(plausibilityCodes($position, SalesBoardIssueCode::InstallmentValueAtypical))->toBe([]);
});

it('warns about an installment at twice the sale value or paid a hundred times its expected value', function () {
    $construction = DerivationFixture::activeConstruction();

    $twice = DerivationFixture::contract(DerivationFixture::unit($construction, '101'), '2026-01-10', '600000.00');
    $twice->forceFill(['code' => 'DOBRO'])->save();
    DerivationFixture::installment($twice, '001', '2026-12-10', '1200000.00');

    // "553,919" lido como milhar: R$ 553.919,00 pagos sobre R$ 600,00 previstos.
    $misread = DerivationFixture::contract(DerivationFixture::unit($construction, '102'), '2026-01-10', '600000.00');
    $misread->forceFill(['code' => 'MILHAR'])->save();
    DerivationFixture::installment($misread, '001', '2026-03-10', '600.00', '2026-03-10', '553919.00');
    DerivationFixture::installment($misread, '002', '2026-12-10', '599400.00');

    // O par: a parcela única pouco acima da venda (quitação corrigida) é legítima.
    $single = DerivationFixture::contract(DerivationFixture::unit($construction, '103'), '2026-01-10', '470000.00');
    $single->forceFill(['code' => 'UNICA'])->save();
    DerivationFixture::installment($single, '001', '2026-12-10', '480000.00');

    $position = DerivationFixture::derive($construction);
    $warnings = collect($position->issues)->where('code', SalesBoardIssueCode::InstallmentValueAtypical)->keyBy('contractCode');

    expect($warnings->keys()->all())->toBe(['DOBRO', 'MILHAR'])
        ->and($warnings['DOBRO']->message)->toContain('cerca de 2 vezes o valor da venda')
        ->and($warnings['MILHAR']->message)->toContain('A parcela 001 do contrato MILHAR foi paga com cem vezes o valor previsto ou mais')
        ->and($position->hasBlockingIssue())->toBeFalse();
});

/**
 * Um empreendimento com a data do caso no ano dado: o ano implausível, ou o
 * piso exato de 01/01/1990.
 */
function plausibilityDateScenario(string $case, bool $implausible): Construction
{
    $construction = DerivationFixture::activeConstruction();
    $day = fn (string $bad): string => $implausible ? $bad : '1990-01-01';

    match ($case) {
        'venda' => DerivationFixture::installment(
            DerivationFixture::contract(DerivationFixture::unit($construction, '101'), $day('0026-03-10')),
            '001',
            '2026-12-10',
            '600000.00',
        ),
        'distrato' => DerivationFixture::contract(DerivationFixture::unit($construction, '101'), '1990-01-01', cancellationDate: $day('0026-03-10'), status: ContractStatus::Cancelled),
        'pagamento' => (function () use ($construction, $day): void {
            $contract = DerivationFixture::contract(DerivationFixture::unit($construction, '101'), '2026-01-10');
            DerivationFixture::installment($contract, '001', '2026-02-10', '1000.00', $day('0026-02-10'), '1000.00');
            DerivationFixture::installment($contract, '002', '2026-12-10', '599000.00');
        })(),
        'cancelamento de parcela' => (function () use ($construction, $day): void {
            $contract = DerivationFixture::contract(DerivationFixture::unit($construction, '101'), '2026-01-10');
            DerivationFixture::installment($contract, '001', '2026-12-10', '600000.00');
            DerivationFixture::installment($contract, '002', '2026-12-11', '1000.00', cancellationDate: $day('0202-03-10'));
        })(),
        'histórico de valor' => ConstructionUnitValue::factory()
            ->forUnit(DerivationFixture::unit($construction, '101', null))
            ->effectiveFrom($day('1970-01-01'))
            ->worth('520000.00')
            ->create(),
        'referência do valor base' => DerivationFixture::unit($construction, '101', '520000.00', $day('1970-01-01')),
        'permuta' => ConstructionUnitExchange::factory()
            ->forUnit(DerivationFixture::unit($construction, '101'))
            ->effectiveFrom($day('1970-01-01'))
            ->worth('450000.00')
            ->create(),
    };

    return $construction;
}

it('blocks dates before 1990 in the facts that decide the position', function (string $case) {
    $blocked = DerivationFixture::derive(plausibilityDateScenario($case, implausible: true));
    $issue = collect($blocked->issues)->firstWhere('code', SalesBoardIssueCode::SourceDateBefore1990);

    expect($issue)->not->toBeNull()
        ->and($issue->isBlocker())->toBeTrue()
        ->and($issue->message)->toContain('anterior a 1990')
        ->and($blocked->isComplete())->toBeFalse();

    // O par: o piso exato, 01/01/1990, não bloqueia.
    expect(DerivationFixture::issueCodes(DerivationFixture::derive(plausibilityDateScenario($case, implausible: false))))
        ->not->toContain(SalesBoardIssueCode::SourceDateBefore1990->value);
})->with([
    'venda',
    'distrato',
    'pagamento',
    'cancelamento de parcela',
    'histórico de valor',
    'referência do valor base',
    'permuta',
]);

it('ignores the due date of an installment when looking for implausible dates', function () {
    $construction = DerivationFixture::activeConstruction();
    $contract = DerivationFixture::contract(DerivationFixture::unit($construction, '101'), '2026-01-10');
    DerivationFixture::installment($contract, '001', '0026-12-10', '600000.00');

    expect(DerivationFixture::issueCodes(DerivationFixture::derive($construction)))
        ->not->toContain(SalesBoardIssueCode::SourceDateBefore1990->value);
});
