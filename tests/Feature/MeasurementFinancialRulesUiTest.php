<?php

use App\Enums\AccessPermission;
use App\Filament\Resources\MeasurementFinancialRules\MeasurementFinancialRuleResource;
use App\Filament\Resources\MeasurementFinancialRules\Pages\ManageMeasurementFinancialRules;
use App\Filament\Resources\Measurements\Pages\ViewMeasurement;
use App\Models\Emission;
use App\Models\MeasurementFinancialRule;
use App\Models\User;
use App\Services\MeasurementWorkflow;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\MeasurementReceiptEvidenceScenario as Scenario;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
});

it('creates a rule for the selected emission and versions it through the resource', function () {
    $this->actingAs(makeAdminUser());
    $emission = Emission::factory()->create();
    $data = [
        'emission_id' => $emission->id, 'name' => 'Retenção prevista', 'description' => 'Conforme contrato da emissão.',
        'direction' => 'under', 'maximum_difference_amount' => '1000.00', 'requires_document' => true,
        'effective_from' => '2026-01-01', 'effective_until' => '2026-12-31',
    ];
    Livewire::test(ManageMeasurementFinancialRules::class)->callAction('create', data: $data)->assertHasNoActionErrors();
    $rule = MeasurementFinancialRule::query()->sole();
    expect($rule->emission_id)->toBe($emission->id)->and($rule->requires_document)->toBeTrue();

    Livewire::test(ManageMeasurementFinancialRules::class)
        ->assertCanSeeTableRecords([$rule])
        ->callAction(TestAction::make('newVersion')->table($rule), data: array_replace($data, ['maximum_difference_amount' => '2000.00']))
        ->assertHasNoActionErrors();
    expect($rule->fresh()->retired_at)->not->toBeNull()
        ->and(MeasurementFinancialRule::query()->where('supersedes_id', $rule->id)->sole()->version)->toBe(2);
});

it('requires a limit and limits management to authorized users', function () {
    $reader = User::factory()->withTwoFactor()->create();
    $this->actingAs($reader)->get(MeasurementFinancialRuleResource::getUrl())->assertForbidden();
    $reader->givePermissionTo(AccessPermission::MeasurementsFinancialRulesView->value);
    $rule = MeasurementFinancialRule::factory()->create();
    Livewire::test(ManageMeasurementFinancialRules::class)
        ->assertSuccessful()->assertActionHidden('create')
        ->assertActionHidden(TestAction::make('newVersion')->table($rule))
        ->assertActionHidden(TestAction::make('retire')->table($rule));

    $this->actingAs(makeAdminUser());
    Livewire::test(ManageMeasurementFinancialRules::class)->callAction('create', data: [
        'emission_id' => $rule->emission_id, 'name' => 'Sem limite', 'description' => 'Condições',
        'direction' => 'both', 'requires_document' => false, 'effective_from' => '2026-01-01',
    ])->assertHasActionErrors(['maximum_difference_amount', 'maximum_difference_percent']);
    expect(MeasurementFinancialRule::query()->count())->toBe(1);
});

/** @return array<string, mixed> */
function financialRulesPaymentUiScenario(): array
{
    $scenario = Scenario::open();
    $workflow = app(MeasurementWorkflow::class);
    $workflow->returnToStage($scenario['measurement'], $scenario['actor'], 4, 'Complemento para conferência financeira.');
    $scenario['measurement']->refresh();
    test()->actingAs($scenario['actor']);

    return $scenario;
}

it('shows financial validation on the correct payment row and preserves the submitted explanation', function () {
    $s = financialRulesPaymentUiScenario();
    $data = ['pay_date' => '2026-05-20', 'payments' => [[
        'plan_set_id' => $s['payment']->plan_set_id, 'amount' => '100,00',
    ]]];
    Livewire::test(ViewMeasurement::class, ['record' => $s['measurement']->id])
        ->callAction('registerPayment', data: $data)
        ->assertHasActionErrors(['payments.0.financial_justification']);
    expect($s['measurement']->payments()->count())->toBe(1);

    $data['payments'][0]['financial_justification'] = 'Complemento contratual a conferir.';
    Livewire::test(ViewMeasurement::class, ['record' => $s['measurement']->id])
        ->callAction('registerPayment', data: $data)->assertHasNoActionErrors();
    $payment = $s['measurement']->payments()->latest('id')->first();
    expect($payment->financial_assessment['requires_acceptance'])->toBeTrue()
        ->and($payment->financial_assessment['justification'])->toBe('Complemento contratual a conferir.');
});

it('requires unchecked financial acceptance in the finalization action', function () {
    $s = financialRulesPaymentUiScenario();
    $workflow = app(MeasurementWorkflow::class);
    $workflow->registerPayment($s['measurement'], $s['actor'], [
        'plan_set_id' => $s['payment']->plan_set_id, 'pay_date' => '2026-05-20', 'amount' => '100.00',
        'financial_justification' => 'Complemento contratual <script>alert(1)</script>',
    ]);
    $workflow->approve($s['measurement']->fresh(), $s['actor']);
    foreach ($s['measurement']->payments()->get() as $payment) {
        $workflow->attachReceipt($payment, $s['actor'], Scenario::file());
        Scenario::approveCurrentReceipt($payment, $s['actor']);
    }
    $page = Livewire::test(ViewMeasurement::class, ['record' => $s['measurement']->id])
        ->assertSee('Exceção sem regra cadastrada')->assertDontSee('<script>alert(1)</script>', false)
        ->mountAction('finalize')
        ->assertSet('mountedActions.0.data.accept_financial_exceptions', false)
        ->callMountedAction()->assertHasActionErrors(['accept_financial_exceptions']);
    expect($s['measurement']->fresh()->status)->toBe('approved');
    $page->set('mountedActions.0.data.accept_financial_exceptions', true)
        ->callMountedAction()->assertHasNoActionErrors();
    expect($s['measurement']->fresh()->status)->toBe('finalized');
});

it('lets the payment manager reassess a returned payment without changing its amount', function () {
    $s = financialRulesPaymentUiScenario();
    Livewire::test(ViewMeasurement::class, ['record' => $s['measurement']->id])
        ->callAction('reassessPayment', data: [
            'payment_id' => $s['payment']->id, 'financial_justification' => 'Conferência após devolução.',
        ])->assertHasNoActionErrors();
    expect($s['payment']->fresh()->amount)->toBe('1011480.16')
        ->and($s['payment']->fresh()->financial_assessment['justification'])->toBe('Conferência após devolução.');
});
