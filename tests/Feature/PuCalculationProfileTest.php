<?php

use App\Domain\PuCalculator\DTOs\PuCurveGenerationResult;
use App\Domain\PuCalculator\DTOs\PuDailyCurveRowData;
use App\Domain\PuCalculator\DTOs\PuSimulationInput;
use App\Domain\PuCalculator\DTOs\PuSimulationResult;
use App\Domain\PuCalculator\Enums\PuCalculationProfile;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuSimulationState;
use App\Domain\PuCalculator\Exceptions\PuNonOperationalProfileException;
use App\Domain\PuCalculator\Services\DecimalRounder;
use App\Domain\PuCalculator\Services\PuAuditLogService;
use App\Domain\PuCalculator\Services\PuCurvePersistenceService;
use App\Domain\PuCalculator\Services\PuOperationalProfileGuard;
use App\Domain\PuCalculator\Services\PuSimulationService;
use App\Filament\Resources\Emissions\Pages\PuCalculatorSimulator;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Pu\PuSimulationFixture;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Http::preventStrayRequests();
});

function profileWindowEnd(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-08-31')->startOfDay();
}

function profileSimulate(?PuCalculationProfile $profile): PuSimulationResult
{
    $emission = PuSimulationFixture::contractualEmission();
    PuSimulationFixture::seedRequiredRates(
        PuSimulationFixture::integralizationDate(),
        profileWindowEnd(),
    );

    return app(PuSimulationService::class)->simulate($emission, new PuSimulationInput(
        firstIntegralizationDate: PuSimulationFixture::integralizationDate(),
        simulationEndDate: profileWindowEnd(),
        calculationProfile: $profile,
    ));
}

// ---------------------------------------------------------------------------
// Default: contratual, sempre
// ---------------------------------------------------------------------------

it('defaults to the contractual profile everywhere it can be omitted', function () {
    expect(PuCalculationProfile::default())->toBe(PuCalculationProfile::Contractual)
        // Input sem perfil informado.
        ->and((new PuSimulationInput)->calculationProfile())->toBe(PuCalculationProfile::Contractual)
        ->and((new PuSimulationInput(calculationProfile: null))->calculationProfile())
        ->toBe(PuCalculationProfile::Contractual)
        // OMISSÃO (ausente ou vazia) é contratual: nenhum caminho existente muda de
        // comportamento por não informar perfil.
        ->and(PuCalculationProfile::fromNullable(null))->toBe(PuCalculationProfile::Contractual)
        ->and(PuCalculationProfile::fromNullable(''))->toBe(PuCalculationProfile::Contractual)
        ->and(PuCalculationProfile::fromNullable('   '))->toBe(PuCalculationProfile::Contractual)
        // E o legado é alcançável apenas quando escrito exatamente.
        ->and(PuCalculationProfile::fromNullable('legacy_compatibility'))
        ->toBe(PuCalculationProfile::LegacyCompatibility);
});

it('refuses an unknown profile instead of silently falling back to the contractual', function (string $value) {
    // Um perfil desconhecido não é omissão: é um pedido que a engine não sabe atender.
    // Resolvê-lo como contratual devolveria a regra do Termo a quem pediu outra coisa,
    // com o rótulo de que a escolha foi respeitada.
    expect(fn () => PuCalculationProfile::fromNullable($value))
        ->toThrow(InvalidArgumentException::class, $value);
})->with([
    'nome parcial' => ['legacy'],
    'caixa alta' => ['LEGACY_COMPATIBILITY'],
    'perfil inexistente' => ['spreadsheet_v3'],
    'lixo' => ['foo'],
]);

it('marks only the contractual profile as operational', function () {
    expect(PuCalculationProfile::Contractual->isOperational())->toBeTrue()
        ->and(PuCalculationProfile::Contractual->isContractual())->toBeTrue()
        ->and(PuCalculationProfile::Contractual->warning())->toBeNull()
        ->and(PuCalculationProfile::Contractual->firstCouponWarning())->toBeNull()
        ->and(PuCalculationProfile::Contractual->roundsIndexFactorForCombination())->toBeTrue()
        ->and(PuCalculationProfile::Contractual->appliesFirstCouponPreIntegralizationPremium())->toBeTrue()
        ->and(PuCalculationProfile::LegacyCompatibility->isOperational())->toBeFalse()
        ->and(PuCalculationProfile::LegacyCompatibility->isLegacyCompatibility())->toBeTrue()
        ->and(PuCalculationProfile::LegacyCompatibility->warning())->not->toBeNull()
        ->and(PuCalculationProfile::LegacyCompatibility->firstCouponWarning())->not->toBeNull()
        ->and(PuCalculationProfile::LegacyCompatibility->roundsIndexFactorForCombination())->toBeFalse()
        ->and(PuCalculationProfile::LegacyCompatibility->appliesFirstCouponPreIntegralizationPremium())->toBeFalse();
});

it('stamps the profile of every row in the calculation memory', function () {
    $contractual = profileSimulate(null);

    expect($contractual->state)->toBe(PuSimulationState::Calculated)
        ->and($contractual->calculationProfile())->toBe(PuCalculationProfile::Contractual);

    foreach ($contractual->rows as $row) {
        expect($row->calculationMemory['calculation_profile'])->toBe('contractual')
            ->and($row->calculationMemory['calculation_profile_is_operational'])->toBeTrue()
            ->and($row->calculationMemory['precision_rules']['calculation_profile'])->toBe('contractual');
    }
});

// ---------------------------------------------------------------------------
// Legado: explícito, isolado, sem vazamento
// ---------------------------------------------------------------------------

it('only omits the first coupon premium under the legacy profile', function () {
    $contractual = profileSimulate(PuCalculationProfile::Contractual);
    $legacy = profileSimulate(PuCalculationProfile::LegacyCompatibility);

    $contractualCoupon = $contractual->rowForDate('2026-06-08');
    $legacyCoupon = $legacy->rowForDate('2026-06-08');

    expect($contractualCoupon)->not->toBeNull()
        ->and($legacyCoupon)->not->toBeNull()
        // Contratual: prêmio dos 2 DU aplicado, como o Termo exige.
        ->and($contractualCoupon->calculationMemory['first_coupon_pre_integralization_premium_applied'])->toBeTrue()
        ->and($contractualCoupon->calculationMemory['first_coupon_pre_integralization_premium'])->not->toBeNull()
        // Legado: a planilha de referência não contempla o prêmio.
        ->and($legacyCoupon->calculationMemory['first_coupon_pre_integralization_premium_applied'])->toBeFalse()
        ->and($legacyCoupon->calculationMemory['first_coupon_pre_integralization_premium'])->toBeNull()
        ->and($legacyCoupon->calculationMemory['calculation_profile'])->toBe('legacy_compatibility')
        ->and($legacyCoupon->calculationMemory['calculation_profile_is_operational'])->toBeFalse()
        // E o pagamento do primeiro cupom fica MENOR no legado, exatamente por isso.
        ->and(bccomp($legacyCoupon->interestPaymentUnitValue, $contractualCoupon->interestPaymentUnitValue, 8))
        ->toBe(-1);
});

it('keeps calendar, rates, lag, periods and events identical across profiles', function () {
    $contractual = profileSimulate(PuCalculationProfile::Contractual);
    $legacy = profileSimulate(PuCalculationProfile::LegacyCompatibility);

    expect($legacy->rows)->toHaveCount(count($contractual->rows))
        // Parâmetros resolvidos -- principal, spread, base, lag, calendários, cronograma
        // e datas contratuais -- são os MESMOS: o perfil não é um parâmetro da operação.
        ->and($legacy->parameters)->toBe($contractual->parameters);

    foreach ($contractual->rows as $index => $reference) {
        $row = $legacy->rows[$index];

        // Estrutura: nada que venha de calendário, taxa, prazo ou evento pode divergir.
        // O que o perfil pode mudar é só precisão/composição, e isso é provado adiante,
        // no teste do Fator Spread -- por isso `factorSpread` NÃO entra nesta lista.
        expect($row->date->toDateString())->toBe($reference->date->toDateString())
            ->and($row->isBusinessDay)->toBe($reference->isBusinessDay)
            ->and($row->dupInterest)->toBe($reference->dupInterest)
            ->and($row->dutInterest)->toBe($reference->dutInterest)
            ->and($row->indexRateDate?->toDateString())->toBe($reference->indexRateDate?->toDateString())
            ->and($row->indexRateValue)->toBe($reference->indexRateValue)
            // Fator diário e produtório acumulado são anteriores à combinação: o perfil
            // não toca nenhum dos dois.
            ->and($row->factorDi)->toBe($reference->factorDi)
            ->and($row->factorDiAccumulated)->toBe($reference->factorDiAccumulated)
            ->and($row->quantity)->toBe($reference->quantity)
            ->and($row->eventOriginalDate?->toDateString())->toBe($reference->eventOriginalDate?->toDateString())
            ->and($row->eventEffectiveDate?->toDateString())->toBe($reference->eventEffectiveDate?->toDateString())
            ->and($row->calculationMemory['calendar_code'])->toBe($reference->calculationMemory['calendar_code'])
            ->and($row->calculationMemory['index_rate_lookup_mode'])->toBe($reference->calculationMemory['index_rate_lookup_mode'])
            ->and($row->calculationMemory['event_types'])->toBe($reference->calculationMemory['event_types']);
    }
});

it('carries the raw spread factor in the legacy profile and the rounded one in the contractual', function () {
    $contractual = profileSimulate(PuCalculationProfile::Contractual);
    $legacy = profileSimulate(PuCalculationProfile::LegacyCompatibility);
    $rounder = app(DecimalRounder::class);
    $scale = DecimalRounder::CALCULATION_SCALE;

    $sawNeutralRow = false;
    $sawDivergence = false;

    foreach ($contractual->rows as $index => $reference) {
        $row = $legacy->rows[$index];
        // O ESTÁGIO é auditado na memória, que carrega a escala de CÁLCULO. O campo da
        // linha (`factorSpread`) é o mesmo número na escala de FATOR, e é comparado adiante
        // contra o estágio de onde saiu -- nunca contra um valor de outra escala.
        $raw = $reference->calculationMemory['factor_spread_unrounded_raw'];

        // A linha da integralização não tem Fator Spread a auditar.
        if ($raw === null) {
            continue;
        }

        $contractualApplied = $reference->calculationMemory['factor_spread_raw'];
        $legacyApplied = $row->calculationMemory['factor_spread_raw'];

        // O BRUTO é o mesmo nos dois perfis: o que muda é o que cada um leva à combinação.
        expect($row->calculationMemory['factor_spread_unrounded_raw'])->toBe($raw);

        if (bccomp($raw, '1', $scale) === 0) {
            // Linha sem accrual de spread: dia não útil logo depois da integralização ou de
            // um reset, em que o produtório ainda não começou. O fator é exatamente 1 nos dois
            // perfis, e aqui igualdade de STRING não prova nada financeiro --
            // `1,0000000000000000` e `1,000000000000000000000000` são o mesmo número em escalas
            // textuais diferentes. A prova correta é a comparação decimal.
            expect(bccomp($contractualApplied, '1', $scale))->toBe(0)
                ->and(bccomp($legacyApplied, '1', $scale))->toBe(0)
                ->and(bccomp($reference->factorSpread, '1', DecimalRounder::FACTOR_SCALE))->toBe(0)
                ->and(bccomp($row->factorSpread, '1', DecimalRounder::FACTOR_SCALE))->toBe(0);

            $sawNeutralRow = true;

            continue;
        }

        // Linha COM accrual: é onde a regra de cada perfil aparece.
        expect($contractualApplied)
            // Contratual: o que entra na combinação é o bruto ARREDONDADO em 9 casas.
            ->toBe($rounder->normalize($rounder->round($raw, 9), $scale))
            // Legado: o que entra na combinação é o PRÓPRIO bruto, sem quantização intermediária.
            ->and($legacyApplied)->toBe($raw)
            // E o campo da linha é esse mesmo estágio na escala de fator, não outro número.
            ->and($reference->factorSpread)
            ->toBe($rounder->round($contractualApplied, DecimalRounder::FACTOR_SCALE))
            ->and($row->factorSpread)
            ->toBe($rounder->round($legacyApplied, DecimalRounder::FACTOR_SCALE));

        if (bccomp($contractualApplied, $legacyApplied, $scale) !== 0) {
            $sawDivergence = true;
        }
    }

    // Anti-vacuidade, nas duas pontas: o teste passou por linha neutra E por linha em que o
    // arredondamento contratual REALMENTE mudou o fator -- a divergência que define o perfil.
    expect($sawNeutralRow)->toBeTrue()
        ->and($sawDivergence)->toBeTrue();
});

it('does not leak the profile from one simulation into the next', function () {
    $legacy = profileSimulate(PuCalculationProfile::LegacyCompatibility);
    $afterLegacy = profileSimulate(null);

    expect($legacy->calculationProfile())->toBe(PuCalculationProfile::LegacyCompatibility)
        ->and($afterLegacy->calculationProfile())->toBe(PuCalculationProfile::Contractual)
        ->and($afterLegacy->rowForDate('2026-06-08')?->calculationMemory['first_coupon_pre_integralization_premium_applied'])
        ->toBeTrue()
        ->and($afterLegacy->rowForDate('2026-06-08')?->calculationMemory['calculation_profile'])
        ->toBe('contractual');
});

it('reports the contractual reference alongside the legacy curve instead of replacing it', function () {
    $legacy = profileSimulate(PuCalculationProfile::LegacyCompatibility);
    $contractual = profileSimulate(PuCalculationProfile::Contractual);

    expect($contractual->profileComparison)->toBe([])
        ->and($legacy->profileComparison)->not->toBe([]);

    $comparison = $legacy->profileComparison['2026-06-08'] ?? null;

    expect($comparison)->not->toBeNull()
        // A coluna contratual é a curva contratual de verdade, não uma cópia da legada.
        ->and($comparison['payment_contractual'])
        ->toBe($contractual->rowForDate('2026-06-08')?->paymentTotalUnitValue)
        ->and($comparison['payment_profile'])
        ->toBe($legacy->rowForDate('2026-06-08')?->paymentTotalUnitValue)
        ->and($comparison['payment_delta'])->toBe(bcsub(
            $comparison['payment_profile'],
            $comparison['payment_contractual'],
            16,
        ))
        ->and($comparison['first_coupon_premium_contractual'])->toBe('sim')
        ->and($comparison['first_coupon_premium_profile'])->toBe('não');
});

it('refuses the legacy profile for indexers where it was never proven', function () {
    $emission = PuSimulationFixture::contractualEmission();
    PuSimulationFixture::seedRequiredRates(
        PuSimulationFixture::integralizationDate(),
        profileWindowEnd(),
    );

    $result = app(PuSimulationService::class)->simulate($emission, new PuSimulationInput(
        firstIntegralizationDate: PuSimulationFixture::integralizationDate(),
        simulationEndDate: profileWindowEnd(),
        overrides: ['indexer' => PuIndexer::Prefixed->value, 'annual_rate' => '10.0'],
        calculationProfile: PuCalculationProfile::LegacyCompatibility,
    ));

    expect($result->state)->toBe(PuSimulationState::MissingInput)
        ->and($result->reason)->toContain('engine CDI');
});

// ---------------------------------------------------------------------------
// Zero efeito operacional
// ---------------------------------------------------------------------------

it('writes nothing operational in any profile', function (?PuCalculationProfile $profile) {
    // ARRANGE COMPLETO primeiro: emissão, baseline, calendário confirmado, taxas e input.
    // O helper `profileSimulate()` cria a emissão junto com o cálculo, e usá-lo aqui
    // colocava um `Emission::factory()->create()` DEPOIS do snapshot -- o teste media o
    // próprio setup como se fosse efeito colateral da simulação.
    $emission = PuSimulationFixture::contractualEmission();
    PuSimulationFixture::seedRequiredRates(
        PuSimulationFixture::integralizationDate(),
        profileWindowEnd(),
    );
    $input = new PuSimulationInput(
        firstIntegralizationDate: PuSimulationFixture::integralizationDate(),
        simulationEndDate: profileWindowEnd(),
        calculationProfile: $profile,
    );

    // A partir daqui o snapshot mede EXCLUSIVAMENTE o act.
    $before = PuSimulationFixture::counts();
    $result = app(PuSimulationService::class)->simulate($emission, $input);
    $after = PuSimulationFixture::counts();

    expect($result->state)->toBe(PuSimulationState::Calculated)
        // Emissão, parâmetro, evento, curva, versão (inclusive candidate), promoção,
        // validação externa e histórico: NADA é criado, atualizado ou apagado por simular.
        ->and($after)->toBe($before)
        ->and($after['emissions'])->toBe($before['emissions'])
        ->and($after['parameters'])->toBe(0)
        ->and($after['events'])->toBe(0)
        ->and($after['curve_versions'])->toBe(0)
        ->and($after['daily_curves'])->toBe(0)
        ->and($after['external_validations'])->toBe(0)
        ->and($after['promotions'])->toBe(0)
        ->and($after['pu_histories'])->toBe(0);
})->with([
    // Zero-write não é propriedade do perfil legado: é propriedade da SIMULAÇÃO.
    'perfil omitido' => [null],
    'contratual' => [PuCalculationProfile::Contractual],
    'legado' => [PuCalculationProfile::LegacyCompatibility],
]);

// ---------------------------------------------------------------------------
// Interface
// ---------------------------------------------------------------------------

it('opens the calculator on the contractual profile with no warning', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs(makeAdminUser());

    $emission = PuSimulationFixture::contractualEmission();

    $component = Livewire::test(PuCalculatorSimulator::class, ['record' => $emission->getRouteKey()])
        ->assertOk()
        ->assertSet('calculationProfile', 'contractual')
        ->assertSee('Perfil de cálculo')
        ->assertSee('Contratual')
        ->assertSee('Regra documental da operação')
        ->assertSee('Compatibilidade com sistema legado')
        ->assertDontSee('Este modo existe para reconciliação');

    expect($component->instance()->calculationProfile())->toBe(PuCalculationProfile::Contractual);
});

it('warns about methodology and about the first coupon when legacy is selected', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs(makeAdminUser());

    $emission = PuSimulationFixture::contractualEmission();

    Livewire::test(PuCalculatorSimulator::class, ['record' => $emission->getRouteKey()])
        ->set('calculationProfile', 'legacy_compatibility')
        ->assertOk()
        ->assertSee('Este modo existe para reconciliação e pode reproduzir metodologia de precisão diferente da regra contratual.')
        ->assertSee('O sistema legado de referência não contempla o prêmio contratual de 2 DU identificado para o primeiro pagamento.')
        ->assertSee('não é persistido');
});

it('rejects a tampered profile in the form instead of rendering under it', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs(makeAdminUser());

    $emission = PuSimulationFixture::contractualEmission();

    // `calculationProfile` é propriedade pública e chega do cliente: um valor fora do
    // enum é possível por adulteração. A tela recusa de forma VISÍVEL -- volta para a
    // autoridade e registra erro -- em vez de calcular como se a escolha tivesse valido,
    // e sem derrubar a renderização de quem está no perfil contratual.
    $component = Livewire::test(PuCalculatorSimulator::class, ['record' => $emission->getRouteKey()])
        ->set('calculationProfile', 'spreadsheet_v3')
        ->assertOk()
        ->assertSet('calculationProfile', 'contractual')
        ->assertHasErrors('calculationProfile')
        ->assertDontSee('Este modo existe para reconciliação');

    expect($component->instance()->calculationProfile())->toBe(PuCalculationProfile::Contractual);
});

it('discards the displayed curve when the profile changes, and persists nothing', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs(makeAdminUser());

    $scenario = PuSimulationFixture::calculableScenario();
    $before = PuSimulationFixture::counts();

    $component = Livewire::test(PuCalculatorSimulator::class, ['record' => $scenario['emission']->getRouteKey()])
        ->set('firstIntegralizationDate', PuSimulationFixture::integralizationDate()->toDateString())
        ->set('simulationEndDate', PuSimulationFixture::windowEndDate()->toDateString())
        ->call('calculate')
        ->assertOk();

    expect($component->instance()->result()?->state)->toBe(PuSimulationState::Calculated);

    // Trocar de perfil invalida o resultado exibido -- e não grava nada.
    $component->set('calculationProfile', 'legacy_compatibility');

    expect($component->instance()->result())->toBeNull()
        ->and($component->get('hasCalculated'))->toBeFalse()
        ->and(PuSimulationFixture::counts())->toBe($before);
});

// ---------------------------------------------------------------------------
// O portão que mantém o legado fora da operação
// ---------------------------------------------------------------------------

/**
 * Reescreve a memória de uma linha real da engine. Os nomes das propriedades do
 * DTO coincidem com os dos parâmetros do construtor, então o spread com chaves
 * string vira argumentos nomeados -- e a linha continua sendo uma linha de verdade,
 * não um objeto inventado para o teste.
 *
 * @param  array<string, mixed>  $memory
 */
function rowWithCalculationMemory(PuDailyCurveRowData $row, array $memory): PuDailyCurveRowData
{
    return new PuDailyCurveRowData(...[...get_object_vars($row), 'calculationMemory' => $memory]);
}

it('refuses to persist an operational curve calculated under the legacy profile', function () {
    $emission = PuSimulationFixture::contractualEmission();
    PuSimulationFixture::seedRequiredRates(
        PuSimulationFixture::integralizationDate(),
        profileWindowEnd(),
    );

    $legacy = app(PuSimulationService::class)->simulate($emission, new PuSimulationInput(
        firstIntegralizationDate: PuSimulationFixture::integralizationDate(),
        simulationEndDate: profileWindowEnd(),
        calculationProfile: PuCalculationProfile::LegacyCompatibility,
    ));

    expect($legacy->state)->toBe(PuSimulationState::Calculated)
        ->and($legacy->rows)->not->toBeEmpty();

    $before = PuSimulationFixture::counts();

    // Hoje nenhum caminho operacional informa perfil; esta chamada simula o engano de
    // um caminho FUTURO que passasse a curva de reconciliação adiante.
    expect(fn () => app(PuCurvePersistenceService::class)->handle(
        $emission,
        new PuCurveGenerationResult(rows: $legacy->rows),
        syncLegacyProjections: false,
    ))->toThrow(PuNonOperationalProfileException::class);

    // A recusa acontece ANTES da transação: nem versão, nem linha, nem projeção.
    expect(PuSimulationFixture::counts())->toBe($before);
});

it('lets the contractual curve through the operational gate', function () {
    $contractual = profileSimulate(PuCalculationProfile::Contractual);
    $guard = app(PuOperationalProfileGuard::class);

    expect($contractual->rows)->not->toBeEmpty();

    // Caminho feliz: toda linha da curva oficial nasce carimbada como contratual e
    // operacional, e é por isso que o portão não produz falso positivo.
    foreach ($contractual->rows as $row) {
        expect($row->calculationMemory['calculation_profile'])->toBe('contractual')
            ->and($row->calculationMemory['calculation_profile_is_operational'])->toBeTrue()
            ->and($row->calculationMemory['engine_version'])->toBe(PuAuditLogService::ENGINE_VERSION);
    }

    // Se o portão recusasse a curva oficial, esta chamada lançaria e o teste falharia:
    // é a asserção de que o caminho operacional continua aberto para o contratual.
    $guard->assertOperational($contractual->rows, 'a curva operacional');

    expect($contractual->state)->toBe(PuSimulationState::Calculated);
});

it('accepts a historical curve from an engine version that predates the profile stamp', function () {
    $contractual = profileSimulate(PuCalculationProfile::Contractual);

    // `phase1-cdi-v1` é anterior ao conceito de perfil, e na época dela só existia o
    // cálculo contratual. Linha histórica sem carimbo continua operacional -- do
    // contrário o portão invalidaria curvas legítimas já persistidas.
    $historical = rowWithCalculationMemory(
        $contractual->rows[0],
        ['engine_version' => 'phase1-cdi-v1'],
    );

    app(PuOperationalProfileGuard::class)->assertOperational([$historical], 'a curva operacional');

    expect($historical->calculationMemory)->not->toHaveKey('calculation_profile');
});

it('fails closed when a curve from the current engine has no profile stamp', function () {
    $contractual = profileSimulate(PuCalculationProfile::Contractual);

    // Toda curva nova da engine corrente carimba o perfil. A ausência do carimbo numa
    // linha que se declara da versão corrente significa memória adulterada ou montada à
    // mão: sem procedência, não entra na operação.
    $unstamped = rowWithCalculationMemory(
        $contractual->rows[0],
        ['engine_version' => PuAuditLogService::ENGINE_VERSION],
    );

    expect(fn () => app(PuOperationalProfileGuard::class)->assertOperational(
        [$unstamped],
        'a curva operacional',
    ))->toThrow(PuNonOperationalProfileException::class, PuAuditLogService::ENGINE_VERSION);
});

it('fails closed when the stamped profile is unknown to the engine', function () {
    $contractual = profileSimulate(PuCalculationProfile::Contractual);

    // Um perfil que a engine não reconhece não pode ser tratado como contratual:
    // seria declarar contratual uma metodologia que ninguém auditou.
    expect(fn () => app(PuOperationalProfileGuard::class)->assertOperational(
        [rowWithCalculationMemory($contractual->rows[0], ['calculation_profile' => 'spreadsheet_v3'])],
        'a curva operacional',
    ))->toThrow(PuNonOperationalProfileException::class, 'spreadsheet_v3');
});
