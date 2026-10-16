<?php

use App\Exceptions\Funds\ContaAzulReconciliationException;
use App\Models\BankAccount;
use App\Models\ContaAzulBillAttribution;
use App\Models\ContaAzulImportBatch;
use App\Models\ContaAzulImportedBill;
use App\Models\Emission;
use App\Models\Fund;
use App\Models\User;
use App\Services\Funds\ContaAzulBillAttributionService;
use App\Services\Funds\ContaAzulStaleBatchRecoveryService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/*
 * Phase 3B attribution on a real MySQL-compatible server: lock-serialized
 * attribution races, idempotency-key enforcement and recovery races.
 * Runs only through scripts/parity-check.sh; skips itself on any other
 * driver.
 *
 * NOTE: when the available server is MariaDB rather than MySQL 8.4, these
 * runs prove transactional behavior on MariaDB only — not MySQL 8.4
 * parity. The final report names whichever engine actually executed.
 */

pest()->group('mysql');

beforeEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requer MySQL para observar locks e UNIQUEs reais. Execute pelo scripts/parity-check.sh com este arquivo.');
    }

    $this->assertStringStartsWith(
        'nimbus_parity_check',
        DB::connection()->getDatabaseName(),
        'Estes testes recriam o banco. Use um banco temporário descartável (nimbus_parity_check_*).',
    );

    Artisan::call('migrate:fresh', ['--no-interaction' => true]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * @param  array{actor_id: int, bill_id: int, emission_id: int, fingerprint: string, reason: string}  $instruction
 */
function contaAzulMysqlAttributeTask(array $instruction): Closure
{
    return static function () use ($instruction): array {
        try {
            $attribution = app(ContaAzulBillAttributionService::class)->attribute(
                User::query()->findOrFail($instruction['actor_id']),
                ContaAzulImportedBill::query()->findOrFail($instruction['bill_id']),
                Emission::query()->findOrFail($instruction['emission_id']),
                $instruction['reason'],
                $instruction['fingerprint'],
            );

            return ['success' => true, 'attribution_id' => $attribution->id, 'exception' => null];
        } catch (Throwable $exception) {
            return ['success' => false, 'attribution_id' => null, 'exception' => $exception::class];
        }
    };
}

/**
 * @param  array{actor_id: int, batch_id: int, reason: string}  $instruction
 */
function contaAzulMysqlRecoverTask(array $instruction): Closure
{
    return static function () use ($instruction): array {
        try {
            $batch = app(ContaAzulStaleBatchRecoveryService::class)->recover(
                User::query()->findOrFail($instruction['actor_id']),
                ContaAzulImportBatch::query()->findOrFail($instruction['batch_id']),
                $instruction['reason'],
            );

            return ['success' => true, 'status' => $batch->status, 'exception' => null];
        } catch (Throwable $exception) {
            return ['success' => false, 'status' => null, 'exception' => $exception::class];
        }
    };
}

function contaAzulMysqlScenario(): array
{
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $actorA = User::factory()->create();
    $actorA->givePermissionTo(['conta-azul-imports.attribute', 'emissions.view']);
    $actorB = User::factory()->create();
    $actorB->givePermissionTo(['conta-azul-imports.attribute', 'emissions.view']);

    $account = BankAccount::factory()->create(['conta_azul_account_id' => 'ca-acc-mysql-attr']);
    $emissionA = Emission::factory()->create(['name' => 'Emissão MySQL A']);
    $emissionB = Emission::factory()->create(['name' => 'Emissão MySQL B']);
    Fund::factory()->create(['bank_account_id' => $account->id, 'emission_id' => $emissionA->id]);
    Fund::factory()->create(['bank_account_id' => $account->id, 'emission_id' => $emissionB->id]);

    $batch = ContaAzulImportBatch::factory()->create([
        'bank_account_id' => $account->id,
        'conta_azul_account_id' => $account->conta_azul_account_id,
        'status' => ContaAzulImportBatch::STATUS_COMPLETED,
    ]);

    $bill = ContaAzulImportedBill::factory()->create([
        'conta_azul_import_batch_id' => $batch->id,
        'content_batch_id' => $batch->id,
        'bank_account_id' => $account->id,
        'reconciliation_status' => ContaAzulImportedBill::RECONCILIATION_READY_FOR_REVIEW,
    ]);

    return [$admin, $actorA, $actorB, $account, $emissionA, $emissionB, $batch, $bill->refresh()];
}

it('commits exactly one attribution when two users race the same bill', function () {
    [$admin, $actorA, $actorB, $account, $emissionA, $emissionB, $batch, $bill] = contaAzulMysqlScenario();

    $results = Concurrency::driver('process')->run([
        contaAzulMysqlAttributeTask([
            'actor_id' => $actorA->id,
            'bill_id' => $bill->id,
            'emission_id' => $emissionA->id,
            'fingerprint' => $bill->payload_fingerprint,
            'reason' => 'Decisão concorrente da usuária A.',
        ]),
        contaAzulMysqlAttributeTask([
            'actor_id' => $actorB->id,
            'bill_id' => $bill->id,
            'emission_id' => $emissionB->id,
            'fingerprint' => $bill->payload_fingerprint,
            'reason' => 'Decisão concorrente do usuário B.',
        ]),
    ]);

    $successes = collect($results)->where('success', true);

    expect($successes)->toHaveCount(1)
        ->and(ContaAzulBillAttribution::query()->where('conta_azul_imported_bill_id', $bill->id)->count())->toBe(1)
        ->and($bill->refresh()->isAttributed())->toBeTrue();

    $loser = collect($results)->firstWhere('success', false);

    expect($loser['exception'])->toBe(ContaAzulReconciliationException::class);
});

it('recovers a stale batch exactly once under concurrent recovery', function () {
    $recovererA = User::factory()->create();
    $recovererA->givePermissionTo(['conta-azul-imports.recover']);
    $recovererB = User::factory()->create();
    $recovererB->givePermissionTo(['conta-azul-imports.recover']);

    $account = BankAccount::factory()->create(['conta_azul_account_id' => 'ca-acc-mysql-recover']);

    $victim = ContaAzulImportBatch::factory()->create([
        'bank_account_id' => $account->id,
        'conta_azul_account_id' => $account->conta_azul_account_id,
        'status' => ContaAzulImportBatch::STATUS_RUNNING,
        'started_at' => now()->subHours(3),
        'last_heartbeat_at' => now()->subHours(2),
    ]);

    $results = Concurrency::driver('process')->run([
        contaAzulMysqlRecoverTask(['actor_id' => $recovererA->id, 'batch_id' => $victim->id, 'reason' => 'Recuperação concorrente A.']),
        contaAzulMysqlRecoverTask(['actor_id' => $recovererB->id, 'batch_id' => $victim->id, 'reason' => 'Recuperação concorrente B.']),
    ]);

    $successes = collect($results)->where('success', true);

    expect($successes)->toHaveCount(1)
        ->and($victim->refresh()->status)->toBe(ContaAzulImportBatch::STATUS_ABANDONED);

    $loser = collect($results)->firstWhere('success', false);

    expect($loser['exception'])->toBe(ContaAzulReconciliationException::class);
});
