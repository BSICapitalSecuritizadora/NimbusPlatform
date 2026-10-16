<?php

use App\Filament\Resources\Measurements\Pages\ViewMeasurement;
use App\Models\MeasurementFinancialRule;
use App\Models\MeasurementPayment;
use App\Services\MeasurementPaymentFinancialService;
use App\Services\MeasurementWorkflow;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\MeasurementNoDisbursementScenario as Scenario;
use Tests\Support\MeasurementRevisionScenario;

/**
 * O registro de pagamento da medição original sem valor devido como a pessoa o
 * vê: o aviso antes de digitar e a recusa no campo do empreendimento. As
 * regras do domínio estão em MeasurementOriginalPaymentAmountDueTest.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    Notification::fake();
});

/**
 * Chave real (UUID) do item do Repeater de pagamentos do modal montado que
 * corresponde ao empreendimento.
 */
function originalDueUiItemKey(Testable $component, int $planSetId): string
{
    return (string) collect($component->get('mountedActions.0.data.payments') ?? [])
        ->search(fn (array $item): bool => (int) ($item['plan_set_id'] ?? 0) === $planSetId);
}

/**
 * Texto de um Placeholder visível do modal montado -- o modal vai na resposta
 * como JSON escapado, então o conteúdo é lido pelo schema montado. Com
 * `$itemKey`, o do item do Repeater.
 */
function originalDueUiModalText(Testable $component, string $name, ?string $itemKey = null): string
{
    $page = $component->instance();
    /** @var Schema $schema */
    $schema = $page->{$page->getMountedActionSchemaName()};
    $suffix = $itemKey === null ? ".{$name}" : ".{$itemKey}.{$name}";

    foreach ($schema->getFlatComponents() as $key => $item) {
        if (((string) $key === $name || str_ends_with((string) $key, $suffix)) && $item instanceof Placeholder) {
            $text = strip_tags((string) preg_replace('/</', ' <', (string) $item->getContent()));

            return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5)));
        }
    }

    return '';
}

it('tells, before any amount is typed, which construction of an original has nothing due', function () {
    $scenario = Scenario::plan([['fund' => '1000000.00'], ['fund' => '800000.00']]);
    $measurement = Scenario::awaitingPayment($scenario, '2026-05', [0, 10]);
    $this->actingAs($scenario['actor']);

    $component = Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->mountAction('registerPayment')
        ->assertActionMounted('registerPayment');

    $zeroDue = originalDueUiItemKey($component, $scenario['planSets'][0]->id);
    $payable = originalDueUiItemKey($component, $scenario['planSets'][1]->id);

    expect(originalDueUiModalText($component, 'reconciliation_divergence', $zeroDue))
        ->toBe('Nada é devido neste empreendimento (valor devido R$ 0,00): deixe o valor em branco. Um pagamento aqui só é registrado com uma regra financeira que o cubra.')
        ->and(originalDueUiModalText($component, 'reconciliation_divergence', $payable))
        ->toBe('Informe o valor para comparar com o saldo esperado.');
});

it('shows the amount typed for a construction with nothing due as a divergence only a rule can cover', function () {
    $scenario = Scenario::plan([['fund' => '1000000.00'], ['fund' => '800000.00']]);
    $measurement = Scenario::awaitingPayment($scenario, '2026-05', [0, 10]);
    $this->actingAs($scenario['actor']);
    $component = Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->mountAction('registerPayment');
    $zeroDue = originalDueUiItemKey($component, $scenario['planSets'][0]->id);

    $component->set("mountedActions.0.data.payments.{$zeroDue}.amount", '1.000,00');

    expect(originalDueUiModalText($component, 'reconciliation_divergence', $zeroDue))
        ->toBe('Nada é devido neste empreendimento (valor devido R$ 0,00): o valor informado é divergência de R$ 1.000,00 acima do valor devido e só é registrado com uma regra financeira que a cubra.');

    // Zero não é pagamento: volta a orientação do campo em branco.
    $component->set("mountedActions.0.data.payments.{$zeroDue}.amount", '0,00');

    expect(originalDueUiModalText($component, 'reconciliation_divergence', $zeroDue))
        ->toBe('Nada é devido neste empreendimento (valor devido R$ 0,00): deixe o valor em branco. Um pagamento aqui só é registrado com uma regra financeira que o cubra.');
});

it('keeps the payment preview of a revision as it was, without the texts of the original', function () {
    // A R1 aprova R$ 80.000,00 sobre R$ 100.000,00 que a R0 pagou.
    $scenario = MeasurementRevisionScenario::plan();
    $revision = MeasurementRevisionScenario::effective($scenario, MeasurementRevisionScenario::finalized($scenario, '2026-05', 10), 8);
    $this->actingAs($scenario['actor']);
    $component = Livewire::test(ViewMeasurement::class, ['record' => $revision->getRouteKey()])
        ->mountAction('registerPayment');
    $item = originalDueUiItemKey($component, $scenario['planSet']->id);

    expect(originalDueUiModalText($component, 'reconciliation_divergence', $item))
        ->toBe('Informe o valor para comparar com o saldo esperado.');

    $component->set("mountedActions.0.data.payments.{$item}.amount", '1.000,00');

    expect(originalDueUiModalText($component, 'reconciliation_divergence', $item))
        ->toBe('Divergência para mais — R$ 21.000,00 acima do saldo esperado.');
});

it('sends a paid original whose engineering record is not verifiable back to Engineering in the preview', function () {
    $scenario = Scenario::plan();
    $measurement = Scenario::awaitingPayment($scenario, '2026-05', [10]);
    app(MeasurementWorkflow::class)->registerPayment($measurement->fresh(), $scenario['actor'], [
        'plan_set_id' => $scenario['planSets'][0]->id, 'pay_date' => '2026-06-10', 'amount' => '60000.00',
        'financial_justification' => 'Primeira parcela.',
    ]);
    // Registro sem a versão de esquema: não verificável, a medição paga ainda
    // volta à Engenharia para ser corrigida.
    $snapshot = $measurement->fresh()->engineering_snapshot;
    unset($snapshot['schema_version']);
    DB::table('measurements')->where('id', $measurement->id)->update(['engineering_snapshot' => json_encode($snapshot)]);
    $this->actingAs($scenario['actor']);
    $component = Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->mountAction('registerPayment');

    expect(originalDueUiModalText($component, 'reconciliation_divergence', originalDueUiItemKey($component, $scenario['planSets'][0]->id)))
        ->toBe('O valor devido desta medição não pode ser determinado: nenhum pagamento é registrado até o registro da Engenharia ser corrigido.');
});

it('adds what was already paid above the approved amount to the divergence a rule must cover', function () {
    $scenario = Scenario::plan();
    $measurement = Scenario::awaitingPayment($scenario, '2026-05', [10]);
    // Fase 4B-1: o excedente já registrado só entrou com regra que o cobria.
    $rule = MeasurementFinancialRule::factory()->create([
        'emission_id' => $measurement->operation->emission_id,
        'direction' => 'over',
        'maximum_difference_amount' => '20000.00',
    ]);
    app(MeasurementWorkflow::class)->registerPayment($measurement->fresh(), $scenario['actor'], [
        'plan_set_id' => $scenario['planSets'][0]->id, 'pay_date' => '2026-06-10', 'amount' => '120000.00',
        'financial_rule_id' => $rule->id,
        'financial_justification' => 'Medição complementar paga junto.',
    ]);
    $this->actingAs($scenario['actor']);
    $component = Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->mountAction('registerPayment');
    $item = originalDueUiItemKey($component, $scenario['planSets'][0]->id);

    $component->set("mountedActions.0.data.payments.{$item}.amount", '20.000,00');

    expect(originalDueUiModalText($component, 'reconciliation_divergence', $item))
        ->toBe('Nada é devido neste empreendimento (valor devido R$ 0,00): com o valor informado, a divergência a cobrir chega a R$ 40.000,00 (R$ 20.000,00 já registrados acima do aprovado), e o pagamento só é registrado com uma regra financeira que a cubra.');
});

it('keeps the paid original out of the Engineering path in the preview of an undetermined position', function () {
    $scenario = Scenario::plan();
    $measurement = Scenario::awaitingPayment($scenario, '2026-05', [10]);
    app(MeasurementWorkflow::class)->registerPayment($measurement->fresh(), $scenario['actor'], [
        'plan_set_id' => $scenario['planSets'][0]->id, 'pay_date' => '2026-06-10', 'amount' => '60000.00',
        'financial_justification' => 'Primeira parcela.',
    ]);
    $snapshot = $measurement->fresh()->engineering_snapshot;
    $snapshot['plan_sets'][0]['realized_monthly_percent'] = null;
    DB::table('measurements')->where('id', $measurement->id)->update(['engineering_snapshot' => json_encode($snapshot)]);
    $this->actingAs($scenario['actor']);
    $component = Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->mountAction('registerPayment');

    expect(originalDueUiModalText($component, 'reconciliation_divergence', originalDueUiItemKey($component, $scenario['planSets'][0]->id)))
        ->toBe('O valor devido desta medição não pode ser determinado: nenhum outro pagamento é registrado nela. A correção dos dados aprovados é uma revisão formal, depois da aprovação da etapa Pagamento e da conferência dos comprovantes.');
});

it('warns about an undetermined position also on the construction without Construction Fund, whose payment is refused too', function () {
    $scenario = Scenario::plan([['fund' => null], ['fund' => '1000000.00']]);
    $measurement = Scenario::awaitingPayment($scenario, '2026-05', [10, 10]);
    $snapshot = $measurement->engineering_snapshot;
    $snapshot['plan_sets'][1]['realized_monthly_percent'] = null;
    DB::table('measurements')->where('id', $measurement->id)->update(['engineering_snapshot' => json_encode($snapshot)]);
    $this->actingAs($scenario['actor']);
    $component = Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->mountAction('registerPayment');
    $withoutFund = originalDueUiItemKey($component, $scenario['planSets'][0]->id);

    expect(originalDueUiModalText($component, 'reconciliation_divergence', $withoutFund))
        ->toBe('O valor devido desta medição não pode ser determinado: nenhum pagamento é registrado até o registro da Engenharia ser corrigido.');

    $component->set("mountedActions.0.data.payments.{$withoutFund}.amount", '1.000,00')->callMountedAction();

    expect($component->errors()->get("mountedActions.0.data.payments.{$withoutFund}.amount")[0] ?? null)
        ->toStartWith('Nenhum pagamento é registrado sem a posição financeira desta medição.')
        ->and(MeasurementPayment::query()->count())->toBe(0);
});

it('says in the reassessment of an original and of a revision that a blank rule keeps the current one', function () {
    // Fase 4B-1: a reavaliação é uma só na original e na revisão -- a conta é
    // a do registro, e em branco a regra atual é mantida.
    $description = 'Atualize a regra, a justificativa ou o documento de suporte de um pagamento existente após uma devolução. O valor do pagamento permanece preservado, e a conta é a do registro: os pagamentos registrados depois dele não mudam o enquadramento. Sem regra escolhida, o pagamento mantém a regra atual dele; uma regra registrada depois do pagamento não o autoriza retroativamente. Quem reavalia passa a responder pelo enquadramento e não aceita a exceção na Finalização.';
    $scenario = Scenario::plan();
    $measurement = Scenario::awaitingPayment($scenario, '2026-05', [10]);
    app(MeasurementWorkflow::class)->registerPayment($measurement->fresh(), $scenario['actor'], [
        'plan_set_id' => $scenario['planSets'][0]->id, 'pay_date' => '2026-06-10', 'amount' => '100000.00',
    ]);
    $this->actingAs($scenario['actor']);

    Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->assertActionExists('reassessPayment', fn (Action $action): bool => $action->getModalDescription() === $description);

    $revisionScenario = MeasurementRevisionScenario::plan();
    $revision = MeasurementRevisionScenario::effective($revisionScenario, MeasurementRevisionScenario::finalized($revisionScenario, '2026-05', 10), 12);
    MeasurementRevisionScenario::pay($revisionScenario, $revision, '20000.00');
    $this->actingAs($revisionScenario['actor']);

    Livewire::test(ViewMeasurement::class, ['record' => $revision->getRouteKey()])
        ->assertActionExists('reassessPayment', fn (Action $action): bool => $action->getModalDescription() === $description);
});

it('tells in the payment modal that no payment is registered while the amount due cannot be determined', function () {
    $scenario = Scenario::plan();
    $measurement = Scenario::awaitingPayment($scenario, '2026-05', [10]);
    $snapshot = $measurement->engineering_snapshot;
    // Sem o percentual do mês, a conciliação leria 0%: o valor devido não se
    // determina, e nenhum pagamento é registrado.
    $snapshot['plan_sets'][0]['realized_monthly_percent'] = null;
    DB::table('measurements')->where('id', $measurement->id)->update(['engineering_snapshot' => json_encode($snapshot)]);
    $this->actingAs($scenario['actor']);
    $component = Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->mountAction('registerPayment');
    $item = originalDueUiItemKey($component, $scenario['planSets'][0]->id);

    expect(originalDueUiModalText($component, 'reconciliation_divergence', $item))
        ->toBe('O valor devido desta medição não pode ser determinado: nenhum pagamento é registrado até o registro da Engenharia ser corrigido.');

    $component
        ->set("mountedActions.0.data.payments.{$item}.amount", '100.000,00')
        ->set("mountedActions.0.data.payments.{$item}.financial_justification", 'Pagamento da competência.')
        ->callMountedAction();

    expect($component->errors()->get("mountedActions.0.data.payments.{$item}.amount"))
        ->toBe(['Nenhum pagamento é registrado sem a posição financeira desta medição. Torre A: percentual realizado do mês ausente ou ilegível no registro da Engenharia — o valor devido não pode ser determinado. Corrija o registro da Engenharia, devolvendo a medição àquela etapa.'])
        ->and(MeasurementPayment::query()->count())->toBe(0);
});

it('warns in the registration of a zero-due original that a payment needs a financial rule that covers it', function () {
    $scenario = Scenario::plan();
    $measurement = Scenario::awaitingPayment($scenario, '2026-05', [0]);
    $this->actingAs($scenario['actor']);

    $component = Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->mountAction('registerPayment')
        ->assertActionMounted('registerPayment');

    expect(originalDueUiModalText($component, 'nothing_due'))
        ->toBe('Nenhum pagamento devido: o valor aprovado pela Engenharia é zero em todos os empreendimentos. Para concluir a etapa sem pagamento, use "Concluir etapa sem desembolso". Sem valor devido, um pagamento só é registrado com uma regra financeira que o cubra. Um pagamento registrado não pode ser alterado nem excluído.');
});

it('refuses on the amount of the zero-due construction a payment justified without rule, and registers nothing', function () {
    $scenario = Scenario::plan([['fund' => '1000000.00'], ['fund' => '800000.00']]);
    $measurement = Scenario::awaitingPayment($scenario, '2026-05', [0, 10]);
    $this->actingAs($scenario['actor']);
    $component = Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->mountAction('registerPayment');
    $zeroDue = originalDueUiItemKey($component, $scenario['planSets'][0]->id);
    $payable = originalDueUiItemKey($component, $scenario['planSets'][1]->id);

    $component
        ->set("mountedActions.0.data.payments.{$zeroDue}.amount", '1.000,00')
        ->set("mountedActions.0.data.payments.{$zeroDue}.financial_justification", 'Adiantamento pedido pela construtora.')
        ->set("mountedActions.0.data.payments.{$payable}.amount", '80.000,00')
        ->callMountedAction();

    // A mensagem tem ':' -- lida do error bag, não pelo assertHasErrors.
    expect($component->errors()->get("mountedActions.0.data.payments.{$zeroDue}.amount"))
        ->toBe([MeasurementPaymentFinancialService::EXCESS_WITHOUT_RULE_REFUSAL.' Torre A: valor aprovado R$ 0,00, já registrado R$ 0,00, saldo a pagar R$ 0,00; com este pagamento, o excedente acumulado chega a R$ 1.000,00. Acima do saldo, o pagamento só é registrado com uma regra financeira vigente que cubra o excedente acumulado; a justificativa sozinha não basta.'])
        ->and(MeasurementPayment::query()->count())->toBe(0);

    $component->assertActionMounted('registerPayment');
});

it('registers through the modal a zero-due payment covered by a financial rule of the emission', function () {
    $scenario = Scenario::plan();
    $measurement = Scenario::awaitingPayment($scenario, '2026-05', [0]);
    $rule = MeasurementFinancialRule::factory()->create([
        'emission_id' => $measurement->operation->emission_id,
        'name' => 'Adiantamento contratual',
        'direction' => 'over',
        'maximum_difference_amount' => '5000.00',
    ]);
    $this->actingAs($scenario['actor']);
    $component = Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->mountAction('registerPayment');
    $item = originalDueUiItemKey($component, $scenario['planSets'][0]->id);

    $component
        ->set('mountedActions.0.data.pay_date', '2026-06-10')
        ->set("mountedActions.0.data.payments.{$item}.amount", '5.000,00')
        ->set("mountedActions.0.data.payments.{$item}.financial_rule_id", $rule->id)
        ->set("mountedActions.0.data.payments.{$item}.financial_justification", 'Adiantamento previsto no contrato da emissão.')
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(MeasurementPayment::query()->sole()->only(['plan_set_id', 'amount', 'financial_rule_id']))->toBe([
        'plan_set_id' => $scenario['planSets'][0]->id,
        'amount' => '5000.00',
        'financial_rule_id' => $rule->id,
    ]);
});
