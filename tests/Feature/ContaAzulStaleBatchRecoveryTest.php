<?php

use App\Exceptions\Funds\ContaAzulReconciliationException;
use App\Models\ContaAzulImportBatch;
use App\Models\ContaAzulImportedBill;
use App\Services\Funds\ContaAzulBillIngestionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\ContaAzulReconciliationFixture as Staging;
use Tests\Support\FundAllocationFixture;

/*
 * Phase 3B: stale import batch recovery.
 *
 * A batch left `running` by a dead process is recovered explicitly: it
 * moves to the terminal, retriable `abandoned` status with full actor and
 * reason provenance. Staged bills are never deleted, incomplete batches
 * are never marked completed, and imports with recent heartbeats are
 * protected from recovery.
 */

pest()->group('parity');

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

function abandonedBatchScenario(): array
{
    $admin = FundAllocationFixture::userWithRole('admin');
    $recoverer = FundAllocationFixture::userWithPermissions(['conta-azul-imports.recover']);
    $account = Staging::account();

    // A first, honest import stages bills, then a second attempt dies
    // mid-flight: the batch below simulates the SIGKILL victim.
    Staging::ingest($admin, $account, [Staging::page([Staging::bill(['id' => 'bill-survivor'])])]);

    $victim = ContaAzulImportBatch::factory()->create([
        'bank_account_id' => $account->id,
        'conta_azul_account_id' => $account->conta_azul_account_id,
        'status' => ContaAzulImportBatch::STATUS_RUNNING,
        'started_at' => now()->subHours(3),
        'last_heartbeat_at' => now()->subHours(2),
        'pages_completed' => 1,
        'records_received' => 1,
        'bills_staged' => 1,
    ]);

    return [$admin, $recoverer, $account, $victim];
}

it('recovers an abandoned running batch without touching staged bills', function () {
    [$admin, $recoverer, $account, $victim] = abandonedBatchScenario();

    $billCount = ContaAzulImportedBill::query()->count();
    $snapshot = Staging::operationalSnapshot();

    $recovered = Staging::recoveryService()->recover(
        $recoverer, $victim, 'Processo encerrado pelo OOM killer durante a página 2.',
    );

    expect($recovered->status)->toBe(ContaAzulImportBatch::STATUS_ABANDONED)
        ->and($recovered->isTerminal())->toBeTrue()
        ->and($recovered->isRetriable())->toBeTrue()
        ->and($recovered->error_code)->toBe('stale_recovered')
        ->and($recovered->finished_at)->not->toBeNull()
        ->and($recovered->recovered_by_user_id)->toBe($recoverer->id)
        ->and($recovered->recovered_at)->not->toBeNull()
        ->and($recovered->recovered_from_status)->toBe(ContaAzulImportBatch::STATUS_RUNNING)
        ->and($recovered->recovery_reason)->toContain('OOM killer')
        ->and(ContaAzulImportedBill::query()->count())->toBe($billCount)
        ->and(ContaAzulImportedBill::query()->where('conta_azul_bill_id', 'bill-survivor')->exists())->toBeTrue()
        ->and(Staging::operationalSnapshot())->toBe($snapshot);

    $event = Activity::query()->where('description', 'import_batch_recovered')->firstOrFail();

    expect($event->properties->toArray()['previous_status'])->toBe(ContaAzulImportBatch::STATUS_RUNNING);
});

it('retries after recovery without duplicating bills', function () {
    [$admin, $recoverer, $account, $victim] = abandonedBatchScenario();

    Staging::recoveryService()->recover($recoverer, $victim, 'Abandono confirmado via logs do supervisor.');

    $record = Staging::bill(['id' => 'bill-survivor']);

    $retry = app(ContaAzulBillIngestionService::class)->retry(
        $admin, $victim->refresh(), [Staging::page([$record])],
    );

    expect($retry->status)->toBe(ContaAzulImportBatch::STATUS_COMPLETED)
        ->and($retry->retries_batch_id)->toBe($victim->id)
        ->and($retry->bills_reobserved)->toBe(1)
        ->and($retry->bills_staged)->toBe(0)
        ->and(ContaAzulImportedBill::query()->where('conta_azul_bill_id', 'bill-survivor')->count())->toBe(1);
});

it('refuses a second recovery of the same batch', function () {
    [$admin, $recoverer, $account, $victim] = abandonedBatchScenario();

    Staging::recoveryService()->recover($recoverer, $victim, 'Primeira recuperação.');

    expect(fn () => Staging::recoveryService()->recover($recoverer, $victim->refresh(), 'Segunda recuperação concorrente.'))
        ->toThrow(ContaAzulReconciliationException::class);

    expect(Activity::query()->where('description', 'import_batch_recovered')->count())->toBe(1);
});

it('protects an active long-running batch from recovery', function () {
    [$admin, $recoverer, $account] = abandonedBatchScenario();

    $active = ContaAzulImportBatch::factory()->create([
        'bank_account_id' => $account->id,
        'conta_azul_account_id' => $account->conta_azul_account_id,
        'status' => ContaAzulImportBatch::STATUS_RUNNING,
        'started_at' => now()->subHours(5),
        'last_heartbeat_at' => now()->subSeconds(30),
    ]);

    expect(fn () => Staging::recoveryService()->recover($recoverer, $active, 'Tentativa contra lote ativo.'))
        ->toThrow(ContaAzulReconciliationException::class);

    expect($active->refresh()->status)->toBe(ContaAzulImportBatch::STATUS_RUNNING);
});

it('lists only genuinely stale batches for recovery', function () {
    [$admin, $recoverer, $account, $victim] = abandonedBatchScenario();

    $staleIds = Staging::recoveryService()->staleBatches()->pluck('id')->all();

    expect($staleIds)->toContain($victim->id);

    $completed = ContaAzulImportBatch::query()
        ->where('status', ContaAzulImportBatch::STATUS_COMPLETED)
        ->firstOrFail();

    expect($staleIds)->not->toContain($completed->id);
});

it('requires the recovery permission and a reason', function () {
    [$admin, $recoverer, $account, $victim] = abandonedBatchScenario();
    $stranger = FundAllocationFixture::userWithPermissions(['conta-azul-imports.view']);

    expect(fn () => Staging::recoveryService()->recover($stranger, $victim, 'Motivo com permissão insuficiente.'))
        ->toThrow(ContaAzulReconciliationException::class);

    expect(fn () => Staging::recoveryService()->recover($recoverer, $victim, 'curto'))
        ->toThrow(ContaAzulReconciliationException::class);

    expect($victim->refresh()->status)->toBe(ContaAzulImportBatch::STATUS_RUNNING);
});

it('recovers through the explicitly invoked command', function () {
    [$admin, $recoverer, $account, $victim] = abandonedBatchScenario();

    $exit = Artisan::call('conta-azul:recover-stale-batch', [
        'batch' => $victim->id,
        '--reason' => 'Recuperação via comando após reinício do servidor.',
        '--as-user' => (string) $recoverer->id,
    ]);

    expect($exit)->toBe(0)
        ->and($victim->refresh()->status)->toBe(ContaAzulImportBatch::STATUS_ABANDONED)
        ->and($victim->recovered_by_user_id)->toBe($recoverer->id);
});

it('refuses the command without an actor or against an active batch', function () {
    [$admin, $recoverer, $account, $victim] = abandonedBatchScenario();

    $exit = Artisan::call('conta-azul:recover-stale-batch', [
        'batch' => $victim->id,
        '--reason' => 'Sem responsável informado.',
    ]);

    expect($exit)->not->toBe(0)
        ->and($victim->refresh()->status)->toBe(ContaAzulImportBatch::STATUS_RUNNING);

    $victim->update(['last_heartbeat_at' => now()]);

    $exit = Artisan::call('conta-azul:recover-stale-batch', [
        'batch' => $victim->id,
        '--reason' => 'Tentativa contra lote com atividade recente.',
        '--as-user' => (string) $recoverer->id,
    ]);

    expect($exit)->not->toBe(0)
        ->and($victim->refresh()->status)->toBe(ContaAzulImportBatch::STATUS_RUNNING);
});
