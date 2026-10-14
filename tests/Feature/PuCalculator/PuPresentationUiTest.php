<?php

use App\Domain\PuCalculator\Enums\PuAmortizationType;
use App\Domain\PuCalculator\Enums\PuEventType;
use App\Domain\PuCalculator\Enums\PuIncidentStatus;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuObligationCalculationState;
use App\Domain\PuCalculator\Enums\PuObligationType;
use App\Domain\PuCalculator\Enums\PuOperationalConditionType;
use App\Domain\PuCalculator\Enums\PuOperationalSeverity;
use App\Domain\PuCalculator\Services\PuFinancialEffectSupport as Effect;
use App\Domain\PuCalculator\Services\PuObligationReader;
use App\Domain\PuCalculator\Services\PuOperationalAlertDispatcher;
use App\Enums\AccessPermission;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\Emissions\Pages\EditEmission;
use App\Filament\Resources\Emissions\Pages\PuCalculatorSimulator;
use App\Jobs\GeneratePuDailyCurveJob;
use App\Models\Emission;
use App\Models\PuOperationalIncident;
use App\Models\User;
use App\Services\Reports\EmissionMonthlyReportService;
use App\Support\PuCalculator\PuDisplay;
use App\Support\PuCalculator\PuOperationalDashboardData;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Action;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Pu\PuObligationFixture as Fx;
use Tests\Support\Pu\PuOperationalActivationFixture as Activation;

/**
 * Fase 8 (UI-6): vocabulário, datas e estados indisponíveis consistentes; botões
 * que só aparecem habilitados quando o domínio aceitaria; site e relatório com a
 * data e a categoria certas.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('formats money, signed differences and percentages without inventing values', function () {
    expect(PuDisplay::money('1234567.89'))->toBe('R$ 1.234.567,89')
        ->and(PuDisplay::money(null))->toBeNull()
        ->and(PuDisplay::money(''))->toBeNull()
        ->and(PuDisplay::money('-5.00'))->toBe(PuDisplay::MINUS.'R$ 5,00')
        ->and(PuDisplay::signedMoney('-5.00'))->toBe(PuDisplay::MINUS.'R$ 5,00')
        ->and(PuDisplay::signedMoney('5.00'))->toBe('+R$ 5,00')
        ->and(PuDisplay::signedMoney('0.00'))->toBe('R$ 0,00')
        ->and(PuDisplay::signedMoney('-0.004'))->toBe('R$ 0,00')
        ->and(PuDisplay::fractionAsPercent('0.98'))->toBe('98,00%')
        ->and(PuDisplay::unavailableExpected(PuObligationCalculationState::AwaitingIndex))->toBe('Aguardando índice')
        ->and(PuDisplay::unavailableExpected(PuObligationCalculationState::NoOfficialCalculation))->not->toContain('0,00');
});

it('labels prefixed and IPCA as simulation only wherever the indexer is chosen', function () {
    expect(PuDisplay::indexerOption(PuIndexer::Prefixed))->toContain('somente simulação')
        ->and(PuDisplay::indexerOption(PuIndexer::Ipca))->toContain('somente simulação')
        ->and(PuDisplay::indexerOption(PuIndexer::Cdi))->toContain('certificado com condições');

    $emission = Fx::emission();
    $this->actingAs(makeAdminUser());

    expect(Livewire::test(PuCalculatorSimulator::class, ['record' => $emission->getRouteKey()])->instance()->indexerOptions())
        ->toBe([
            PuIndexer::Cdi->value => PuDisplay::indexerOption(PuIndexer::Cdi),
            PuIndexer::Prefixed->value => PuDisplay::indexerOption(PuIndexer::Prefixed),
            PuIndexer::Ipca->value => PuDisplay::indexerOption(PuIndexer::Ipca),
        ]);

    Livewire::test(EditEmission::class, ['record' => $emission->getRouteKey()])
        ->mountAction('configurePuCalculation')
        ->assertMountedActionModalSee(['Prefixado — não certificado: somente simulação', 'IPCA — não certificado: somente simulação', 'Padrão:']);
});

it('disables curve generation with the reason while PU operations are off, and a forged call queues nothing', function () {
    Queue::fake();
    $emission = Fx::emission();
    config()->set('pu_calculator.operations.enabled', false);
    $this->actingAs(makeAdminUser());

    Livewire::test(EditEmission::class, ['record' => $emission->getRouteKey()])
        ->assertActionDisabled('generatePuDailyCurve')
        ->assertActionExists('generatePuDailyCurve', fn (Action $action): bool => str_contains((string) $action->getTooltip(), 'PU_OPERATIONS_ENABLED'))
        ->assertActionDisabled('homologatePuCurve')
        ->call('mountAction', 'generatePuDailyCurve')
        ->assertSet('mountedActions', []);

    Queue::assertNothingPushed();
});

it('explains that a configured but not activated emission cannot generate or homologate', function () {
    Queue::fake();
    Activation::withoutAutomaticActivation();
    $emission = Fx::emission();
    $this->actingAs(makeAdminUser());

    Livewire::test(EditEmission::class, ['record' => $emission->getRouteKey()])
        ->assertActionDisabled('generatePuDailyCurve')
        ->assertActionExists('generatePuDailyCurve', fn (Action $action): bool => str_contains((string) $action->getTooltip(), 'não foi ativada'))
        ->mountAction('puCurvePanel')
        ->assertMountedActionModalSee(['Configurado, operação do PU não ativada', 'Abrir Operação do PU']);

    Queue::assertNothingPushed();
});

it('blocks generation of a non certified indexer before dispatch', function () {
    Queue::fake();
    $emission = Fx::emission();
    $emission->puParameter->forceFill(['indexer' => PuIndexer::Prefixed->value, 'annual_rate' => '10.00000000'])->save();
    $this->actingAs(makeAdminUser());

    Livewire::test(EditEmission::class, ['record' => $emission->getRouteKey()])
        ->assertActionDisabled('generatePuDailyCurve')
        ->assertActionExists('generatePuDailyCurve', fn (Action $action): bool => str_contains((string) $action->getTooltip(), 'não é certificado para uso oficial'));

    Queue::assertNotPushed(GeneratePuDailyCurveJob::class);
});

it('keeps generation available for an activated emission with a certified indexer', function () {
    Queue::fake();
    $emission = Fx::emission([[PuEventType::InterestPayment, '2026-03-09']]);
    $this->actingAs(makeAdminUser());

    Livewire::test(EditEmission::class, ['record' => $emission->getRouteKey()])
        ->assertActionEnabled('generatePuDailyCurve')
        ->callAction('generatePuDailyCurve');

    Queue::assertPushed(GeneratePuDailyCurveJob::class);
});

it('shows on the public site the position date of the PU instead of a daily quote', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-16 10:00', 'America/Sao_Paulo'));
    $emission = Fx::emission([[PuEventType::InterestPayment, '2026-03-09']]);
    $emission->forceFill(['is_public' => true, 'if_code' => 'CRIP8POS01'])->save();
    $official = Fx::official($emission);
    $last = Fx::lastDate($official);

    $this->get(route('site.emissions.show', 'CRIP8POS01'))
        ->assertOk()
        ->assertSee('Posição em '.CarbonImmutable::parse($last)->format('d/m/Y'))
        ->assertDontSee('Cotação Diária');
});

it('keeps the accelerated principal of an early maturity out of the extraordinary amortization series', function () {
    $emission = Fx::emission([
        [PuEventType::InterestPayment, '2026-03-09'],
        [PuEventType::InterestPayment, '2026-04-09'],
    ]);
    Fx::publish('2026-03-16', '2026-03-31');
    Fx::event($emission, PuEventType::EarlyMaturity, '2026-03-20', ['financial_effect' => [
        Effect::KEY_ACCELERATED_PRINCIPAL => Effect::ACCELERATED_PRINCIPAL_OUTSTANDING_BALANCE,
        Effect::KEY_ACCRUED_INTEREST => Effect::ACCRUED_INTEREST_ORDINARY_PRO_RATA,
        Effect::KEY_ADDITIONAL_CHARGES => Effect::NONE,
    ]]);
    Fx::official($emission);

    $history = app(PuObligationReader::class)->officialPaymentHistory($emission->fresh(), CarbonImmutable::parse('2026-12-31'));
    $maturity = $history->first(fn ($payment): bool => $payment->payment_date->toDateString() === '2026-03-20');

    expect($maturity->accelerated_principal_value)->toBe('100000.00')
        ->and($maturity->extra_amortization_value)->toBe('0.00');
});

it('presents each obligation of the monthly report on its own, with the signed divergence', function () {
    $emission = Fx::emission([
        [PuEventType::InterestPayment, '2026-03-09'],
        [PuEventType::ExtraordinaryAmortization, '2026-03-09', [
            'amortization_type' => PuAmortizationType::Percentage->value,
            'amortization_value' => '0.1000000000000000',
            'financial_effect' => [
                Effect::KEY_PRINCIPAL_REDUCTION => Effect::PRINCIPAL_REDUCTION_ON_EFFECTIVE_DATE,
                Effect::KEY_ACCRUED_INTEREST => Effect::ACCRUED_INTEREST_PAID_BY_INTEREST_PAYMENT,
            ],
        ]],
    ]);
    Fx::official($emission);
    $ordinary = Fx::obligation($emission, PuObligationType::ScheduledPayment, '2026-03-09');
    Fx::settle($ordinary, bcsub(Fx::expectedTotal($ordinary), '10.00', 2), reference: 'B3-P8-REPORT');

    $payment = app(EmissionMonthlyReportService::class)->build($emission->fresh(), CarbonImmutable::parse('2026-03-01'))['payment'];
    $titles = array_column($payment['obligations'], 'title');
    $ordinaryBlock = collect($payment['obligations'])->first(fn (array $block): bool => str_starts_with($block['title'], PuObligationType::ScheduledPayment->label()));
    $summary = collect($ordinaryBlock['summary'])->pluck('value', 'label');

    expect($payment['obligations'])->toHaveCount(2)
        ->and($titles[0].$titles[1])->toContain(PuObligationType::ExtraordinaryAmortization->label())
        ->and($summary['Divergência (liquidado − previsto)'])->toBe(PuDisplay::MINUS.'R$ 10,00')
        ->and($summary['Versão da curva oficial'])->toBe('v1');

    $html = view('pdf.partials.emission-monthly-report-body', app(EmissionMonthlyReportService::class)->build($emission->fresh(), CarbonImmutable::parse('2026-03-01')))->render();

    expect($html)->toContain('Componente previsto')
        ->and($html)->toContain(e(PuObligationType::ExtraordinaryAmortization->label()));
});

it('drops the redundant forecast badge and explains the informed schedule once', function () {
    $emission = Fx::emission();
    $this->actingAs(makeAdminUser());

    Livewire::test(PaymentsRelationManager::class, ['ownerRecord' => $emission, 'pageClass' => EditEmission::class])
        ->assertTableColumnDoesNotExist('value_source')
        ->assertSee('não é o cálculo oficial');
});

it('does not call the PU dashboard normal while an incident is open, and shows the operation gates', function () {
    $emission = Fx::emission();
    PuOperationalIncident::factory()->create([
        'incident_key' => PuOperationalConditionType::SettlementConflictOpen->value.':emission:'.$emission->id,
        'type' => PuOperationalConditionType::SettlementConflictOpen->value,
        'check_name' => PuOperationalConditionType::SettlementConflictOpen->check(),
        'severity' => PuOperationalSeverity::Critical->value,
        'status' => PuIncidentStatus::Active->value,
        'emission_id' => $emission->id,
        'indexer' => null,
    ]);

    $overview = app(PuOperationalDashboardData::class)->overview();

    expect($overview['health']['tone'])->toBe('danger')
        ->and(collect($overview['health']['chips'])->firstWhere('label', 'incidente crítico')['value'])->toBe(1)
        ->and($overview['operations']['globally_enabled'])->toBeTrue()
        ->and($overview['operations']['activated'])->toBe(1);
});

it('links the incident bell notification to the incidents page and the affected emission', function () {
    $emission = Fx::emission();
    $monitor = User::factory()->create();
    $monitor->givePermissionTo(AccessPermission::PuOperationsMonitor->value);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $incident = PuOperationalIncident::factory()->create([
        'incident_key' => PuOperationalConditionType::SettlementDivergent->value.':emission:'.$emission->id,
        'type' => PuOperationalConditionType::SettlementDivergent->value,
        'check_name' => PuOperationalConditionType::SettlementDivergent->check(),
        'emission_id' => $emission->id,
        'indexer' => null,
    ]);

    app(PuOperationalAlertDispatcher::class)->dispatch([$incident->id]);

    $actions = collect($monitor->fresh()->notifications()->sole()->data['actions'] ?? []);

    expect($actions->pluck('url')->implode(' '))->toContain('/pu-incidentes', '/pu-operation');
});

it('explains that governed PU history is never physically deleted and points to the archival status', function () {
    $emission = Fx::emission([[PuEventType::InterestPayment, '2026-03-09']]);
    Fx::official($emission);
    $user = User::factory()->create();
    $user->givePermissionTo('emissions.delete');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $response = Gate::forUser($user->fresh())->inspect('delete', $emission->fresh());

    expect($response->allowed())->toBeFalse()
        ->and($response->message())->toContain('histórico financeiro governado do PU', 'nunca é apagado fisicamente', Emission::STATUS_OPTIONS['closed']);
});
