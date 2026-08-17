<?php

use App\Enums\GuaranteeCoverageStatus;
use App\Enums\GuaranteeLegalStatus;
use App\Enums\GuaranteeType;
use App\Enums\GuaranteeValueSource;
use App\Enums\GuaranteeValueStatus;
use App\Models\Construction;
use App\Models\Emission;
use App\Models\Fund;
use App\Models\Guarantee;
use App\Models\GuaranteeMonthlyPosition;
use App\Models\GuaranteeValuation;
use App\Models\IntegralizationHistory;
use App\Models\PuHistory;
use App\Models\Receivable;
use App\Models\SalesBoard;
use App\Services\Guarantees\EmissionGuaranteeCoverageEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * Emissão com saldo devedor conhecido na competência: o motor lê o último PU do
 * mês multiplicado pela quantidade integralizada acumulada.
 */
function emissionWithOutstandingBalance(float $outstandingBalance, string $referenceMonth = '2026-07-01'): Emission
{
    $emission = Emission::factory()->create(['issued_quantity' => 1000000]);

    IntegralizationHistory::query()->create([
        'emission_id' => $emission->id,
        'date' => Carbon::parse($referenceMonth)->subMonth()->toDateString(),
        'quantity' => 1000,
        'unit_value' => 1,
        'financial_value' => 1000,
        'investor_fund' => 'Fundo A',
    ]);

    PuHistory::query()->create([
        'emission_id' => $emission->id,
        'date' => Carbon::parse($referenceMonth)->endOfMonth()->toDateString(),
        'unit_value' => $outstandingBalance / 1000,
    ]);

    return $emission;
}

function buildPosition(Emission $emission, string $referenceMonth = '2026-07-01')
{
    return app(EmissionGuaranteeCoverageEngine::class)->buildPosition($emission->fresh(), $referenceMonth);
}

it('scenario 1: a real estate guarantee above the contractual minimum is compliant', function (): void {
    $emission = emissionWithOutstandingBalance(20_000_000);

    $guarantee = Guarantee::factory()
        ->effectiveBetween()
        ->ofType(GuaranteeType::RealEstateFiduciaryAlienation)
        ->requiringPercentage(1.2)
        ->create(['emission_id' => $emission->id, 'legal_status' => GuaranteeLegalStatus::Active]);

    GuaranteeValuation::factory()->on('2026-06-30', 30_000_000)->create(['guarantee_id' => $guarantee->id]);

    $position = buildPosition($emission);

    expect($position->outstandingBalance)->toBe(20_000_000.0)
        ->and($position->totalEligibleValue)->toBe(30_000_000.0)
        ->and($position->requiredRatio)->toBe(1.2)
        ->and($position->totalRequiredValue)->toBe(24_000_000.0)
        ->and($position->surplusDeficit)->toBe(6_000_000.0)
        ->and($position->coverageStatus)->toBe(GuaranteeCoverageStatus::Compliant);
});

it('scenario 2: receivables below the required minimum produce a deficit and a breach', function (): void {
    $emission = emissionWithOutstandingBalance(20_000_000);

    Receivable::factory()->create([
        'emission_id' => $emission->id,
        'reference_month' => '2026-07-01',
        'performing_balance_post_event_amount' => 22_000_000,
    ]);

    Guarantee::factory()
        ->effectiveBetween()
        ->ofType(GuaranteeType::ReceivablesFiduciaryAssignment)
        ->requiringPercentage(1.2)
        ->create(['emission_id' => $emission->id, 'legal_status' => GuaranteeLegalStatus::Active]);

    $position = buildPosition($emission);

    expect($position->totalEligibleValue)->toBe(22_000_000.0)
        ->and($position->totalRequiredValue)->toBe(24_000_000.0)
        ->and($position->surplusDeficit)->toBe(-2_000_000.0)
        ->and($position->coverageStatus)->toBe(GuaranteeCoverageStatus::NonCompliant);
});

it('scenario 3: combined guarantees consolidate into a single coverage figure', function (): void {
    $emission = emissionWithOutstandingBalance(28_100_000);
    $construction = Construction::factory()->create(['emission_id' => $emission->id]);

    Receivable::factory()->create([
        'emission_id' => $emission->id,
        'reference_month' => '2026-07-01',
        'performing_balance_post_event_amount' => 19_800_000,
    ]);

    SalesBoard::factory()->forEmissionAndConstruction($emission, $construction)->create([
        'reference_month' => '2026-07-01',
        'stock_value' => 8_400_000,
    ]);

    $fund = Fund::factory()->create([
        'emission_id' => $emission->id,
        'balance' => 1_350_000,
        'balance_updated_at' => '2026-07-15',
    ]);

    Guarantee::factory()
        ->effectiveBetween()
        ->ofType(GuaranteeType::ReceivablesFiduciaryAssignment)
        ->requiringPercentage(1.2)
        ->create(['emission_id' => $emission->id, 'legal_status' => GuaranteeLegalStatus::Active]);

    Guarantee::factory()
        ->effectiveBetween()
        ->ofType(GuaranteeType::Inventory)
        ->create([
            'emission_id' => $emission->id,
            'construction_id' => $construction->id,
            'legal_status' => GuaranteeLegalStatus::Active,
        ]);

    Guarantee::factory()
        ->effectiveBetween()
        ->ofType(GuaranteeType::ReserveFund)
        ->requiringAbsolute(1_000_000)
        ->create([
            'emission_id' => $emission->id,
            'fund_id' => $fund->id,
            'legal_status' => GuaranteeLegalStatus::Active,
        ]);

    $quotas = Guarantee::factory()
        ->effectiveBetween()
        ->ofType(GuaranteeType::QuotaFiduciaryAlienation)
        ->create(['emission_id' => $emission->id, 'legal_status' => GuaranteeLegalStatus::Active]);

    GuaranteeMonthlyPosition::query()->create([
        'guarantee_id' => $quotas->id,
        'emission_id' => $emission->id,
        'reference_month' => '2026-07-01',
        'current_value' => 10_100_000,
        'value_source' => GuaranteeValueSource::Manual,
        'value_status' => GuaranteeValueStatus::Manual,
    ]);

    $position = buildPosition($emission);

    // 19,8 + 8,4 + 1,35 + 10,1 = 39,65 mi
    expect($position->totalEligibleValue)->toBe(39_650_000.0)
        ->and($position->outstandingBalance)->toBe(28_100_000.0)
        ->and($position->coverageRatio)->toBe(1.411032)
        ->and($position->requiredRatio)->toBe(1.2)
        ->and($position->surplusDeficit)->toBe(5_930_000.0)
        ->and($position->activeGuaranteesCount)->toBe(4)
        ->and($position->coverageStatus)->toBe(GuaranteeCoverageStatus::Compliant);
});

it('scenario 5: a released guarantee stops counting only from its legal release date', function (): void {
    $emission = emissionWithOutstandingBalance(20_000_000);

    $guarantee = Guarantee::factory()
        ->effectiveBetween()
        ->ofType(GuaranteeType::RealEstateFiduciaryAlienation)
        ->requiringPercentage(1.2)
        ->released('2026-07-10')
        ->create(['emission_id' => $emission->id]);

    GuaranteeValuation::factory()->on('2026-01-01', 30_000_000)->create(['guarantee_id' => $guarantee->id]);

    $beforeRelease = buildPosition($emission, '2026-06-01');
    $afterRelease = buildPosition($emission, '2026-07-01');

    expect($beforeRelease->activeGuaranteesCount)->toBe(1)
        ->and($afterRelease->activeGuaranteesCount)->toBe(0);
});

it('scenario 7: a missing manual value reports pending update rather than non-compliance', function (): void {
    $emission = emissionWithOutstandingBalance(20_000_000);

    Receivable::factory()->create([
        'emission_id' => $emission->id,
        'reference_month' => '2026-07-01',
        'performing_balance_post_event_amount' => 30_000_000,
    ]);

    Guarantee::factory()
        ->effectiveBetween()
        ->ofType(GuaranteeType::ReceivablesFiduciaryAssignment)
        ->requiringPercentage(1.2)
        ->create(['emission_id' => $emission->id, 'legal_status' => GuaranteeLegalStatus::Active]);

    Guarantee::factory()
        ->effectiveBetween()
        ->ofType(GuaranteeType::QuotaFiduciaryAlienation)
        ->create(['emission_id' => $emission->id, 'legal_status' => GuaranteeLegalStatus::Active]);

    $position = buildPosition($emission);

    expect($position->coverageStatus)->toBe(GuaranteeCoverageStatus::PendingUpdate)
        ->and($position->totalEligibleValue)->toBeNull()
        ->and($position->pendingSources)->toContain('Alienação Fiduciária de Quotas')
        ->and($position->pendingPositions())->toHaveCount(1);
});

it('applies the eligibility haircut to the current value', function (): void {
    $emission = emissionWithOutstandingBalance(10_000_000);

    Receivable::factory()->create([
        'emission_id' => $emission->id,
        'reference_month' => '2026-07-01',
        'performing_balance_post_event_amount' => 20_000_000,
    ]);

    Guarantee::factory()
        ->effectiveBetween()
        ->ofType(GuaranteeType::ReceivablesFiduciaryAssignment)
        ->requiringPercentage(1.2)
        ->withHaircut(0.8)
        ->create(['emission_id' => $emission->id, 'legal_status' => GuaranteeLegalStatus::Active]);

    $position = buildPosition($emission);

    expect($position->totalGrossValue)->toBe(20_000_000.0)
        ->and($position->totalEligibleValue)->toBe(16_000_000.0);
});

it('uses the valuation in force on the competence, not the most recent one', function (): void {
    $emission = emissionWithOutstandingBalance(10_000_000);

    $guarantee = Guarantee::factory()
        ->effectiveBetween()
        ->ofType(GuaranteeType::RealEstateFiduciaryAlienation)
        ->requiringPercentage(1.2)
        ->create(['emission_id' => $emission->id, 'legal_status' => GuaranteeLegalStatus::Active]);

    GuaranteeValuation::factory()->on('2024-01-10', 20_000_000)->create(['guarantee_id' => $guarantee->id]);
    GuaranteeValuation::factory()->on('2026-12-30', 23_500_000)->create(['guarantee_id' => $guarantee->id]);

    expect(buildPosition($emission, '2026-07-01')->totalEligibleValue)->toBe(20_000_000.0)
        ->and(buildPosition($emission, '2027-01-01')->totalEligibleValue)->toBe(23_500_000.0);
});

it('resolves the binding minimum as the strictest percentage among active guarantees', function (): void {
    $emission = emissionWithOutstandingBalance(10_000_000);

    Receivable::factory()->create([
        'emission_id' => $emission->id,
        'reference_month' => '2026-07-01',
        'performing_balance_post_event_amount' => 15_000_000,
    ]);

    Guarantee::factory()
        ->effectiveBetween()
        ->ofType(GuaranteeType::ReceivablesFiduciaryAssignment)
        ->requiringPercentage(1.2)
        ->create(['emission_id' => $emission->id, 'legal_status' => GuaranteeLegalStatus::Active]);

    Guarantee::factory()
        ->effectiveBetween()
        ->ofType(GuaranteeType::CreditRightsFiduciaryAssignment)
        ->requiringPercentage(1.3)
        ->create(['emission_id' => $emission->id, 'legal_status' => GuaranteeLegalStatus::Active]);

    expect(buildPosition($emission)->requiredRatio)->toBe(1.3);
});

it('reports insufficient data instead of zero when the competence has no pu', function (): void {
    $emission = Emission::factory()->create(['issued_quantity' => 1000]);

    IntegralizationHistory::query()->create([
        'emission_id' => $emission->id,
        'date' => '2026-06-10',
        'quantity' => 100,
        'unit_value' => 10,
        'financial_value' => 1000,
        'investor_fund' => 'Fundo A',
    ]);

    Guarantee::factory()
        ->effectiveBetween()
        ->ofType(GuaranteeType::ReceivablesFiduciaryAssignment)
        ->requiringPercentage(1.2)
        ->create(['emission_id' => $emission->id, 'legal_status' => GuaranteeLegalStatus::Active]);

    $position = buildPosition($emission);

    expect($position->outstandingBalance)->toBeNull()
        ->and($position->totalRequiredValue)->toBeNull()
        ->and($position->coverageRatio)->toBeNull();
});

it('treats a personal guarantee as not applicable for monetary position', function (): void {
    $emission = emissionWithOutstandingBalance(10_000_000);

    Guarantee::factory()
        ->effectiveBetween()
        ->ofType(GuaranteeType::Surety)
        ->create(['emission_id' => $emission->id, 'legal_status' => GuaranteeLegalStatus::Active]);

    $position = buildPosition($emission);

    expect($position->positions->first()->coverageStatus)->toBe(GuaranteeCoverageStatus::NotApplicable)
        ->and($position->hasPendingValues())->toBeFalse();
});
