<?php

use App\Domain\PuCalculator\Enums\PuAmortizationType;
use App\Domain\PuCalculator\Enums\PuEventType;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Services\PuFinancialEffectSupport as Effect;
use App\Enums\AccessPermission;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\PuEventsRelationManager;
use App\Filament\Resources\Emissions\Pages\EditEmission;
use App\Filament\Resources\Emissions\Schemas\PuContractualEventFields;
use App\Models\Emission;
use App\Models\EmissionPuEvent;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Pu\PuObligationFixture as Fx;

/**
 * Fase 8 (UI-5): eventos com efeito financeiro entram por formulário guiado, que
 * monta o `financial_effect` exato que a engine aceita e recusa, antes de gravar,
 * o que a geração da curva recusaria. Tipo sem regra não é oferecido.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $user = User::factory()->create();
    $user->givePermissionTo([AccessPermission::EmissionsView->value, AccessPermission::EmissionsUpdate->value, AccessPermission::PuParametersConfigure->value]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs($user->fresh());
});

function p8eEvents(Emission $emission): Testable
{
    return Livewire::test(PuEventsRelationManager::class, ['ownerRecord' => $emission->fresh(), 'pageClass' => EditEmission::class]);
}

function p8eEmission(): Emission
{
    return Fx::emission([
        [PuEventType::InterestPayment, '2026-03-09'],
        [PuEventType::InterestPayment, '2026-04-08'],
        [PuEventType::InterestPayment, '2026-05-08'],
    ]);
}

it('builds a supported extraordinary amortization from structured fields, with the contractual cap as a fraction', function () {
    $emission = p8eEmission();

    p8eEvents($emission)
        ->callAction(TestAction::make('create')->table(), data: [
            'event_type' => PuEventType::ExtraordinaryAmortization->value,
            'effective_date' => '2026-03-09',
            'xa_mode' => PuAmortizationType::Percentage->value,
            'xa_percent' => '10',
            'xa_accrued_interest' => Effect::ACCRUED_INTEREST_PAID_BY_INTEREST_PAYMENT,
            'xa_reduction_on_effective_date' => true,
            'xa_has_cap' => true,
            'xa_cap_percent' => '98',
            'document_reference' => 'Termo, cláusula 5.3',
            'sequence' => 1,
        ])
        ->assertHasNoActionErrors();

    $event = EmissionPuEvent::query()->where('event_type', PuEventType::ExtraordinaryAmortization->value)->sole();

    expect($event->amortization_type)->toBe(PuAmortizationType::Percentage->value)
        ->and(bccomp((string) $event->amortization_value, '0.1', 16))->toBe(0)
        ->and($event->financial_effect)->toBe([
            Effect::KEY_PRINCIPAL_REDUCTION => Effect::PRINCIPAL_REDUCTION_ON_EFFECTIVE_DATE,
            Effect::KEY_ACCRUED_INTEREST => Effect::ACCRUED_INTEREST_PAID_BY_INTEREST_PAYMENT,
            Effect::KEY_MAXIMUM_PRINCIPAL_FRACTION => '0.98',
        ])
        ->and($event->document_reference)->toBe('Termo, cláusula 5.3')
        ->and(app(Effect::class)->issues(Effect::fromModel($event), $emission->puEvents()->active()->get()->map(fn ($e) => Effect::fromModel($e))->all(), $emission->puParameter->resolvedCalculationMethod()))->toBe([]);

    p8eEvents($emission)->assertTableColumnStateSet('financial_support', 'Calculado', $event);
});

it('refuses an extraordinary amortization whose terms the curve would reject, saving nothing', function (array $terms, string $reasonFragment) {
    $emission = p8eEmission();

    $component = p8eEvents($emission)
        ->callAction(TestAction::make('create')->table(), data: [
            'event_type' => PuEventType::ExtraordinaryAmortization->value,
            'xa_mode' => PuAmortizationType::Percentage->value,
            'xa_reduction_on_effective_date' => true,
            'document_reference' => 'Termo',
            'sequence' => 1,
            ...$terms,
        ]);

    // O corpo antes do assertNotified, que esvazia as notificações da sessão.
    expect(collect(session()->get('filament.claimed_notifications') ?? session()->get('filament.notifications') ?? [])->pluck('body')->implode(' '))->toContain($reasonFragment, 'Nada foi gravado');
    $component->assertNotified('Evento não cadastrado: a curva o recusaria.');

    expect(EmissionPuEvent::query()->where('event_type', PuEventType::ExtraordinaryAmortization->value)->count())->toBe(0);
})->with([
    'juros pagos sem pagamento de juros na data' => [['effective_date' => '2026-03-20', 'xa_percent' => '10', 'xa_accrued_interest' => Effect::ACCRUED_INTEREST_PAID_BY_INTEREST_PAYMENT], 'não há pagamento de juros'],
    'percentual acima do teto contratual' => [['effective_date' => '2026-03-09', 'xa_percent' => '10', 'xa_accrued_interest' => Effect::ACCRUED_INTEREST_PAID_BY_INTEREST_PAYMENT, 'xa_has_cap' => true, 'xa_cap_percent' => '5'], 'excede o teto'],
]);

it('requires the explicit restricted terms of an early maturity and previews what stops being due', function () {
    $emission = p8eEmission();

    p8eEvents($emission)
        ->mountAction(TestAction::make('create')->table())
        ->setActionData(['event_type' => PuEventType::EarlyMaturity->value, 'effective_date' => '2026-04-15', 'document_reference' => 'Notificação de vencimento', 'sequence' => 1])
        ->assertMountedActionModalSee(['Principal acelerado = saldo devedor em 15/04/2026', '1 pagamento(s) do cronograma posterior(es) (08/05/2026)'])
        ->callMountedAction()
        ->assertHasActionErrors(['em_outstanding_balance', 'em_pro_rata_interest', 'em_no_additional_charges'])
        ->setActionData(['em_outstanding_balance' => true, 'em_pro_rata_interest' => true, 'em_no_additional_charges' => true])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(EmissionPuEvent::query()->where('event_type', PuEventType::EarlyMaturity->value)->sole()->financial_effect)->toBe([
        Effect::KEY_ACCELERATED_PRINCIPAL => Effect::ACCELERATED_PRINCIPAL_OUTSTANDING_BALANCE,
        Effect::KEY_ACCRUED_INTEREST => Effect::ACCRUED_INTEREST_ORDINARY_PRO_RATA,
        Effect::KEY_ADDITIONAL_CHARGES => Effect::NONE,
    ]);
});

it('records a waiver only as having no effect on the PU', function () {
    $emission = p8eEmission();

    p8eEvents($emission)
        ->callAction(TestAction::make('create')->table(), data: ['event_type' => PuEventType::Waiver->value, 'effective_date' => '2026-03-20', 'sequence' => 1, 'wv_no_pu_effect' => false])
        ->assertHasActionErrors(['wv_no_pu_effect']);

    p8eEvents($emission)
        ->callAction(TestAction::make('create')->table(), data: ['event_type' => PuEventType::Waiver->value, 'effective_date' => '2026-03-20', 'sequence' => 1, 'wv_no_pu_effect' => true])
        ->assertHasNoActionErrors();

    $waiver = EmissionPuEvent::query()->where('event_type', PuEventType::Waiver->value)->sole();

    expect($waiver->financial_effect)->toBe([Effect::KEY_PU_EFFECT => Effect::NONE]);
    p8eEvents($emission)->assertTableColumnStateSet('financial_support', 'Sem efeito no PU', $waiver);
});

it('offers future spread amendments only at period starts and never touches the official version', function () {
    $emission = p8eEmission();
    $official = Fx::official($emission);
    $before = $official->fresh()->only(['status', 'calculation_version', 'homologated_at']);

    expect(PuContractualEventFields::periodStartOptions($emission->fresh()))->toHaveKeys(['2026-03-09', '2026-04-08', '2026-05-08'])
        ->not->toHaveKey('2026-03-02');

    p8eEvents($emission)
        ->callAction(TestAction::make('create')->table(), data: [
            'event_type' => PuEventType::SpreadAmendment->value,
            'sa_effective_date' => '2026-04-08',
            'sa_spread_rate' => '7.5',
            'document_reference' => 'Aditamento 2',
            'sequence' => 1,
        ])
        ->assertHasNoActionErrors();

    $amendment = EmissionPuEvent::query()->where('event_type', PuEventType::SpreadAmendment->value)->sole();

    expect($amendment->effective_date->toDateString())->toBe('2026-04-08')
        ->and($amendment->financial_effect)->toBe(['spread_rate' => '7.50000000'])
        ->and($official->fresh()->only(['status', 'calculation_version', 'homologated_at']))->toEqual($before);
});

it('never offers event types without a calculation rule and lists them as such', function () {
    $offered = array_keys(PuContractualEventFields::typeOptions());

    foreach ([PuEventType::Default, PuEventType::GracePeriod, PuEventType::Deferral, PuEventType::Premium, PuEventType::ContractualCharge, PuEventType::EarlySettlement, PuEventType::IndexerChange, PuEventType::ExtraordinaryInterest] as $unsupported) {
        expect($offered)->not->toContain($unsupported->value);
    }

    p8eEvents(p8eEmission())
        ->mountAction(TestAction::make('effectSupportMatrix')->table())
        ->assertMountedActionModalSee(['Inadimplemento (mora)', 'Não oferecido', 'Ciclo de vida representado; cálculo financeiro não suportado']);
});

it('ignores a raw financial effect forged into the payload of a scheduled payment', function () {
    $emission = p8eEmission();

    p8eEvents($emission)
        ->mountAction(TestAction::make('create')->table())
        ->setActionData(['event_type' => PuEventType::InterestPayment->value, 'original_date' => '2026-06-08', 'effective_date' => '2026-06-08', 'amortization_type' => PuAmortizationType::None->value, 'sequence' => 1, 'financial_effect' => ['pu_effect' => 'suspend_interest']])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(EmissionPuEvent::query()->whereDate('effective_date', '2026-06-08')->sole()->financial_effect)->toBeNull();
});

it('flags an imported event that would block the curve', function () {
    $emission = p8eEmission();
    $blocking = Fx::event($emission, PuEventType::Waiver, '2026-03-20', ['financial_effect' => ['pu_effect' => 'suspend_interest']]);

    p8eEvents($emission)
        ->assertTableColumnStateSet('financial_support', 'Bloqueia a curva', $blocking);
});

it('refuses calculated events on an indexer the engine does not cover', function () {
    $emission = p8eEmission();
    $emission->puParameter->forceFill(['indexer' => PuIndexer::Prefixed->value, 'annual_rate' => '10.00000000'])->save();

    p8eEvents($emission)
        ->callAction(TestAction::make('create')->table(), data: [
            'event_type' => PuEventType::ExtraordinaryAmortization->value,
            'effective_date' => '2026-03-09',
            'xa_mode' => PuAmortizationType::UnitValue->value,
            'xa_unit_value' => '10',
            'xa_accrued_interest' => Effect::ACCRUED_INTEREST_PAID_BY_INTEREST_PAYMENT,
            'xa_reduction_on_effective_date' => true,
            'document_reference' => 'Termo',
            'sequence' => 1,
        ])
        ->assertNotified('Evento não cadastrado: a curva o recusaria.');

    expect(EmissionPuEvent::query()->where('event_type', PuEventType::ExtraordinaryAmortization->value)->count())->toBe(0);
});
