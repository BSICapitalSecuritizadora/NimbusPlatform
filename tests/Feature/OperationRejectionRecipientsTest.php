<?php

use App\Enums\MeasurementResponsibility;
use App\Exceptions\OperationLifecycleException;
use App\Filament\Resources\Measurements\MeasurementResource;
use App\Filament\Resources\Measurements\Pages\ListMeasurements;
use App\Filament\Resources\Operations\OperationResource;
use App\Filament\Resources\Operations\Pages\CreateOperation;
use App\Filament\Resources\Operations\Pages\EditOperation;
use App\Filament\Resources\Operations\Pages\ListOperations;
use App\Models\Construction;
use App\Models\Emission;
use App\Models\Measurement;
use App\Models\MeasurementPlanSet;
use App\Models\Operation;
use App\Models\ResponsibilityDelegation;
use App\Models\User;
use App\Notifications\MeasurementWorkflowNotification;
use App\Services\MeasurementCockpitService;
use App\Services\MeasurementOperationalReadModel;
use App\Services\MeasurementWorkflow;
use App\Services\OperationResponsibilityService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\DatabaseConcurrencyFailure;

/*
 * A lista "Notificar em caso de recusa" (Operation::rejectionNotifyUsers) só
 * define quem recebe o aviso de recusa da Engenharia. Ela não é participação:
 * não dá leitura nem escrita sobre a operação, as medições, os arquivos e os
 * pagamentos. Mesmo assim, quem entra nela recebe por e-mail dados da operação,
 * e por isso a lista fica sob a mesma governança dos responsáveis.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    Notification::fake();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function rejectionRecipientEditor(array $attributes = []): User
{
    $user = User::factory()->withTwoFactor()->create($attributes);
    $user->assignRole('editor');

    return $user;
}

/**
 * O watcher recebe de propósito as permissões que, somadas a uma
 * participação, abririam exclusão e gestão de responsáveis: assim cada
 * negativa abaixo prova que a lista não é participação, não que falta
 * permissão.
 *
 * @return array{participant: User, watcher: User, operation: Operation, measurement: Measurement}
 */
function rejectionRecipientScenario(): array
{
    $participant = rejectionRecipientEditor();
    $watcher = rejectionRecipientEditor();
    $watcher->givePermissionTo(['operations.manage-responsibilities', 'operations.delete', 'measurements.delete']);
    $operation = Operation::factory()->create(['assigned_user_id' => $participant->id]);
    $operation->rejectionNotifyUsers()->attach($watcher->id);
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->id,
        'status' => 'pending',
        'current_stage' => 1,
        'storage_path' => null,
        'filename' => null,
    ]);

    return compact('participant', 'watcher', 'operation', 'measurement');
}

/**
 * Operação que a página de edição consegue salvar: o formulário exige ao
 * menos um empreendimento, e ele vem do plano já vinculado.
 *
 * @param  array<string, mixed>  $attributes
 */
function rejectionRecipientEditableOperation(array $attributes = []): Operation
{
    $operation = Operation::factory()->create($attributes);
    $construction = Construction::factory()->create(['emission_id' => $operation->emission_id]);
    MeasurementPlanSet::factory()->create([
        'operation_id' => $operation->id,
        'construction_id' => $construction->id,
    ]);

    return $operation;
}

/**
 * Notificações do Filament na sessão, lidas sem consumir -- o
 * `assertNotified()` compara só o título e esvazia a sessão.
 *
 * @return list<array{title: string, body: string, status: string}>
 */
function rejectionRecipientNotifications(): array
{
    return array_map(
        fn (array $notification): array => [
            'title' => (string) ($notification['title'] ?? ''),
            'body' => (string) ($notification['body'] ?? ''),
            'status' => (string) ($notification['status'] ?? ''),
        ],
        array_values(session()->get('filament.claimed_notifications') ?? session()->get('filament.notifications') ?? []),
    );
}

/**
 * O serviço real, exceto a gravação da lista, que falha com `$failure`.
 */
function rejectionRecipientSyncFailsWith(Throwable $failure): void
{
    $service = Mockery::mock(OperationResponsibilityService::class)->makePartial();
    $service->shouldReceive('syncRejectionRecipients')->andThrow($failure);
    app()->instance(OperationResponsibilityService::class, $service);
}

/**
 * @return Builder<Activity>
 */
function rejectionRecipientActivities(): Builder
{
    return Activity::query()
        ->where('log_name', 'operations')
        ->where('event', 'rejection_recipients_updated');
}

it('does not turn a rejection recipient into a participant', function () {
    ['participant' => $participant, 'watcher' => $watcher, 'operation' => $operation, 'measurement' => $measurement] = rejectionRecipientScenario();
    $gate = Gate::forUser($watcher);

    expect($gate->allows('view', $operation))->toBeFalse()
        ->and($gate->allows('view', $measurement))->toBeFalse()
        ->and($gate->allows('update', $operation))->toBeFalse()
        ->and($gate->allows('manageResponsibilities', $operation))->toBeFalse()
        ->and($gate->allows('delete', $operation))->toBeFalse()
        ->and($gate->allows('createForOperation', [Measurement::class, $operation]))->toBeFalse()
        ->and($gate->allows('update', $measurement))->toBeFalse()
        ->and($gate->allows('delete', $measurement))->toBeFalse()
        ->and(Operation::query()->visibleTo($watcher)->whereKey($operation)->exists())->toBeFalse()
        ->and(Measurement::query()->visibleTo($watcher)->whereKey($measurement)->exists())->toBeFalse()
        ->and(app(MeasurementOperationalReadModel::class)
            ->applyAssignmentFilter(Measurement::query(), $watcher, 'direct')
            ->whereKey($measurement)
            ->exists())->toBeFalse()
        ->and(app(MeasurementCockpitService::class)->summaryFor($watcher)['total'])->toBe(0)
        ->and(Gate::forUser($participant)->allows('view', $operation))->toBeTrue()
        ->and(Gate::forUser($participant)->allows('update', $operation))->toBeTrue();
});

it('classifies a delegate on the rejection list as delegated and keeps a listed participant direct', function () {
    $manager = rejectionRecipientEditor();
    $delegate = rejectionRecipientEditor();
    $operation = Operation::factory()->create(['payment_manager_user_id' => $manager->id]);
    $operation->rejectionNotifyUsers()->attach([$manager->id, $delegate->id]);
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->id,
        'status' => 'pending',
        'current_stage' => 1,
        'storage_path' => null,
        'filename' => null,
    ]);
    ResponsibilityDelegation::factory()->active()->forStage(
        MeasurementWorkflow::STAGE_PAYMENT,
        $operation,
        MeasurementResponsibility::PaymentManager,
    )->create([
        'delegator_user_id' => $manager->id,
        'delegate_user_id' => $delegate->id,
    ]);
    $readModel = app(MeasurementOperationalReadModel::class);
    $assigned = fn (User $user, string $assignment): bool => $readModel
        ->applyAssignmentFilter(Measurement::query()->visibleTo($user), $user, $assignment)
        ->whereKey($measurement)
        ->exists();

    expect($assigned($delegate, 'direct'))->toBeFalse()
        ->and($assigned($delegate, 'delegated'))->toBeTrue()
        ->and(app(MeasurementCockpitService::class)->summaryFor($delegate)['delegated'])->toBe(1)
        ->and(Gate::forUser($delegate)->allows('view', $measurement))->toBeTrue()
        ->and(Gate::forUser($delegate)->allows('update', $operation))->toBeFalse()
        ->and($assigned($manager, 'direct'))->toBeTrue()
        ->and($assigned($manager, 'delegated'))->toBeFalse()
        ->and(Gate::forUser($manager)->allows('update', $operation))->toBeTrue()
        ->and(Gate::forUser($manager)->allows('createForOperation', [Measurement::class, $operation]))->toBeTrue();
});

it('refuses every measurement download to a rejection recipient', function () {
    ['participant' => $participant, 'watcher' => $watcher, 'operation' => $operation, 'measurement' => $measurement] = rejectionRecipientScenario();
    $assetPath = 'nimbus_docs/measurements/assets/rejection-recipient.pdf';
    Storage::disk('local')->put($assetPath, '%PDF-1.7 rejection-recipient-asset');
    $asset = $measurement->assets()->create([
        'storage_path' => $assetPath,
        'storage_disk' => 'local',
        'filename' => 'medicao.pdf',
    ]);
    $filePath = 'nimbus_docs/measurements/rejection-recipient-file.pdf';
    Storage::disk('local')->put($filePath, '%PDF-1.7 rejection-recipient-file');
    $measurement->forceFill(['storage_path' => $filePath, 'storage_disk' => 'local'])->save();
    $payment = $measurement->payments()->create([
        'operation_id' => $operation->id,
        'amount' => 1000,
        'pay_date' => now(),
    ]);

    $this->actingAs($watcher);

    $statuses = collect([
        'asset' => route('admin.measurements.assets.download', $asset),
        'file' => route('admin.measurements.file.download', $measurement),
        'receipt' => route('admin.measurements.receipts.download', $payment),
        'financial-support' => route('admin.measurements.financial-support.download', $payment),
    ])->map(fn (string $url): int => $this->get($url)->getStatusCode())->all();

    expect($statuses)->toBe([
        'asset' => 403,
        'file' => 403,
        'receipt' => 403,
        'financial-support' => 403,
    ]);

    $this->actingAs($participant)
        ->get(route('admin.measurements.assets.download', $asset))
        ->assertOk();
});

it('hides the operation and its measurements from a rejection recipient', function () {
    ['watcher' => $watcher, 'operation' => $operation, 'measurement' => $measurement] = rejectionRecipientScenario();
    $measurement->payments()->create([
        'operation_id' => $operation->id,
        'amount' => 1000,
        'pay_date' => now(),
    ]);

    $this->actingAs($watcher);

    $this->get(OperationResource::getUrl('view', ['record' => $operation]))->assertNotFound();
    $this->get(MeasurementResource::getUrl('view', ['record' => $measurement]))->assertNotFound();

    Livewire::test(ListOperations::class)
        ->assertSuccessful()
        ->assertCanNotSeeTableRecords([$operation]);

    Livewire::test(ListMeasurements::class)
        ->assertSuccessful()
        ->assertCanNotSeeTableRecords([$measurement]);

    expect(app(MeasurementOperationalReadModel::class)->paymentQueryFor($watcher)->whereKey($measurement)->exists())
        ->toBeFalse();
});

it('still notifies rejection recipients without a link they cannot open', function () {
    $engineer = rejectionRecipientEditor();
    $uploader = rejectionRecipientEditor();
    $watcher = rejectionRecipientEditor();
    $operation = Operation::factory()->create([
        'responsible_user_id' => $engineer->id,
        'assigned_user_id' => $uploader->id,
    ]);
    $operation->rejectionNotifyUsers()->attach($watcher->id);
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->id,
        'storage_path' => null,
        'uploaded_by' => $uploader->id,
        'status' => 'in_review',
        'current_stage' => 1,
    ]);
    $measurement->reviews()->create([
        'stage' => 1,
        'reviewer_user_id' => $engineer->id,
        'status' => 'pending',
    ]);

    app(MeasurementWorkflow::class)->reject($measurement, $engineer, 'Faltam documentos');

    Notification::assertSentTo(
        $watcher,
        MeasurementWorkflowNotification::class,
        fn (MeasurementWorkflowNotification $notification): bool => $notification->event === 'rejected'
            && $notification->toMail($watcher)->viewData['url'] === null,
    );
    Notification::assertSentTo(
        $uploader,
        MeasurementWorkflowNotification::class,
        fn (MeasurementWorkflowNotification $notification): bool => $notification->event === 'rejected'
            && filled($notification->toMail($uploader)->viewData['url']),
    );

    expect(Gate::forUser($watcher)->allows('view', $measurement->fresh()))->toBeFalse();
});

it('ignores a forged rejection-recipient payload from a coordinator without manage-responsibilities', function () {
    $coordinator = rejectionRecipientEditor();
    $outsider = rejectionRecipientEditor();
    $operation = rejectionRecipientEditableOperation(['assigned_user_id' => $coordinator->id]);
    $this->actingAs($coordinator);

    $page = Livewire::test(EditOperation::class, ['record' => $operation->getRouteKey()])
        ->fillForm(['rejectionNotifyUsers' => [$outsider->id]])
        ->call('save');

    expect($operation->rejectionNotifyUsers()->count())->toBe(0)
        ->and(rejectionRecipientActivities()->count())->toBe(0)
        ->and(Gate::forUser($outsider)->allows('view', $operation->fresh()))->toBeFalse();

    $page->assertFormFieldDisabled('rejectionNotifyUsers');
});

it('lets a responsibility manager change rejection recipients and records who changed what', function () {
    $admin = makeAdminUser();
    $ana = User::factory()->create(['name' => 'Ana']);
    $bruno = User::factory()->create(['name' => 'Bruno']);
    $operation = rejectionRecipientEditableOperation();
    $this->actingAs($admin);

    Livewire::test(EditOperation::class, ['record' => $operation->getRouteKey()])
        ->assertFormFieldEnabled('rejectionNotifyUsers')
        ->fillForm(['rejectionNotifyUsers' => [$ana->id, $bruno->id]])
        ->call('save')
        ->assertHasNoFormErrors();

    Livewire::test(EditOperation::class, ['record' => $operation->getRouteKey()])
        ->fillForm(['rejectionNotifyUsers' => [$bruno->id]])
        ->call('save')
        ->assertHasNoFormErrors();

    $activity = rejectionRecipientActivities()->latest('id')->first();

    expect($operation->rejectionNotifyUsers()->pluck('users.id')->all())->toBe([$bruno->id])
        ->and(rejectionRecipientActivities()->count())->toBe(2)
        ->and($activity->causer_id)->toBe($admin->id)
        ->and($activity->subject?->is($operation))->toBeTrue()
        ->and($activity->description)->toBe('operation_rejection_recipients_updated')
        ->and($activity->properties['added'])->toBe([])
        ->and($activity->properties['removed'])->toBe([['id' => $ana->id, 'name' => 'Ana']])
        ->and($activity->properties['recipients'])->toBe([['id' => $bruno->id, 'name' => 'Bruno']]);
});

it('writes nothing when the rejection recipients did not change', function () {
    $admin = makeAdminUser();
    $bystander = rejectionRecipientEditor();
    $recipient = User::factory()->create();
    $operation = Operation::factory()->create();
    $service = app(OperationResponsibilityService::class);
    $service->syncRejectionRecipients($admin, $operation, [$recipient->id]);

    expect($service->syncRejectionRecipients($admin, $operation, [(string) $recipient->id]))
        ->toBe(['added' => [], 'removed' => []])
        // Sem mudança não há o que autorizar: quem só salva a operação sem
        // mexer na lista não precisa da permissão de gerir responsáveis.
        ->and($service->syncRejectionRecipients($bystander, $operation, [$recipient->id]))
        ->toBe(['added' => [], 'removed' => []])
        ->and(rejectionRecipientActivities()->count())->toBe(1)
        ->and($operation->rejectionNotifyUsers()->pluck('users.id')->all())->toBe([$recipient->id]);
});

it('refuses an ineligible new recipient but lets an existing one be removed', function (string $ineligibility) {
    $admin = makeAdminUser();
    $listed = User::factory()->create();
    $operation = Operation::factory()->create();
    $service = app(OperationResponsibilityService::class);
    $service->syncRejectionRecipients($admin, $operation, [$listed->id]);
    $candidate = match ($ineligibility) {
        'inactive' => User::factory()->create(['is_active' => false]),
        'unapproved' => User::factory()->unapproved()->create(),
    };

    expect(fn () => $service->syncRejectionRecipients($admin, $operation, [$listed->id, $candidate->id]))
        ->toThrow(ValidationException::class, 'ativos e provisionados')
        ->and($operation->rejectionNotifyUsers()->pluck('users.id')->all())->toBe([$listed->id]);

    $listed->forceFill(match ($ineligibility) {
        'inactive' => ['is_active' => false],
        'unapproved' => ['approved_at' => null],
    })->save();

    expect($service->syncRejectionRecipients($admin, $operation, []))
        ->toBe(['added' => [], 'removed' => [$listed->id]])
        ->and($operation->rejectionNotifyUsers()->count())->toBe(0);
})->with([
    'inactive' => ['inactive'],
    'unapproved' => ['unapproved'],
]);

it('refuses rejection-recipient changes on a terminal operation', function (string $status) {
    $admin = makeAdminUser();
    $listed = User::factory()->create();
    $newcomer = User::factory()->create();
    $operation = Operation::factory()->create(['status' => $status]);
    $operation->rejectionNotifyUsers()->attach($listed->id);

    expect(fn () => app(OperationResponsibilityService::class)->syncRejectionRecipients($admin, $operation, [$newcomer->id]))
        ->toThrow(OperationLifecycleException::class, 'não podem ser alterados')
        ->and($operation->rejectionNotifyUsers()->pluck('users.id')->all())->toBe([$listed->id])
        ->and(rejectionRecipientActivities()->count())->toBe(0);
})->with([
    'completed' => ['completed'],
    'canceled' => ['canceled'],
]);

it('shows the closed-operation refusal on the rejection recipients field', function () {
    $admin = makeAdminUser();
    $newcomer = User::factory()->create();
    $operation = rejectionRecipientEditableOperation(['status' => 'completed']);
    $this->actingAs($admin);

    $page = Livewire::test(EditOperation::class, ['record' => $operation->getRouteKey()])
        ->fillForm(['rejectionNotifyUsers' => [$newcomer->id]])
        ->call('save');

    expect($page->errors()->get('data.rejectionNotifyUsers'))
        ->toBe(['Os responsáveis de uma operação em "Concluída" não podem ser alterados. Reabra a operação para retomar a configuração.'])
        ->and($operation->rejectionNotifyUsers()->count())->toBe(0)
        ->and(rejectionRecipientActivities()->count())->toBe(0);
});

it('requires manage-responsibilities outside Filament too', function () {
    $participant = rejectionRecipientEditor();
    $outsider = rejectionRecipientEditor();
    $recipient = User::factory()->create();
    $operation = Operation::factory()->create(['assigned_user_id' => $participant->id]);
    $service = app(OperationResponsibilityService::class);

    expect(fn () => $service->syncRejectionRecipients($participant, $operation, [$recipient->id]))
        ->toThrow(AuthorizationException::class);

    $outsider->givePermissionTo('operations.manage-responsibilities');

    expect(fn () => $service->syncRejectionRecipients($outsider, $operation, [$recipient->id]))
        ->toThrow(AuthorizationException::class)
        ->and($operation->rejectionNotifyUsers()->count())->toBe(0);

    $participant->givePermissionTo('operations.manage-responsibilities');
    $created = Operation::factory()->create(['status' => 'draft']);

    expect($service->syncRejectionRecipients($participant, $operation, [$recipient->id]))
        ->toBe(['added' => [$recipient->id], 'removed' => []])
        // Na criação vale a permissão de classe, como nos sete responsáveis:
        // quem a tem define a lista mesmo sem participar da operação nova.
        ->and($service->syncRejectionRecipients($outsider, $created, [$recipient->id], onCreation: true))
        ->toBe(['added' => [$recipient->id], 'removed' => []]);
});

it('records the rejection recipients chosen when creating an operation', function (string $creator) {
    $recipient = User::factory()->create(['name' => 'Carla']);

    if ($creator === 'admin') {
        $actor = makeAdminUser();
        $emissionId = Emission::factory()->create()->id;
        $constructionId = Construction::factory()->create(['emission_id' => $emissionId])->id;
    } else {
        // Gestor de responsáveis fora do perfil administrativo: a emissão e o
        // empreendimento só lhe são visíveis por outra operação que ele coordena,
        // e ele não participa da operação nova.
        $actor = rejectionRecipientEditor();
        $actor->givePermissionTo('operations.manage-responsibilities');
        $coordinated = rejectionRecipientEditableOperation(['assigned_user_id' => $actor->id]);
        $emissionId = $coordinated->emission_id;
        $constructionId = $coordinated->planSets()->value('construction_id');
    }

    $this->actingAs($actor);

    Livewire::test(CreateOperation::class)
        ->assertFormFieldEnabled('rejectionNotifyUsers')
        ->fillForm(['emission_id' => $emissionId])
        ->fillForm([
            'developments' => [
                ['construction_id' => $constructionId, 'construction_fund_amount' => '100.000,00'],
            ],
            'rejectionNotifyUsers' => [$recipient->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $operation = Operation::query()->latest('id')->first();
    $activity = rejectionRecipientActivities()->sole();

    expect($operation->rejectionNotifyUsers()->pluck('users.id')->all())->toBe([$recipient->id])
        ->and($activity->causer_id)->toBe($actor->id)
        ->and($activity->subject?->is($operation))->toBeTrue()
        ->and($activity->properties['added'])->toBe([['id' => $recipient->id, 'name' => 'Carla']])
        ->and($activity->properties['removed'])->toBe([]);
})->with([
    'admin' => ['admin'],
    'responsibility manager outside the operation' => ['manager'],
]);

it('keeps the rejection recipients field read-only for a creator without manage-responsibilities', function () {
    $this->actingAs(rejectionRecipientEditor());

    Livewire::test(CreateOperation::class)
        ->assertFormFieldDisabled('rejectionNotifyUsers');
});

it('asks to try again and saves nothing when the database refuses the rejection recipients because of a concurrent update', function (string $failure) {
    Exceptions::fake();
    $admin = makeAdminUser();
    $ana = User::factory()->create(['name' => 'Ana']);
    $operation = rejectionRecipientEditableOperation();
    $responsibleBefore = $operation->responsible_user_id;
    $exception = DatabaseConcurrencyFailure::make($failure);
    rejectionRecipientSyncFailsWith($exception);
    $this->actingAs($admin);

    // A lista é gravada antes da operação: a recusa para a gravação inteira,
    // inclusive o responsável trocado no mesmo envio.
    $page = Livewire::test(EditOperation::class, ['record' => $operation->getRouteKey()])
        ->fillForm(['rejectionNotifyUsers' => [$ana->id], 'responsible_user_id' => $ana->id]);

    if (! DatabaseConcurrencyFailure::isConcurrency($failure)) {
        expect(fn () => $page->call('save'))->toThrow(QueryException::class);

        return;
    }

    $page->call('save');

    expect(rejectionRecipientNotifications())->toBe([[
        'title' => 'Operação não atualizada.',
        'body' => 'Outra pessoa está atualizando esta operação. Tente novamente em instantes.',
        'status' => 'danger',
    ]])
        ->and($page->errors()->keys())->toBe([])
        ->and($operation->fresh()->responsible_user_id)->toBe($responsibleBefore)
        ->and($operation->rejectionNotifyUsers()->count())->toBe(0);

    $page->assertNoRedirect();
    expect(collect(Exceptions::reported())->contains(fn (Throwable $reported): bool => ($reported === $exception) || ($reported->getPrevious() === $exception)))->toBeTrue();
})->with(DatabaseConcurrencyFailure::CASES);

it('creates the operation and warns that its rejection recipients were not saved when the database refuses them because of a concurrent update', function () {
    Exceptions::fake();
    $admin = makeAdminUser();
    $recipient = User::factory()->create();
    $emissionId = Emission::factory()->create()->id;
    $constructionId = Construction::factory()->create(['emission_id' => $emissionId])->id;
    $exception = DatabaseConcurrencyFailure::make('lock wait timeout');
    rejectionRecipientSyncFailsWith($exception);
    $this->actingAs($admin);

    // Sem transação na página, a operação já está gravada quando a lista é
    // gravada: tentar de novo criaria outra operação. Ela segue sem a lista.
    $page = Livewire::test(CreateOperation::class)
        ->fillForm(['emission_id' => $emissionId])
        ->fillForm([
            'developments' => [
                ['construction_id' => $constructionId, 'construction_fund_amount' => '100.000,00'],
            ],
            'rejectionNotifyUsers' => [$recipient->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $operations = Operation::query()->where('emission_id', $emissionId)->get();

    expect($operations)->toHaveCount(1)
        ->and($operations->first()->rejectionNotifyUsers()->count())->toBe(0)
        ->and(collect(rejectionRecipientNotifications())->firstWhere('title', 'Lista de notificação de recusa não gravada.'))->toBe([
            'title' => 'Lista de notificação de recusa não gravada.',
            'body' => 'A operação foi criada, mas outra pessoa estava atualizando-a e a lista "Notificar em caso de recusa" ficou vazia. Edite a operação para incluir os usuários.',
            'status' => 'warning',
        ]);

    $page->assertRedirect();
    expect(collect(Exceptions::reported())->contains(fn (Throwable $reported): bool => ($reported === $exception) || ($reported->getPrevious() === $exception)))->toBeTrue();
});
