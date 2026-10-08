<?php

use App\Models\Construction;
use App\Models\MeasurementPlanSet;
use App\Models\MeasurementPlanVersion;
use App\Models\Operation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\CommittedRowsSweeper;

/*
 * As quatro migrations da Fase 2 (versões do plano de medição) sobre dados
 * legados, no MySQL de verdade: lá a DDL faz commit implícito e não se desfaz,
 * e é isso que a idempotência e as recusas prévias precisam aguentar. O banco é
 * recriado por teste e as linhas commitadas são varridas no fim.
 *
 * O esquema anterior à Fase 2 vem do down() das quatro, em ordem reversa, logo
 * depois do migrate:fresh -- e não de `migrate:rollback --path`: o rollback
 * apaga o registro delas em `migrations`, o migrate seguinte o regrava com ids
 * novos e a varredura os removeria, deixando as tabelas sem o registro.
 */
pest()->group('mysql');

const PLAN_VERSION_MIGRATION_FILES = [
    '2026_10_07_200000_create_measurement_plan_versions_table',
    '2026_10_07_200100_attach_measurement_plan_lines_to_versions',
    '2026_10_07_200200_link_measurement_files_to_plan_versions',
    '2026_10_07_200300_move_construction_fund_to_plan_versions',
];

beforeEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requer MySQL para o DDL real das migrations das versões do plano. Execute pelo scripts/parity-check.sh com este arquivo.');
    }

    $this->assertStringStartsWith(
        'nimbus_parity_check',
        DB::connection()->getDatabaseName(),
        'Estes testes recriam o banco. Use o banco temporário de scripts/parity-check.sh.',
    );

    Artisan::call('migrate:fresh', ['--no-interaction' => true]);
    $this->committedRows = CommittedRowsSweeper::afterFreshMigration();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    planVersionMigrationRollBackAll();
    planVersionMigrationExpectLegacySchema();
});

afterEach(function () {
    if (DB::getDriverName() !== 'mysql' || ! isset($this->committedRows)) {
        return;
    }

    planVersionMigrationRestoreSchema();
    $this->committedRows->sweep();

    expect($this->committedRows->leftovers())->toBe([]);
});

/**
 * A migration real, tal como foi publicada; cada chamada devolve uma instância
 * nova para rodar up() ou down().
 */
function planVersionMigration(string $name): Migration
{
    return require database_path('migrations/'.$name.'.php');
}

function planVersionMigrationUpAll(): void
{
    foreach (PLAN_VERSION_MIGRATION_FILES as $name) {
        planVersionMigration($name)->up();
    }
}

function planVersionMigrationRollBackAll(): void
{
    foreach (array_reverse(PLAN_VERSION_MIGRATION_FILES) as $name) {
        planVersionMigration($name)->down();
    }
}

/**
 * A varredura fotografou o esquema completo. Um teste interrompido no meio o
 * deixa legado ou meio migrado, com dados que as recusas prévias barrariam:
 * sem as linhas de medição, as quatro (idempotentes) completam o que falta.
 */
function planVersionMigrationRestoreSchema(): void
{
    $complete = Schema::hasTable('measurement_plan_versions')
        && Schema::hasColumn('measurement_plan_lines', 'lineage_key')
        && Schema::hasColumn('measurement_assets', 'line_claim_key')
        && ! Schema::hasColumn('measurements', 'plan_set_id')
        && ! Schema::hasColumn('measurement_plan_sets', 'construction_fund_amount');

    if ($complete) {
        return;
    }

    Schema::withoutForeignKeyConstraints(function (): void {
        foreach (['measurement_payments', 'measurement_reviews', 'measurement_assets', 'measurement_plan_lines', 'measurement_plan_versions', 'measurements', 'measurement_plan_sets'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }
    });

    planVersionMigrationUpAll();
}

/**
 * @param  list<string>  $columns
 */
function planVersionMigrationOnDelete(string $table, array $columns): ?string
{
    $foreignKey = collect(Schema::getForeignKeys($table))
        ->first(fn (array $foreignKey): bool => $foreignKey['columns'] === $columns);

    return $foreignKey === null ? null : strtolower((string) $foreignKey['on_delete']);
}

/**
 * @return array{name: string, columns: list<string>, unique: bool}|null
 */
function planVersionMigrationIndex(string $table, string $name): ?array
{
    $index = collect(Schema::getIndexes($table))->firstWhere('name', $name);

    return $index === null ? null : ['name' => $index['name'], 'columns' => $index['columns'], 'unique' => (bool) $index['unique']];
}

/**
 * O esquema anterior à Fase 2: o fundo no plano, a sequência única por plano,
 * as referências históricas em SET NULL e a coluna `measurements.plan_set_id`.
 */
function planVersionMigrationExpectLegacySchema(): void
{
    expect(Schema::hasTable('measurement_plan_versions'))->toBeFalse()
        ->and(collect(Schema::getColumns('measurement_plan_sets'))->firstWhere('name', 'construction_fund_amount'))
        ->toMatchArray(['type' => 'decimal(18,2)', 'nullable' => true])
        ->and(planVersionMigrationIndex('measurement_plan_sets', 'mps_operation_construction_unique'))->toBeNull()
        ->and(Schema::hasColumn('measurement_plan_lines', 'plan_version_id'))->toBeFalse()
        ->and(Schema::hasColumn('measurement_plan_lines', 'lineage_key'))->toBeFalse()
        ->and(planVersionMigrationIndex('measurement_plan_lines', 'measurement_plan_lines_plan_set_id_sequence_number_unique'))
        ->toBe(['name' => 'measurement_plan_lines_plan_set_id_sequence_number_unique', 'columns' => ['plan_set_id', 'sequence_number'], 'unique' => true])
        ->and(Schema::hasColumn('measurement_assets', 'plan_version_id'))->toBeFalse()
        ->and(Schema::hasColumn('measurement_assets', 'line_claim_key'))->toBeFalse()
        ->and(planVersionMigrationOnDelete('measurement_assets', ['plan_set_id']))->toBe('set null')
        ->and(planVersionMigrationOnDelete('measurement_assets', ['plan_line_id']))->toBe('set null')
        ->and(planVersionMigrationOnDelete('measurement_payments', ['plan_set_id']))->toBe('set null')
        ->and(planVersionMigrationOnDelete('measurements', ['plan_set_id']))->toBe('set null');
}

function planVersionMigrationExpectVersionedSchema(): void
{
    $assetReferences = [['plan_set_id'], ['plan_line_id'], ['plan_version_id'], ['plan_version_id', 'plan_set_id'], ['plan_line_id', 'plan_version_id']];
    $versionColumns = collect(Schema::getColumns('measurement_plan_versions'))->keyBy('name');

    // A situação compara byte a byte e sem PAD: 'Active' ou 'active ' não
    // ocupam a vaga de vigente nem passam no CHECK. E a versão guarda o marco da
    // ativação: a última medição da operação quando ela passou a valer.
    expect($versionColumns->get('status'))->toMatchArray(['type' => 'varchar(20)', 'collation' => 'utf8mb4_0900_bin', 'nullable' => false])
        ->and($versionColumns->get('last_measurement_id_at_activation'))->toMatchArray(['type' => 'bigint unsigned', 'nullable' => true, 'default' => null]);

    expect(Schema::hasTable('measurement_plan_versions'))->toBeTrue()
        ->and(Schema::hasColumn('measurement_plan_sets', 'construction_fund_amount'))->toBeFalse()
        ->and(planVersionMigrationIndex('measurement_plan_sets', 'mps_operation_construction_unique'))
        ->toBe(['name' => 'mps_operation_construction_unique', 'columns' => ['operation_id', 'construction_id'], 'unique' => true])
        ->and(collect(Schema::getColumns('measurement_plan_lines'))->whereIn('name', ['plan_version_id', 'lineage_key'])->pluck('nullable', 'name')->all())
        ->toBe(['plan_version_id' => false, 'lineage_key' => false])
        ->and(planVersionMigrationIndex('measurement_plan_lines', 'measurement_plan_lines_plan_set_id_sequence_number_unique'))->toBeNull()
        ->and(planVersionMigrationIndex('measurement_plan_lines', 'mpl_version_sequence_unique'))
        ->toBe(['name' => 'mpl_version_sequence_unique', 'columns' => ['plan_version_id', 'sequence_number'], 'unique' => true])
        ->and(planVersionMigrationIndex('measurement_assets', 'ma_line_claim_unique'))
        ->toBe(['name' => 'ma_line_claim_unique', 'columns' => ['line_claim_key'], 'unique' => true])
        ->and(array_map(fn (array $columns): ?string => planVersionMigrationOnDelete('measurement_assets', $columns), $assetReferences))
        ->toBe(['restrict', 'restrict', 'restrict', 'restrict', 'restrict'])
        ->and(planVersionMigrationOnDelete('measurement_payments', ['plan_set_id']))->toBe('restrict')
        ->and(Schema::hasColumn('measurements', 'plan_set_id'))->toBeFalse();
}

/**
 * Plano como a aplicação anterior à Fase 2 o gravava, com o Fundo de Obra no
 * próprio plano. Pelo query builder: o model atual abre a V1 no `created` e
 * recusa o fundo no plano.
 *
 * @param  array<string, mixed>  $attributes
 */
function planVersionMigrationLegacyPlan(Operation $operation, array $attributes = []): int
{
    return DB::table('measurement_plan_sets')->insertGetId([
        'operation_id' => $operation->id,
        'construction_id' => null,
        'name' => 'Plano '.Str::random(8),
        'is_default' => false,
        'construction_fund_amount' => '1000000.00',
        'created_at' => '2026-04-10 12:00:00',
        'updated_at' => '2026-04-10 12:00:00',
        ...$attributes,
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function planVersionMigrationLegacyLine(int $planSetId, int $sequence, string $measurementDate, array $attributes = []): int
{
    return DB::table('measurement_plan_lines')->insertGetId([
        'plan_set_id' => $planSetId,
        'operation_id' => DB::table('measurement_plan_sets')->where('id', $planSetId)->value('operation_id'),
        'sequence_number' => $sequence,
        'planned_monthly_percent' => '10.00',
        'planned_cumulative_percent' => sprintf('%d.00', 10 * $sequence),
        'measurement_date' => $measurementDate,
        'created_at' => now(),
        'updated_at' => now(),
        ...$attributes,
    ]);
}

function planVersionMigrationLegacyMeasurement(Operation $operation, string $status): int
{
    return DB::table('measurements')->insertGetId([
        'operation_id' => $operation->id,
        'reference_month' => '2026-05-01',
        'status' => $status,
        'current_stage' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function planVersionMigrationLegacyEngineering(int $measurementId, string $status): void
{
    DB::table('measurement_reviews')->insert([
        'measurement_id' => $measurementId,
        'stage' => 1,
        'status' => $status,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function planVersionMigrationLegacyPayment(int $measurementId, int $planSetId): int
{
    return DB::table('measurement_payments')->insertGetId([
        'operation_id' => DB::table('measurements')->where('id', $measurementId)->value('operation_id'),
        'measurement_id' => $measurementId,
        'plan_set_id' => $planSetId,
        'pay_date' => '2026-06-10',
        'amount' => '125000.00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function planVersionMigrationLegacyAsset(int $measurementId, int $planSetId, ?int $planLineId): int
{
    return DB::table('measurement_assets')->insertGetId([
        'measurement_id' => $measurementId,
        'plan_set_id' => $planSetId,
        'plan_line_id' => $planLineId,
        'filename' => 'medicao.pdf',
        'storage_path' => 'measurements/legacy/'.Str::uuid().'.pdf',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @return array<int, int> id da V1 por plano
 */
function planVersionMigrationFirstVersions(): array
{
    return DB::table('measurement_plan_versions')->where('version_number', 1)->pluck('id', 'plan_set_id')->all();
}

/**
 * @param  list<string>  $columns
 * @return array<string, mixed>
 */
function planVersionMigrationPick(object $row, array $columns): array
{
    return array_combine($columns, array_map(fn (string $column): mixed => $row->{$column}, $columns));
}

/**
 * Tudo o que as quatro migrations escrevem -- linhas, trilha e esquema --, para
 * provar que a reexecução não muda nada.
 *
 * @return array<string, mixed>
 */
function planVersionMigrationSnapshot(): array
{
    $snapshot = [];

    foreach (['measurement_plan_sets', 'measurement_plan_versions', 'measurement_plan_lines', 'measurement_assets', 'measurement_payments', 'measurements'] as $table) {
        $snapshot[$table] = [
            'rows' => DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
            'columns' => Schema::getColumnListing($table),
            'indexes' => collect(Schema::getIndexes($table))->sortBy('name')->values()->all(),
            'foreign_keys' => collect(Schema::getForeignKeys($table))->sortBy('name')->values()->all(),
        ];
    }

    $snapshot['trails'] = DB::table('activity_log')
        ->where('log_name', 'measurements')
        ->orderBy('id')
        ->get()
        ->map(fn (object $row): array => (array) $row)
        ->all();

    return $snapshot;
}

/**
 * Derruba a próxima instrução que começar com `$statement` logo depois de o
 * MySQL executá-la -- e, sendo DDL, commitá-la --, como uma queda do
 * `migrate --force` do startup entre dois ALTERs: o que veio antes fica, o que
 * vinha depois não acontece.
 */
function planVersionMigrationInterruptAfter(string $statement, RuntimeException $interruption): void
{
    $armed = true;

    DB::listen(function (QueryExecuted $query) use (&$armed, $statement, $interruption): void {
        if ($armed && str_starts_with($query->sql, $statement)) {
            $armed = false;

            throw $interruption;
        }
    });
}

/**
 * O que o down() precisa devolver: o fundo de cada plano e os vínculos de
 * linhas, arquivos e pagamentos com os planos.
 *
 * @return array<string, array<int|string, mixed>>
 */
function planVersionMigrationLegacyState(): array
{
    $rows = fn (string $table, array $columns): array => DB::table($table)->orderBy('id')->get($columns)
        ->map(fn (object $row): array => (array) $row)
        ->all();

    return [
        'funds' => DB::table('measurement_plan_sets')->orderBy('id')->pluck('construction_fund_amount', 'id')->all(),
        'lines' => $rows('measurement_plan_lines', ['id', 'plan_set_id', 'sequence_number', 'measurement_date']),
        'assets' => $rows('measurement_assets', ['id', 'measurement_id', 'plan_set_id', 'plan_line_id']),
        'payments' => $rows('measurement_payments', ['id', 'measurement_id', 'plan_set_id']),
    ];
}

it('gives each legacy plan a single V1 with the fund and the schedule it had, active when in use and draft when empty', function () {
    $operation = Operation::factory()->create();
    $otherOperation = Operation::factory()->create();

    // Cronograma anterior à criação: a V1 vale desde a 1ª medição prevista, e o
    // dia 15 vira o mês. Todos os planos daqui são sem obra: nulo não colide na
    // unique nova de obra por operação.
    $scheduledEarly = planVersionMigrationLegacyPlan($operation, ['name' => 'Torre A', 'construction_fund_amount' => '10114801.60', 'created_at' => '2026-05-10 12:00:00']);
    $earlyLines = [
        planVersionMigrationLegacyLine($scheduledEarly, 1, '2026-03-15'),
        planVersionMigrationLegacyLine($scheduledEarly, 2, '2026-04-01'),
    ];

    // Só arquivo, criado às 22h de 30/04 em Brasília (01h de 01/05 em UTC): o
    // mês de criação é o de negócio, abril.
    $fileOnly = planVersionMigrationLegacyPlan($operation, ['name' => 'Torre B', 'construction_fund_amount' => '850000.00', 'created_at' => '2026-05-01 01:00:00']);
    planVersionMigrationLegacyAsset(planVersionMigrationLegacyMeasurement($operation, 'in_review'), $fileOnly, null);

    $paymentOnly = planVersionMigrationLegacyPlan($otherOperation, ['name' => 'Torre C', 'construction_fund_amount' => null, 'created_at' => '2026-06-15 12:00:00']);
    planVersionMigrationLegacyPayment(planVersionMigrationLegacyMeasurement($otherOperation, 'finalized'), $paymentOnly);

    // Cronograma que começa depois da criação: a V1 nunca vale depois do mês
    // de criação.
    $scheduledLate = planVersionMigrationLegacyPlan($otherOperation, ['name' => 'Torre D', 'construction_fund_amount' => '4500000.50', 'created_at' => '2026-06-20 12:00:00']);
    $lateLines = [
        planVersionMigrationLegacyLine($scheduledLate, 1, '2026-08-01'),
        planVersionMigrationLegacyLine($scheduledLate, 2, '2026-09-01'),
    ];

    $empty = planVersionMigrationLegacyPlan($otherOperation, ['name' => 'Torre E', 'construction_fund_amount' => '2500000.00', 'created_at' => '2026-07-01 12:00:00']);

    planVersionMigrationUpAll();

    planVersionMigrationExpectVersionedSchema();

    $expected = [
        $scheduledEarly => [$operation->id, 'active', '2026-03-01', 'first_schedule_line_month', '10114801.60', '2026-05-10 12:00:00'],
        $fileOnly => [$operation->id, 'active', '2026-04-01', 'plan_creation_month', '850000.00', '2026-05-01 01:00:00'],
        $paymentOnly => [$otherOperation->id, 'active', '2026-06-01', 'plan_creation_month', null, '2026-06-15 12:00:00'],
        $scheduledLate => [$otherOperation->id, 'active', '2026-06-01', 'plan_creation_month', '4500000.50', '2026-06-20 12:00:00'],
        $empty => [$otherOperation->id, 'draft', null, null, '2500000.00', '2026-07-01 12:00:00'],
    ];

    foreach ($expected as $planSetId => [$operationId, $status, $effectiveFrom, $rule, $fund, $createdAt]) {
        $version = DB::table('measurement_plan_versions')->where('plan_set_id', $planSetId)->sole();
        $active = $status === 'active';

        expect(planVersionMigrationPick($version, [
            'operation_id', 'version_number', 'status', 'effective_from', 'construction_fund_amount', 'revision',
            'previous_version_id', 'superseded_by_version_id', 'created_by', 'activated_by', 'activated_at',
            'activation_progress_percent', 'active_plan_set_id', 'draft_plan_set_id', 'created_at',
        ]))->toBe([
            'operation_id' => $operationId,
            'version_number' => 1,
            'status' => $status,
            'effective_from' => $effectiveFrom,
            'construction_fund_amount' => $fund,
            'revision' => 0,
            'previous_version_id' => null,
            'superseded_by_version_id' => null,
            'created_by' => null,
            'activated_by' => null,
            'activated_at' => $active ? $createdAt : null,
            'activation_progress_percent' => null,
            'active_plan_set_id' => $active ? $planSetId : null,
            'draft_plan_set_id' => $active ? null : $planSetId,
            'created_at' => $createdAt,
        ]);

        $trail = DB::table('activity_log')
            ->where('subject_type', MeasurementPlanVersion::class)
            ->where('subject_id', $version->id)
            ->sole();
        $properties = json_decode($trail->properties, true, flags: JSON_THROW_ON_ERROR);
        $expectedProperties = [
            'migration' => PLAN_VERSION_MIGRATION_FILES[0],
            'operation_id' => $operationId,
            'plan_set_id' => $planSetId,
            'version_number' => 1,
            'status' => $status,
            'construction_fund_amount' => $fund,
            'effective_from' => $effectiveFrom,
            'effective_from_rule' => $rule,
        ];
        // O MySQL reordena as chaves do JSON; a comparação continua estrita.
        ksort($properties);
        ksort($expectedProperties);

        expect([$trail->log_name, $trail->description, $trail->causer_id])->toBe(['measurements', 'plan_version_backfilled', null])
            ->and($properties)->toBe($expectedProperties);
    }

    expect(DB::table('measurement_plan_versions')->count())->toBe(5)
        ->and(DB::table('activity_log')->where('description', 'plan_version_backfilled')->count())->toBe(5);

    // A aplicação nova lê da versão o mesmo fundo, sem arredondamento.
    expect(MeasurementPlanSet::query()->findOrFail($scheduledEarly)->currentConstructionFundAmount())->toBe('10114801.60')
        ->and(MeasurementPlanSet::query()->findOrFail($empty)->currentConstructionFundAmount())->toBe('2500000.00')
        ->and(MeasurementPlanSet::query()->findOrFail($paymentOnly)->currentConstructionFundAmount())->toBeNull();

    // O cronograma vai para a V1, cada linha com a própria linhagem.
    $firstVersions = planVersionMigrationFirstVersions();
    $lines = DB::table('measurement_plan_lines')->orderBy('id')->get()->keyBy('id');

    expect($lines->keys()->all())->toBe([...$earlyLines, ...$lateLines])
        ->and($lines->map(fn (object $line): int => $line->plan_version_id)->all())->toBe([
            $earlyLines[0] => $firstVersions[$scheduledEarly],
            $earlyLines[1] => $firstVersions[$scheduledEarly],
            $lateLines[0] => $firstVersions[$scheduledLate],
            $lateLines[1] => $firstVersions[$scheduledLate],
        ])
        ->and($lines->pluck('lineage_key')->all())->each->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/')
        ->and($lines->pluck('lineage_key')->unique()->count())->toBe(4);

    // A sequência passa a ser única por versão: a revisão repete a da V1, e a
    // mesma versão continua sem sequência repetida.
    $revisionId = DB::table('measurement_plan_versions')->insertGetId([
        'operation_id' => $operation->id,
        'plan_set_id' => $scheduledEarly,
        'version_number' => 2,
        'status' => 'draft',
        'previous_version_id' => $firstVersions[$scheduledEarly],
        'construction_fund_amount' => '10114801.60',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $revisedLine = planVersionMigrationLegacyLine($scheduledEarly, 1, '2026-03-01', [
        'plan_version_id' => $revisionId,
        'lineage_key' => $lines[$earlyLines[0]]->lineage_key,
    ]);

    expect(fn () => planVersionMigrationLegacyLine($scheduledEarly, 1, '2026-03-01', [
        'plan_version_id' => $firstVersions[$scheduledEarly],
        'lineage_key' => (string) Str::ulid(),
    ]))->toThrow(QueryException::class, 'mpl_version_sequence_unique');

    expect(DB::table('measurement_plan_lines')->where('plan_set_id', $scheduledEarly)->orderBy('id')->pluck('id')->all())
        ->toBe([...$earlyLines, $revisedLine]);
});

it('captures the version of each legacy file and gives each planned measurement to the measurement that holds it', function () {
    $operation = Operation::factory()->create();
    $plan = planVersionMigrationLegacyPlan($operation, ['name' => 'Torre A']);
    $otherPlan = planVersionMigrationLegacyPlan($operation, ['name' => 'Torre B']);
    $sequence = 0;
    $newLine = function () use ($plan, &$sequence): int {
        $sequence++;

        return planVersionMigrationLegacyLine($plan, $sequence, CarbonImmutable::parse('2026-04-01')->addMonths($sequence)->toDateString());
    };
    $measurement = fn (string $status): int => planVersionMigrationLegacyMeasurement($operation, $status);
    $file = fn (int $measurementId, int $planSetId, ?int $lineId): int => planVersionMigrationLegacyAsset($measurementId, $planSetId, $lineId);
    // Por arquivo: o plano, a linha e se ele fica com a linha.
    $expected = [];

    // Toda medição de pé ocupa a sua medição prevista: aberta ou finalizada.
    foreach (['pending', 'in_review', 'paused', 'approved', 'awaiting_payment', 'awaiting_receipt', 'finalized'] as $status) {
        $line = $newLine();
        $expected[$file($measurement($status), $plan, $line)] = [$plan, $line, true];
    }

    // A recusada solta a linha, a não ser que tenha Engenharia vigente ou pagamento.
    $line = $newLine();
    $expected[$file($measurement('rejected'), $plan, $line)] = [$plan, $line, false];

    $line = $newLine();
    $rejectedAfterEngineering = $measurement('rejected');
    planVersionMigrationLegacyEngineering($rejectedAfterEngineering, 'approved');
    $expected[$file($rejectedAfterEngineering, $plan, $line)] = [$plan, $line, true];

    $line = $newLine();
    $rejectedAfterPayment = $measurement('rejected');
    planVersionMigrationLegacyPayment($rejectedAfterPayment, $plan);
    $expected[$file($rejectedAfterPayment, $plan, $line)] = [$plan, $line, true];

    // Duas medições de pé na mesma linha (a corrida das duas abas): fica com
    // ela a de Engenharia vigente, depois a paga, depois a mais antiga.
    $contestedLine = $newLine();
    $outranked = $file($measurement('in_review'), $plan, $contestedLine);
    $expected[$outranked] = [$plan, $contestedLine, false];
    $engineered = $measurement('in_review');
    planVersionMigrationLegacyEngineering($engineered, 'approved');
    $expected[$file($engineered, $plan, $contestedLine)] = [$plan, $contestedLine, true];

    $line = $newLine();
    $expected[$file($measurement('pending'), $plan, $line)] = [$plan, $line, false];
    $paid = $measurement('finalized');
    planVersionMigrationLegacyPayment($paid, $plan);
    $expected[$file($paid, $plan, $line)] = [$plan, $line, true];

    $line = $newLine();
    $paidFirst = $measurement('finalized');
    planVersionMigrationLegacyPayment($paidFirst, $plan);
    $expected[$file($paidFirst, $plan, $line)] = [$plan, $line, false];
    $engineeredLater = $measurement('in_review');
    planVersionMigrationLegacyEngineering($engineeredLater, 'approved');
    $expected[$file($engineeredLater, $plan, $line)] = [$plan, $line, true];

    // Engenharia ainda pendente não é vigente: vale a mais antiga.
    $line = $newLine();
    $expected[$file($measurement('pending'), $plan, $line)] = [$plan, $line, true];
    $awaitingEngineering = $measurement('in_review');
    planVersionMigrationLegacyEngineering($awaitingEngineering, 'pending');
    $expected[$file($awaitingEngineering, $plan, $line)] = [$plan, $line, false];

    // A recusada não disputa: a reenviada depois dela fica com a linha.
    $line = $newLine();
    $expected[$file($measurement('rejected'), $plan, $line)] = [$plan, $line, false];
    $expected[$file($measurement('pending'), $plan, $line)] = [$plan, $line, true];

    // Arquivo sem linha fica com a V1 do próprio plano e não ocupa nada; o de
    // outro plano, com a V1 daquele plano.
    $expected[$file($measurement('pending'), $plan, null)] = [$plan, null, false];
    $otherLine = planVersionMigrationLegacyLine($otherPlan, 1, '2026-05-01');
    $expected[$file($measurement('pending'), $otherPlan, $otherLine)] = [$otherPlan, $otherLine, true];

    // Plano só com pagamento: nada além do RESTRICT do pagamento o segura.
    $paidOnlyPlan = planVersionMigrationLegacyPlan($operation, ['name' => 'Torre C']);
    $payment = planVersionMigrationLegacyPayment($measurement('finalized'), $paidOnlyPlan);

    planVersionMigrationUpAll();

    planVersionMigrationExpectVersionedSchema();

    $firstVersions = planVersionMigrationFirstVersions();
    $lines = DB::table('measurement_plan_lines')->get(['id', 'plan_version_id', 'lineage_key'])->keyBy('id');

    expect(DB::table('measurement_assets')->orderBy('id')->get()
        ->mapWithKeys(fn (object $asset): array => [$asset->id => [$asset->plan_version_id, $asset->line_claim_key]])
        ->all())
        ->toBe(collect($expected)->map(fn (array $expectation): array => [
            $expectation[1] === null ? $firstVersions[$expectation[0]] : $lines[$expectation[1]]->plan_version_id,
            $expectation[2] ? $lines[$expectation[1]]->lineage_key : null,
        ])->all())
        ->and($lines[$contestedLine]->plan_version_id)->toBe($firstVersions[$plan])
        ->and($lines[$otherLine]->plan_version_id)->toBe($firstVersions[$otherPlan]);

    // A unique é a garantia do banco: a medição preterida não toma a linha por fora.
    expect(fn () => DB::table('measurement_assets')->where('id', $outranked)->update(['line_claim_key' => $lines[$contestedLine]->lineage_key]))
        ->toThrow(QueryException::class, 'ma_line_claim_unique');
    expect(DB::table('measurement_assets')->where('id', $outranked)->value('line_claim_key'))->toBeNull();

    // O plano com pagamento não sai mais: o vínculo histórico não vira nulo.
    expect(fn () => DB::table('measurement_plan_sets')->where('id', $paidOnlyPlan)->delete())
        ->toThrow(QueryException::class, 'measurement_payments_plan_set_id_foreign');
    expect(DB::table('measurement_plan_sets')->where('id', $paidOnlyPlan)->exists())->toBeTrue()
        ->and(DB::table('measurement_payments')->where('id', $payment)->value('plan_set_id'))->toBe($paidOnlyPlan)
        ->and(DB::table('measurement_plan_versions')->where('plan_set_id', $paidOnlyPlan)->count())->toBe(1);
});

it('stops before each legacy inconsistency without blocking the re-run, and completes once the data is fixed', function () {
    $operation = Operation::factory()->create();
    $otherOperation = Operation::factory()->create();
    $construction = Construction::factory()->create(['emission_id' => $operation->emission_id]);
    $anotherConstruction = Construction::factory()->create(['emission_id' => $operation->emission_id]);

    $plan = planVersionMigrationLegacyPlan($operation, ['name' => 'Torre A', 'construction_id' => $construction->id]);
    $duplicate = planVersionMigrationLegacyPlan($operation, ['name' => 'Torre A (cópia)', 'construction_id' => $construction->id]);
    $line = planVersionMigrationLegacyLine($plan, 1, '2026-05-01');
    $strayLine = planVersionMigrationLegacyLine($plan, 2, '2026-06-01', ['operation_id' => $otherOperation->id]);
    $duplicateLine = planVersionMigrationLegacyLine($duplicate, 1, '2026-05-01');
    $crossedFile = planVersionMigrationLegacyAsset(planVersionMigrationLegacyMeasurement($operation, 'in_review'), $plan, $duplicateLine);
    $misplacedMeasurement = planVersionMigrationLegacyMeasurement($otherOperation, 'pending');
    $foreignFile = planVersionMigrationLegacyAsset($misplacedMeasurement, $plan, $line);

    // A mesma obra planejada duas vezes na operação. A DDL que antecede a
    // checagem fica (o MySQL não a desfaz), mas nenhuma versão nasce, e a
    // reexecução passa por ela e para no mesmo dado.
    $duplicateRefusal = new RuntimeException(sprintf(
        'measurement_plan_sets possui %d obra(s) com mais de um plano na mesma operação (operação:obra %s). Investigue antes de versionar os planos.',
        1,
        $operation->id.':'.$construction->id,
    ));

    expect(fn () => planVersionMigration(PLAN_VERSION_MIGRATION_FILES[0])->up())->toThrow($duplicateRefusal);
    expect(Schema::hasTable('measurement_plan_versions'))->toBeTrue()
        ->and(DB::table('measurement_plan_versions')->count())->toBe(0)
        ->and(DB::table('activity_log')->where('description', 'plan_version_backfilled')->count())->toBe(0)
        ->and(planVersionMigrationIndex('measurement_plan_sets', 'mps_operation_construction_unique'))->toBeNull();
    expect(fn () => planVersionMigration(PLAN_VERSION_MIGRATION_FILES[0])->up())->toThrow($duplicateRefusal);

    DB::table('measurement_plan_sets')->where('id', $duplicate)->update(['construction_id' => $anotherConstruction->id]);
    planVersionMigration(PLAN_VERSION_MIGRATION_FILES[0])->up();

    // Linha de outra operação: recusada antes de qualquer DDL nas linhas.
    expect(fn () => planVersionMigration(PLAN_VERSION_MIGRATION_FILES[1])->up())->toThrow(new RuntimeException(sprintf(
        'measurement_plan_lines possui %d linha(s) cuja operação difere da do plano (ids: %s). Investigue antes de versionar o cronograma.',
        1,
        $strayLine,
    )));
    expect(Schema::hasColumn('measurement_plan_lines', 'plan_version_id'))->toBeFalse();

    DB::table('measurement_plan_lines')->where('id', $strayLine)->update(['operation_id' => $operation->id]);
    planVersionMigration(PLAN_VERSION_MIGRATION_FILES[1])->up();

    // Arquivo cuja linha é de outro plano; corrigido, o arquivo cujo plano é de
    // outra operação. Os dois antes de qualquer DDL nos arquivos.
    expect(fn () => planVersionMigration(PLAN_VERSION_MIGRATION_FILES[2])->up())->toThrow(new RuntimeException(sprintf(
        'measurement_assets possui %d arquivo(s) cuja linha do cronograma é de outro plano (ids: %s). Investigue antes de vincular os arquivos às versões do plano.',
        1,
        $crossedFile,
    )));
    expect(Schema::hasColumn('measurement_assets', 'plan_version_id'))->toBeFalse();

    DB::table('measurement_assets')->where('id', $crossedFile)->update(['plan_set_id' => $duplicate]);

    expect(fn () => planVersionMigration(PLAN_VERSION_MIGRATION_FILES[2])->up())->toThrow(new RuntimeException(sprintf(
        'measurement_assets possui %d arquivo(s) cujo plano é de outra operação (ids: %s). Investigue antes de vincular os arquivos às versões do plano.',
        1,
        $foreignFile,
    )));
    expect(Schema::hasColumn('measurement_assets', 'plan_version_id'))->toBeFalse();

    DB::table('measurements')->where('id', $misplacedMeasurement)->update(['operation_id' => $operation->id]);
    planVersionMigration(PLAN_VERSION_MIGRATION_FILES[2])->up();
    planVersionMigration(PLAN_VERSION_MIGRATION_FILES[3])->up();

    planVersionMigrationExpectVersionedSchema();

    $firstVersions = planVersionMigrationFirstVersions();

    expect(DB::table('measurement_plan_versions')->orderBy('plan_set_id')->get()
        ->map(fn (object $version): array => [$version->plan_set_id, $version->version_number, $version->status])
        ->all())->toBe([[$plan, 1, 'active'], [$duplicate, 1, 'active']])
        ->and(DB::table('measurement_plan_lines')->orderBy('id')->pluck('plan_version_id', 'id')->all())->toBe([
            $line => $firstVersions[$plan],
            $strayLine => $firstVersions[$plan],
            $duplicateLine => $firstVersions[$duplicate],
        ])
        ->and(DB::table('measurement_assets')->orderBy('id')->pluck('plan_version_id', 'id')->all())->toBe([
            $crossedFile => $firstVersions[$duplicate],
            $foreignFile => $firstVersions[$plan],
        ]);
});

it('resumes the backfill after a failure midway and never gives a plan a second V1', function () {
    $operation = Operation::factory()->create();
    $first = planVersionMigrationLegacyPlan($operation, ['name' => 'Torre A', 'construction_fund_amount' => '10114801.60']);
    $firstLine = planVersionMigrationLegacyLine($first, 1, '2026-05-01');
    planVersionMigrationLegacyLine($first, 2, '2026-06-01');
    planVersionMigrationLegacyAsset(planVersionMigrationLegacyMeasurement($operation, 'in_review'), $first, $firstLine);
    $second = planVersionMigrationLegacyPlan($operation, ['name' => 'Torre B', 'construction_fund_amount' => null]);
    $third = planVersionMigrationLegacyPlan($operation, ['name' => 'Torre C', 'construction_fund_amount' => '777.77']);
    planVersionMigrationLegacyLine($third, 1, '2026-07-01');

    // Falha no 2º plano logo depois de gravar a versão, antes da trilha: a
    // transação do plano desfaz a versão, e o 1º fica com a V1 e a trilha dele.
    $failure = new RuntimeException('Falha simulada ao preencher a V1 do segundo plano.');
    $versionInserts = 0;

    DB::listen(function (QueryExecuted $query) use (&$versionInserts, $failure): void {
        if (str_starts_with($query->sql, 'insert into `measurement_plan_versions`') && ++$versionInserts === 2) {
            throw $failure;
        }
    });

    expect(fn () => planVersionMigration(PLAN_VERSION_MIGRATION_FILES[0])->up())->toThrow($failure);

    $firstVersion = DB::table('measurement_plan_versions')->sole();

    expect($firstVersion->plan_set_id)->toBe($first)
        ->and(DB::table('activity_log')->where('description', 'plan_version_backfilled')->pluck('subject_id')->all())->toBe([$firstVersion->id]);

    planVersionMigration(PLAN_VERSION_MIGRATION_FILES[0])->up();

    $versions = DB::table('measurement_plan_versions')->orderBy('id')->get();

    expect($versions->map(fn (object $version): array => [$version->plan_set_id, $version->version_number, $version->status, $version->construction_fund_amount])->all())
        ->toBe([[$first, 1, 'active', '10114801.60'], [$second, 1, 'draft', null], [$third, 1, 'active', '777.77']])
        ->and((array) $versions->first())->toBe((array) $firstVersion)
        ->and(DB::table('activity_log')->where('description', 'plan_version_backfilled')->orderBy('id')->pluck('subject_id')->all())
        ->toBe($versions->pluck('id')->all());

    // Rodar de novo, completo, não abre uma segunda V1 nem grava outra trilha.
    $backfilled = planVersionMigrationSnapshot();

    planVersionMigration(PLAN_VERSION_MIGRATION_FILES[0])->up();

    expect(planVersionMigrationSnapshot())->toBe($backfilled);

    foreach (array_slice(PLAN_VERSION_MIGRATION_FILES, 1) as $name) {
        planVersionMigration($name)->up();
    }

    $migrated = planVersionMigrationSnapshot();

    planVersionMigrationUpAll();

    expect(planVersionMigrationSnapshot())->toBe($migrated)
        ->and(DB::table('measurement_plan_versions')->count())->toBe(3);
});

it('rolls back to the legacy schema while every plan only has its V1, and refuses once a revision exists', function () {
    $operation = Operation::factory()->create();
    $inUse = planVersionMigrationLegacyPlan($operation, ['name' => 'Torre A', 'construction_fund_amount' => '10114801.60']);
    $line = planVersionMigrationLegacyLine($inUse, 1, '2026-05-01');
    planVersionMigrationLegacyLine($inUse, 2, '2026-06-01');
    $measurement = planVersionMigrationLegacyMeasurement($operation, 'finalized');
    planVersionMigrationLegacyAsset($measurement, $inUse, $line);
    planVersionMigrationLegacyPayment($measurement, $inUse);
    $empty = planVersionMigrationLegacyPlan($operation, ['name' => 'Torre B', 'construction_fund_amount' => null]);
    $legacy = planVersionMigrationLegacyState();

    planVersionMigrationUpAll();
    planVersionMigrationRollBackAll();

    // O fundo volta da V1 para o plano, centavo por centavo, e nenhum vínculo
    // de linha, arquivo ou pagamento se perde no caminho.
    planVersionMigrationExpectLegacySchema();
    expect(planVersionMigrationLegacyState())->toBe($legacy);

    planVersionMigrationUpAll();

    expect(DB::table('measurement_plan_versions')->orderBy('plan_set_id')->get()
        ->map(fn (object $version): array => [$version->plan_set_id, $version->version_number, $version->status, $version->construction_fund_amount])
        ->all())->toBe([[$inUse, 1, 'active', '10114801.60'], [$empty, 1, 'draft', null]]);

    // Com uma revisão gravada, o fundo é de cada versão e a sequência repete
    // entre versões: a reversão apagaria histórico de planejamento. Cada down()
    // recusa antes de qualquer DDL, mesmo rodado fora de ordem.
    DB::table('measurement_plan_versions')->insert([
        'operation_id' => $operation->id,
        'plan_set_id' => $inUse,
        'version_number' => 2,
        'status' => 'draft',
        'previous_version_id' => planVersionMigrationFirstVersions()[$inUse],
        'construction_fund_amount' => '11630000.00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $revised = planVersionMigrationSnapshot();

    expect(fn () => planVersionMigrationRollBackAll())->toThrow(new RuntimeException(
        'Há revisões do plano de medição gravadas: o Fundo de Obra é de cada versão e não volta para o plano. Corrija com uma nova migration.'
    ));
    expect(fn () => planVersionMigration(PLAN_VERSION_MIGRATION_FILES[1])->down())->toThrow(new RuntimeException(
        'measurement_plan_lines pertence a revisões do plano; a sequência única por plano não cabe mais. Corrija com uma nova migration.'
    ));
    expect(fn () => planVersionMigration(PLAN_VERSION_MIGRATION_FILES[0])->down())->toThrow(new RuntimeException(
        'measurement_plan_versions possui revisões, rascunhos ou versões canceladas; a reversão apagaria o histórico de planejamento e não é admitida. Corrija com uma nova migration.'
    ));

    planVersionMigrationExpectVersionedSchema();
    expect(planVersionMigrationSnapshot())->toBe($revised);
});

/*
 * No MySQL a troca da FK do pagamento são dois ALTERs, cada um commitado por
 * si: interrompida entre eles, a coluna fica sem FK nenhuma. A reexecução da
 * 200200 -- o próximo boot roda o `migrate --force` de novo, sem ninguém olhando
 * -- precisa recriar a FK, e não concluir a migration com o pagamento solto.
 */

it('recreates the RESTRICT on the payment plan when the re-run finds the swap cut between its two ALTERs', function () {
    $operation = Operation::factory()->create();
    // Plano só com pagamento: nada além da FK do pagamento o segura.
    $paidOnlyPlan = planVersionMigrationLegacyPlan($operation, ['name' => 'Torre C']);
    $payment = planVersionMigrationLegacyPayment(planVersionMigrationLegacyMeasurement($operation, 'finalized'), $paidOnlyPlan);
    $interruption = new RuntimeException('Conexão perdida entre a remoção do SET NULL do pagamento e a criação do RESTRICT.');

    planVersionMigration(PLAN_VERSION_MIGRATION_FILES[0])->up();
    planVersionMigration(PLAN_VERSION_MIGRATION_FILES[1])->up();
    planVersionMigrationInterruptAfter('alter table `measurement_payments` drop foreign key', $interruption);

    expect(fn () => planVersionMigration(PLAN_VERSION_MIGRATION_FILES[2])->up())->toThrow($interruption);

    // O SET NULL saiu e o RESTRICT não entrou: o pagamento ficou sem FK. Os
    // arquivos, trocados antes, já estão em RESTRICT; a coluna da medição, que
    // sai depois, continua lá.
    expect(planVersionMigrationOnDelete('measurement_payments', ['plan_set_id']))->toBeNull()
        ->and(planVersionMigrationOnDelete('measurement_assets', ['plan_set_id']))->toBe('restrict')
        ->and(Schema::hasColumn('measurements', 'plan_set_id'))->toBeTrue();

    planVersionMigration(PLAN_VERSION_MIGRATION_FILES[2])->up();
    planVersionMigration(PLAN_VERSION_MIGRATION_FILES[3])->up();

    planVersionMigrationExpectVersionedSchema();

    // O banco volta a recusar a exclusão do plano pago: o vínculo histórico
    // do pagamento não vira nulo.
    expect(fn () => DB::table('measurement_plan_sets')->where('id', $paidOnlyPlan)->delete())
        ->toThrow(QueryException::class, 'measurement_payments_plan_set_id_foreign');
    expect(DB::table('measurement_plan_sets')->where('id', $paidOnlyPlan)->exists())->toBeTrue()
        ->and(DB::table('measurement_payments')->where('id', $payment)->value('plan_set_id'))->toBe($paidOnlyPlan);
});

it('restores the SET NULL on the payment plan when the rollback re-run finds the swap cut between its two ALTERs', function () {
    $operation = Operation::factory()->create();
    $paidOnlyPlan = planVersionMigrationLegacyPlan($operation, ['name' => 'Torre C']);
    $payment = planVersionMigrationLegacyPayment(planVersionMigrationLegacyMeasurement($operation, 'finalized'), $paidOnlyPlan);
    $interruption = new RuntimeException('Conexão perdida entre a remoção do RESTRICT do pagamento e a volta do SET NULL.');

    planVersionMigrationUpAll();
    planVersionMigration(PLAN_VERSION_MIGRATION_FILES[3])->down();
    planVersionMigrationInterruptAfter('alter table `measurement_payments` drop foreign key', $interruption);

    expect(fn () => planVersionMigration(PLAN_VERSION_MIGRATION_FILES[2])->down())->toThrow($interruption);

    // A coluna da medição já voltou e o RESTRICT saiu, mas o SET NULL não
    // entrou; os arquivos continuam como a 200200 os deixou.
    expect(planVersionMigrationOnDelete('measurement_payments', ['plan_set_id']))->toBeNull()
        ->and(Schema::hasColumn('measurements', 'plan_set_id'))->toBeTrue()
        ->and(Schema::hasColumn('measurement_assets', 'line_claim_key'))->toBeTrue();

    planVersionMigration(PLAN_VERSION_MIGRATION_FILES[2])->down();
    planVersionMigration(PLAN_VERSION_MIGRATION_FILES[1])->down();
    planVersionMigration(PLAN_VERSION_MIGRATION_FILES[0])->down();

    planVersionMigrationExpectLegacySchema();

    // De volta ao SET NULL de antes da Fase 2: excluir o plano solta o
    // pagamento em vez de ser recusado.
    expect(DB::table('measurement_plan_sets')->where('id', $paidOnlyPlan)->delete())->toBe(1)
        ->and(DB::table('measurement_payments')->where('id', $payment)->value('plan_set_id'))->toBeNull();
});

it('adds the activation watermark to a versions table created without it, leaving it empty on the backfilled V1', function () {
    $operation = Operation::factory()->create();
    $inUse = planVersionMigrationLegacyPlan($operation, ['name' => 'Torre A']);
    planVersionMigrationLegacyLine($inUse, 1, '2026-05-01');

    planVersionMigration(PLAN_VERSION_MIGRATION_FILES[0])->up();

    // A tabela de versões como uma execução anterior ao marco a deixou.
    Schema::table('measurement_plan_versions', fn (Blueprint $table) => $table->dropColumn('last_measurement_id_at_activation'));

    planVersionMigrationUpAll();

    planVersionMigrationExpectVersionedSchema();

    $columns = Schema::getColumnListing('measurement_plan_versions');

    // A coluna volta no lugar dela. A V1 do legado fica sem marco: valia para
    // toda medição antes das versões, e a Engenharia continua cobrando o plano
    // de todas.
    expect(array_slice($columns, (int) array_search('activation_progress_percent', $columns, true), 2))
        ->toBe(['activation_progress_percent', 'last_measurement_id_at_activation'])
        ->and(DB::table('measurement_plan_versions')->where('plan_set_id', $inUse)->get(['version_number', 'status', 'last_measurement_id_at_activation'])
            ->map(fn (object $version): array => (array) $version)
            ->all())->toBe([['version_number' => 1, 'status' => 'active', 'last_measurement_id_at_activation' => null]]);
});
