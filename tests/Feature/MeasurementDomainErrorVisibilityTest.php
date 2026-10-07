<?php

use App\Exceptions\MeasurementWorkflowException;
use App\Filament\Resources\Measurements\MeasurementResource;
use App\Filament\Resources\Measurements\Pages\CreateMeasurement;
use App\Filament\Resources\Measurements\Pages\EditMeasurement;
use App\Filament\Resources\Measurements\Pages\ViewMeasurement;
use App\Models\Construction;
use App\Models\Measurement;
use App\Models\MeasurementAsset;
use App\Models\MeasurementPayment;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Models\Operation;
use App\Models\User;
use App\Services\DocumentStorageService;
use App\Services\MeasurementAuthorizationService;
use App\Services\MeasurementFileValidationService;
use App\Services\MeasurementReceiptEvidenceService;
use App\Services\MeasurementWorkflow;
use App\Services\Security\ClamAvFileScanner;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Action;
use Filament\Forms\Components\Contracts\HasNestedRecursiveValidationRules;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Schemas\Schema;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\DatabaseConcurrencyFailure;
use Tests\Support\MeasurementPhysicalProgressScenario as PhysicalScenario;
use Tests\Support\MeasurementReceiptEvidenceScenario as ReceiptScenario;

/**
 * Nenhuma recusa da Medição pode sumir.
 *
 * O Filament só desenha um erro no campo cujo statePath bate exatamente com a
 * chave -- ou com o prefixo `campo.` nos campos que validam itens aninhados --,
 * não tem área genérica de erros e o Livewire guarda o resto no error bag sem
 * mostrar nada. Uma recusa do domínio com chave sem campo no modal (arquivo da
 * Engenharia ausente, `payments` na etapa Pagamento, comprovante sumido, o
 * antivírus no envio) virava um clique que não fazia nada.
 *
 * A propriedade que este arquivo fixa: todo erro que fica no bag pertence a um
 * campo visível do schema montado, e o resto chega à pessoa como notificação.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    Notification::fake();
});

/**
 * O schema que a pessoa está vendo: o modal da ação montada, por padrão.
 */
function domainErrorMountedSchema(Testable $component): Schema
{
    $page = $component->instance();

    return $page->{$page->getMountedActionSchemaName()};
}

/**
 * Chaves do error bag que nenhum campo visível desenha -- exatamente a regra
 * do Filament: statePath igual, ou prefixo `campo.` só nos campos de itens
 * aninhados. Campo oculto (`Hidden`) não desenha erro.
 *
 * @return list<string>
 */
function domainErrorKeysWithoutField(Testable $component, ?Schema $schema = null): array
{
    $fields = collect(($schema ?? domainErrorMountedSchema($component))->getFlatFields())
        ->reject(fn (Field $field): bool => $field instanceof Hidden);

    return collect($component->errors()->keys())
        ->reject(fn (string $key): bool => $fields->contains(
            fn (Field $field): bool => ($key === $field->getStatePath())
                || (($field instanceof HasNestedRecursiveValidationRules) && str_starts_with($key, $field->getStatePath().'.')),
        ))
        ->values()
        ->all();
}

/**
 * Componente visível do modal montado pelo fim do caminho (`notes`,
 * `unpaid_plan_sets`...), ou `null` quando ele não aparece.
 */
function domainErrorModalComponent(Testable $component, string $name): mixed
{
    foreach (domainErrorMountedSchema($component)->getFlatComponents() as $key => $item) {
        if (($key === $name) || str_ends_with((string) $key, ".{$name}")) {
            return $item;
        }
    }

    return null;
}

/**
 * Texto de um Placeholder do modal. O modal vai na resposta como JSON
 * escapado, então o conteúdo é lido pelo schema montado.
 */
function domainErrorModalText(Testable $component, string $name): string
{
    $placeholder = domainErrorModalComponent($component, $name);

    if (! $placeholder instanceof Placeholder) {
        return '';
    }

    return trim((string) preg_replace('/\s+/', ' ', strip_tags((string) $placeholder->getContent())));
}

/**
 * Notificações do Filament na sessão, lidas sem consumir -- o
 * `assertNotified()` compara só o título e esvazia a sessão.
 *
 * @return list<array<string, mixed>>
 */
function domainErrorNotifications(): array
{
    return array_values(session()->get('filament.claimed_notifications') ?? session()->get('filament.notifications') ?? []);
}

function domainErrorNotificationBody(string $title): ?string
{
    $body = collect(domainErrorNotifications())->firstWhere('title', $title)['body'] ?? null;

    return $body === null ? null : (string) $body;
}

/**
 * Registros `critical` emitidos a partir daqui.
 *
 * @return ArrayObject<int, array{message: string, context: array<string, mixed>}>
 */
function domainErrorCriticalLogs(): ArrayObject
{
    $logs = new ArrayObject;

    Log::listen(function (MessageLogged $logged) use ($logs): void {
        if ($logged->level === 'critical') {
            $logs->append(['message' => $logged->message, 'context' => $logged->context]);
        }
    });

    return $logs;
}

/**
 * Registros `warning` emitidos a partir daqui -- as recusas de integridade de
 * arquivo, que a pessoa lê como notificação e a operação lê no log.
 *
 * @return ArrayObject<int, array{message: string, context: array<string, mixed>}>
 */
function domainErrorWarningLogs(): ArrayObject
{
    $logs = new ArrayObject;

    Log::listen(function (MessageLogged $logged) use ($logs): void {
        if ($logged->level === 'warning') {
            $logs->append(['message' => $logged->message, 'context' => $logged->context]);
        }
    });

    return $logs;
}

/**
 * Medição de dois empreendimentos com os comprovantes conferidos, pronta para
 * a Finalização: Torre Alfa paga R$ 1.000,00 e Torre Beta, R$ 2.000,00.
 *
 * @return array{actor: User, measurement: Measurement, alfa: MeasurementPlanSet, beta: MeasurementPlanSet, payments: list<MeasurementPayment>}
 */
function domainErrorReadyToFinalize(): array
{
    $scenario = domainErrorTwoDevelopments();
    $workflow = app(MeasurementWorkflow::class);
    $payments = [];

    foreach (['alfa' => '1000.00', 'beta' => '2000.00'] as $development => $amount) {
        $payments[] = $workflow->registerPayment($scenario['measurement']->fresh(), $scenario['actor'], [
            'plan_set_id' => $scenario[$development]->id,
            'pay_date' => '2026-08-31',
            'amount' => $amount,
        ]);
    }

    $workflow->approve($scenario['measurement']->fresh(), $scenario['actor']);

    foreach ($payments as $payment) {
        $workflow->attachReceipt($payment->fresh(), $scenario['actor'], ReceiptScenario::file());
        ReceiptScenario::approveCurrentReceipt($payment, $scenario['actor']);
    }

    return $scenario + ['payments' => $payments];
}

/**
 * Antivírus ligado que responde `$verdict` às duas varreduras do módulo.
 */
function domainErrorScanner(string $verdict): void
{
    $scanner = Mockery::mock(ClamAvFileScanner::class);
    $scanner->shouldReceive('isEnabled')->andReturnTrue();
    $scanner->shouldReceive('scan')->andReturn($verdict);
    $scanner->shouldReceive('scanStream')->andReturn($verdict);
    app()->instance(ClamAvFileScanner::class, $scanner);
}

/**
 * O envio de comprovante falha com `$failure`; o resto do serviço é o real.
 */
function domainErrorReceiptUploadFailsWith(Throwable $failure): void
{
    $service = Mockery::mock(MeasurementReceiptEvidenceService::class, [
        app(MeasurementAuthorizationService::class),
        app(DocumentStorageService::class),
        app(MeasurementFileValidationService::class),
    ])->makePartial();
    $service->shouldReceive('upload')->andThrow($failure);
    app()->instance(MeasurementReceiptEvidenceService::class, $service);
}

/**
 * Medição de um empreendimento, devolvida pela Finalização à etapa Pagamento
 * com o pagamento original (conciliado) já registrado.
 *
 * @return array{actor: User, operation: Operation, measurement: Measurement, payment: MeasurementPayment}
 */
function domainErrorPaymentStage(): array
{
    $scenario = ReceiptScenario::open();
    app(MeasurementWorkflow::class)->returnToStage($scenario['measurement']->fresh(), $scenario['actor'], MeasurementWorkflow::STAGE_PAYMENT, 'Complemento para conferência financeira.');
    $scenario['measurement']->refresh();
    test()->actingAs($scenario['actor']);

    return $scenario;
}

/**
 * Chave real do único item do Repeater de pagamentos do modal montado.
 */
function domainErrorPaymentItemKey(Testable $component): string
{
    return (string) array_key_first($component->get('mountedActions.0.data.payments') ?? []);
}

/**
 * Torre Alfa espera R$ 1.000,00 e Torre Beta, R$ 2.000,00 (fundos de
 * R$ 10.000,00 e R$ 20.000,00 a 10%). Engenharia, Gestão e Compliance
 * aprovadas: a medição espera a etapa Pagamento.
 *
 * @return array{actor: User, measurement: Measurement, alfa: MeasurementPlanSet, beta: MeasurementPlanSet}
 */
function domainErrorTwoDevelopments(): array
{
    config()->set('filesystems.private_disk', 'local');
    $actor = User::factory()->withTwoFactor()->create();
    $actor->givePermissionTo(['measurements.view', 'measurements.create', 'measurements.review', 'measurements.pay', 'measurements.receipts', 'measurements.finalize']);
    $operation = Operation::factory()->create([
        'status' => 'active',
        'assigned_user_id' => $actor->id,
        'responsible_user_id' => $actor->id,
        'stage2_reviewer_user_id' => $actor->id,
        'stage3_reviewer_user_id' => $actor->id,
        'payment_manager_user_id' => $actor->id,
        'payment_receipt_uploader_user_id' => $actor->id,
        'payment_finalizer_user_id' => $actor->id,
    ]);
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->id,
        'reference_month' => '2026-08-01',
        'status' => 'pending',
        'current_stage' => 1,
        'storage_path' => null,
        'filename' => null,
        'uploaded_by' => $actor->id,
    ]);
    $planSets = [];

    foreach (['Torre Alfa' => '10000.00', 'Torre Beta' => '20000.00'] as $name => $fund) {
        $planSet = MeasurementPlanSet::factory()->create([
            'operation_id' => $operation->id,
            'construction_id' => Construction::factory()->create(['development_name' => $name])->id,
            'name' => $name,
            'is_default' => $planSets === [],
            'construction_fund_amount' => $fund,
            'initial_incurred_amount' => '0.00',
        ]);
        $line = MeasurementPlanLine::factory()->create([
            'operation_id' => $operation->id,
            'plan_set_id' => $planSet->id,
            'sequence_number' => 1,
            'initial_realized_cumulative_percent' => 0,
            'measurement_date' => '2026-08-01',
        ]);
        $path = "nimbus_docs/measurements/assets/visibility-{$measurement->id}-{$planSet->id}.pdf";
        Storage::disk('local')->put($path, "%PDF-1.7 {$name}");
        $measurement->assets()->create(['plan_set_id' => $planSet->id, 'plan_line_id' => $line->id, 'storage_path' => $path, 'storage_disk' => 'local']);
        $planSets[] = $planSet;
    }

    $workflow = app(MeasurementWorkflow::class);
    $workflow->startReview($measurement->fresh(), $actor);
    $workflow->approve($measurement->fresh(), $actor, engineeringProgress: [$planSets[0]->id => 10, $planSets[1]->id => 10]);
    $workflow->approve($measurement->fresh(), $actor);
    $workflow->approve($measurement->fresh(), $actor);
    test()->actingAs($actor);

    return ['actor' => $actor, 'measurement' => $measurement->fresh(), 'alfa' => $planSets[0], 'beta' => $planSets[1]];
}

/**
 * A Torre Alfa recebe o valor esperado; a Torre Beta fica sem pagamento.
 *
 * @param  array{actor: User, measurement: Measurement, alfa: MeasurementPlanSet, beta: MeasurementPlanSet}  $scenario
 */
function domainErrorPayOnlyAlfa(array $scenario): MeasurementPayment
{
    return app(MeasurementWorkflow::class)->registerPayment($scenario['measurement']->fresh(), $scenario['actor'], [
        'plan_set_id' => $scenario['alfa']->id,
        'pay_date' => '2026-08-31',
        'amount' => '1000.00',
    ]);
}

/**
 * Formulário de Enviar Medição preenchido para a linha de maio do plano.
 *
 * @param  array{actor: User, operation: Operation, planSet: MeasurementPlanSet, lines: array<string, MeasurementPlanLine>}  $scenario
 */
function domainErrorFilledCreateForm(array $scenario, string $content): Testable
{
    $component = Livewire::test(CreateMeasurement::class)
        ->fillForm(['operation_id' => $scenario['operation']->id]);
    $assetKey = (string) array_key_first($component->get('data.assets'));

    return $component->fillForm([
        'reference_month' => '2026-05-01',
        'assets' => [$assetKey => [
            'plan_set_id' => $scenario['planSet']->id,
            'plan_line_id' => $scenario['lines']['2026-05']->id,
            'storage_path' => [UploadedFile::fake()->createWithContent('medicao.pdf', $content)],
        ]],
    ]);
}

// ── Recusas do domínio nos modais ────────────────────────────────────────────

it('explains an Engineering refusal that has no field and keeps the modal open', function () {
    $scenario = PhysicalScenario::plan();
    $measurement = PhysicalScenario::measurement($scenario, '2026-05');
    Storage::disk('local')->delete($measurement->assets()->sole()->storage_path);
    $this->actingAs($scenario['actor']);

    $component = Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->callAction('approve', data: ['realized' => [$scenario['planSet']->id => 10]]);

    expect(domainErrorNotificationBody('Ação não concluída.'))->toBe('O arquivo de medição de Plano padrão não foi encontrado no armazenamento.')
        ->and($component->errors()->keys())->toBe([])
        ->and(domainErrorKeysWithoutField($component))->toBe([]);

    $component->assertActionMounted('approve')->assertNotified('Ação não concluída.');

    expect($measurement->fresh()->current_stage)->toBe(MeasurementWorkflow::STAGE_ENGINEERING)
        ->and($measurement->fresh()->engineering_snapshot)->toBeNull();
});

it('keeps an Engineering refusal that has a field under the development field, without a notification', function () {
    $scenario = PhysicalScenario::plan();
    $may = PhysicalScenario::measured($scenario, '2026-05', 10);
    $second = PhysicalScenario::measurement($scenario, '2026-05');
    $this->actingAs($scenario['actor']);
    $field = "mountedActions.0.data.realized.{$scenario['planSet']->id}";

    $component = Livewire::test(ViewMeasurement::class, ['record' => $second->getRouteKey()])
        ->callAction('approve', data: ['realized' => [$scenario['planSet']->id => 4]]);

    expect($component->errors()->get($field))->toBe([
        "A medição 01 (05/2026) do cronograma de Plano padrão já está vinculada à medição #{$may->id}, aprovada pela Engenharia. Recuse esta medição ou corrija a linha do cronograma escolhida no envio.",
    ])
        ->and(domainErrorKeysWithoutField($component))->toBe([])
        ->and(domainErrorNotifications())->toBe([]);

    $component->assertActionMounted('approve');
});

it('puts the field errors on their fields and the rest in a single notification', function () {
    $scenario = PhysicalScenario::plan();
    PhysicalScenario::measured($scenario, '2026-05', 10);
    $second = PhysicalScenario::measurement($scenario, '2026-05');
    DB::table('measurements')->where('id', $second->id)->update(['reference_month' => null]);
    $this->actingAs($scenario['actor']);
    $field = "mountedActions.0.data.realized.{$scenario['planSet']->id}";

    $component = Livewire::test(ViewMeasurement::class, ['record' => $second->getRouteKey()])
        ->callAction('approve', data: ['realized' => [$scenario['planSet']->id => 4]]);

    expect($component->errors()->keys())->toBe([$field])
        ->and(domainErrorKeysWithoutField($component))->toBe([])
        ->and(domainErrorNotifications())->toHaveCount(1)
        ->and(domainErrorNotificationBody('Ação não concluída.'))->toBe('Informe a competência da medição antes de aprovar a Engenharia.');

    expect($second->fresh()->engineering_snapshot)->toBeNull();
});

it('tells to register a payment, without offering the justification, when the Payment stage has no payment', function () {
    $scenario = PhysicalScenario::plan();
    $measurement = PhysicalScenario::measured($scenario, '2026-05', 10);
    app(MeasurementWorkflow::class)->approve($measurement->fresh(), $scenario['actor']);
    app(MeasurementWorkflow::class)->approve($measurement->fresh(), $scenario['actor']);
    $this->actingAs($scenario['actor']);

    $component = Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->mountAction('approve');

    // A justificativa da ausência não resolve a falta de pagamento: o modal
    // não a oferece, nem a lista que mandava justificar.
    expect(domainErrorModalText($component, 'payment_required'))->toBe('Cadastre ao menos um pagamento antes de aprovar a etapa Pagamento.')
        ->and(domainErrorModalComponent($component, 'notes'))->toBeNull()
        ->and(domainErrorModalComponent($component, 'unpaid_plan_sets'))->toBeNull();

    $component->callMountedAction();

    expect(domainErrorNotificationBody('Ação não concluída.'))->toBe('Cadastre ao menos um pagamento válido antes de aprovar a etapa Pagamento.')
        ->and($component->errors()->keys())->toBe([])
        ->and(domainErrorKeysWithoutField($component))->toBe([]);

    $component->assertActionMounted('approve');

    expect($measurement->fresh()->status)->toBe('awaiting_payment')
        ->and($measurement->fresh()->reviewForStage(MeasurementWorkflow::STAGE_PAYMENT)?->status)->toBe('pending');
});

it('shows the financial validation of a payment on the real row of the repeater', function () {
    $scenario = domainErrorPaymentStage();

    $component = Livewire::test(ViewMeasurement::class, ['record' => $scenario['measurement']->getRouteKey()])
        ->mountAction('registerPayment');
    $key = domainErrorPaymentItemKey($component);

    $component->set("mountedActions.0.data.payments.{$key}.amount", '100,00')
        ->callMountedAction();

    expect($key)->not->toBe('')->not->toBe('0')
        ->and($component->errors()->get("mountedActions.0.data.payments.{$key}.financial_justification"))
        ->toBe(['Explique a divergência financeira para a conferência do Finalizador.'])
        ->and(domainErrorKeysWithoutField($component))->toBe([])
        ->and(domainErrorNotifications())->toBe([])
        ->and($scenario['measurement']->payments()->count())->toBe(1);
});

it('puts the refusal of a field shared by every payment row on that single field of the modal', function () {
    $scenario = domainErrorPaymentStage();

    $component = Livewire::test(ViewMeasurement::class, ['record' => $scenario['measurement']->getRouteKey()])
        ->mountAction('registerPayment');
    $key = domainErrorPaymentItemKey($component);

    $component->fillForm([
        'method' => str_repeat('T', 256),
        'payments' => [$key => ['amount' => '100,00', 'financial_justification' => 'Complemento contratual a conferir.']],
    ])->callMountedAction();

    expect($component->errors()->toArray())->toBe(['mountedActions.0.data.method' => ['O campo método não deve ser maior que 255 caracteres.']])
        ->and(domainErrorKeysWithoutField($component))->toBe([])
        ->and(domainErrorNotifications())->toBe([])
        ->and($scenario['measurement']->payments()->count())->toBe(1);
});

it('refuses a zero payment amount on the row itself, in plain words', function () {
    $scenario = domainErrorPaymentStage();

    $component = Livewire::test(ViewMeasurement::class, ['record' => $scenario['measurement']->getRouteKey()])
        ->mountAction('registerPayment');
    $key = domainErrorPaymentItemKey($component);

    $component->set("mountedActions.0.data.payments.{$key}.amount", '0,00')
        ->callMountedAction();

    expect($component->errors()->toArray())->toBe(["mountedActions.0.data.payments.{$key}.amount" => ['O valor do pagamento deve ser maior que zero.']])
        ->and(domainErrorNotifications())->toBe([])
        ->and($scenario['measurement']->payments()->count())->toBe(1);
});

it('names the payment fields when the payment registration refuses a row', function (string $field) {
    $scenario = domainErrorPaymentStage();
    $row = [
        'plan_set_id' => $scenario['payment']->plan_set_id,
        'pay_date' => '2026-05-20',
        'amount' => '100.00',
        'method' => 'TED',
        'financial_justification' => 'Complemento contratual a conferir.',
    ];
    [$row, $message] = match ($field) {
        'amount' => [['amount' => '0.00'] + $row, 'O campo valor do pagamento deve ser maior que 0.'],
        'method' => [['method' => str_repeat('T', 256)] + $row, 'O campo método não deve ser maior que 255 caracteres.'],
        'financial_justification' => [['financial_justification' => str_repeat('J', 5001)] + $row, 'O campo justificativa da divergência não deve ser maior que 5000 caracteres.'],
    };

    try {
        app(MeasurementWorkflow::class)->registerPayments($scenario['measurement']->fresh(), $scenario['actor'], [$row]);
        $errors = [];
    } catch (ValidationException $exception) {
        $errors = $exception->errors();
    }

    expect($errors)->toBe(["payments.0.{$field}" => [$message]])
        ->and($scenario['measurement']->payments()->count())->toBe(1);
})->with(['amount', 'method', 'financial_justification']);

it('puts the divergence justification error on the row of the development that diverged', function (string $rows) {
    $scenario = domainErrorTwoDevelopments();

    $component = Livewire::test(ViewMeasurement::class, ['record' => $scenario['measurement']->getRouteKey()])
        ->mountAction('registerPayment');
    $keyOf = collect($component->get('mountedActions.0.data.payments'))
        ->mapWithKeys(fn (array $row, string $key): array => [(int) $row['plan_set_id'] => $key]);
    $diverging = $rows === 'Alfa blank, Beta divergent' ? 'beta' : 'alfa';
    $blank = $diverging === 'beta' ? 'alfa' : 'beta';

    // R$ 100,00 contra o saldo esperado de R$ 1.000,00 (Alfa) ou R$ 2.000,00
    // (Beta), sem justificativa. O domínio numera só as linhas com valor: com
    // a primeira em branco, a linha 0 dele é o segundo item do Repeater.
    $component->set("mountedActions.0.data.payments.{$keyOf[$scenario[$diverging]->id]}.amount", '100,00')
        ->callMountedAction();

    expect($component->errors()->toArray())->toBe([
        "mountedActions.0.data.payments.{$keyOf[$scenario[$diverging]->id]}.financial_justification" => ['Explique a divergência financeira para a conferência do Finalizador.'],
    ])
        ->and(collect($component->errors()->keys())->filter(fn (string $key): bool => str_contains($key, $keyOf[$scenario[$blank]->id]))->all())->toBe([])
        ->and($scenario['measurement']->payments()->count())->toBe(0);
})->with(['Alfa blank, Beta divergent', 'Alfa divergent, Beta blank']);

it('shows an unavailable antivirus under the support document of the payment row and logs it', function () {
    $scenario = domainErrorPaymentStage();
    domainErrorScanner(ClamAvFileScanner::RESULT_UNAVAILABLE);
    $logs = domainErrorCriticalLogs();

    $component = Livewire::test(ViewMeasurement::class, ['record' => $scenario['measurement']->getRouteKey()])
        ->mountAction('registerPayment');
    $key = domainErrorPaymentItemKey($component);

    $component->fillForm(['payments' => [$key => [
        'amount' => '100,00',
        'financial_justification' => 'Complemento contratual a conferir.',
        'financial_support' => [ReceiptScenario::file('suporte.pdf')],
    ]]])->callMountedAction();

    expect($component->errors()->get("mountedActions.0.data.payments.{$key}.financial_support"))
        ->toBe(['O documento não passou na verificação de segurança ou o antivírus está indisponível.'])
        ->and(domainErrorKeysWithoutField($component))->toBe([])
        ->and($scenario['measurement']->payments()->count())->toBe(1)
        ->and(Storage::disk('local')->allFiles('nimbus_docs/measurements/financial-support'))->toBe([])
        ->and($logs->getArrayCopy())->toHaveCount(1)
        ->and($logs[0]['message'])->toBe('Upload bloqueado pela varredura antivírus.')
        ->and($logs[0]['context'])->toMatchArray(['reason' => 'antivirus_indisponivel', 'field' => 'financial_support', 'original_filename' => 'suporte.pdf']);
});

it('keeps an unavailable antivirus on the receipt field and logs it', function () {
    $scenario = ReceiptScenario::open();
    $this->actingAs($scenario['actor']);
    domainErrorScanner(ClamAvFileScanner::RESULT_UNAVAILABLE);
    $logs = domainErrorCriticalLogs();

    $component = Livewire::test(ViewMeasurement::class, ['record' => $scenario['measurement']->getRouteKey()])
        ->callAction('attachReceipt', data: ['payment_id' => $scenario['payment']->id, 'receipt' => ReceiptScenario::file()]);

    expect($component->errors()->get('mountedActions.0.data.receipt'))
        ->toBe(['Não foi possível verificar a segurança do arquivo. Tente novamente quando o antivírus estiver disponível.'])
        ->and(domainErrorKeysWithoutField($component))->toBe([])
        ->and(domainErrorNotifications())->toBe([])
        ->and($scenario['payment']->fresh()->currentReceiptEvidence)->toBeNull()
        ->and($logs->getArrayCopy())->toHaveCount(1)
        ->and($logs[0]['context'])->toMatchArray(['reason' => 'antivirus_indisponivel', 'field' => 'receipt', 'disk' => 'local'])
        ->and($logs[0]['context']['relative_path'])->toStartWith('nimbus_docs/measurements/receipts/');
});

it('explains a receipt whose stored file disappeared before the documentary review', function () {
    $scenario = ReceiptScenario::open();
    $evidence = app(MeasurementReceiptEvidenceService::class)->upload($scenario['payment'], $scenario['actor'], ReceiptScenario::file());
    Storage::disk('local')->delete($evidence->storage_path);
    $this->actingAs($scenario['actor']);

    $component = Livewire::test(ViewMeasurement::class, ['record' => $scenario['measurement']->getRouteKey()])
        ->callAction('reviewReceipt', data: ['evidence_id' => $evidence->id, 'decision' => 'approved', 'confirmed' => true]);

    expect(domainErrorNotificationBody('Ação não concluída.'))->toBe('O arquivo enviado não foi encontrado em um armazenamento permitido.')
        ->and($component->errors()->keys())->toBe([])
        ->and(domainErrorKeysWithoutField($component))->toBe([])
        ->and($evidence->fresh()->review_status->value)->toBe('pending');

    $component->assertActionMounted('reviewReceipt');
});

it('logs a receipt refused by the integrity check of the documentary review', function (string $tampering) {
    $scenario = ReceiptScenario::open();
    $evidence = app(MeasurementReceiptEvidenceService::class)->upload($scenario['payment'], $scenario['actor'], ReceiptScenario::file());
    $logs = domainErrorWarningLogs();

    if ($tampering === 'file removed from the storage') {
        Storage::disk('local')->delete($evidence->storage_path);
    } else {
        Storage::disk('local')->put($evidence->storage_path, '%PDF-1.7 comprovante trocado depois do envio');
    }

    $this->actingAs($scenario['actor']);

    Livewire::test(ViewMeasurement::class, ['record' => $scenario['measurement']->getRouteKey()])
        ->callAction('reviewReceipt', data: ['evidence_id' => $evidence->id, 'decision' => 'approved', 'confirmed' => true])
        ->assertActionMounted('reviewReceipt');

    expect(domainErrorNotificationBody('Ação não concluída.'))->toBe($tampering === 'file removed from the storage'
        ? 'O arquivo enviado não foi encontrado em um armazenamento permitido.'
        : 'O comprovante está ausente, sem SHA-256 ou não corresponde ao conteúdo auditado.')
        ->and($logs->getArrayCopy())->toBe([[
            'message' => 'Comprovante de pagamento recusado na conferência de integridade.',
            'context' => [
                'reason' => $tampering === 'file removed from the storage' ? 'arquivo_ausente_ou_invalido' : 'sha256_divergente',
                'measurement_id' => $scenario['measurement']->id,
                'payment_id' => $scenario['payment']->id,
                'evidence_id' => $evidence->id,
                'version' => 1,
                'disk' => 'local',
                'relative_path' => $evidence->storage_path,
            ],
        ]])
        ->and($evidence->fresh()->review_status->value)->toBe('pending');
})->with(['file removed from the storage', 'content changed after the upload']);

it('logs a measurement file or a receipt refused by the integrity check of the finalization', function (string $tampering) {
    $scenario = domainErrorReadyToFinalize();
    $asset = $scenario['measurement']->assets()->where('plan_set_id', $scenario['beta']->id)->sole();
    $evidence = $scenario['payments'][1]->fresh()->currentReceiptEvidence;
    $logs = domainErrorWarningLogs();

    [$path, $expectedNotification, $expectedContext] = match ($tampering) {
        'measurement file removed' => [$asset->storage_path, "O arquivo de medição #{$asset->id} é inválido ou está ausente.", [
            'reason' => 'arquivo_ausente_ou_invalido', 'measurement_id' => $scenario['measurement']->id, 'operation_id' => $scenario['measurement']->operation_id,
            'asset_id' => $asset->id, 'disk' => 'local', 'relative_path' => $asset->storage_path,
        ]],
        'measurement file changed' => [$asset->storage_path, "O arquivo de medição #{$asset->id} está ausente, sem SHA-256 ou não corresponde ao conteúdo auditado.", [
            'reason' => 'sha256_divergente', 'measurement_id' => $scenario['measurement']->id, 'operation_id' => $scenario['measurement']->operation_id,
            'asset_id' => $asset->id, 'disk' => 'local', 'relative_path' => $asset->storage_path,
        ]],
        'receipt changed' => [$evidence->storage_path, "O comprovante #{$scenario['payments'][1]->id} é inválido, foi alterado ou está ausente.", [
            'reason' => 'sha256_divergente', 'measurement_id' => $scenario['measurement']->id, 'payment_id' => $scenario['payments'][1]->id,
            'evidence_id' => $evidence->id, 'version' => 1, 'disk' => 'local', 'relative_path' => $evidence->storage_path,
        ]],
    };

    $tampering === 'measurement file removed'
        ? Storage::disk('local')->delete($path)
        : Storage::disk('local')->put($path, '%PDF-1.7 conteúdo trocado depois da conferência');

    Livewire::test(ViewMeasurement::class, ['record' => $scenario['measurement']->getRouteKey()])
        ->callAction('finalize')
        ->assertActionMounted('finalize');

    expect(domainErrorNotificationBody('Ação não concluída.'))->toBe($expectedNotification)
        ->and($logs->getArrayCopy())->toHaveCount(1)
        ->and($logs[0]['message'])->toBe($tampering === 'receipt changed'
            ? 'Comprovante de pagamento recusado na conferência de integridade.'
            : 'Arquivo de medição recusado na conferência de integridade da Finalização.')
        ->and($logs[0]['context'])->toBe($expectedContext)
        ->and($scenario['measurement']->fresh()->status)->toBe('approved');
})->with(['measurement file removed', 'measurement file changed', 'receipt changed']);

// ── O que não é recusa do domínio ────────────────────────────────────────────

it('explains a submission whose action was taken away by another decision', function (string $race) {
    if ($race === 'Engineering approval after a terminal rejection') {
        $scenario = PhysicalScenario::plan();
        $measurement = PhysicalScenario::measurement($scenario, '2026-05');
        $this->actingAs($scenario['actor']);
        $action = 'approve';
        $component = Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
            ->mountAction($action)
            ->fillForm(['realized' => [$scenario['planSet']->id => 10]]);

        app(MeasurementWorkflow::class)->reject($measurement->fresh(), $scenario['actor'], 'Medição indevida.');
        $expectedStatus = 'rejected';
    } else {
        $scenario = domainErrorPaymentStage();
        $measurement = $scenario['measurement'];
        $action = 'registerPayment';
        $component = Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
            ->mountAction($action);
        $component->set('mountedActions.0.data.payments.'.domainErrorPaymentItemKey($component).'.amount', '100,00');

        app(MeasurementWorkflow::class)->approve($measurement->fresh(), $scenario['actor']);
        $expectedStatus = 'awaiting_receipt';
    }

    $component->callMountedAction();

    expect(domainErrorNotificationBody('Ação não concluída.'))
        ->toBe('A medição mudou depois que esta janela foi aberta e esta ação não está mais disponível para você. Atualize a página.');

    $component->assertActionNotMounted($action)->assertNotified('Ação não concluída.');

    expect($measurement->fresh()->status)->toBe($expectedStatus)
        ->and($measurement->payments()->count())->toBe($race === 'Engineering approval after a terminal rejection' ? 0 : 1);
})->with(['Engineering approval after a terminal rejection', 'payment registration after the payment stage was approved']);

it('reports an unexpected failure with a generic message and keeps the modal open', function () {
    Exceptions::fake();
    $scenario = PhysicalScenario::plan();
    $measurement = PhysicalScenario::measurement($scenario, '2026-05');
    $this->actingAs($scenario['actor']);
    $component = Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()]);

    Activity::creating(function (): never {
        throw new RuntimeException('SQLSTATE[HY000]: activity_log indisponível');
    });

    $component->callAction('pause', data: ['reason' => 'Aguardando documento do construtor.']);

    $notification = collect(domainErrorNotifications())->sole();

    expect($notification['title'])->toBe('Ação não concluída.')
        ->and((string) $notification['body'])->toBe('Não foi possível concluir a ação agora. Atualize a página para conferir a situação da medição antes de tentar de novo.')
        ->and(json_encode($notification))->not->toContain('SQLSTATE')
        ->and(json_encode($notification))->not->toContain('activity_log');

    Exceptions::assertReported(RuntimeException::class);
    $component->assertActionMounted('pause');

    expect($measurement->fresh()->status)->toBe('in_review')
        ->and($measurement->pauses()->count())->toBe(0);
});

it('asks to try again when the database refuses the action because of a concurrent update', function (string $failure) {
    Exceptions::fake();
    $scenario = ReceiptScenario::open();
    $this->actingAs($scenario['actor']);
    $deadlock = new QueryException('mysql', 'insert into `measurement_payment_receipt_evidences` (`version`) values (?)', [1], new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'));
    $exception = match ($failure) {
        'deadlock' => $deadlock,
        'lock wait timeout' => new QueryException('mysql', 'select * from `measurements` where `id` = ? limit 1 for update', [1], new PDOException('SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction')),
        'deadlock inside an outer transaction' => new DeadlockException($deadlock->getMessage(), 0, $deadlock),
        'integrity violation' => new QueryException('mysql', 'insert into `measurement_payment_receipt_evidences` (`version`) values (?)', [1], new PDOException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry')),
    };
    domainErrorReceiptUploadFailsWith($exception);

    $component = Livewire::test(ViewMeasurement::class, ['record' => $scenario['measurement']->getRouteKey()])
        ->callAction('attachReceipt', data: ['payment_id' => $scenario['payment']->id, 'receipt' => ReceiptScenario::file()]);

    expect(domainErrorNotificationBody('Ação não concluída.'))->toBe($failure === 'integrity violation'
        ? 'Não foi possível concluir a ação agora. Atualize a página para conferir a situação da medição antes de tentar de novo.'
        : 'A medição está sendo atualizada por outra pessoa. Tente novamente em instantes.')
        ->and(json_encode(domainErrorNotifications()))->not->toContain('SQLSTATE');

    Exceptions::assertReported($exception::class);
    $component->assertActionMounted('attachReceipt');

    expect($scenario['payment']->fresh()->currentReceiptEvidence)->toBeNull();
})->with(['deadlock', 'lock wait timeout', 'deadlock inside an outer transaction', 'integrity violation']);

it('leaves an HTTP response to the panel instead of turning it into the generic message', function () {
    $scenario = ReceiptScenario::open();
    $this->actingAs($scenario['actor']);
    domainErrorReceiptUploadFailsWith(new HttpException(403, 'Acesso negado pelo painel.'));

    Livewire::test(ViewMeasurement::class, ['record' => $scenario['measurement']->getRouteKey()])
        ->callAction('attachReceipt', data: ['payment_id' => $scenario['payment']->id, 'receipt' => ReceiptScenario::file()])
        ->assertForbidden();

    expect(domainErrorNotifications())->toBe([]);
});

it('explains a lost authorization in Portuguese', function () {
    $scenario = ReceiptScenario::open();
    $this->actingAs($scenario['actor']);
    domainErrorReceiptUploadFailsWith(new AuthorizationException);

    $component = Livewire::test(ViewMeasurement::class, ['record' => $scenario['measurement']->getRouteKey()])
        ->callAction('attachReceipt', data: ['payment_id' => $scenario['payment']->id, 'receipt' => ReceiptScenario::file()]);

    expect(domainErrorNotificationBody('Ação não concluída.'))
        ->toBe('Você não tem permissão para concluir esta ação nesta medição. Atualize a página para ver a situação atual.')
        ->and(json_encode(domainErrorNotifications()))->not->toContain('This action is unauthorized');

    $component->assertActionMounted('attachReceipt');
});

// ── Recusar com pagamento (item 3) ───────────────────────────────────────────

it('shows Recusar disabled with the reason on a paid measurement back at Engineering and refuses a forged submission', function () {
    $scenario = ReceiptScenario::open();
    $workflow = app(MeasurementWorkflow::class);
    $workflow->returnToStage($scenario['measurement']->fresh(), $scenario['actor'], MeasurementWorkflow::STAGE_ENGINEERING, 'Rever o percentual medido.');
    $measurement = $scenario['measurement']->fresh();
    $this->actingAs($scenario['actor']);

    $component = Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->assertActionVisible('reject')
        ->assertActionDisabled('reject')
        ->assertActionExists('reject', fn (Action $action): bool => $action->getTooltip() === MeasurementWorkflow::TERMINAL_REJECTION_WITH_PAYMENTS)
        ->call('mountAction', 'reject')
        ->assertSet('mountedActions', []);

    $component->set('mountedActions', [[
        'name' => 'reject',
        'arguments' => [],
        'context' => [],
        'data' => ['notes' => 'Medição indevida.', 'expected_stage' => MeasurementWorkflow::STAGE_ENGINEERING, 'expected_revision' => $measurement->workflow_revision],
    ]])->call('callMountedAction');

    expect(domainErrorNotificationBody('Ação não concluída.'))
        ->toBe('A medição mudou depois que esta janela foi aberta e esta ação não está mais disponível para você. Atualize a página.');

    $component->assertSet('mountedActions', []);

    expect($measurement->fresh()->status)->toBe('in_review')
        ->and($measurement->fresh()->reviewForStage(MeasurementWorkflow::STAGE_ENGINEERING)?->status)->toBe('pending')
        ->and(Activity::query()->where('description', 'measurement_stage_rejected')->count())->toBe(0);
});

it('keeps Recusar enabled where the rejection does not close a paid measurement', function (string $case) {
    if ($case === 'Engineering without payment') {
        $scenario = PhysicalScenario::plan();
        $measurement = PhysicalScenario::measurement($scenario, '2026-05');
        $this->actingAs($scenario['actor']);
        $expectedStatus = 'rejected';
    } else {
        $scenario = domainErrorPaymentStage();
        $measurement = $scenario['measurement'];
        $expectedStatus = 'in_review';
    }

    Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->assertActionEnabled('reject')
        ->assertActionExists('reject', fn (Action $action): bool => $action->getTooltip() === null)
        ->callAction('reject', data: ['notes' => 'Corrigir antes de seguir.'])
        ->assertHasNoActionErrors();

    expect($measurement->fresh()->status)->toBe($expectedStatus);
})->with(['Engineering without payment', 'Payment stage with payment']);

// ── Empreendimento exigido sem pagamento (item 5) ────────────────────────────

it('lists the developments without payment and requires the justification to approve the payment stage', function () {
    $scenario = domainErrorTwoDevelopments();
    domainErrorPayOnlyAlfa($scenario);
    $justification = 'Torre Beta sem pagamento: pendência documental do construtor.';

    $component = Livewire::test(ViewMeasurement::class, ['record' => $scenario['measurement']->getRouteKey()])
        ->mountAction('approve');
    $notes = domainErrorModalComponent($component, 'notes');

    expect(domainErrorModalText($component, 'unpaid_plan_sets'))->toContain('Torre Beta')
        ->toContain('R$ 2.000,00')
        ->not->toContain('Torre Alfa')
        ->and($notes?->getStatePath())->toBe('mountedActions.0.data.notes')
        ->and($notes?->getLabel())->toBe('Justificativa da ausência de pagamento')
        ->and($notes?->isRequired())->toBeTrue();

    $component->callMountedAction();

    expect($component->errors()->get('mountedActions.0.data.notes'))
        ->toBe(['Justifique a ausência de pagamento nesta competência de: Torre Beta (R$ 2.000,00); o Finalizador precisará aceitar expressamente.'])
        ->and(domainErrorKeysWithoutField($component))->toBe([])
        ->and($scenario['measurement']->fresh()->status)->toBe('awaiting_payment');

    $component->fillForm(['notes' => $justification])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect($scenario['measurement']->fresh()->status)->toBe('awaiting_receipt')
        ->and($scenario['measurement']->fresh()->reviewForStage(MeasurementWorkflow::STAGE_PAYMENT)?->notes)->toBe($justification);
});

it('keeps the comment optional when every development with an expected amount was paid', function () {
    $scenario = domainErrorPaymentStage();

    $component = Livewire::test(ViewMeasurement::class, ['record' => $scenario['measurement']->getRouteKey()])
        ->mountAction('approve');
    $notes = domainErrorModalComponent($component, 'notes');

    expect(domainErrorModalComponent($component, 'unpaid_plan_sets'))->toBeNull()
        ->and($notes?->getLabel())->toBe('Comentário (opcional)')
        ->and($notes?->isRequired())->toBeFalse();

    $component->callMountedAction()->assertHasNoActionErrors();

    expect($scenario['measurement']->fresh()->status)->toBe('awaiting_receipt');
});

it('shows the development without payment to the finalizer, with its justification and the pending acceptance', function () {
    $scenario = domainErrorTwoDevelopments();
    $payment = domainErrorPayOnlyAlfa($scenario);
    $justification = 'Torre Beta sem pagamento: pendência documental do construtor.';
    $workflow = app(MeasurementWorkflow::class);
    $workflow->approve($scenario['measurement']->fresh(), $scenario['actor'], $justification);
    $workflow->attachReceipt($payment->fresh(), $scenario['actor'], ReceiptScenario::file());
    ReceiptScenario::approveCurrentReceipt($payment, $scenario['actor']);

    Livewire::test(ViewMeasurement::class, ['record' => $scenario['measurement']->getRouteKey()])
        ->assertSee('Empreendimentos sem pagamento nesta competência')
        ->assertSee($justification)
        ->assertSee('Aceite pendente: o Finalizador precisa aceitar expressamente a ausência de pagamento ao finalizar.');

    $component = Livewire::test(ViewMeasurement::class, ['record' => $scenario['measurement']->getRouteKey()])
        ->mountAction('finalize');
    $review = domainErrorModalText($component, 'financial_review');
    $acceptance = domainErrorModalComponent($component, 'accept_financial_exceptions');

    expect($review)->toContain('Empreendimentos sem pagamento nesta competência')
        ->toContain('Torre Beta')
        ->toContain('R$ 2.000,00')
        ->toContain($justification)
        ->toContain('Aceite pendente')
        ->and($acceptance)->not->toBeNull();

    $component->callMountedAction();

    expect($component->errors()->keys())->toBe(['mountedActions.0.data.accept_financial_exceptions'])
        ->and(domainErrorKeysWithoutField($component))->toBe([])
        ->and($scenario['measurement']->fresh()->status)->toBe('approved');

    $component->fillForm(['accept_financial_exceptions' => true])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect($scenario['measurement']->fresh()->status)->toBe('finalized');

    Livewire::test(ViewMeasurement::class, ['record' => $scenario['measurement']->getRouteKey()])
        ->assertSee('Empreendimentos sem pagamento nesta competência')
        ->assertSee('Ausência de pagamento aceita expressamente na finalização. Decisão registrada no histórico de auditoria.')
        ->assertDontSee('Aceite pendente');
});

it('refuses the finalization without the acceptance with the sentence of the domain, naming what is accepted', function (string $reason) {
    $scenario = domainErrorTwoDevelopments();
    $workflow = app(MeasurementWorkflow::class);
    $payments = [domainErrorPayOnlyAlfa($scenario)];

    if ($reason === 'divergent payment') {
        $payments[] = $workflow->registerPayment($scenario['measurement']->fresh(), $scenario['actor'], [
            'plan_set_id' => $scenario['beta']->id,
            'pay_date' => '2026-08-31',
            'amount' => '1500.00',
            'financial_justification' => 'Retenção de R$ 500,00 por pendência documental.',
        ]);
        $workflow->approve($scenario['measurement']->fresh(), $scenario['actor']);
        $expected = 'Confirme expressamente o aceite das divergências e justificativas financeiras.';
    } else {
        $workflow->approve($scenario['measurement']->fresh(), $scenario['actor'], 'Torre Beta sem pagamento: pendência documental do construtor.');
        $expected = 'Confirme expressamente o aceite da ausência de pagamento justificada na etapa Pagamento: Torre Beta (R$ 2.000,00).';
    }

    foreach ($payments as $payment) {
        $workflow->attachReceipt($payment->fresh(), $scenario['actor'], ReceiptScenario::file());
        ReceiptScenario::approveCurrentReceipt($payment, $scenario['actor']);
    }

    $component = Livewire::test(ViewMeasurement::class, ['record' => $scenario['measurement']->getRouteKey()])
        ->mountAction('finalize')
        ->callMountedAction();

    try {
        $workflow->finalize($scenario['measurement']->fresh(), $scenario['actor']);
        $domain = null;
    } catch (ValidationException $exception) {
        $domain = $exception->errors()['accept_financial_exceptions'] ?? null;
    }

    expect($component->errors()->toArray())->toBe(['mountedActions.0.data.accept_financial_exceptions' => [$expected]])
        ->and($domain)->toBe([$expected])
        ->and($scenario['measurement']->fresh()->status)->toBe('approved');
})->with(['development without payment', 'divergent payment']);

it('lists the developments without payment from the payment stage on, even before any payment exists', function () {
    $scenario = PhysicalScenario::plan();
    $measurement = PhysicalScenario::measured($scenario, '2026-05', 10);
    $this->actingAs($scenario['actor']);

    Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->assertSee('Conciliação Financeira')
        ->assertDontSee('Empreendimentos sem pagamento nesta competência');

    app(MeasurementWorkflow::class)->approve($measurement->fresh(), $scenario['actor']);
    app(MeasurementWorkflow::class)->approve($measurement->fresh(), $scenario['actor']);

    Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->assertSee('O enquadramento financeiro aparecerá após o registro dos pagamentos.')
        ->assertSee('Empreendimentos sem pagamento nesta competência')
        ->assertSee('Valor esperado</dt><dd>R$ 100.000,00</dd>', false)
        ->assertSee('Ainda não registrada: a aprovação da etapa Pagamento precisa justificar a ausência de pagamento.')
        ->assertSee('Aceite pendente: o Finalizador precisa aceitar expressamente a ausência de pagamento ao finalizar.');
});

it('does not present as accepted a development without payment of a measurement finalized before the acceptance existed', function () {
    $scenario = domainErrorTwoDevelopments();
    $payment = domainErrorPayOnlyAlfa($scenario);
    $workflow = app(MeasurementWorkflow::class);
    $workflow->approve($scenario['measurement']->fresh(), $scenario['actor'], 'Torre Beta fica para a próxima competência.');
    $workflow->attachReceipt($payment->fresh(), $scenario['actor'], ReceiptScenario::file());
    ReceiptScenario::approveCurrentReceipt($payment, $scenario['actor']);
    $scenario['measurement']->fresh()->forceFill(['status' => 'finalized'])->save();

    Livewire::test(ViewMeasurement::class, ['record' => $scenario['measurement']->getRouteKey()])
        ->assertSee('Empreendimentos sem pagamento nesta competência')
        ->assertSee('Medição finalizada sem aceite registrado para a ausência de pagamento.')
        ->assertDontSee('Ausência de pagamento aceita expressamente');
});

it('no longer says that the reconciliation divergence is merely informative', function () {
    $scenario = domainErrorTwoDevelopments();
    domainErrorPayOnlyAlfa($scenario);

    Livewire::test(ViewMeasurement::class, ['record' => $scenario['measurement']->getRouteKey()])
        ->assertSee('Conciliação Financeira')
        ->assertDontSee('estritamente informativa')
        ->assertSee('A divergência não impede o registro do pagamento: exige justificativa na etapa Pagamento e o aceite expresso do Finalizador, inclusive quando um empreendimento fica sem pagamento nesta competência.');
});

// ── Arquivos: caminho forjado no estado do modal (pedido do A4) ──────────────

it('refuses a forged receipt path on the field and still accepts a real upload', function () {
    $scenario = ReceiptScenario::open();
    $this->actingAs($scenario['actor']);
    $forged = 'nimbus_docs/measurements/receipts/'.Str::uuid().'/alheio.pdf';
    Storage::disk('local')->put($forged, '%PDF-1.7 comprovante de outra medição');

    $component = Livewire::test(ViewMeasurement::class, ['record' => $scenario['measurement']->getRouteKey()])
        ->mountAction('attachReceipt')
        ->set('mountedActions.0.data.payment_id', $scenario['payment']->id)
        ->set('mountedActions.0.data.receipt', [(string) Str::uuid() => $forged])
        ->callMountedAction();

    expect($component->errors()->get('mountedActions.0.data.receipt'))->toBe(['O campo comprovante contém um caminho de arquivo que não é permitido.'])
        ->and(domainErrorKeysWithoutField($component))->toBe([])
        ->and($scenario['payment']->fresh()->currentReceiptEvidence)->toBeNull();

    // Em lista, o arquivo novo substitui a entrada forjada no estado do campo
    // (arquivo único num estado que já é array seria anexado a ela).
    $component->fillForm(['receipt' => [ReceiptScenario::file()]])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect($scenario['payment']->fresh()->currentReceiptEvidence?->original_filename)->toBe('comprovante TED.pdf');
    Storage::disk('local')->assertExists($forged);
});

it('refuses a forged support document path on the payment row and still accepts a real upload', function () {
    $scenario = domainErrorPaymentStage();
    $forged = 'nimbus_docs/measurements/financial-support/'.Str::uuid().'/alheio.pdf';
    Storage::disk('local')->put($forged, '%PDF-1.7 suporte de outra medição');

    $component = Livewire::test(ViewMeasurement::class, ['record' => $scenario['measurement']->getRouteKey()])
        ->mountAction('registerPayment');
    $key = domainErrorPaymentItemKey($component);

    $component->fillForm(['payments' => [$key => ['amount' => '100,00', 'financial_justification' => 'Complemento contratual a conferir.']]])
        ->set("mountedActions.0.data.payments.{$key}.financial_support", [(string) Str::uuid() => $forged])
        ->callMountedAction();

    expect($component->errors()->get("mountedActions.0.data.payments.{$key}.financial_support"))
        ->toBe(['O campo documento de suporte contém um caminho de arquivo que não é permitido.'])
        ->and(domainErrorKeysWithoutField($component))->toBe([])
        ->and($scenario['measurement']->payments()->count())->toBe(1);

    $component->fillForm(['payments' => [$key => ['financial_support' => [ReceiptScenario::file('suporte.pdf')]]]])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $support = $scenario['measurement']->payments()->latest('id')->first()?->financial_assessment['support'] ?? [];

    expect($scenario['measurement']->payments()->count())->toBe(2)
        ->and($support['name'] ?? null)->toBe('suporte.pdf')
        ->and($support['path'] ?? null)->not->toBe($forged);
});

// ── Enviar e Editar Medição ──────────────────────────────────────────────────

it('explains an antivirus refusal when sending a measurement, logs it and lets the person send it again', function (string $verdict) {
    $scenario = PhysicalScenario::plan();
    $this->actingAs($scenario['actor']);
    domainErrorScanner($verdict);
    $logs = domainErrorCriticalLogs();

    $component = domainErrorFilledCreateForm($scenario, '%PDF-1.7 envio bloqueado')->call('create');

    expect(domainErrorNotificationBody('Medição não enviada.'))->toBe($verdict === ClamAvFileScanner::RESULT_INFECTED
        ? 'O arquivo foi bloqueado pelo antivírus. Envie um arquivo seguro.'
        : 'Não foi possível verificar a segurança do arquivo. Tente novamente quando o antivírus estiver disponível.')
        ->and($component->errors()->keys())->toBe([])
        ->and(domainErrorKeysWithoutField($component, $component->instance()->form))->toBe([])
        ->and($scenario['operation']->measurements()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles('nimbus_docs/measurements/assets'))->toBe([])
        ->and($logs->getArrayCopy())->toHaveCount(1)
        ->and($logs[0]['context'])->toMatchArray([
            'reason' => $verdict === ClamAvFileScanner::RESULT_INFECTED ? 'malware_detectado' : 'antivirus_indisponivel',
            'field' => 'asset',
            'disk' => 'local',
        ]);

    domainErrorScanner(ClamAvFileScanner::RESULT_CLEAN);
    $assetKey = (string) array_key_first($component->get('data.assets'));
    $component->fillForm(['assets' => [$assetKey => [
        'storage_path' => [UploadedFile::fake()->createWithContent('medicao.pdf', '%PDF-1.7 envio liberado')],
    ]]])->call('create')->assertHasNoFormErrors();

    $sent = $scenario['operation']->measurements()->sole();

    expect($sent->status)->toBe('in_review')
        ->and(Storage::disk('local')->get($sent->assets()->sole()->storage_path))->toBe('%PDF-1.7 envio liberado');
})->with([ClamAvFileScanner::RESULT_UNAVAILABLE, ClamAvFileScanner::RESULT_INFECTED]);

it('explains an unavailable antivirus when replacing a file in the edit form', function () {
    $scenario = PhysicalScenario::plan();
    $measurement = PhysicalScenario::measurement($scenario, '2026-05');
    $asset = $measurement->assets()->sole();
    $before = $asset->only(['storage_path', 'sha256']);
    $this->actingAs($scenario['actor']);
    domainErrorScanner(ClamAvFileScanner::RESULT_UNAVAILABLE);

    $component = Livewire::test(EditMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->fillForm(['assets' => ["record-{$asset->id}" => [
            'plan_set_id' => $asset->plan_set_id,
            'plan_line_id' => $asset->plan_line_id,
            'storage_path' => [UploadedFile::fake()->createWithContent('nova.pdf', '%PDF-1.7 nova versão')],
        ]]])
        ->call('save');

    expect(domainErrorNotificationBody('Medição não atualizada.'))
        ->toBe('Não foi possível verificar a segurança do arquivo. Tente novamente quando o antivírus estiver disponível.')
        ->and($component->errors()->keys())->toBe([])
        ->and($asset->fresh()->only(['storage_path', 'sha256']))->toBe($before)
        ->and(Storage::disk('local')->allFiles('nimbus_docs/measurements/assets'))->toBe([$before['storage_path']]);
});

it('explains a workflow refusal raised while sending or updating a measurement', function (string $page) {
    $scenario = PhysicalScenario::plan();
    $this->actingAs($scenario['actor']);
    $refusal = 'A operação da medição foi alterada durante esta ação.';

    if ($page === 'send') {
        Measurement::creating(function () use ($refusal): never {
            throw new MeasurementWorkflowException($refusal);
        });
        $component = domainErrorFilledCreateForm($scenario, '%PDF-1.7 envio')->call('create');
        $title = 'Medição não enviada.';
    } else {
        $measurement = PhysicalScenario::measurement($scenario, '2026-05');
        MeasurementAsset::saving(function () use ($refusal): never {
            throw new MeasurementWorkflowException($refusal);
        });
        $component = Livewire::test(EditMeasurement::class, ['record' => $measurement->getRouteKey()])->call('save');
        $title = 'Medição não atualizada.';
    }

    expect(domainErrorNotificationBody($title))->toBe($refusal)
        ->and($component->errors()->keys())->toBe([]);
})->with(['send', 'update']);

it('keeps the validation errors of the form itself on their fields', function () {
    $scenario = PhysicalScenario::plan();
    $this->actingAs($scenario['actor']);

    $component = Livewire::test(CreateMeasurement::class)
        ->fillForm(['operation_id' => $scenario['operation']->id])
        ->call('create');
    $assetKey = (string) array_key_first($component->get('data.assets'));

    expect($component->errors()->keys())->toContain("data.assets.{$assetKey}.storage_path")
        ->toContain("data.assets.{$assetKey}.plan_line_id")
        ->and(domainErrorKeysWithoutField($component, $component->instance()->form))->toBe([])
        ->and(domainErrorNotifications())->toBe([]);
});

it('explains in Portuguese that only a direct participant sends a measurement of the operation', function (string $refusal) {
    $scenario = PhysicalScenario::plan();
    $this->actingAs($scenario['actor']);

    if ($refusal === 'the policy refuses the operation') {
        // O seletor mostra o que a pessoa enxerga -- inclusive por delegação --,
        // e é a policy do envio que recusa quem não participa diretamente.
        Gate::before(fn (User $user, string $ability): ?bool => $ability === 'createForOperation' ? false : null);
    } else {
        // A permissão de envio sai entre a autorização da página e o início da
        // análise, que confere o envio de novo sob o lock da operação.
        Measurement::created(function (): void {
            auth()->user()->revokePermissionTo('measurements.create');
        });
    }

    $component = domainErrorFilledCreateForm($scenario, '%PDF-1.7 envio recusado')->call('create');

    expect(domainErrorNotificationBody('Medição não enviada.'))
        ->toBe('Você não pode enviar medição nesta operação: o envio é feito por quem participa diretamente dela.')
        ->and(domainErrorNotifications())->toHaveCount(1)
        ->and(json_encode(domainErrorNotifications()))->not->toContain('unauthorized')
        ->and($component->errors()->keys())->toBe([])
        ->and($scenario['operation']->measurements()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles('nimbus_docs/measurements/assets'))->toBe([]);

    $component->assertSuccessful();
})->with(['the policy refuses the operation', 'permission lost before the analysis starts']);

it('asks to try again when the database refuses a measurement submission because of a concurrent update', function (string $failure) {
    Exceptions::fake();
    $scenario = PhysicalScenario::plan();
    $this->actingAs($scenario['actor']);
    $exception = DatabaseConcurrencyFailure::make($failure);
    Measurement::creating(function () use ($exception): never {
        throw $exception;
    });
    $component = domainErrorFilledCreateForm($scenario, '%PDF-1.7 envio concorrente');

    if (! DatabaseConcurrencyFailure::isConcurrency($failure)) {
        expect(fn () => $component->call('create'))->toThrow(QueryException::class);
        expect(domainErrorNotifications())->toBe([]);

        return;
    }

    $component->call('create');

    expect(domainErrorNotificationBody('Medição não enviada.'))->toBe('Outra pessoa está atualizando esta operação. Tente novamente em instantes.')
        ->and(json_encode(domainErrorNotifications()))->not->toContain('SQLSTATE')
        ->and($component->errors()->keys())->toBe([])
        ->and($scenario['operation']->measurements()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles('nimbus_docs/measurements/assets'))->toBe([]);

    expect(collect(Exceptions::reported())->contains(fn (Throwable $reported): bool => ($reported === $exception) || ($reported->getPrevious() === $exception)))->toBeTrue();
})->with(DatabaseConcurrencyFailure::CASES);

it('asks to try again when the database refuses a measurement update because of a concurrent update', function (string $failure) {
    Exceptions::fake();
    $scenario = PhysicalScenario::plan();
    $measurement = PhysicalScenario::measurement($scenario, '2026-05');
    $notesBefore = $measurement->notes;
    $this->actingAs($scenario['actor']);
    $exception = DatabaseConcurrencyFailure::make($failure);
    $component = Livewire::test(EditMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->fillForm(['notes' => 'Observação que não deve ser gravada.']);
    Measurement::updating(function () use ($exception): never {
        throw $exception;
    });

    if (! DatabaseConcurrencyFailure::isConcurrency($failure)) {
        expect(fn () => $component->call('save'))->toThrow(QueryException::class);
        expect(domainErrorNotifications())->toBe([]);

        return;
    }

    $component->call('save');

    expect(domainErrorNotificationBody('Medição não atualizada.'))->toBe('Outra pessoa está atualizando esta medição. Tente novamente em instantes.')
        ->and(domainErrorNotifications())->toHaveCount(1)
        ->and(json_encode(domainErrorNotifications()))->not->toContain('SQLSTATE')
        ->and($component->errors()->keys())->toBe([])
        ->and($measurement->fresh()->notes)->toBe($notesBefore);

    $component->assertNoRedirect();
    expect(collect(Exceptions::reported())->contains(fn (Throwable $reported): bool => ($reported === $exception) || ($reported->getPrevious() === $exception)))->toBeTrue();
})->with(DatabaseConcurrencyFailure::CASES);

it('refuses the edit under the operation lock when Engineering approved the measurement after the access check', function () {
    $scenario = PhysicalScenario::plan();
    $measurement = PhysicalScenario::measurement($scenario, '2026-05');
    $asset = $measurement->assets()->sole();
    $before = $asset->only(['storage_path', 'sha256']);
    $notesBefore = $measurement->notes;
    $this->actingAs($scenario['actor']);
    $component = Livewire::test(EditMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->fillForm([
            'notes' => 'Observação que não deve ser gravada.',
            'assets' => ["record-{$asset->id}" => [
                'plan_set_id' => $asset->plan_set_id,
                'plan_line_id' => $asset->plan_line_id,
                'storage_path' => [UploadedFile::fake()->createWithContent('nova.pdf', '%PDF-1.7 nova versão')],
            ]],
        ]);
    $pageLevel = DB::transactionLevel() + 1;
    $approvedMeanwhile = false;

    // A aprovação termina enquanto a gravação espera a Operation: aqui, logo
    // depois do lock da Operation, a primeira instrução da transação da página.
    // O SQLite ignora o FOR UPDATE; a espera de verdade está no teste MySQL.
    DB::listen(function (QueryExecuted $query) use ($measurement, $pageLevel, &$approvedMeanwhile): void {
        if ($approvedMeanwhile || (DB::transactionLevel() < $pageLevel) || ! str_starts_with($query->sql, 'select * from "operations"')) {
            return;
        }

        $approvedMeanwhile = true;
        DB::table('measurement_reviews')
            ->where('measurement_id', $measurement->id)
            ->where('stage', MeasurementWorkflow::STAGE_ENGINEERING)
            ->update(['status' => 'approved']);
    });

    $component->call('save');

    expect($approvedMeanwhile)->toBeTrue()
        ->and(domainErrorNotificationBody('Medição não atualizada.'))->toBe('A Engenharia aprovou esta medição enquanto você editava. Atualize a página.')
        ->and(domainErrorNotifications())->toHaveCount(1)
        ->and($component->errors()->keys())->toBe([])
        ->and($asset->fresh()->only(['storage_path', 'sha256']))->toBe($before)
        ->and($measurement->fresh()->notes)->toBe($notesBefore)
        ->and(Storage::disk('local')->allFiles('nimbus_docs/measurements/assets'))->toBe([$before['storage_path']]);
});

it('sends the person to the measurement page when Engineering approved it while the edit page was open', function (string $interaction) {
    $scenario = PhysicalScenario::plan();
    $measurement = PhysicalScenario::measurement($scenario, '2026-05');
    $notesBefore = $measurement->notes;
    $this->actingAs($scenario['actor']);
    $component = Livewire::test(EditMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->fillForm(['notes' => 'Ajuste de observação.']);

    PhysicalScenario::approveEngineering($scenario, $measurement, 10);

    if ($interaction === 'saving') {
        $component->call('save');
    } else {
        $component->set('data.notes', 'Outra digitação.');
    }

    expect(domainErrorNotificationBody('Medição não atualizada.'))->toBe('A Engenharia já aprovou esta medição; ela não pode mais ser editada.')
        ->and(domainErrorNotifications())->toHaveCount(1)
        ->and($component->errors()->keys())->toBe([])
        ->and($measurement->fresh()->notes)->toBe($notesBefore);

    $component->assertRedirect(MeasurementResource::getUrl('view', ['record' => $measurement]));
})->with(['saving', 'typing']);

it('sends an approved measurement opened by its edit link to the measurement page', function () {
    $scenario = PhysicalScenario::plan();
    $measurement = PhysicalScenario::measured($scenario, '2026-05', 10);
    $this->actingAs($scenario['actor']);

    Livewire::test(EditMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->assertRedirect(MeasurementResource::getUrl('view', ['record' => $measurement]));

    expect(domainErrorNotificationBody('Medição não atualizada.'))->toBe('A Engenharia já aprovou esta medição; ela não pode mais ser editada.');
});

it('keeps answering 403 to who sees the measurement but cannot edit it', function () {
    $scenario = PhysicalScenario::plan();
    $measurement = PhysicalScenario::measurement($scenario, '2026-05');
    $reviewer = User::factory()->withTwoFactor()->create();
    $reviewer->givePermissionTo(['measurements.view', 'measurements.review']);
    DB::table('operations')->where('id', $scenario['operation']->id)->update(['stage2_reviewer_user_id' => $reviewer->id]);
    $this->actingAs($reviewer);

    expect(Gate::forUser($reviewer)->allows('view', $measurement->fresh()))->toBeTrue();

    Livewire::test(EditMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->assertForbidden();

    expect(domainErrorNotifications())->toBe([]);
});
