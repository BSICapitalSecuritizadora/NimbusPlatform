<?php

use App\Enums\MeasurementReceiptReviewStatus;
use App\Exceptions\MeasurementWorkflowException;
use App\Filament\Resources\Measurements\Pages\ViewMeasurement;
use App\Models\Construction;
use App\Models\Emission;
use App\Models\Measurement;
use App\Models\MeasurementFinancialRule;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Models\Operation;
use App\Models\User;
use App\Services\MeasurementFinancialRuleService;
use App\Services\MeasurementPaymentFinancialService;
use App\Services\MeasurementReceiptEvidenceService;
use App\Services\MeasurementWorkflow;
use App\Services\Security\ClamAvFileScanner;
use Database\Seeders\MeasurementFinancialRuleSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\MeasurementReceiptEvidenceScenario;

uses(RefreshDatabase::class);
pest()->group('parity');

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
    config(['filesystems.private_disk' => 'local']);
});

/** @return array<string, mixed> */
function financialRuleScenario(): array
{
    $actor = makeAdminUser();
    $emission = Emission::factory()->create();
    $construction = Construction::factory()->create(['emission_id' => $emission->id]);
    $operation = Operation::factory()->forEmission($emission)->create([
        'assigned_user_id' => $actor->id, 'responsible_user_id' => $actor->id,
        'stage2_reviewer_user_id' => $actor->id, 'stage3_reviewer_user_id' => $actor->id,
        'payment_manager_user_id' => $actor->id, 'payment_receipt_uploader_user_id' => $actor->id,
        'payment_finalizer_user_id' => $actor->id,
    ]);
    $plan = MeasurementPlanSet::factory()->default()->create([
        'operation_id' => $operation->id, 'construction_id' => $construction->id,
        'construction_fund_amount' => '10000.00', 'initial_incurred_amount' => 0,
    ]);
    $line = MeasurementPlanLine::factory()->create([
        'operation_id' => $operation->id, 'plan_set_id' => $plan->id, 'sequence_number' => 1,
        'measurement_date' => '2026-05-01', 'initial_realized_cumulative_percent' => 0,
    ]);
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->id, 'reference_month' => '2026-05-01', 'storage_path' => null,
        'status' => 'pending', 'current_stage' => 1,
    ]);
    $path = 'nimbus_docs/measurements/financial-rule-test.pdf';
    Storage::disk('local')->put($path, '%PDF-1.7 engineering');
    $measurement->assets()->create(['plan_set_id' => $plan->id, 'plan_line_id' => $line->id, 'storage_path' => $path, 'storage_disk' => 'local']);
    $workflow = app(MeasurementWorkflow::class);
    $workflow->startReview($measurement, $actor);
    $workflow->approve($measurement->fresh(), $actor, engineeringProgress: [$plan->id => 10]);
    $workflow->approve($measurement->fresh(), $actor);
    $workflow->approve($measurement->fresh(), $actor);
    $measurement->refresh();
    $rule = MeasurementFinancialRule::factory()->create([
        'emission_id' => $emission->id, 'construction_id' => $construction->id,
        'maximum_difference_amount' => '100.00', 'maximum_difference_percent' => '10.0000', 'created_by' => $actor->id,
    ]);
    $data = ['plan_set_id' => $plan->id, 'pay_date' => '2026-05-20', 'amount' => '900.00',
        'financial_rule_id' => $rule->id, 'financial_justification' => 'Retenção conforme contrato da emissão.'];

    return compact('actor', 'emission', 'construction', 'operation', 'plan', 'measurement', 'workflow', 'rule', 'data');
}

it('requires permission and validates the emission construction and validity of a rule', function () {
    $actor = makeAdminUser();
    $service = app(MeasurementFinancialRuleService::class);
    $data = MeasurementFinancialRule::factory()->make()->snapshot();
    expect(fn () => $service->register($data, User::factory()->create()))->toThrow(AuthorizationException::class);
    $data['construction_id'] = Construction::factory()->create()->id;
    expect(fn () => $service->register($data, $actor))->toThrow(ValidationException::class);
    $data['construction_id'] = null;
    $data['effective_until'] = '2025-12-31';
    expect(fn () => $service->register($data, $actor))->toThrow(ValidationException::class);
    $data['effective_until'] = '2026-12-31';
    $rule = $service->register($data, $actor);
    expect($rule->version)->toBe(1)->and($rule->created_by)->toBe($actor->id);
});

it('creates immutable versions and preserves the original conditions', function () {
    $scenario = financialRuleScenario();
    $rule = $scenario['rule'];
    $next = app(MeasurementFinancialRuleService::class)->register(array_replace($rule->snapshot(), [
        'maximum_difference_amount' => '200.00',
    ]), $scenario['actor'], $rule);
    expect($next->version)->toBe(2)->and($next->supersedes_id)->toBe($rule->id)
        ->and($rule->fresh()->retired_at)->not->toBeNull()->and($rule->fresh()->maximum_difference_amount)->toBe('100.00');
    expect(fn () => $next->update(['name' => 'Alteração retroativa']))->toThrow(LogicException::class);
    expect(fn () => $rule->delete())->toThrow(LogicException::class);
    expect(fn () => app(MeasurementFinancialRuleService::class)->register($rule->snapshot(), $scenario['actor'], $rule))->toThrow(ValidationException::class);
});

it('only offers available rules for the approved emission and construction on the payment date', function () {
    $s = financialRuleScenario();
    $global = MeasurementFinancialRule::factory()->create(['emission_id' => $s['emission']->id]);
    MeasurementFinancialRule::factory()->create();
    MeasurementFinancialRule::factory()->create(['emission_id' => $s['emission']->id, 'construction_id' => Construction::factory()->create()->id]);
    MeasurementFinancialRule::factory()->create(['emission_id' => $s['emission']->id, 'effective_from' => '2026-06-01']);
    MeasurementFinancialRule::factory()->create(['emission_id' => $s['emission']->id, 'retired_at' => now()]);
    expect(app(MeasurementFinancialRuleService::class)->availableFor($s['measurement'], $s['plan']->id, '2026-05-20')->pluck('id')->all())
        ->toEqualCanonicalizing([$s['rule']->id, $global->id]);
});

it('rejects a divergent payment without justification without changing the workflow', function () {
    $s = financialRuleScenario();
    $data = array_replace($s['data'], ['financial_justification' => '   ']);
    expect(fn () => $s['workflow']->registerPayment($s['measurement'], $s['actor'], $data))->toThrow(ValidationException::class);
    expect($s['measurement']->payments()->count())->toBe(0)
        ->and($s['measurement']->fresh()->workflow_revision)->toBe($s['measurement']->workflow_revision);
});

it('rejects payment amounts that cannot be represented in cents', function (string $amount) {
    $scenario = financialRuleScenario();

    expect(fn () => $scenario['workflow']->registerPayment($scenario['measurement'], $scenario['actor'], array_replace($scenario['data'], [
        'amount' => $amount,
        'financial_rule_id' => null,
    ])))->toThrow(ValidationException::class);

    expect($scenario['measurement']->payments()->count())->toBe(0);
})->with(['900.001', '1e3', '10000000000000000.00']);

it('validates reassessment input before replacing the recorded explanation', function () {
    $scenario = financialRuleScenario();
    $payment = $scenario['workflow']->registerPayment($scenario['measurement'], $scenario['actor'], $scenario['data']);

    expect(fn () => $scenario['workflow']->reassessPayment($payment, $scenario['actor'], [
        'financial_rule_id' => [$scenario['rule']->id],
        'financial_justification' => 'Reavaliação com dados inválidos.',
    ], $scenario['measurement']->fresh()->workflow_revision))->toThrow(ValidationException::class);

    expect($payment->fresh()->financial_assessment)->toEqual($payment->financial_assessment);
});

it('checks rule scope direction limits validity and evidence on the server', function (string $change) {
    $s = financialRuleScenario();
    $data = $s['data'];
    match ($change) {
        'scope' => $data['financial_rule_id'] = MeasurementFinancialRule::factory()->create()->id,
        'amount' => $data['amount'] = '899.99',
        'direction' => $data['amount'] = '1100.00',
        'date' => $data['pay_date'] = '2027-01-01',
        'document' => DB::table('measurement_financial_rules')->where('id', $s['rule']->id)->update(['requires_document' => true]),
        'percent' => DB::table('measurement_financial_rules')->where('id', $s['rule']->id)->update(['maximum_difference_percent' => '9.9999']),
    };
    expect(fn () => $s['workflow']->registerPayment($s['measurement'], $s['actor'], $data))->toThrow(ValidationException::class);
    expect($s['measurement']->payments()->count())->toBe(0);
})->with(['scope', 'amount', 'direction', 'date', 'document', 'percent']);

it('requires explicit acceptance at finalization and freezes the applied version in the audit', function () {
    $s = financialRuleScenario();
    $payment = $s['workflow']->registerPayment($s['measurement'], $s['actor'], $s['data']);
    app(MeasurementFinancialRuleService::class)->retire($s['rule'], $s['actor']);
    $s['workflow']->approve($s['measurement']->fresh(), $s['actor']);
    $evidence = app(MeasurementReceiptEvidenceService::class)->upload($payment, $s['actor'], MeasurementReceiptEvidenceScenario::file());
    app(MeasurementReceiptEvidenceService::class)->review($evidence, $s['actor'], MeasurementReceiptReviewStatus::Approved, true);
    expect(fn () => $s['workflow']->finalize($s['measurement']->fresh(), $s['actor']))->toThrow(ValidationException::class);
    expect($s['measurement']->fresh()->status)->toBe('approved');
    $s['workflow']->finalize($s['measurement']->fresh(), $s['actor'], acceptFinancialExceptions: true);
    $event = Activity::query()->where('description', 'measurement_finalized')->latest('id')->firstOrFail();
    expect($event->causer_id)->toBe($s['actor']->id)
        ->and($event->properties['financial_exceptions_accepted'][0]['assessment']['rule']['version'])->toBe(1)
        ->and($s['measurement']->fresh()->status)->toBe('finalized');
});

it('allows a justified exception without a matching rule and never labels it as a rule match', function () {
    $s = financialRuleScenario();
    $payment = $s['workflow']->registerPayment($s['measurement'], $s['actor'], array_replace($s['data'], ['financial_rule_id' => null]));
    expect($payment->financial_rule_id)->toBeNull()->and($payment->financial_assessment['rule'])->toBeNull()
        ->and($payment->financial_assessment['requires_acceptance'])->toBeTrue();
});

it('does not classify a matched payment as an exception', function () {
    $s = financialRuleScenario();
    $payment = $s['workflow']->registerPayment($s['measurement'], $s['actor'], [
        'plan_set_id' => $s['plan']->id, 'amount' => '1000.00', 'pay_date' => '2026-05-20',
    ]);
    expect($payment->financial_assessment['requires_acceptance'])->toBeFalse();
});

it('refuses finalization of an assessment whose payment was changed', function () {
    $s = financialRuleScenario();
    $payment = $s['workflow']->registerPayment($s['measurement'], $s['actor'], $s['data']);
    DB::table('measurement_payments')->where('id', $payment->id)->update(['amount' => '901.00']);
    expect(fn () => app(MeasurementPaymentFinancialService::class)->acceptForFinalization($s['measurement'], true))
        ->toThrow(MeasurementWorkflowException::class);
    $s['workflow']->reassessPayment($payment->fresh(), $s['actor'], $s['data'], $s['measurement']->fresh()->workflow_revision);
    expect($payment->fresh()->financial_assessment['amount'])->toBe('901.00');
});

it('stores private evidence authorizes downloads and rejects changed bytes', function () {
    $s = financialRuleScenario();
    $this->actingAs($s['actor']);
    $payment = $s['workflow']->registerPayment($s['measurement'], $s['actor'], $s['data'] + [
        'financial_support' => MeasurementReceiptEvidenceScenario::file('contrato.pdf'),
    ]);
    $support = $payment->financial_assessment['support'];
    expect($support['disk'])->toBe('local');
    $url = route('admin.measurements.financial-support.download', $payment);
    $this->get($url)->assertSuccessful();
    $outsider = User::factory()->withTwoFactor()->create();
    $outsider->givePermissionTo('measurements.view');
    $this->actingAs($outsider)->get($url)->assertForbidden();
    Storage::disk('local')->put($support['path'], '%PDF-1.7 changed');
    $this->actingAs($s['actor'])->get($url)->assertNotFound();
});

it('explains damaged support at finalization and allows correction after returning to payment', function () {
    $scenario = financialRuleScenario();
    $workflow = $scenario['workflow'];
    $payment = $workflow->registerPayment($scenario['measurement'], $scenario['actor'], $scenario['data'] + [
        'financial_support' => MeasurementReceiptEvidenceScenario::file('contrato.pdf'),
    ]);
    $originalAssessment = $payment->financial_assessment;
    $workflow->approve($scenario['measurement']->fresh(), $scenario['actor']);
    $evidenceService = app(MeasurementReceiptEvidenceService::class);
    $evidence = $evidenceService->upload($payment, $scenario['actor'], MeasurementReceiptEvidenceScenario::file());
    $evidenceService->review($evidence, $scenario['actor'], MeasurementReceiptReviewStatus::Approved, true);
    Storage::disk('local')->delete($originalAssessment['support']['path']);

    $this->actingAs($scenario['actor']);
    Livewire::test(ViewMeasurement::class, ['record' => $scenario['measurement']->id])
        ->assertSee('-10,00%')
        ->callAction('finalize', data: ['accept_financial_exceptions' => true])
        ->assertNotified(Filament\Notifications\Notification::make()->danger()->title('Ação não concluída.')
            ->body('O documento de suporte do pagamento #'.$payment->id.' está ausente ou inválido. Devolva à etapa Pagamento para substituir o documento.')
            ->persistent());
    expect($scenario['measurement']->fresh()->status)->toBe('approved');

    $workflow->returnToStage($scenario['measurement']->fresh(), $scenario['actor'], MeasurementWorkflow::STAGE_PAYMENT, 'Substituir documento de suporte.');
    $workflow->reassessPayment($payment->fresh(), $scenario['actor'], array_replace($scenario['data'], [
        'financial_justification' => 'Documento substituído após conferência do Finalizador.',
        'financial_support' => MeasurementReceiptEvidenceScenario::file('contrato-corrigido.pdf'),
    ]), $scenario['measurement']->fresh()->workflow_revision);
    $workflow->approve($scenario['measurement']->fresh(), $scenario['actor']);

    expect(fn () => $workflow->finalize($scenario['measurement']->fresh(), $scenario['actor']))->toThrow(ValidationException::class);
    $workflow->finalize($scenario['measurement']->fresh(), $scenario['actor'], acceptFinancialExceptions: true);
    expect($scenario['measurement']->fresh()->status)->toBe('finalized')
        ->and($payment->fresh()->financial_assessment['support']['name'])->toBe('contrato-corrigido.pdf')
        ->and(Activity::query()->where('log_name', 'measurement_payments')->where('subject_id', $payment->id)
            ->where('event', 'created')->firstOrFail()->properties['attributes']['financial_assessment'])->toEqual($originalAssessment);
});

it('blocks infected or unscannable support before registering a payment', function (string $verdict) {
    $s = financialRuleScenario();
    $scanner = Mockery::mock(ClamAvFileScanner::class);
    $scanner->shouldReceive('isEnabled')->andReturnTrue();
    $scanner->shouldReceive('scan')->once()->andReturn($verdict);
    app()->instance(ClamAvFileScanner::class, $scanner);
    expect(fn () => $s['workflow']->registerPayment($s['measurement'], $s['actor'], $s['data'] + [
        'financial_support' => MeasurementReceiptEvidenceScenario::file(),
    ]))->toThrow(ValidationException::class);
    expect($s['measurement']->payments()->count())->toBe(0);
})->with([ClamAvFileScanner::RESULT_INFECTED, ClamAvFileScanner::RESULT_UNAVAILABLE]);

it('compensates support files when another payment in the batch fails validation', function () {
    $s = financialRuleScenario();
    expect(fn () => $s['workflow']->registerPayments($s['measurement'], $s['actor'], [
        $s['data'] + ['financial_support' => MeasurementReceiptEvidenceScenario::file()],
        array_replace($s['data'], ['financial_rule_id' => null, 'financial_justification' => null]),
    ]))->toThrow(ValidationException::class);
    expect($s['measurement']->payments()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles('nimbus_docs/measurements/financial-support'))->toBe([]);
});

it('applies a scoped rule and private document through the payment form', function () {
    $s = financialRuleScenario();
    $this->actingAs($s['actor']);
    Livewire::test(ViewMeasurement::class, ['record' => $s['measurement']->id])
        ->callAction('registerPayment', data: [
            'pay_date' => '2026-05-20', 'payments' => [[
                'plan_set_id' => $s['plan']->id, 'amount' => '900,00',
                'financial_rule_id' => $s['rule']->id, 'financial_justification' => 'Retenção conforme contrato.',
                'financial_support' => [MeasurementReceiptEvidenceScenario::file()],
            ]],
        ])->assertHasNoActionErrors();
    $payment = $s['measurement']->payments()->sole();
    expect($payment->financial_rule_id)->toBe($s['rule']->id)
        ->and($payment->financial_assessment['rule']['version'])->toBe(1)
        ->and($payment->financial_assessment['support']['sha256'])->not->toBeEmpty();
});

it('only reassesses payments for the authorized responsible in the current workflow revision', function () {
    $s = financialRuleScenario();
    $payment = $s['workflow']->registerPayment($s['measurement'], $s['actor'], $s['data']);
    $revision = $s['measurement']->fresh()->workflow_revision;
    $outsider = User::factory()->create();
    $outsider->givePermissionTo('measurements.pay');
    expect(fn () => $s['workflow']->reassessPayment($payment, $outsider, $s['data'], $revision))->toThrow(AuthorizationException::class);
    expect(fn () => $s['workflow']->reassessPayment($payment, $s['actor'], $s['data'], $revision - 1))->toThrow(MeasurementWorkflowException::class);
    $s['workflow']->approve($s['measurement']->fresh(), $s['actor']);
    expect(fn () => $s['workflow']->reassessPayment($payment, $s['actor'], $s['data'], $s['measurement']->fresh()->workflow_revision))
        ->toThrow(MeasurementWorkflowException::class);
    expect($payment->fresh()->financial_assessment)->toEqual($payment->financial_assessment);
});

it('installs rule permissions idempotently without creating business rules', function () {
    $admin = makeAdminUser();
    $seeder = app(MeasurementFinancialRuleSeeder::class);
    $seeder->run();
    $seeder->run();
    expect($admin->can('measurements.financial-rules.manage'))->toBeTrue()
        ->and(MeasurementFinancialRule::query()->count())->toBe(0);
});
