<?php

use App\Exceptions\Funds\ContaAzulBillIngestionException;
use App\Models\BankAccount;
use App\Models\ContaAzulImportBatch;
use App\Models\ContaAzulImportedBill;
use App\Models\User;
use App\Services\Funds\ContaAzulBillIngestionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\FundAllocationFixture;

/*
 * Phase 3A staging on a real MySQL-compatible server: lock-serialized
 * duplicate-ingestion prevention, database-level identity and CHECK
 * enforcement, and migration round-trip under real DDL (implicit commits,
 * no transactional rollback). Runs only through scripts/parity-check.sh;
 * skips itself on any other driver.
 */

pest()->group('mysql');

beforeEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requer MySQL para observar locks, UNIQUEs, CHECKs e DDL real. Execute pelo scripts/parity-check.sh com este arquivo.');
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
 * @param  array{actor_id: int, account_id: int, bills: array<int, array<string, mixed>>, total: int}  $instruction
 */
function contaAzulMysqlIngestTask(array $instruction): Closure
{
    return static function () use ($instruction): array {
        try {
            $batch = app(ContaAzulBillIngestionService::class)->ingest(
                User::query()->findOrFail($instruction['actor_id']),
                BankAccount::query()->findOrFail($instruction['account_id']),
                [['itens' => $instruction['bills'], 'itens_totais' => $instruction['total']]],
                ['from' => '2026-01-01', 'to' => '2026-01-31'],
            );

            return ['success' => true, 'batch_id' => $batch->id, 'status' => $batch->status, 'exception' => null];
        } catch (Throwable $exception) {
            return ['success' => false, 'batch_id' => null, 'status' => null, 'exception' => $exception::class];
        }
    };
}

function contaAzulMysqlBill(string $id, float $total = 100.00): array
{
    return [
        'id' => $id,
        'status' => 'PENDING',
        'total' => $total,
        'pago' => 0,
        'nao_pago' => $total,
        'descricao' => 'DESPESA OPERACIONAL',
        'data_vencimento' => '2026-10-20',
        'categorias' => [['id' => 'cat-1', 'nome' => 'SISTEMA']],
    ];
}

it('rejects every concurrent importer while a batch is already open', function () {
    $admin = FundAllocationFixture::userWithRole('admin');
    $account = BankAccount::factory()->create(['conta_azul_account_id' => 'ca-acc-mysql-open']);

    ContaAzulImportBatch::factory()->create([
        'bank_account_id' => $account->id,
        'conta_azul_account_id' => $account->conta_azul_account_id,
        'date_from' => '2026-01-01',
        'date_to' => '2026-01-31',
        'integration_context' => 'conta_azul',
        'status' => ContaAzulImportBatch::STATUS_RUNNING,
    ]);

    $results = Concurrency::driver('process')->run([
        contaAzulMysqlIngestTask([
            'actor_id' => $admin->id,
            'account_id' => $account->id,
            'bills' => [contaAzulMysqlBill('bill-mysql-open-1')],
            'total' => 1,
        ]),
        contaAzulMysqlIngestTask([
            'actor_id' => $admin->id,
            'account_id' => $account->id,
            'bills' => [contaAzulMysqlBill('bill-mysql-open-2')],
            'total' => 1,
        ]),
    ]);

    expect($results[0]['success'])->toBeFalse()
        ->and($results[0]['exception'])->toBe(ContaAzulBillIngestionException::class)
        ->and($results[1]['success'])->toBeFalse()
        ->and($results[1]['exception'])->toBe(ContaAzulBillIngestionException::class)
        ->and(ContaAzulImportBatch::query()->count())->toBe(1)
        ->and(ContaAzulImportedBill::query()->count())->toBe(0);
});

it('stages each bill exactly once when two importers race the same window', function () {
    $admin = FundAllocationFixture::userWithRole('admin');
    $account = BankAccount::factory()->create(['conta_azul_account_id' => 'ca-acc-mysql-race']);

    // Overlapping sets: the shared bill is the create race; the loser's
    // unique violation must degrade to read-and-update, never duplicate.
    $results = Concurrency::driver('process')->run([
        contaAzulMysqlIngestTask([
            'actor_id' => $admin->id,
            'account_id' => $account->id,
            'bills' => [
                contaAzulMysqlBill('bill-mysql-race-shared', 300.00),
                contaAzulMysqlBill('bill-mysql-race-a', 100.00),
            ],
            'total' => 2,
        ]),
        contaAzulMysqlIngestTask([
            'actor_id' => $admin->id,
            'account_id' => $account->id,
            'bills' => [
                contaAzulMysqlBill('bill-mysql-race-shared', 300.00),
                contaAzulMysqlBill('bill-mysql-race-b', 200.00),
            ],
            'total' => 2,
        ]),
    ]);

    // Scheduling decides whether the racers overlap (one wins, one is
    // rejected as duplicate) or serialize (the second idempotently
    // re-observes). Both outcomes are correct; the invariants below hold
    // for either.
    $successes = collect($results)->where('success', true);

    expect($successes->count())->toBeGreaterThanOrEqual(1)
        ->and(ContaAzulImportedBill::query()->where('conta_azul_bill_id', 'bill-mysql-race-shared')->count())->toBe(1)
        ->and(ContaAzulImportedBill::query()->count())->toBeGreaterThanOrEqual(2)
        ->and(ContaAzulImportedBill::query()->count())->toBeLessThanOrEqual(3);

    $storedIds = ContaAzulImportedBill::query()->pluck('conta_azul_bill_id')->all();
    sort($storedIds);

    // Whatever interleaving ran, every stored row is complete and every
    // batch reached a terminal state.
    foreach (ContaAzulImportedBill::query()->get() as $bill) {
        expect($bill->payload_fingerprint)->not->toBeEmpty()
            ->and($bill->reconciliation_status)->toBe(ContaAzulImportedBill::RECONCILIATION_PENDING);
    }

    foreach (ContaAzulImportBatch::query()->get() as $batch) {
        expect($batch->isTerminal())->toBeTrue();
    }

    expect($storedIds)->toContain('bill-mysql-race-shared');
});

it('enforces the global bill identity at the database level', function () {
    $batch = ContaAzulImportBatch::factory()->create();
    $other = ContaAzulImportBatch::factory()->create();

    ContaAzulImportedBill::factory()->create([
        'conta_azul_import_batch_id' => $batch->id,
        'last_seen_batch_id' => $batch->id,
        'bank_account_id' => $batch->bank_account_id,
        'conta_azul_bill_id' => 'bill-mysql-unique',
    ]);

    try {
        ContaAzulImportedBill::factory()->create([
            'conta_azul_import_batch_id' => $other->id,
            'last_seen_batch_id' => $other->id,
            'bank_account_id' => $other->bank_account_id,
            'conta_azul_bill_id' => 'bill-mysql-unique',
        ]);

        $this->fail('Expected a unique violation for the duplicated bill identity.');
    } catch (QueryException $exception) {
        expect($exception->getCode())->toBe('23000');
    }

    expect(ContaAzulImportedBill::query()->where('conta_azul_bill_id', 'bill-mysql-unique')->count())->toBe(1);
});

it('rejects negative staged amounts through the check constraint', function () {
    $batch = ContaAzulImportBatch::factory()->create();

    expect(fn () => ContaAzulImportedBill::factory()->create([
        'conta_azul_import_batch_id' => $batch->id,
        'last_seen_batch_id' => $batch->id,
        'bank_account_id' => $batch->bank_account_id,
        'total_amount' => '-1.00',
    ]))->toThrow(QueryException::class);
});

it('round-trips the staging migrations on real DDL', function () {
    $base = database_path('migrations');
    $permissions = $base.'/2026_10_15_130200_seed_conta_azul_import_permissions.php';
    $batches = $base.'/2026_10_15_130000_create_conta_azul_import_batches_table.php';
    $bills = $base.'/2026_10_15_130100_create_conta_azul_imported_bills_table.php';
    $billColumns = $base.'/2026_10_15_170612_add_phase_3b_columns_to_conta_azul_imported_bills_table.php';
    $batchColumns = $base.'/2026_10_15_170613_add_phase_3b_columns_to_conta_azul_import_batches_table.php';
    $attributions = $base.'/2026_10_15_170614_create_conta_azul_bill_attributions_table.php';
    $reconPermissions = $base.'/2026_10_15_170615_seed_conta_azul_reconciliation_permissions.php';

    // Down in real rollback order: the Phase 3B schema depends on the 3A
    // tables (attribution history references bills), so it unwinds first —
    // exactly as `migrate:rollback` would. Bills reference batches, the
    // permission seeds depend on nothing.
    (require $reconPermissions)->down();
    (require $attributions)->down();
    (require $billColumns)->down();
    (require $batchColumns)->down();
    (require $bills)->down();
    (require $batches)->down();
    (require $permissions)->down();

    expect(Schema::hasTable('conta_azul_imported_bills'))->toBeFalse()
        ->and(Schema::hasTable('conta_azul_import_batches'))->toBeFalse()
        ->and(Schema::hasTable('conta_azul_bill_attributions'))->toBeFalse();

    (require $permissions)->up();
    (require $batches)->up();
    (require $bills)->up();
    (require $batchColumns)->up();
    (require $billColumns)->up();
    (require $attributions)->up();
    (require $reconPermissions)->up();

    expect(Schema::hasTable('conta_azul_import_batches'))->toBeTrue()
        ->and(Schema::hasTable('conta_azul_imported_bills'))->toBeTrue();

    // The re-created schema enforces its foreign keys.
    $batch = ContaAzulImportBatch::factory()->create();

    expect(fn () => ContaAzulImportedBill::factory()->create([
        'conta_azul_import_batch_id' => $batch->id + 999999,
        'bank_account_id' => $batch->bank_account_id,
    ]))->toThrow(QueryException::class);

    // And the domain still writes through it.
    $bill = ContaAzulImportedBill::factory()->create([
        'conta_azul_import_batch_id' => $batch->id,
        'last_seen_batch_id' => $batch->id,
        'bank_account_id' => $batch->bank_account_id,
    ]);

    expect($bill->exists())->toBeTrue();
});
