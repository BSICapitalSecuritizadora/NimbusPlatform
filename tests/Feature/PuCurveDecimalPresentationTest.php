<?php

use App\Domain\PuCalculator\DTOs\PuDailyCurveRowData;
use App\Domain\PuCalculator\DTOs\PuSimulationInput;
use App\Domain\PuCalculator\Enums\PuSimulationState;
use App\Domain\PuCalculator\Services\DecimalRounder;
use App\Domain\PuCalculator\Services\PuSimulationService;
use App\Support\PuCalculator\PuDecimalPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Pu\PuSimulationFixture;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Http::preventStrayRequests();
});

function presenter(): PuDecimalPresenter
{
    return app(PuDecimalPresenter::class);
}

// ---------------------------------------------------------------------------
// Formatação pt-BR com 8 casas
// ---------------------------------------------------------------------------

it('formats monetary values with eight decimals in brazilian notation', function (
    ?string $engineValue,
    ?string $expected,
) {
    expect(presenter()->money($engineValue))->toBe($expected);
})->with([
    'PU com milhar' => ['1013.0913710000000000', '1.013,09137100'],
    'PU de cupom' => ['1016.8859620000000000', '1.016,88596200'],
    'juros sem milhar' => ['0.7566840000000000', '0,75668400'],
    'principal redondo' => ['1000.0000000000000000', '1.000,00000000'],
    // Zero é exibido explicitamente, nunca como travessão.
    'amortização zero' => ['0.0000000000000000', '0,00000000'],
    'milhão' => ['1234567.8912345600000000', '1.234.567,89123456'],
    'valor ausente' => [null, null],
    'string vazia' => ['', null],
]);

it('uses a comma for decimals and a dot for thousands', function () {
    $formatted = presenter()->money('1013.0913710000000000');

    expect($formatted)->toBe('1.013,09137100')
        ->and(substr_count($formatted, ','))->toBe(1)
        ->and(substr_count($formatted, '.'))->toBe(1)
        // A vírgula separa exatamente 8 casas decimais.
        ->and(strlen(substr($formatted, (int) strpos($formatted, ',') + 1)))->toBe(8);
});

it('rounds for display with the same half-up rule the engine uses', function () {
    // Meio para cima, afastando-se de zero -- idêntico a DecimalRounder::round().
    expect(presenter()->money('0.000000005'))->toBe('0,00000001')
        ->and(presenter()->money('0.000000004'))->toBe('0,00000000')
        ->and(presenter()->money('-0.000000005'))->toBe('-0,00000001')
        // Um valor que arredonda para zero nunca vira "-0,00000000".
        ->and(presenter()->money('-0.000000001'))->toBe('0,00000000');
});

it('formats the index rate with the same eight decimals', function () {
    expect(presenter()->rate('14.40000000'))->toBe('14,40000000')
        ->and(presenter()->rate('14.90000000'))->toBe('14,90000000')
        ->and(presenter()->rate(null))->toBeNull();
});

it('never truncates technical factors, which stay at full engine precision', function () {
    // O fator é auditado na 8ª e na 9ª casa: esconder precisão aqui inviabilizaria
    // a comparação com a referência externa.
    expect(presenter()->factor('1.0015659890000000'))->toBe('1,0015659890000000')
        ->and(presenter()->factor('1.0005513100000000'))->toBe('1,0005513100000000')
        ->and(presenter()->factor(null))->toBeNull();
});

it('formats without ever going through a float', function () {
    // 1e17 não é representável exatamente em ponto flutuante de dupla precisão;
    // number_format() perderia o último dígito. A formatação por string não.
    expect(presenter()->money('123456789012345678.1234567800000000'))
        ->toBe('123.456.789.012.345.678,12345678');
});

// ---------------------------------------------------------------------------
// A apresentação não toca a engine
// ---------------------------------------------------------------------------

it('leaves the engine values untouched at full internal scale', function () {
    $emission = PuSimulationFixture::contractualEmission();
    PuSimulationFixture::seedRequiredRates(
        PuSimulationFixture::integralizationDate(),
        CarbonImmutable::parse('2026-06-30')->startOfDay(),
    );

    $result = app(PuSimulationService::class)->simulate($emission, new PuSimulationInput(
        firstIntegralizationDate: PuSimulationFixture::integralizationDate(),
        simulationEndDate: CarbonImmutable::parse('2026-06-30')->startOfDay(),
    ));

    expect($result->state)->toBe(PuSimulationState::Calculated);

    foreach ($result->rows as $row) {
        // A engine continua entregando 16 casas; a tela é que mostra 8.
        expect($row->updatedUnitValue)->toMatch('/^-?\d+\.\d{16}$/')
            ->and($row->interestRealUnitValue)->toMatch('/^-?\d+\.\d{16}$/')
            ->and($row->residualUnitValue)->toMatch('/^-?\d+\.\d{16}$/')
            ->and($row->factorDiAccumulated)->toMatch('/^-?\d+\.\d{16}$/')
            ->and($row->factorSpreadDi)->toMatch('/^-?\d+\.\d{16}$/');

        // E a formatação é função pura do valor da engine: reconstruir a partir
        // do arredondamento a 8 casas devolve exatamente o que a tela mostra.
        expect(presenter()->money($row->updatedUnitValue))->toBe(
            presenter()->money(app(DecimalRounder::class)->round($row->updatedUnitValue, 8)),
        );
    }
});

it('presents the real Alto Bellevue curve rows in the expected brazilian shape', function () {
    $emission = PuSimulationFixture::contractualEmission();
    PuSimulationFixture::seedRequiredRates(
        PuSimulationFixture::integralizationDate(),
        CarbonImmutable::parse('2026-06-30')->startOfDay(),
    );

    $result = app(PuSimulationService::class)->simulate($emission, new PuSimulationInput(
        firstIntegralizationDate: PuSimulationFixture::integralizationDate(),
        simulationEndDate: CarbonImmutable::parse('2026-06-30')->startOfDay(),
    ));
    $coupon = collect($result->rows)
        ->first(fn (PuDailyCurveRowData $row): bool => in_array(
            'interest_payment',
            $row->calculationMemory['event_types'] ?? [],
            true,
        ));

    expect($coupon)->not->toBeNull();

    $pattern = '/^\d{1,3}(\.\d{3})*,\d{8}$/';

    expect(presenter()->money($coupon->updatedUnitValue))->toMatch($pattern)
        ->and(presenter()->money($coupon->interestRealUnitValue))->toMatch($pattern)
        ->and(presenter()->money($coupon->paymentTotalUnitValue))->toMatch($pattern)
        ->and(presenter()->money($coupon->residualUnitValue))->toBe('1.000,00000000')
        // Amortização zero aparece explícita, não como travessão.
        ->and(presenter()->money($coupon->amortizationUnitValue))->toBe('0,00000000');
});

// ---------------------------------------------------------------------------
// Auditoria de precisão: por que a 7ª e a 8ª casa do PU são sempre zero
// ---------------------------------------------------------------------------

it('quantises every PU at the sixth decimal because the interest factor is rounded to nine', function () {
    $emission = PuSimulationFixture::contractualEmission();
    PuSimulationFixture::seedRequiredRates(
        PuSimulationFixture::integralizationDate(),
        CarbonImmutable::parse('2026-06-30')->startOfDay(),
    );

    $result = app(PuSimulationService::class)->simulate($emission, new PuSimulationInput(
        firstIntegralizationDate: PuSimulationFixture::integralizationDate(),
        simulationEndDate: CarbonImmutable::parse('2026-06-30')->startOfDay(),
    ));

    expect($result->state)->toBe(PuSimulationState::Calculated);

    foreach ($result->rows as $row) {
        // O Fator de Juros é arredondado em 9 casas e o VNU é exatamente 1.000,
        // então juros e PU são sempre múltiplos exatos de 1e-6. Consequência
        // direta: a 7ª e a 8ª casa decimal do PU são estruturalmente zero, e
        // nenhuma referência externa que carregue o fator além da 9ª casa pode
        // coincidir com o Nimbus nessas duas casas.
        expect($row->calculationMemory['precision_rules']['combined_interest_factor'])->toBe(9)
            ->and($row->unitBaseValue)->toBe('1000.0000000000000000')
            ->and(substr($row->updatedUnitValue, -10))->toBe('0000000000')
            ->and(substr($row->interestRealUnitValue, -10))->toBe('0000000000');
    }
});

it('states the rounding rule of every stage in the calculation memory', function () {
    $emission = PuSimulationFixture::contractualEmission();
    PuSimulationFixture::seedRequiredRates(
        PuSimulationFixture::integralizationDate(),
        CarbonImmutable::parse('2026-06-30')->startOfDay(),
    );

    $result = app(PuSimulationService::class)->simulate($emission, new PuSimulationInput(
        firstIntegralizationDate: PuSimulationFixture::integralizationDate(),
        simulationEndDate: CarbonImmutable::parse('2026-06-30')->startOfDay(),
    ));
    $rules = $result->rows[0]->calculationMemory['precision_rules'];

    // BusinessDayLagExact: as quatro etapas com casas contratuais explícitas.
    expect($rules['rounding_mode'])->toBe('half_up_away_from_zero')
        ->and($rules['daily_index_factor'])->toBe(8)
        ->and($rules['index_factor_for_combination'])->toBe(8)
        ->and($rules['spread_factor'])->toBe(9)
        ->and($rules['combined_interest_factor'])->toBe(9)
        // O produtório acumulado NÃO sofre truncamento progressivo: segue na
        // escala de cálculo até o arredondamento final de 8 casas.
        ->and($rules['accumulated_index_factor'])->toBe(DecimalRounder::CALCULATION_SCALE)
        ->and($rules['interest_unit_value'])->toBe(DecimalRounder::CALCULATION_SCALE);
});
