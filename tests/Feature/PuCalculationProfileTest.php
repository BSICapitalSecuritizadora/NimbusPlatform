<?php

use App\Domain\PuCalculator\DTOs\PuSimulationInput;
use App\Domain\PuCalculator\Enums\PuCalculationProfile;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuSimulationState;
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

function profileSimulate(?PuCalculationProfile $profile): App\Domain\PuCalculator\DTOs\PuSimulationResult
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
        // String ausente, vazia ou desconhecida NUNCA alcança o legado.
        ->and(PuCalculationProfile::fromNullable(null))->toBe(PuCalculationProfile::Contractual)
        ->and(PuCalculationProfile::fromNullable(''))->toBe(PuCalculationProfile::Contractual)
        ->and(PuCalculationProfile::fromNullable('   '))->toBe(PuCalculationProfile::Contractual)
        ->and(PuCalculationProfile::fromNullable('legacy'))->toBe(PuCalculationProfile::Contractual)
        ->and(PuCalculationProfile::fromNullable('LEGACY_COMPATIBILITY'))->toBe(PuCalculationProfile::Contractual)
        // E é explicitamente alcançável quando escrito corretamente.
        ->and(PuCalculationProfile::fromNullable('legacy_compatibility'))
        ->toBe(PuCalculationProfile::LegacyCompatibility);
});

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

it('leaves calendar, CDI, lag, DUP and the coupon dates identical across profiles', function () {
    $contractual = profileSimulate(PuCalculationProfile::Contractual);
    $legacy = profileSimulate(PuCalculationProfile::LegacyCompatibility);

    expect($legacy->rows)->toHaveCount(count($contractual->rows));

    foreach ($contractual->rows as $index => $reference) {
        $row = $legacy->rows[$index];

        // Nada fora dos dois estágios comprovados pode divergir.
        expect($row->date->toDateString())->toBe($reference->date->toDateString())
            ->and($row->isBusinessDay)->toBe($reference->isBusinessDay)
            ->and($row->dupInterest)->toBe($reference->dupInterest)
            ->and($row->dutInterest)->toBe($reference->dutInterest)
            ->and($row->indexRateDate?->toDateString())->toBe($reference->indexRateDate?->toDateString())
            ->and($row->indexRateValue)->toBe($reference->indexRateValue)
            ->and($row->factorDi)->toBe($reference->factorDi)
            ->and($row->factorDiAccumulated)->toBe($reference->factorDiAccumulated)
            ->and($row->factorSpread)->toBe($reference->factorSpread)
            ->and($row->quantity)->toBe($reference->quantity)
            ->and($row->eventEffectiveDate?->toDateString())->toBe($reference->eventEffectiveDate?->toDateString())
            ->and($row->calculationMemory['calendar_code'])->toBe($reference->calculationMemory['calendar_code'])
            ->and($row->calculationMemory['event_types'])->toBe($reference->calculationMemory['event_types']);
    }
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

it('writes nothing operational when the legacy profile is used', function () {
    PuSimulationFixture::contractualEmission();
    PuSimulationFixture::seedRequiredRates(
        PuSimulationFixture::integralizationDate(),
        profileWindowEnd(),
    );

    $before = PuSimulationFixture::counts();
    $result = profileSimulate(PuCalculationProfile::LegacyCompatibility);
    $after = PuSimulationFixture::counts();

    expect($result->state)->toBe(PuSimulationState::Calculated)
        // Parâmetro, evento, curva, versão, candidate, promoção, homologação:
        // NADA é criado, atualizado ou apagado por simular no perfil legado.
        ->and($after)->toBe($before)
        ->and($after['parameters'])->toBe(0)
        ->and($after['curve_versions'])->toBe(0)
        ->and($after['daily_curves'])->toBe(0)
        ->and($after['promotions'])->toBe(0);
});

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
