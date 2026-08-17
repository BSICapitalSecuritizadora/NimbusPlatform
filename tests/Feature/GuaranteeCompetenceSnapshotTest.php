<?php

use App\Enums\GuaranteeCoverageStatus;
use App\Enums\GuaranteeLegalStatus;
use App\Enums\GuaranteeType;
use App\Enums\GuaranteeValueStatus;
use App\Models\Emission;
use App\Models\Guarantee;
use App\Models\GuaranteeMonthlyPosition;
use App\Models\GuaranteeSnapshot;
use App\Models\IntegralizationHistory;
use App\Models\PuHistory;
use App\Models\Receivable;
use App\Services\Guarantees\EmissionGuaranteeCoverageEngine;
use App\Services\Guarantees\GuaranteeAlertBuilder;
use App\Services\Guarantees\GuaranteeSnapshotWriter;
use App\Services\Reports\EmissionMonthlyReportService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function emissionWithReceivablesGuarantee(float $outstanding, float $performing, string $month = '2026-07-01'): Emission
{
    $emission = Emission::factory()->create(['issued_quantity' => 1000000]);

    IntegralizationHistory::query()->create([
        'emission_id' => $emission->id,
        'date' => Carbon::parse($month)->subMonth()->toDateString(),
        'quantity' => 1000,
        'unit_value' => 1,
        'financial_value' => 1000,
        'investor_fund' => 'Fundo A',
    ]);

    PuHistory::query()->create([
        'emission_id' => $emission->id,
        'date' => Carbon::parse($month)->endOfMonth()->toDateString(),
        'unit_value' => $outstanding / 1000,
    ]);

    Receivable::factory()->create([
        'emission_id' => $emission->id,
        'reference_month' => $month,
        'performing_balance_post_event_amount' => $performing,
    ]);

    Guarantee::factory()
        ->effectiveBetween()
        ->ofType(GuaranteeType::ReceivablesFiduciaryAssignment)
        ->requiringPercentage(1.2)
        ->create(['emission_id' => $emission->id, 'legal_status' => GuaranteeLegalStatus::Active]);

    return $emission;
}

it('persists the consolidated competence and each guarantee position', function (): void {
    $emission = emissionWithReceivablesGuarantee(20_000_000, 30_000_000);
    $actor = makeAdminUser();

    app(GuaranteeSnapshotWriter::class)->persist($emission, '2026-07-01', $actor);

    $snapshot = GuaranteeSnapshot::query()->sole();

    expect((float) $snapshot->total_eligible_value)->toBe(30_000_000.0)
        ->and((float) $snapshot->total_required_value)->toBe(24_000_000.0)
        ->and((float) $snapshot->outstanding_balance)->toBe(20_000_000.0)
        ->and((float) $snapshot->surplus_deficit)->toBe(6_000_000.0)
        ->and($snapshot->coverage_status)->toBe(GuaranteeCoverageStatus::Compliant)
        ->and($snapshot->active_guarantees_count)->toBe(1)
        ->and($snapshot->isClosed())->toBeFalse();

    $position = GuaranteeMonthlyPosition::query()->sole();

    expect((float) $position->current_value)->toBe(30_000_000.0)
        ->and((float) $position->required_value)->toBe(24_000_000.0)
        ->and($position->value_status)->toBe(GuaranteeValueStatus::Automatic)
        ->and($position->metadata['value']['performing_balance'])->toEqual(30_000_000);
});

it('reproduces the historical indicator even after the operational source changes', function (): void {
    $emission = emissionWithReceivablesGuarantee(20_000_000, 30_000_000);
    $actor = makeAdminUser();

    app(GuaranteeSnapshotWriter::class)->close($emission, '2026-07-01', $actor);

    // A carteira é corrigida retroativamente depois do fechamento.
    Receivable::query()->where('emission_id', $emission->id)->update([
        'performing_balance_post_event_amount' => 5_000_000,
    ]);

    $snapshot = GuaranteeSnapshot::query()->sole();

    expect((float) $snapshot->total_eligible_value)->toBe(30_000_000.0)
        ->and($snapshot->coverage_status)->toBe(GuaranteeCoverageStatus::Compliant);
});

it('refuses to change a closed competence until it is reopened', function (): void {
    $emission = emissionWithReceivablesGuarantee(20_000_000, 30_000_000);
    $actor = makeAdminUser();

    app(GuaranteeSnapshotWriter::class)->close($emission, '2026-07-01', $actor);

    expect(fn () => app(GuaranteeSnapshotWriter::class)->persist($emission, '2026-07-01', $actor))
        ->toThrow(ValidationException::class);

    app(GuaranteeSnapshotWriter::class)->reopen($emission, '2026-07-01', $actor, 'Correção da carteira.');

    expect(GuaranteeSnapshot::query()->sole()->isClosed())->toBeFalse();

    app(GuaranteeSnapshotWriter::class)->persist($emission, '2026-07-01', $actor);

    expect(GuaranteeSnapshot::query()->count())->toBe(1);
});

it('audits competence closing and reopening', function (): void {
    $emission = emissionWithReceivablesGuarantee(20_000_000, 30_000_000);
    $actor = makeAdminUser();

    app(GuaranteeSnapshotWriter::class)->close($emission, '2026-07-01', $actor);
    app(GuaranteeSnapshotWriter::class)->reopen($emission, '2026-07-01', $actor, 'Correção.');

    $events = Activity::query()
        ->where('log_name', GuaranteeSnapshotWriter::LOG_NAME)
        ->pluck('event');

    expect($events)->toContain(GuaranteeSnapshotWriter::EVENT_COMPETENCE_CLOSED)
        ->and($events)->toContain(GuaranteeSnapshotWriter::EVENT_COMPETENCE_REOPENED);
});

it('records a manual value and audits the previous figure', function (): void {
    $emission = Emission::factory()->create(['issued_quantity' => 1000]);
    $actor = makeAdminUser();

    $guarantee = Guarantee::factory()
        ->effectiveBetween()
        ->ofType(GuaranteeType::QuotaFiduciaryAlienation)
        ->create(['emission_id' => $emission->id, 'legal_status' => GuaranteeLegalStatus::Active]);

    $writer = app(GuaranteeSnapshotWriter::class);

    $writer->recordManualValue($guarantee, '07/2026', 10_100_000, $actor);
    $writer->recordManualValue($guarantee, '07/2026', 9_000_000, $actor);

    $position = GuaranteeMonthlyPosition::query()->sole();

    expect((float) $position->current_value)->toBe(9_000_000.0)
        ->and($position->value_status)->toBe(GuaranteeValueStatus::Manual)
        ->and($position->updated_by)->toBe($actor->id);

    $audit = Activity::query()
        ->where('event', GuaranteeSnapshotWriter::EVENT_VALUE_UPDATED)
        ->latest('id')
        ->first();

    expect($audit->properties['old_value'])->toEqual(10_100_000)
        ->and($audit->properties['new_value'])->toEqual(9_000_000);
});

it('raises an alert when coverage falls below the contractual minimum', function (): void {
    $emission = emissionWithReceivablesGuarantee(20_000_000, 22_000_000);

    $position = app(EmissionGuaranteeCoverageEngine::class)->buildPosition($emission, '2026-07-01');
    $alerts = app(GuaranteeAlertBuilder::class)->build($emission, $position);

    expect($alerts->pluck('title'))->toContain('Cobertura abaixo do mínimo contratual')
        ->and($alerts->firstWhere('title', 'Cobertura abaixo do mínimo contratual')['severity'])
        ->toBe(GuaranteeAlertBuilder::SEVERITY_DANGER);
});

it('raises a pending alert instead of a breach when a manual value is missing', function (): void {
    $emission = emissionWithReceivablesGuarantee(20_000_000, 30_000_000);

    Guarantee::factory()
        ->effectiveBetween()
        ->ofType(GuaranteeType::QuotaFiduciaryAlienation)
        ->create(['emission_id' => $emission->id, 'legal_status' => GuaranteeLegalStatus::Active]);

    $position = app(EmissionGuaranteeCoverageEngine::class)->buildPosition($emission, '2026-07-01');
    $alerts = app(GuaranteeAlertBuilder::class)->build($emission, $position);

    expect($alerts->pluck('title'))->toContain('Valor da competência pendente')
        ->and($alerts->pluck('title'))->not->toContain('Cobertura abaixo do mínimo contratual');
});

it('feeds the monthly report from the consolidated guarantee module', function (): void {
    $emission = emissionWithReceivablesGuarantee(20_000_000, 30_000_000);
    $actor = makeAdminUser();

    app(GuaranteeSnapshotWriter::class)->close($emission, '2026-07-01', $actor);

    $report = app(EmissionMonthlyReportService::class)
        ->build($emission->fresh(), Carbon::parse('2026-07-15'));

    expect($report)->toHaveKey('guarantees')
        ->and($report['guarantees']['consolidated'])->toBeTrue()
        ->and($report['guarantees']['eligible_value'])->toBe('R$ 30.000.000,00')
        ->and($report['guarantees']['required_value'])->toBe('R$ 24.000.000,00')
        ->and($report['guarantees']['coverage_ratio'])->toBe('150,00%')
        ->and($report['guarantees']['status'])->toBe(GuaranteeCoverageStatus::Compliant->label())
        ->and($report['guarantees']['items'])->toHaveCount(1);
});

it('reports missing values as not informed rather than zero', function (): void {
    $emission = emissionWithReceivablesGuarantee(20_000_000, 30_000_000);

    // Garantia sem fonte automática e sem valor digitado na competência.
    Guarantee::factory()
        ->effectiveBetween()
        ->ofType(GuaranteeType::QuotaFiduciaryAlienation)
        ->create(['emission_id' => $emission->id, 'legal_status' => GuaranteeLegalStatus::Active]);

    $report = app(EmissionMonthlyReportService::class)
        ->build($emission->fresh(), Carbon::parse('2026-07-15'));

    expect($report['guarantees']['eligible_value'])->toBe('Não informado')
        ->and($report['guarantees']['eligible_value'])->not->toBe('R$ 0,00')
        ->and($report['guarantees']['status'])->toBe(GuaranteeCoverageStatus::PendingUpdate->label());
});
