<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

/**
 * A migration da marca de saldo devedor e da reabertura em
 * `guarantee_snapshots`, retomada depois de uma falha no meio.
 *
 * No MySQL o Laravel emite um ALTER por coluna e o banco não desfaz DDL: uma
 * interrupção do `migrate --force` do startup entre dois ALTERs deixa parte das
 * colunas criada. A reexecução precisa criar só o que falta -- inclusive a FK,
 * que é o último passo --, e o rollback precisa terminar a partir de qualquer
 * desses estados. Parte do grupo `parity`: no MySQL o nome da FK é o do banco e
 * a remoção é por nome; no SQLite, pela coluna.
 */
pest()->group('parity');

uses(RefreshDatabase::class);

const GUARANTEE_SNAPSHOT_REOPENING_COLUMNS = [
    'outstanding_balance_outdated_at',
    'outstanding_balance_outdated_reason',
    'reopened_at',
    'reopened_by',
    'reopen_reason',
];

/**
 * No MySQL a DDL destes testes commita a transação que o `RefreshDatabase`
 * abriu: uma coluna removida chegaria ao arquivo seguinte da suíte. A migration
 * é idempotente, então reexecutá-la devolve o schema completo.
 */
afterEach(function () {
    guaranteeSnapshotsReopeningMigration()->up();
});

function guaranteeSnapshotsReopeningMigration(): Migration
{
    return require database_path('migrations/2026_10_01_144325_add_outdated_balance_and_reopening_to_guarantee_snapshots_table.php');
}

/**
 * @return array{name: string|null, columns: list<string>, foreign_table: string, on_delete: string}|null
 */
function guaranteeSnapshotsReopenedByForeignKey(): ?array
{
    return collect(Schema::getForeignKeys('guarantee_snapshots'))
        ->first(fn (array $foreignKey): bool => $foreignKey['columns'] === ['reopened_by']);
}

/**
 * O estado que uma interrupção deixa: só as colunas informadas existem, e a FK,
 * que é o último passo, ainda não.
 *
 * @param  list<string>  $present
 */
function leaveGuaranteeSnapshotsReopeningPartial(array $present): void
{
    $foreignKey = guaranteeSnapshotsReopenedByForeignKey();

    if ($foreignKey !== null) {
        Schema::table('guarantee_snapshots', function (Blueprint $table) use ($foreignKey): void {
            $table->dropForeign(filled($foreignKey['name'] ?? null) ? $foreignKey['name'] : ['reopened_by']);
        });
    }

    $missing = array_values(array_diff(GUARANTEE_SNAPSHOT_REOPENING_COLUMNS, $present));

    Schema::table('guarantee_snapshots', function (Blueprint $table) use ($missing): void {
        $table->dropColumn($missing);
    });
}

function expectGuaranteeSnapshotsReopeningComplete(): void
{
    $foreignKey = guaranteeSnapshotsReopenedByForeignKey();

    expect(Schema::hasColumns('guarantee_snapshots', GUARANTEE_SNAPSHOT_REOPENING_COLUMNS))->toBeTrue()
        ->and($foreignKey)->not->toBeNull()
        ->and($foreignKey['foreign_table'])->toBe('users')
        ->and(strtolower((string) $foreignKey['on_delete']))->toBe('set null');
}

it('creates only what an interrupted run left missing', function (array $present) {
    leaveGuaranteeSnapshotsReopeningPartial($present);

    expect(Schema::hasColumns('guarantee_snapshots', $present))->toBeTrue()
        ->and(guaranteeSnapshotsReopenedByForeignKey())->toBeNull();

    guaranteeSnapshotsReopeningMigration()->up();

    expectGuaranteeSnapshotsReopeningComplete();
})->with([
    'só a primeira coluna do saldo devedor' => [['outstanding_balance_outdated_at']],
    'saldo devedor completo e só reopened_at' => [['outstanding_balance_outdated_at', 'outstanding_balance_outdated_reason', 'reopened_at']],
    'sem reopen_reason e sem a FK' => [['outstanding_balance_outdated_at', 'outstanding_balance_outdated_reason', 'reopened_at', 'reopened_by']],
    'todas as colunas e ainda sem a FK' => [GUARANTEE_SNAPSHOT_REOPENING_COLUMNS],
]);

it('runs again over a complete schema without touching it', function () {
    guaranteeSnapshotsReopeningMigration()->up();

    expectGuaranteeSnapshotsReopeningComplete();
});

it('rolls back and migrates again, also from an interrupted run', function () {
    $migration = guaranteeSnapshotsReopeningMigration();

    $migration->down();

    expect(collect(GUARANTEE_SNAPSHOT_REOPENING_COLUMNS)->filter(fn (string $column): bool => Schema::hasColumn('guarantee_snapshots', $column))->all())->toBe([])
        ->and(guaranteeSnapshotsReopenedByForeignKey())->toBeNull();

    $migration->up();

    expectGuaranteeSnapshotsReopeningComplete();

    leaveGuaranteeSnapshotsReopeningPartial(['outstanding_balance_outdated_at', 'reopened_at']);

    $migration->down();

    expect(collect(GUARANTEE_SNAPSHOT_REOPENING_COLUMNS)->filter(fn (string $column): bool => Schema::hasColumn('guarantee_snapshots', $column))->all())->toBe([]);

    $migration->up();

    expectGuaranteeSnapshotsReopeningComplete();
});
