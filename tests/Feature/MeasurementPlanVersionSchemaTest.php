<?php

use App\Enums\MeasurementPlanRevisionCategory;
use App\Exceptions\MeasurementWorkflowException;
use App\Models\Construction;
use App\Models\Measurement;
use App\Models\MeasurementAsset;
use App\Models\MeasurementPayment;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Models\MeasurementPlanVersion;
use App\Models\Operation;
use App\Models\User;
use App\Services\MeasurementPlanVersionService;
use App\Services\MeasurementWorkflow;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\MeasurementPhysicalProgressScenario as Scenario;

uses(RefreshDatabase::class);

/**
 * O plano versionado contra o próprio banco: colunas, uniques, FKs compostas,
 * RESTRICT, cascata e, no MySQL, os CHECKs da versão.
 *
 * Os modelos recusam antes quase tudo o que está aqui; o que se prova é que
 * uma escrita que pule o domínio (script, correção manual, migration futura)
 * ainda esbarra no banco. Por isso as escritas recusadas são cruas, e partem
 * de linhas válidas gravadas pelo serviço. Roda também no MySQL (job parity):
 * as colunas geradas, a cascata através delas e a collation do status só são
 * de verdade lá.
 */
pest()->group('parity');

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    Notification::fake();
    // As medições destes testes guardam o arquivo no disco privado falso.
    config()->set('filesystems.private_disk', 'local');
});

/**
 * Leva o relógio às 10h de Brasília (13h UTC) do dia: a vigência de uma
 * versão ativada é o mês desse dia no calendário de negócio.
 */
function planVersionSchemaTravelTo(string $date): void
{
    test()->travelTo(CarbonImmutable::parse("{$date} 13:00:00", 'UTC'));
}

/**
 * Cronograma de 10% ao mês de 06/2026 a 08/2026, como o formulário o envia.
 *
 * @return list<array{sequence_number: int, planned_monthly_percent: string, planned_cumulative_percent: string, measurement_date: string}>
 */
function planVersionSchemaSchedule(): array
{
    return [
        ['sequence_number' => 1, 'planned_monthly_percent' => '10.00', 'planned_cumulative_percent' => '10.00', 'measurement_date' => '2026-06'],
        ['sequence_number' => 2, 'planned_monthly_percent' => '10.00', 'planned_cumulative_percent' => '20.00', 'measurement_date' => '2026-07'],
        ['sequence_number' => 3, 'planned_monthly_percent' => '10.00', 'planned_cumulative_percent' => '30.00', 'measurement_date' => '2026-08'],
    ];
}

/**
 * Ativa pelo serviço o rascunho como a pessoa o viu: o contador atual.
 */
function planVersionSchemaActivate(MeasurementPlanVersion $draft, User $planner): MeasurementPlanVersion
{
    $seen = $draft->fresh();

    return app(MeasurementPlanVersionService::class)->activate($seen, $planner, (int) $seen->revision);
}

function planVersionSchemaRevise(MeasurementPlanSet $planSet, User $planner): MeasurementPlanVersion
{
    return app(MeasurementPlanVersionService::class)->createRevision($planSet, $planner, [
        'revision_category' => MeasurementPlanRevisionCategory::Schedule->value,
        'revision_reason' => 'Replanejamento do cronograma aprovado pelo comitê de crédito.',
    ]);
}

/**
 * @return array<string, MeasurementPlanLine> linhas da versão por competência ('Y-m')
 */
function planVersionSchemaLinesOf(MeasurementPlanVersion $version): array
{
    return MeasurementPlanLine::query()
        ->where('plan_version_id', $version->id)
        ->orderBy('sequence_number')
        ->get()
        ->keyBy(fn (MeasurementPlanLine $line): string => $line->measurement_date->format('Y-m'))
        ->all();
}

/**
 * Duas operações em 01/06/2026, cada uma com um plano criado pelo serviço: o
 * Residencial Aurora, com a V1 vigente desde 06/2026, e o Residencial Boreal,
 * de outra operação, ainda com a V1 em rascunho. Quem envia as medições
 * responde por todas as etapas; quem planeja é um administrador.
 *
 * @return array{actor: User, planner: User, operation: Operation, otherOperation: Operation, planSet: MeasurementPlanSet, v1: MeasurementPlanVersion, lines: array<string, MeasurementPlanLine>, otherPlanSet: MeasurementPlanSet, otherDraft: MeasurementPlanVersion, otherLines: array<string, MeasurementPlanLine>}
 */
function planVersionSchemaScenario(): array
{
    planVersionSchemaTravelTo('2026-06-01');

    $actor = User::factory()->withTwoFactor()->create();
    $actor->givePermissionTo(['measurements.view', 'measurements.create', 'measurements.update', 'measurements.review', 'measurements.pay', 'measurements.receipts', 'measurements.finalize']);
    $planner = makeAdminUser();
    $operation = Operation::factory()->create(['status' => 'active', ...array_fill_keys(Operation::RESPONSIBILITY_FIELDS, $actor->id)]);
    $otherOperation = Operation::factory()->create(['status' => 'active', ...array_fill_keys(Operation::RESPONSIBILITY_FIELDS, $actor->id)]);
    $service = app(MeasurementPlanVersionService::class);
    $plan = fn (Operation $owner, string $name): MeasurementPlanSet => $service->createPlan($owner, $planner, [
        'name' => $name,
        'is_default' => true,
        'initial_incurred_amount' => '0.00',
    ], ['construction_fund_amount' => '1000000.00'], planVersionSchemaSchedule());

    $planSet = $plan($operation, 'Residencial Aurora');
    $v1 = planVersionSchemaActivate($planSet->draftVersion()->firstOrFail(), $planner);
    $otherPlanSet = $plan($otherOperation, 'Residencial Boreal');
    $otherDraft = $otherPlanSet->draftVersion()->firstOrFail();

    return [
        'actor' => $actor,
        'planner' => $planner,
        'operation' => $operation,
        'otherOperation' => $otherOperation,
        'planSet' => $planSet->fresh(),
        'v1' => $v1,
        'lines' => planVersionSchemaLinesOf($v1),
        'otherPlanSet' => $otherPlanSet->fresh(),
        'otherDraft' => $otherDraft,
        'otherLines' => planVersionSchemaLinesOf($otherDraft),
    ];
}

/**
 * O plano Boreal percorre pelo serviço todas as situações, ainda sem medição:
 * a V1 ativada e substituída pela V2 (vigente), a V3 aberta e cancelada e a
 * V4 em rascunho -- cada uma com o cronograma de três meses.
 *
 * @param  array{planner: User, otherPlanSet: MeasurementPlanSet, otherDraft: MeasurementPlanVersion}  $scenario
 * @return array<int, MeasurementPlanVersion> versões pelo número
 */
function planVersionSchemaLifecycle(array $scenario): array
{
    $planSet = $scenario['otherPlanSet'];
    $planner = $scenario['planner'];

    planVersionSchemaActivate($scenario['otherDraft'], $planner);
    planVersionSchemaActivate(planVersionSchemaRevise($planSet, $planner), $planner);
    $cancelled = planVersionSchemaRevise($planSet, $planner);
    app(MeasurementPlanVersionService::class)->cancel($cancelled, $planner, 'Revisão aberta por engano.', (int) $cancelled->revision);
    planVersionSchemaRevise($planSet, $planner);

    return MeasurementPlanVersion::query()
        ->where('plan_set_id', $planSet->id)
        ->orderBy('version_number')
        ->get()
        ->keyBy('version_number')
        ->all();
}

/**
 * Medição da competência ainda sem arquivo, como o envio a cria.
 */
function planVersionSchemaMeasurement(Operation $operation, User $actor): Measurement
{
    return Measurement::factory()->create([
        'operation_id' => $operation->id,
        'reference_month' => '2026-06-01',
        'status' => 'pending',
        'current_stage' => MeasurementWorkflow::STAGE_ENGINEERING,
        'storage_path' => null,
        'filename' => null,
        'uploaded_by' => $actor->id,
    ]);
}

/**
 * Arquivo da medição de junho do Aurora, gravado pelo modelo: captura a V1 e
 * ocupa a linhagem da linha de junho.
 *
 * @param  array{actor: User, operation: Operation, planSet: MeasurementPlanSet, lines: array<string, MeasurementPlanLine>}  $scenario
 */
function planVersionSchemaMeasurementFile(array $scenario): MeasurementAsset
{
    return Scenario::measurement($scenario, '2026-06')->assets()->sole();
}

/**
 * Pagamento sobre o plano Boreal, sem arquivo de medição: o que referencia o
 * plano aqui é só o pagamento.
 *
 * @param  array{actor: User, otherOperation: Operation, otherPlanSet: MeasurementPlanSet}  $scenario
 */
function planVersionSchemaPayment(array $scenario): MeasurementPayment
{
    return MeasurementPayment::factory()->create([
        'operation_id' => $scenario['otherOperation']->id,
        'measurement_id' => planVersionSchemaMeasurement($scenario['otherOperation'], $scenario['actor'])->id,
        'plan_set_id' => $scenario['otherPlanSet']->id,
        'pay_date' => '2026-06-10',
        'amount' => '100000.00',
    ]);
}

/**
 * A linha gravada como o banco a devolve, pronta para ser reinserida com
 * outros valores: sem o id e sem as colunas geradas, que só o banco escreve.
 *
 * @return array<string, mixed>
 */
function planVersionSchemaRow(string $table, int $id): array
{
    return Arr::except((array) DB::table($table)->where('id', $id)->first(), ['id', 'active_plan_set_id', 'draft_plan_set_id']);
}

/**
 * Tudo o que o plano grava, linha a linha: a escrita recusada não pode ter
 * deixado nada para trás nem levado nada junto.
 *
 * @return array<string, list<array<string, mixed>>>
 */
function planVersionSchemaFootprint(): array
{
    return collect(['measurement_plan_sets', 'measurement_plan_versions', 'measurement_plan_lines', 'measurement_assets', 'measurement_payments'])
        ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->all()])
        ->all();
}

/**
 * Versão do Boreal -- cuja V1 é rascunho, sem vigente -- com o número 2: só o
 * CHECK sob teste pode recusá-la.
 *
 * @param  array{otherDraft: MeasurementPlanVersion}  $scenario
 * @param  array<string, mixed>  $attributes
 * @return array<string, mixed>
 */
function planVersionSchemaCheckedRow(array $scenario, array $attributes): array
{
    return [...planVersionSchemaRow('measurement_plan_versions', $scenario['otherDraft']->id), 'version_number' => 2, ...$attributes];
}

/**
 * Vagas de vigente e de rascunho de cada versão do plano, lidas das colunas
 * geradas.
 *
 * @return array<string, array{active: int|null, draft: int|null}>
 */
function planVersionSchemaSlots(MeasurementPlanSet $planSet): array
{
    return DB::table('measurement_plan_versions')
        ->where('plan_set_id', $planSet->id)
        ->orderBy('version_number')
        ->get()
        ->mapWithKeys(fn (object $version): array => ["V{$version->version_number} {$version->status}" => [
            'active' => $version->active_plan_set_id === null ? null : (int) $version->active_plan_set_id,
            'draft' => $version->draft_plan_set_id === null ? null : (int) $version->draft_plan_set_id,
        ]])
        ->all();
}

/**
 * Como cada banco nomeia a unique que recusou a escrita: o índice, no MySQL;
 * as colunas, no SQLite.
 *
 * @param  list<string>  $columns
 */
function planVersionSchemaUniqueMarker(string $table, string $index, array $columns): string
{
    return DB::getDriverName() === 'mysql'
        ? "for key '{$table}.{$index}'"
        : 'UNIQUE constraint failed: '.implode(', ', array_map(fn (string $column): string => "{$table}.{$column}", $columns));
}

/**
 * A FK que recusou gravar a linha filha. O MySQL a nomeia; o SQLite só diz que
 * uma FK falhou -- lá, a linha consistente aceita logo antes é que mostra qual
 * coluna foi recusada.
 */
function planVersionSchemaForeignKeyMarker(string $table, string $constraint): string
{
    return DB::getDriverName() === 'mysql'
        ? sprintf('Cannot add or update a child row: a foreign key constraint fails (`%s`.`%s`, CONSTRAINT `%s` FOREIGN KEY', DB::connection()->getDatabaseName(), $table, $constraint)
        : 'FOREIGN KEY constraint failed';
}

/**
 * A exclusão recusada porque a tabela filha ainda referencia a linha. A FK que
 * segura depende do caminho da cascata; a tabela filha, não.
 */
function planVersionSchemaRestrictMarker(string $childTable): string
{
    return DB::getDriverName() === 'mysql'
        ? sprintf('Cannot delete or update a parent row: a foreign key constraint fails (`%s`.`%s`', DB::connection()->getDatabaseName(), $childTable)
        : 'FOREIGN KEY constraint failed';
}

// ── Colunas ──────────────────────────────────────────────────────────────────

it('keeps the fund in the version and ties every schedule line to a version', function () {
    $columns = fn (string $table): array => collect(Schema::getColumns($table))->keyBy('name')->all();
    $lines = $columns('measurement_plan_lines');
    $assets = $columns('measurement_assets');
    $versions = $columns('measurement_plan_versions');

    expect(Schema::hasColumn('measurement_plan_sets', 'construction_fund_amount'))->toBeFalse()
        ->and($versions)->toHaveKey('construction_fund_amount')
        // A medição cobre um plano por empreendimento pelos arquivos; a coluna
        // antiga nunca foi gravada nem lida.
        ->and(Schema::hasColumn('measurements', 'plan_set_id'))->toBeFalse()
        ->and($lines['plan_version_id']['nullable'])->toBeFalse()
        ->and($lines['lineage_key']['nullable'])->toBeFalse()
        // Arquivo sem plano (legado) não tem versão, e a ocupação esvazia na
        // recusa terminal.
        ->and($assets['plan_version_id']['nullable'])->toBeTrue()
        ->and($assets['line_claim_key']['nullable'])->toBeTrue()
        // VIRTUAL, e não STORED: o MySQL recusa CASCADE na FK da coluna-base de
        // uma coluna gerada STORED, e a versão desce em cascata com o plano.
        ->and($versions['active_plan_set_id']['generation']['type'] ?? null)->toBe('virtual')
        ->and($versions['active_plan_set_id']['generation']['expression'])->toContain('active')->toContain('plan_set_id')->not->toContain('draft')
        ->and($versions['active_plan_set_id']['nullable'])->toBeTrue()
        ->and($versions['draft_plan_set_id']['generation']['type'] ?? null)->toBe('virtual')
        ->and($versions['draft_plan_set_id']['generation']['expression'])->toContain('draft')->toContain('plan_set_id')->not->toContain('active')
        ->and($versions['draft_plan_set_id']['nullable'])->toBeTrue();
});

it('derives the active and the draft slot of each version from its status', function () {
    $scenario = planVersionSchemaScenario();
    $planSetId = $scenario['otherPlanSet']->id;

    expect(planVersionSchemaSlots($scenario['otherPlanSet']))->toBe([
        'V1 draft' => ['active' => null, 'draft' => $planSetId],
    ]);

    planVersionSchemaLifecycle($scenario);

    // Só a vigente e o rascunho ocupam vaga; substituída e cancelada ficam
    // fora das uniques e se acumulam no histórico.
    expect(planVersionSchemaSlots($scenario['otherPlanSet']))->toBe([
        'V1 superseded' => ['active' => null, 'draft' => null],
        'V2 active' => ['active' => $planSetId, 'draft' => null],
        'V3 cancelled' => ['active' => null, 'draft' => null],
        'V4 draft' => ['active' => null, 'draft' => $planSetId],
    ]);
});

// ── Uniques ──────────────────────────────────────────────────────────────────

it('numbers each version of a plan once', function () {
    $scenario = planVersionSchemaScenario();
    $row = [...planVersionSchemaRow('measurement_plan_versions', $scenario['v1']->id), 'status' => 'cancelled'];
    $before = planVersionSchemaFootprint();

    // Nem uma versão cancelada reaproveita o número: a V1 do histórico seria
    // outra.
    expect(fn () => DB::table('measurement_plan_versions')->insert([...$row, 'version_number' => 1]))
        ->toThrow(UniqueConstraintViolationException::class, planVersionSchemaUniqueMarker('measurement_plan_versions', 'mpv_plan_set_version_unique', ['plan_set_id', 'version_number']));

    expect(planVersionSchemaFootprint())->toBe($before);

    DB::table('measurement_plan_versions')->insert([...$row, 'version_number' => 2]);

    // O número seguinte cabe, e a V1 existe uma vez em cada plano.
    expect(DB::table('measurement_plan_versions')->where('plan_set_id', $scenario['planSet']->id)->orderBy('version_number')->pluck('version_number')->map(fn (mixed $number): int => (int) $number)->all())
        ->toBe([1, 2])
        ->and(DB::table('measurement_plan_versions')->where('version_number', 1)->orderBy('plan_set_id')->pluck('plan_set_id')->map(fn (mixed $id): int => (int) $id)->all())
        ->toBe([$scenario['planSet']->id, $scenario['otherPlanSet']->id]);
});

it('keeps one active and one draft per plan while superseded and cancelled versions pile up', function () {
    $scenario = planVersionSchemaScenario();
    $versions = planVersionSchemaLifecycle($scenario);
    $copy = fn (int $number, int $versionNumber): array => [...planVersionSchemaRow('measurement_plan_versions', $versions[$number]->id), 'version_number' => $versionNumber];
    $before = planVersionSchemaFootprint();

    // Duas vigentes dariam duas respostas para "qual cronograma vale"; dois
    // rascunhos, duas edições paralelas disputando a próxima vigência.
    expect(fn () => DB::table('measurement_plan_versions')->insert($copy(2, 5)))
        ->toThrow(UniqueConstraintViolationException::class, planVersionSchemaUniqueMarker('measurement_plan_versions', 'mpv_active_plan_set_unique', ['active_plan_set_id']))
        ->and(fn () => DB::table('measurement_plan_versions')->insert($copy(4, 5)))
        ->toThrow(UniqueConstraintViolationException::class, planVersionSchemaUniqueMarker('measurement_plan_versions', 'mpv_draft_plan_set_unique', ['draft_plan_set_id']));

    expect(planVersionSchemaFootprint())->toBe($before);

    DB::table('measurement_plan_versions')->insert([$copy(1, 5), $copy(1, 6), $copy(3, 7), $copy(3, 8)]);

    expect(DB::table('measurement_plan_versions')->where('plan_set_id', $scenario['otherPlanSet']->id)->orderBy('version_number')->pluck('status')->all())
        ->toBe(['superseded', 'active', 'cancelled', 'draft', 'superseded', 'superseded', 'cancelled', 'cancelled']);
});

it('numbers the schedule lines once per version and repeats the numbers in the next version', function () {
    $scenario = planVersionSchemaScenario();
    $v1 = $scenario['v1'];
    $v2 = planVersionSchemaRevise($scenario['planSet'], $scenario['planner']);
    $sequences = fn (MeasurementPlanVersion $version): array => DB::table('measurement_plan_lines')
        ->where('plan_version_id', $version->id)
        ->orderBy('sequence_number')
        ->pluck('sequence_number')
        ->map(fn (mixed $sequence): int => (int) $sequence)
        ->all();
    $line = fn (MeasurementPlanVersion $version, int $sequence): array => [
        ...planVersionSchemaRow('measurement_plan_lines', $scenario['lines']['2026-08']->id),
        'plan_version_id' => $version->id,
        'sequence_number' => $sequence,
        'lineage_key' => (string) Str::ulid(),
    ];

    // A revisão copiou a V1 com as mesmas sequências: o banco já as aceitou em
    // duas versões do mesmo plano.
    expect($sequences($v1))->toBe([1, 2, 3])
        ->and($sequences($v2))->toBe([1, 2, 3]);

    $before = planVersionSchemaFootprint();

    expect(fn () => DB::table('measurement_plan_lines')->insert($line($v2, 1)))
        ->toThrow(UniqueConstraintViolationException::class, planVersionSchemaUniqueMarker('measurement_plan_lines', 'mpl_version_sequence_unique', ['plan_version_id', 'sequence_number']));

    expect(planVersionSchemaFootprint())->toBe($before);

    DB::table('measurement_plan_lines')->insert([$line($v1, 4), $line($v2, 4)]);

    expect($sequences($v1))->toBe([1, 2, 3, 4])
        ->and($sequences($v2))->toBe([1, 2, 3, 4]);
});

it('keeps each lineage once per version and the same lineage across the versions of the plan', function () {
    $scenario = planVersionSchemaScenario();
    $v2 = planVersionSchemaRevise($scenario['planSet'], $scenario['planner']);
    $lineages = fn (MeasurementPlanVersion $version): array => DB::table('measurement_plan_lines')
        ->where('plan_version_id', $version->id)
        ->orderBy('sequence_number')
        ->pluck('lineage_key')
        ->all();

    // A cópia da revisão é a mesma medição prevista: mesma linhagem, linha a
    // linha.
    expect($lineages($v2))->toBe($lineages($scenario['v1']))
        ->and(array_unique($lineages($v2)))->toHaveCount(3);

    $before = planVersionSchemaFootprint();

    // Duas linhas da versão com a mesma linhagem seriam a mesma medição
    // prevista duas vezes no cronograma.
    expect(fn () => DB::table('measurement_plan_lines')->insert([
        ...planVersionSchemaRow('measurement_plan_lines', $scenario['lines']['2026-08']->id),
        'plan_version_id' => $v2->id,
        'sequence_number' => 9,
        'lineage_key' => $lineages($v2)[0],
    ]))->toThrow(UniqueConstraintViolationException::class, planVersionSchemaUniqueMarker('measurement_plan_lines', 'mpl_version_lineage_unique', ['plan_version_id', 'lineage_key']));

    expect(planVersionSchemaFootprint())->toBe($before);
});

it('lets one measurement at a time claim a planned measurement while released files repeat freely', function () {
    $scenario = planVersionSchemaScenario();
    $asset = planVersionSchemaMeasurementFile($scenario);
    $june = $scenario['lines']['2026-06'];
    $file = fn (?string $claim): array => [
        ...planVersionSchemaRow('measurement_assets', $asset->id),
        'measurement_id' => planVersionSchemaMeasurement($scenario['operation'], $scenario['actor'])->id,
        'line_claim_key' => $claim,
    ];
    $rival = $file($june->lineage_key);
    $before = planVersionSchemaFootprint();

    expect($asset->line_claim_key)->toBe($june->lineage_key);

    // A corrida das duas abas: a segunda medição de junho esbarra na unique
    // mesmo sem passar pelo modelo.
    expect(fn () => DB::table('measurement_assets')->insert($rival))
        ->toThrow(UniqueConstraintViolationException::class, planVersionSchemaUniqueMarker('measurement_assets', 'ma_line_claim_unique', ['line_claim_key']));

    expect(planVersionSchemaFootprint())->toBe($before);

    // Arquivos que soltaram a linha (recusa terminal) ficam com a ocupação
    // vazia, quantos forem.
    DB::table('measurement_assets')->insert([$file(null), $file(null)]);

    expect(DB::table('measurement_assets')->where('plan_line_id', $june->id)->orderBy('id')->pluck('line_claim_key')->all())
        ->toBe([$june->lineage_key, null, null]);
});

it('plans each construction once per operation while plans without construction repeat', function () {
    $scenario = planVersionSchemaScenario();
    $construction = Construction::factory()->create(['development_name' => 'Torre Celeste']);
    $plan = fn (Operation $operation, ?Construction $planned, string $name): array => [
        'operation_id' => $operation->id,
        'construction_id' => $planned?->id,
        'name' => $name,
        'is_default' => false,
        'initial_incurred_amount' => '0.00',
        'initial_physical_progress_percent' => '0.00',
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('measurement_plan_sets')->insert($plan($scenario['operation'], $construction, 'Torre Celeste'));
    $before = planVersionSchemaFootprint();

    // Nome diferente: quem recusa é a unique (operação, obra), não a do nome.
    expect(fn () => DB::table('measurement_plan_sets')->insert($plan($scenario['operation'], $construction, 'Torre Celeste replanejada')))
        ->toThrow(UniqueConstraintViolationException::class, planVersionSchemaUniqueMarker('measurement_plan_sets', 'mps_operation_construction_unique', ['operation_id', 'construction_id']));

    expect(planVersionSchemaFootprint())->toBe($before);

    // A mesma obra em outra operação cabe, e plano sem obra (legado) não
    // colide: nulo não ocupa a unique.
    DB::table('measurement_plan_sets')->insert([
        $plan($scenario['otherOperation'], $construction, 'Torre Celeste'),
        $plan($scenario['operation'], null, 'Plano legado A'),
        $plan($scenario['operation'], null, 'Plano legado B'),
    ]);

    expect(DB::table('measurement_plan_sets')->where('construction_id', $construction->id)->orderBy('operation_id')->pluck('operation_id')->map(fn (mixed $id): int => (int) $id)->all())
        ->toBe([$scenario['operation']->id, $scenario['otherOperation']->id])
        ->and(DB::table('measurement_plan_sets')->where('operation_id', $scenario['operation']->id)->whereNull('construction_id')->orderBy('id')->pluck('name')->all())
        ->toBe(['Residencial Aurora', 'Plano legado A', 'Plano legado B']);
});

// ── FKs compostas ────────────────────────────────────────────────────────────

it('refuses a schedule line placed in a version of another plan', function () {
    $scenario = planVersionSchemaScenario();
    $line = fn (int $sequence): array => [
        ...planVersionSchemaRow('measurement_plan_lines', $scenario['lines']['2026-08']->id),
        'sequence_number' => $sequence,
        'lineage_key' => (string) Str::ulid(),
    ];

    // A mesma linha, na versão do próprio plano, o banco aceita.
    DB::table('measurement_plan_lines')->insert($line(10));
    $before = planVersionSchemaFootprint();

    expect(fn () => DB::table('measurement_plan_lines')->insert([...$line(11), 'plan_version_id' => $scenario['otherDraft']->id]))
        ->toThrow(QueryException::class, planVersionSchemaForeignKeyMarker('measurement_plan_lines', 'mpl_version_plan_set_foreign'));

    expect(planVersionSchemaFootprint())->toBe($before);
});

it('refuses a schedule line of another operation than its plan', function () {
    $scenario = planVersionSchemaScenario();
    $line = fn (int $sequence): array => [
        ...planVersionSchemaRow('measurement_plan_lines', $scenario['lines']['2026-08']->id),
        'sequence_number' => $sequence,
        'lineage_key' => (string) Str::ulid(),
    ];

    DB::table('measurement_plan_lines')->insert($line(10));
    $before = planVersionSchemaFootprint();

    // A operação copiada na linha é a do plano: as consultas da operação a
    // usam sem passar pelo plano.
    expect(fn () => DB::table('measurement_plan_lines')->insert([...$line(11), 'operation_id' => $scenario['otherOperation']->id]))
        ->toThrow(QueryException::class, planVersionSchemaForeignKeyMarker('measurement_plan_lines', 'mpl_plan_set_operation_foreign'));

    expect(planVersionSchemaFootprint())->toBe($before);
});

it('refuses a version of another operation than its plan', function () {
    $scenario = planVersionSchemaScenario();
    $version = fn (int $versionNumber): array => [
        ...planVersionSchemaRow('measurement_plan_versions', $scenario['v1']->id),
        'status' => 'cancelled',
        'version_number' => $versionNumber,
    ];

    DB::table('measurement_plan_versions')->insert($version(2));
    $before = planVersionSchemaFootprint();

    expect(fn () => DB::table('measurement_plan_versions')->insert([...$version(3), 'operation_id' => $scenario['otherOperation']->id]))
        ->toThrow(QueryException::class, planVersionSchemaForeignKeyMarker('measurement_plan_versions', 'mpv_plan_set_operation_foreign'));

    expect(planVersionSchemaFootprint())->toBe($before);
});

it('refuses a measurement file whose version belongs to another plan', function () {
    $scenario = planVersionSchemaScenario();
    $asset = planVersionSchemaMeasurementFile($scenario);
    $file = fn (): array => [
        ...planVersionSchemaRow('measurement_assets', $asset->id),
        'measurement_id' => planVersionSchemaMeasurement($scenario['operation'], $scenario['actor'])->id,
        'line_claim_key' => null,
    ];

    DB::table('measurement_assets')->insert($file());
    $mismatched = [...$file(), 'plan_version_id' => $scenario['otherDraft']->id, 'plan_line_id' => $scenario['otherLines']['2026-06']->id];
    $before = planVersionSchemaFootprint();

    // Linha e versão do Boreal entre si consistentes, mas o arquivo diz ser do
    // Aurora: a versão capturada precisa ser do plano do arquivo.
    expect(fn () => DB::table('measurement_assets')->insert($mismatched))
        ->toThrow(QueryException::class, planVersionSchemaForeignKeyMarker('measurement_assets', 'ma_version_plan_set_foreign'));

    expect(planVersionSchemaFootprint())->toBe($before);
});

it('refuses a measurement file whose planned line belongs to another version of the plan', function () {
    $scenario = planVersionSchemaScenario();
    $asset = planVersionSchemaMeasurementFile($scenario);
    $v2 = planVersionSchemaRevise($scenario['planSet'], $scenario['planner']);
    $file = fn (MeasurementPlanLine $line): array => [
        ...planVersionSchemaRow('measurement_assets', $asset->id),
        'measurement_id' => planVersionSchemaMeasurement($scenario['operation'], $scenario['actor'])->id,
        'plan_version_id' => $v2->id,
        'plan_line_id' => $line->id,
        'line_claim_key' => null,
    ];

    DB::table('measurement_assets')->insert($file(planVersionSchemaLinesOf($v2)['2026-06']));
    $mismatched = $file($scenario['lines']['2026-06']);
    $before = planVersionSchemaFootprint();

    // Junho da V1 sob a V2: mesmo plano, mas a linha não é da versão capturada.
    expect(fn () => DB::table('measurement_assets')->insert($mismatched))
        ->toThrow(QueryException::class, planVersionSchemaForeignKeyMarker('measurement_assets', 'ma_line_version_foreign'));

    expect(planVersionSchemaFootprint())->toBe($before);
});

// ── RESTRICT e CASCADE ───────────────────────────────────────────────────────

it('refuses to delete the version, the line or the plan a measurement file references', function () {
    $scenario = planVersionSchemaScenario();
    planVersionSchemaMeasurementFile($scenario);
    $before = planVersionSchemaFootprint();
    $marker = planVersionSchemaRestrictMarker('measurement_assets');

    // Antes era SET NULL: o arquivo perdia o vínculo histórico em silêncio.
    expect(fn () => DB::table('measurement_plan_versions')->where('id', $scenario['v1']->id)->delete())->toThrow(QueryException::class, $marker)
        ->and(fn () => DB::table('measurement_plan_lines')->where('id', $scenario['lines']['2026-06']->id)->delete())->toThrow(QueryException::class, $marker)
        ->and(fn () => DB::table('measurement_plan_sets')->where('id', $scenario['planSet']->id)->delete())->toThrow(QueryException::class, $marker);

    expect(planVersionSchemaFootprint())->toBe($before);

    // A linha que nenhum arquivo referencia continua excluível.
    expect(DB::table('measurement_plan_lines')->where('id', $scenario['lines']['2026-08']->id)->delete())->toBe(1);
});

it('refuses to delete the plan a payment references', function () {
    $scenario = planVersionSchemaScenario();
    planVersionSchemaPayment($scenario);
    $before = planVersionSchemaFootprint();

    expect(fn () => DB::table('measurement_plan_sets')->where('id', $scenario['otherPlanSet']->id)->delete())
        ->toThrow(QueryException::class, planVersionSchemaRestrictMarker('measurement_payments'));

    expect(planVersionSchemaFootprint())->toBe($before);
});

it('keeps a payment on the plan it was recorded for through the model', function () {
    $scenario = planVersionSchemaScenario();
    $payment = planVersionSchemaPayment($scenario);
    $refusal = new MeasurementWorkflowException('O pagamento continua vinculado à medição, à operação e ao plano em que foi registrado.');
    $before = (array) DB::table('measurement_payments')->where('id', $payment->id)->first();
    $activities = DB::table('activity_log')->count();

    // O banco segura a exclusão do plano; a troca (ou o esvaziamento) do plano
    // do pagamento quem recusa é o modelo.
    expect(fn () => $payment->fresh()->update(['plan_set_id' => $scenario['planSet']->id]))->toThrow($refusal)
        ->and(fn () => $payment->fresh()->update(['plan_set_id' => null]))->toThrow($refusal);

    expect((array) DB::table('measurement_payments')->where('id', $payment->id)->first())->toBe($before)
        ->and(DB::table('activity_log')->count())->toBe($activities);
});

it('deletes a plan without measurement history together with all its versions and lines', function () {
    $scenario = planVersionSchemaScenario();
    $versions = planVersionSchemaLifecycle($scenario);
    $planSetId = $scenario['otherPlanSet']->id;
    $before = planVersionSchemaFootprint();
    $belongsToThePlan = fn (string $table, array $row): bool => (int) $row[$table === 'measurement_plan_sets' ? 'id' : 'plan_set_id'] === $planSetId;
    $expected = collect($before)
        ->map(fn (array $rows, string $table): array => array_values(array_filter($rows, fn (array $row): bool => ! $belongsToThePlan($table, $row))))
        ->all();

    expect(collect($versions)->map(fn (MeasurementPlanVersion $version): string => $version->status->value)->all())
        ->toBe([1 => 'superseded', 2 => 'active', 3 => 'cancelled', 4 => 'draft'])
        ->and(DB::table('measurement_plan_lines')->where('plan_set_id', $planSetId)->count())->toBe(12);

    // Plano cadastrado por engano, sem medição: sai inteiro, e as versões --
    // que se referenciam entre si -- descem com ele pelas FKs.
    expect(DB::table('measurement_plan_sets')->where('id', $planSetId)->delete())->toBe(1);

    expect(planVersionSchemaFootprint())->toBe($expected)
        ->and(DB::table('measurement_plan_versions')->whereIn('id', collect($versions)->pluck('id')->all())->exists())->toBeFalse();
});

// ── CHECKs do MySQL ──────────────────────────────────────────────────────────

describe('MySQL CHECK constraints', function () {
    beforeEach(function () {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Os CHECKs de measurement_plan_versions só existem no MySQL: o SQLite dos testes não aceita ADD CONSTRAINT, e lá a regra fica no modelo da versão. Rode pelo scripts/parity-check.sh.');
        }
    });

    it('refuses a status the application does not know', function (string $unknown, string $known) {
        $scenario = planVersionSchemaScenario();
        $row = fn (string $status): array => planVersionSchemaCheckedRow($scenario, [
            'status' => $status,
            'effective_from' => '2026-06-01',
            'activated_at' => '2026-06-01 13:00:00',
            'activated_by' => $scenario['planner']->id,
        ]);
        $before = planVersionSchemaFootprint();

        expect(fn () => DB::table('measurement_plan_versions')->insert($row($unknown)))
            ->toThrow(QueryException::class, "Check constraint 'mpv_status_check' is violated");

        expect(planVersionSchemaFootprint())->toBe($before);

        DB::table('measurement_plan_versions')->insert($row($known));

        expect(DB::table('measurement_plan_versions')->where('plan_set_id', $scenario['otherPlanSet']->id)->orderBy('version_number')->pluck('status')->all())
            ->toBe(['draft', $known]);
    })->with([
        'unknown status' => ['archived', 'cancelled'],
        // Com collation sem caixa, 'Active' passaria no CHECK e ocuparia a vaga
        // de vigente; o SQLite compara byte a byte.
        'capitalised status' => ['Active', 'active'],
        // O espaço no fim também: o SQLite não iguala 'active ' a 'active', mas
        // uma collation binária PAD SPACE iguala -- e a vaga de vigente também.
        'status padded with a trailing space' => ['active ', 'active'],
    ]);

    it('compares the status in binary', function () {
        $status = collect(Schema::getColumns('measurement_plan_versions'))->firstWhere('name', 'status');

        expect($status['collation'])->toStartWith('utf8mb4')->toEndWith('_bin');
    });

    it('starts the version numbers at one', function () {
        $scenario = planVersionSchemaScenario();
        $row = fn (int $versionNumber): array => planVersionSchemaCheckedRow($scenario, ['status' => 'cancelled', 'version_number' => $versionNumber]);
        $before = planVersionSchemaFootprint();

        expect(fn () => DB::table('measurement_plan_versions')->insert($row(0)))
            ->toThrow(QueryException::class, "Check constraint 'mpv_version_number_check' is violated");

        expect(planVersionSchemaFootprint())->toBe($before);

        DB::table('measurement_plan_versions')->insert($row(2));

        expect(DB::table('measurement_plan_versions')->where('plan_set_id', $scenario['otherPlanSet']->id)->orderBy('version_number')->pluck('version_number')->map(fn (mixed $number): int => (int) $number)->all())
            ->toBe([1, 2]);
    });

    it('refuses an effective version without its effective date or its activation record', function (string $status, string $missing) {
        $scenario = planVersionSchemaScenario();
        $complete = planVersionSchemaCheckedRow($scenario, [
            'status' => $status,
            'effective_from' => '2026-06-01',
            'activated_at' => '2026-06-01 13:00:00',
            'activated_by' => $scenario['planner']->id,
        ]);
        $before = planVersionSchemaFootprint();

        // Vigente ou substituída valeu a partir de uma competência, ativada por
        // alguém em algum instante; sem isso a versão não diz o que regeu.
        expect(fn () => DB::table('measurement_plan_versions')->insert([...$complete, $missing => null]))
            ->toThrow(QueryException::class, "Check constraint 'mpv_active_effective_from_check' is violated");

        expect(planVersionSchemaFootprint())->toBe($before);

        DB::table('measurement_plan_versions')->insert($complete);

        expect(DB::table('measurement_plan_versions')->where('plan_set_id', $scenario['otherPlanSet']->id)->orderBy('version_number')->pluck('status')->all())
            ->toBe(['draft', $status]);
    })->with([
        'active without effective date' => ['active', 'effective_from'],
        'active without activation' => ['active', 'activated_at'],
        'superseded without effective date' => ['superseded', 'effective_from'],
        'superseded without activation' => ['superseded', 'activated_at'],
    ]);
});
