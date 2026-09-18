<?php

use App\Domain\PuCalculator\DTOs\PuSimulationInput;
use App\Domain\PuCalculator\Enums\PuCalculationProfile;
use App\Domain\PuCalculator\Enums\PuSimulationState;
use App\Domain\PuCalculator\Factories\PuCalculatorFactory;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Services\CdiFactorCompositionService;
use App\Domain\PuCalculator\Services\DecimalRounder;
use App\Domain\PuCalculator\Services\PuPrecisionPolicy;
use App\Domain\PuCalculator\Services\PuSimulationService;
use App\Models\Emission;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Pu\PuLegacyReferenceFixture;
use Tests\Support\Pu\PuSimulationFixture;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Http::preventStrayRequests();
});

// ---------------------------------------------------------------------------
// Prova do PIPELINE legado a partir dos números observados na planilha
// ---------------------------------------------------------------------------

/**
 * Esta seção não roda a engine. Ela opera sobre os três fatores que a própria
 * planilha expôs em 31/08/2026 e demonstra, só com BCMath, QUAL sequência de
 * operações produz o número legado e qual produz o número do Nimbus.
 *
 * É a evidência que autoriza o perfil `LegacyCompatibility` a existir: sem ela
 * o perfil seria palpite.
 */

it('derives the contractual interest factor from the observed DI and spread', function () {
    $observed = PuLegacyReferenceFixture::august31FactorBreakdown();
    $rounder = app(DecimalRounder::class);

    // Contratual: Fator DI considerado com 8 casas ANTES de combinar.
    $indexFactorAt8 = $rounder->round($observed['index_factor_accumulated'], 8);
    $product = bcmul($indexFactorAt8, $observed['spread_factor_displayed'], 30);
    $interestFactor = $rounder->round($product, 9);
    $interest = $rounder->truncate(bcmul('1000', bcsub($interestFactor, '1', 30), 30), 8);

    expect($indexFactorAt8)->toBe('1.00779463')
        // O produto contratual fica em 1,011296120732..., que arredonda para
        // 1,011296121 -- e 1.000 x 0,011296121 = 11,29612100, o valor do Nimbus.
        ->and($interestFactor)->toBe('1.011296121')
        ->and($interest)->toBe(PuLegacyReferenceFixture::AUGUST_31_CONTRACTUAL_INTEREST);
});

it('derives the legacy interest from the observed combined factor', function () {
    $observed = PuLegacyReferenceFixture::august31FactorBreakdown();
    $rounder = app(DecimalRounder::class);

    // Legado: o Fator de Juros continua arredondado em 9 casas -- é o que faz os
    // juros da planilha caírem em múltiplo exato de 1e-6.
    $interestFactor = $rounder->round($observed['combined_factor'], 9);
    $interest = $rounder->truncate(bcmul('1000', bcsub($interestFactor, '1', 30), 30), 8);

    expect($interestFactor)->toBe('1.011296122')
        ->and($interest)->toBe($observed['interest'])
        ->and($interest)->toBe('11.29612200')
        // E é exatamente R$ 0,000001 por unidade acima do contratual.
        ->and(bcsub($interest, PuLegacyReferenceFixture::AUGUST_31_CONTRACTUAL_INTEREST, 8))
        ->toBe('0.00000100');
});

it('proves the legacy carries both factors unrounded into the combination', function () {
    $observed = PuLegacyReferenceFixture::august31FactorBreakdown();
    $rounder = app(DecimalRounder::class);

    $withIndexFactorAt8 = bcmul(
        $rounder->round($observed['index_factor_accumulated'], 8),
        $observed['spread_factor_displayed'],
        30,
    );
    $withIndexFactorRaw = bcmul(
        $observed['index_factor_accumulated'],
        $observed['spread_factor_displayed'],
        30,
    );

    // 1. Arredondar o DI em 8 NÃO reproduz o produto observado: sobra ~1,25e-9.
    expect($rounder->round($withIndexFactorAt8, 16))->not->toBe($observed['combined_factor']);

    // 2. Levar o DI inteiro chega MUITO mais perto, mas ainda não bate: sobra
    //    ~2,5e-10 -- e a sobra tem o sinal de um Fator Spread que a planilha
    //    EXIBE em 9 casas e multiplica inteiro.
    $rawGap = $rounder->absoluteDifference($withIndexFactorRaw, $observed['combined_factor'], 16);
    $roundedGap = $rounder->absoluteDifference($withIndexFactorAt8, $observed['combined_factor'], 16);

    expect(bccomp($rawGap, $roundedGap, 16))->toBe(-1)
        // Uma ordem de grandeza separa as duas hipóteses: o Fator DI do legado é
        // carregado inteiro, não arredondado em 8.
        ->and(bccomp($rawGap, '0.000000001', 16))->toBe(-1)
        ->and(bccomp($roundedGap, '0.000000001', 16))->toBe(1);

    // 3. Nas duas variantes o Fator de Juros de 9 casas é o mesmo, então esta
    //    linha sozinha não separa "Spread inteiro" de "Spread em 9". O que separa
    //    é o produto exibido, e é por ele que o perfil foi calibrado.
    expect($rounder->round($withIndexFactorRaw, 9))->toBe('1.011296122')
        ->and($rounder->round($observed['combined_factor'], 9))->toBe('1.011296122');
});

it('cannot reproduce the trailing ...99 artefact without introducing a float', function () {
    $rounder = app(DecimalRounder::class);

    // A planilha mostra 0,76537599 onde o valor decimal exato é 0,765376.
    // Em decimal puro não existe operação contratual que produza esse final:
    // truncar, arredondar e normalizar devolvem todos o mesmo 0,76537600.
    expect($rounder->truncate('0.765376', 8))->toBe('0.76537600')
        ->and($rounder->round('0.765376', 8))->toBe('0.76537600')
        ->and(app(PuPrecisionPolicy::class)->unitValue('0.765376'))
        ->toBe('0.765376000000000000000000');

    // A distância é sempre exatamente uma unidade da 8ª casa.
    expect(bcsub('0.76537600', '0.76537599', 8))
        ->toBe(PuLegacyReferenceFixture::BINARY_REPRESENTATION_ARTEFACT)
        ->and(bcsub('16.88596200', '16.88596199', 8))
        ->toBe(PuLegacyReferenceFixture::BINARY_REPRESENTATION_ARTEFACT)
        ->and(bcsub('17.54936400', '17.54936399', 8))
        ->toBe(PuLegacyReferenceFixture::BINARY_REPRESENTATION_ARTEFACT);
});

// ---------------------------------------------------------------------------
// A engine implementa o pipeline provado acima
// ---------------------------------------------------------------------------

function legacyWindowEnd(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-08-31')->startOfDay();
}

function legacySimulate(PuCalculationProfile $profile): App\Domain\PuCalculator\DTOs\PuSimulationResult
{
    $emission = PuSimulationFixture::contractualEmission();
    PuSimulationFixture::seedRequiredRates(
        PuSimulationFixture::integralizationDate(),
        legacyWindowEnd(),
    );

    return app(PuSimulationService::class)->simulate($emission, new PuSimulationInput(
        firstIntegralizationDate: PuSimulationFixture::integralizationDate(),
        simulationEndDate: legacyWindowEnd(),
        calculationProfile: $profile,
    ));
}

it('feeds the combination with the raw DI and the raw spread under the legacy profile', function () {
    $legacy = legacySimulate(PuCalculationProfile::LegacyCompatibility);
    $rounder = app(DecimalRounder::class);

    expect($legacy->state)->toBe(PuSimulationState::Calculated);

    $seenUnroundedDi = false;
    $seenUnroundedSpread = false;

    foreach ($legacy->rows as $index => $row) {
        $memory = $row->calculationMemory;

        // Fator DI aplicado = o próprio produtório, sem o arredondamento em 8.
        expect($memory['factor_di_applied_raw'])->toBe($memory['factor_di_accumulated_raw']);

        if ($index === 0) {
            continue;
        }

        // Fator Spread aplicado = o bruto, sem o arredondamento em 9.
        expect($memory['factor_spread_raw'])->toBe($memory['factor_spread_unrounded_raw']);

        if ($memory['factor_di_accumulated_raw'] !== $rounder->normalize(
            $rounder->round($memory['factor_di_accumulated_raw'], 8),
            DecimalRounder::CALCULATION_SCALE,
        )) {
            $seenUnroundedDi = true;
        }

        if ($memory['factor_spread_raw'] !== $rounder->normalize(
            $rounder->round($memory['factor_spread_raw'], 9),
            DecimalRounder::CALCULATION_SCALE,
        )) {
            $seenUnroundedSpread = true;
        }

        // O Fator de Juros CONTINUA em 9 casas nos dois perfis.
        expect($memory['interest_factor_applied_raw'])->toBe($rounder->normalize(
            $rounder->round($memory['interest_factor_applied_raw'], 9),
            DecimalRounder::CALCULATION_SCALE,
        ));

        // E a quantização monetária de 8 casas também vale nos dois perfis.
        expect($memory['interest_real_unit_value_raw'])
            ->toBe(app(PuPrecisionPolicy::class)->unitValue($memory['interest_real_unit_value_raw']));
    }

    // Prova de que o teste não passou por vacuidade: houve linha em que o
    // arredondamento contratual REALMENTE teria mudado o fator.
    expect($seenUnroundedDi)->toBeTrue()
        ->and($seenUnroundedSpread)->toBeTrue();
});

it('declares the legacy stages honestly in the precision rules', function () {
    $legacy = legacySimulate(PuCalculationProfile::LegacyCompatibility);
    $contractual = legacySimulate(PuCalculationProfile::Contractual);

    $legacyRules = $legacy->lastRow()?->calculationMemory['precision_rules'];
    $contractualRules = $contractual->lastRow()?->calculationMemory['precision_rules'];

    expect($legacyRules['calculation_profile'])->toBe('legacy_compatibility')
        // "integral" = sem arredondamento intermediário. A memória não pode dizer
        // "8 casas" num estágio que o perfil legado não arredonda.
        ->and($legacyRules['index_factor_for_combination'])->toBeNull()
        ->and($legacyRules['spread_factor'])->toBeNull()
        // O que NÃO muda continua declarado igual nos dois perfis.
        ->and($legacyRules['daily_index_factor'])->toBe(8)
        ->and($legacyRules['accumulated_index_factor'])->toBe(16)
        ->and($legacyRules['accumulated_index_factor_mode'])->toBe('truncate_after_each_multiplication')
        ->and($legacyRules['combined_interest_factor'])->toBe(9)
        ->and($legacyRules['interest_unit_value'])->toBe(8)
        ->and($legacyRules['unit_value_quantization'])->toBe('truncate_toward_zero')
        ->and($contractualRules['calculation_profile'])->toBe('contractual')
        ->and($contractualRules['index_factor_for_combination'])->toBe(8)
        ->and($contractualRules['spread_factor'])->toBe(9);
});

it('refuses the legacy profile on calculators where it was never proven', function () {
    $emission = Emission::factory()->create();

    foreach ([PuIndexer::Prefixed, PuIndexer::Ipca] as $indexer) {
        $calculator = app(PuCalculatorFactory::class)->forIndexer($indexer);
        $thrown = null;

        try {
            $calculator->calculate($emission, null, null, PuCalculationProfile::LegacyCompatibility);
        } catch (Throwable $exception) {
            $thrown = $exception;
        }

        // Recusa explícita, e não silenciosamente calcular no contratual fingindo
        // ter atendido o pedido.
        expect($thrown)->toBeInstanceOf(InvalidArgumentException::class)
            ->and($thrown->getMessage())->toContain('só existe para a engine CDI');
    }
});

// ---------------------------------------------------------------------------
// Paridade numérica contra a planilha
// ---------------------------------------------------------------------------

it('lists every audited date and whether the reference value is available', function () {
    $rows = PuLegacyReferenceFixture::rows();

    // O fixture NUNCA inventa valor. Datas sem leitura da planilha ficam nulas e
    // carregam o motivo -- é isso que impede a paridade de virar um espelho do
    // próprio Nimbus.
    foreach ($rows as $date => $row) {
        expect($date)->toMatch('/^\d{4}-\d{2}-\d{2}$/');

        if ($row['pu'] === null) {
            expect($row['note'])->not->toBeNull();

            continue;
        }

        expect($row['pu'])->toMatch('/^\d+\.\d{8}$/');
    }

    expect(PuLegacyReferenceFixture::datesWithObservedPu())
        ->toBe(['2026-06-08', '2026-06-09', '2026-07-08', '2026-08-10', '2026-08-31'])
        ->and(PuLegacyReferenceFixture::datesWithoutObservedValue())
        ->toBe(['2026-05-18', '2026-05-19', '2026-06-05', '2026-06-10', '2026-07-09', '2026-08-11']);
});

it('reconciles the legacy curve against the spreadsheet line by line', function () {
    expect(PuLegacyReferenceFixture::datesWithObservedPu())->not->toBeEmpty();
})->skip(
    'Paridade numérica pendente de dois insumos que não estão no repositório: '
    .'(1) a série REAL de CDI do período 2026-05 a 2026-08 — o fixture da suíte usa uma taxa '
    .'sintética constante de 14,90%, que não reproduz nenhuma linha da planilha; '
    .'(2) os valores da planilha em 18/05, 19/05, 05/06, 10/06, 09/07 e 11/08. '
    .'A metodologia do legado JÁ está provada nos casos acima a partir dos fatores observados em '
    .'31/08. Para ligar esta paridade: carregue a série real de CDI e complete '
    .'PuLegacyReferenceFixture::rows(), com tolerância de exatamente 1e-8 nas linhas em que a '
    .'planilha exibe o artefato binário (...99).',
);
