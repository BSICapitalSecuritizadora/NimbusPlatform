<?php

use App\Enums\OperationStatus;
use App\Filament\Resources\Activities\ActivityResource;
use App\Models\Measurement;
use App\Models\MeasurementPayment;
use App\Models\MeasurementPlanSet;
use App\Models\Operation;
use App\Models\ResponsibilityDelegation;
use App\Models\User;
use App\Services\MeasurementWorkflow;
use App\Services\OperationLifecycleService;
use App\Services\ResponsibilityDelegationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
});

function auditAdmin(): User
{
    $user = User::factory()->withTwoFactor()->create();
    $user->assignRole('admin');

    return $user;
}

/**
 * O log_name da última Activity gravada sobre um registro.
 */
function lastLogNameFor(object $subject, ?string $description = null): ?string
{
    return Activity::query()
        ->where('subject_type', $subject::class)
        ->where('subject_id', $subject->getKey())
        ->when($description !== null, fn ($query) => $query->where('description', $description))
        ->latest('id')
        ->value('log_name');
}

// ── Produtores ────────────────────────────────────────────────────────────────

it('files the measurement attribute trail under the measurements log', function () {
    $admin = auditAdmin();
    $this->actingAs($admin);
    $operation = Operation::factory()->create(['assigned_user_id' => $admin->getKey()]);
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->getKey(),
        'status' => 'pending',
        'current_stage' => 1,
    ]);

    expect(lastLogNameFor($measurement, 'created'))->toBe('measurements');

    $measurement->update(['status' => 'in_review', 'current_stage' => 2]);

    $updated = Activity::query()
        ->where('subject_type', Measurement::class)
        ->where('subject_id', $measurement->getKey())
        ->where('description', 'updated')
        ->latest('id')
        ->first();

    expect($updated->log_name)->toBe('measurements')
        ->and($updated->properties['attributes']['status'])->toBe('in_review')
        ->and($updated->properties['attributes']['current_stage'])->toBe(2);
});

it('keeps the workflow decision trail in its own category, alongside the attribute trail', function () {
    $admin = auditAdmin();
    $this->actingAs($admin);
    $operation = Operation::factory()->create([
        'status' => OperationStatus::Active,
        'assigned_user_id' => $admin->getKey(),
        'responsible_user_id' => $admin->getKey(),
        'stage2_reviewer_user_id' => $admin->getKey(),
        'stage3_reviewer_user_id' => $admin->getKey(),
    ]);
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->getKey(),
        'status' => 'in_review',
        'current_stage' => 2,
    ]);
    $measurement->reviews()->create(['stage' => 1, 'status' => 'approved', 'reviewed_at' => now()]);
    $measurement->reviews()->create(['stage' => 2, 'status' => 'pending']);

    app(MeasurementWorkflow::class)->approve($measurement->fresh(), $admin, 'Aprovação de gestão.');

    // As duas trilhas continuam existindo e são complementares: a decisão fica em
    // `measurement_workflow`, a mudança de coluna em `measurements`. O que mudou
    // é que as duas têm a mesma retenção.
    expect(Activity::query()
        ->where('log_name', 'measurement_workflow')
        ->where('description', 'measurement_stage_approved')
        ->exists())->toBeTrue()
        ->and(Activity::query()
            ->where('log_name', 'measurements')
            ->where('subject_id', $measurement->getKey())
            ->where('description', 'updated')
            ->exists())->toBeTrue()
        ->and(Activity::query()
            ->where('log_name', 'default')
            ->where('subject_type', Measurement::class)
            ->count())->toBe(0);
});

it('files an admin override under the workflow category, unchanged', function () {
    $admin = auditAdmin();
    $this->actingAs($admin);
    // O admin não detém nenhuma responsabilidade nesta operação: a ação passa
    // pelo bypass administrativo, e a origem registrada continua sendo a da P2.3.
    $operation = Operation::factory()->create(['status' => OperationStatus::Active]);
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->getKey(),
        'status' => 'in_review',
        'current_stage' => 2,
    ]);
    $measurement->reviews()->create(['stage' => 1, 'status' => 'approved', 'reviewed_at' => now()]);
    $measurement->reviews()->create(['stage' => 2, 'status' => 'pending']);

    app(MeasurementWorkflow::class)->approve($measurement->fresh(), $admin, 'Override.');

    $activity = Activity::query()
        ->where('description', 'measurement_stage_approved')
        ->latest('id')
        ->first();

    expect($activity->log_name)->toBe('measurement_workflow')
        ->and($activity->properties['admin_override'])->toBeTrue();
});

it('files the payment and receipt evidence under the measurement payments log', function () {
    $admin = auditAdmin();
    $this->actingAs($admin);
    $operation = Operation::factory()->create(['assigned_user_id' => $admin->getKey()]);
    $planSet = MeasurementPlanSet::factory()->create(['operation_id' => $operation->getKey()]);
    $measurement = Measurement::factory()->create(['operation_id' => $operation->getKey()]);

    $payment = MeasurementPayment::query()->create([
        'operation_id' => $operation->getKey(),
        'measurement_id' => $measurement->getKey(),
        'plan_set_id' => $planSet->getKey(),
        'pay_date' => '2026-09-01',
        'amount' => 125000.50,
    ]);

    expect(lastLogNameFor($payment, 'created'))->toBe('measurement_payments');

    $payment->update(['receipt_sha256' => str_repeat('a', 64)]);

    expect(lastLogNameFor($payment, 'updated'))->toBe('measurement_payments');
});

it('files both halves of the delegation history under the delegations log', function () {
    $admin = auditAdmin();
    $delegator = User::factory()->create();
    $delegate = User::factory()->create();
    $delegator->givePermissionTo('measurements.review');
    $delegate->givePermissionTo('measurements.review');
    $operation = Operation::factory()->create([
        'status' => OperationStatus::Active,
        'responsible_user_id' => $delegator->getKey(),
    ]);

    $delegation = app(ResponsibilityDelegationService::class)->createDelegation([
        'delegator_user_id' => $delegator->getKey(),
        'delegate_user_id' => $delegate->getKey(),
        'scope_type' => 'operation',
        'scope_operation_id' => $operation->getKey(),
        'starts_at' => now()->subMinute(),
        'ends_at' => now()->addDays(5),
        'reason' => 'Cobertura de férias da Engenharia.',
    ], $admin);

    app(ResponsibilityDelegationService::class)->revokeDelegation($delegation, $admin, 'Não é mais necessária.');

    // O evento explícito e a mudança de coluna do próprio modelo caem na mesma
    // categoria: o histórico de delegação é indivisível.
    expect(Activity::query()
        ->where('subject_type', ResponsibilityDelegation::class)
        ->where('subject_id', $delegation->getKey())
        ->pluck('log_name')
        ->unique()
        ->all())->toBe(['delegations']);
});

it('keeps the operation trail under the operations log', function () {
    $admin = auditAdmin();
    $this->actingAs($admin);
    $operation = Operation::factory()->create([
        'status' => OperationStatus::Active,
        'assigned_user_id' => $admin->getKey(),
    ]);

    $operation->update(['title' => 'Título revisado']);
    app(OperationLifecycleService::class)->cancel($operation, $admin, 'Obra suspensa.');

    expect(Activity::query()
        ->where('subject_type', Operation::class)
        ->where('subject_id', $operation->getKey())
        ->pluck('log_name')
        ->unique()
        ->all())->toBe(['operations']);
});

it('leaves no measurement-aggregate trail in the disposable bucket', function () {
    $admin = auditAdmin();
    $this->actingAs($admin);
    $operation = Operation::factory()->create(['assigned_user_id' => $admin->getKey()]);
    $planSet = MeasurementPlanSet::factory()->create(['operation_id' => $operation->getKey()]);
    $measurement = Measurement::factory()->create(['operation_id' => $operation->getKey()]);
    MeasurementPayment::query()->create([
        'operation_id' => $operation->getKey(),
        'measurement_id' => $measurement->getKey(),
        'plan_set_id' => $planSet->getKey(),
        'pay_date' => '2026-09-01',
        'amount' => 1000,
    ]);

    expect(Activity::query()
        ->whereIn('subject_type', [Measurement::class, MeasurementPayment::class, Operation::class])
        ->where(fn ($query) => $query->where('log_name', 'default')->orWhereNull('log_name'))
        ->count())->toBe(0);
});

// ── Retenção efetiva ──────────────────────────────────────────────────────────

it('survives the disposable window in every category the aggregates now write to', function (string $logName) {
    $disposableDays = (int) config('audit.retention_disposable_days', 365);

    $this->travelTo(now()->startOfSecond());

    $activity = Activity::query()->create([
        'log_name' => $logName,
        'description' => 'evidência regulada',
        'properties' => json_encode([]),
        'created_at' => now()->subDays($disposableDays + 10),
        'updated_at' => now()->subDays($disposableDays + 10),
    ]);

    $this->artisan('audit:clean-filtered')->assertExitCode(0);

    expect(Activity::query()->whereKey($activity->getKey())->exists())->toBeTrue();
})->with(['measurements', 'measurement_payments', 'delegations', 'operations', 'measurement_workflow', 'measurement_file_access']);

it('still discards the same-age generic trail', function () {
    $disposableDays = (int) config('audit.retention_disposable_days', 365);

    $this->travelTo(now()->startOfSecond());

    $disposable = Activity::query()->create([
        'log_name' => 'default',
        'description' => 'registro genérico',
        'properties' => json_encode([]),
        'created_at' => now()->subDays($disposableDays + 10),
        'updated_at' => now()->subDays($disposableDays + 10),
    ]);

    $this->artisan('audit:clean-filtered')->assertExitCode(0);

    expect(Activity::query()->whereKey($disposable->getKey())->exists())->toBeFalse();
});

it('discards protected evidence only past the workflow window', function () {
    $workflowDays = (int) config('audit.retention_workflow_days', 2555);

    $this->travelTo(now()->startOfSecond());

    $expired = Activity::query()->create([
        'log_name' => 'measurements',
        'description' => 'evidência expirada',
        'properties' => json_encode([]),
        'created_at' => now()->subDays($workflowDays + 10),
        'updated_at' => now()->subDays($workflowDays + 10),
    ]);

    $this->artisan('audit:clean-filtered')->assertExitCode(0);

    expect(Activity::query()->whereKey($expired->getKey())->exists())->toBeFalse();
});

// ── Política ──────────────────────────────────────────────────────────────────

it('protects the category every audited aggregate actually writes to', function (string $model, string $logName) {
    // O elo que faltava: o produtor declara a categoria e a política a protege.
    // Enquanto as duas listas viviam separadas, um agregado podia gravar numa
    // categoria que a limpeza não conhecia -- e ninguém percebia.
    expect((new $model)->getActivitylogOptions()->logName)->toBe($logName)
        ->and(config('audit.protected_logs'))->toContain($logName);
})->with([
    'Measurement' => [Measurement::class, 'measurements'],
    'MeasurementPayment' => [MeasurementPayment::class, 'measurement_payments'],
    'ResponsibilityDelegation' => [ResponsibilityDelegation::class, 'delegations'],
    'Operation' => [Operation::class, 'operations'],
]);

it('reads the protected list from the config instead of a private copy', function () {
    $disposableDays = (int) config('audit.retention_disposable_days', 365);

    $this->travelTo(now()->startOfSecond());

    $activity = Activity::query()->create([
        'log_name' => 'measurements',
        'description' => 'evidência regulada',
        'properties' => json_encode([]),
        'created_at' => now()->subDays($disposableDays + 10),
        'updated_at' => now()->subDays($disposableDays + 10),
    ]);

    // Tirar a categoria do config tem de mudar o comportamento da limpeza; se
    // não mudar, o comando voltou a decidir por conta própria.
    config()->set('audit.protected_logs', ['measurement_workflow']);

    $this->artisan('audit:clean-filtered')->assertExitCode(0);

    expect(Activity::query()->whereKey($activity->getKey())->exists())->toBeFalse();
});

it('refuses to run at all when no category is declared protected', function () {
    $disposableDays = (int) config('audit.retention_disposable_days', 365);

    $this->travelTo(now()->startOfSecond());

    $activity = Activity::query()->create([
        'log_name' => 'measurements',
        'description' => 'evidência regulada',
        'properties' => json_encode([]),
        'created_at' => now()->subDays($disposableDays + 10),
        'updated_at' => now()->subDays($disposableDays + 10),
    ]);

    // Um config vazio significa configuração quebrada, não "nada é protegido":
    // seguir em frente apagaria sete anos de evidência em nome de um engano.
    config()->set('audit.protected_logs', []);

    $this->artisan('audit:clean-filtered')->assertExitCode(1);

    expect(Activity::query()->whereKey($activity->getKey())->exists())->toBeTrue();
});

// ── Apresentação ──────────────────────────────────────────────────────────────

it('names every produced category in Portuguese instead of showing the raw slug', function (string $logName, string $label) {
    expect(ActivityResource::friendlyLogName($logName))->toBe($label);
})->with([
    ['measurements', 'Medições'],
    ['measurement_workflow', 'Fluxo de Medições'],
    ['measurement_payments', 'Pagamentos de Medição'],
    ['measurement_file_access', 'Acesso a Arquivos de Medição'],
    ['measurement_assets', 'Arquivos de Medição'],
    ['measurement_exports', 'Exportações de Medição'],
    ['delegations', 'Delegações de Responsabilidade'],
    ['operations', 'Operações'],
    ['default', 'Geral'],
]);
