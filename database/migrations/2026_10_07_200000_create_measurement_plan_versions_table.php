<?php

use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Versões do plano de medição.
 *
 * O plano (`measurement_plan_sets`) continua sendo o contexto de medição de uma
 * obra dentro da operação: dele são o avanço físico inicial -- um só, imutável
 * --, o teto de 100%, os arquivos e os pagamentos. O que muda numa replanificação
 * -- o cronograma previsto e o Fundo de Obra (o custo previsto da obra) -- passa
 * a viver numa versão: V1, V2, V3... Uma versão vigente nunca é editada; a
 * mudança nasce num rascunho, que ao ser ativado substitui a vigente.
 *
 * No máximo uma vigente e um rascunho por plano, garantidos pelo banco: as
 * colunas geradas `active_plan_set_id` e `draft_plan_set_id` valem o id do plano
 * enquanto a versão está naquela situação e nulo depois, e a unique sobre cada
 * uma recusa a segunda. São VIRTUAL, e não STORED como em
 * `contracts.occupied_unit_lock`: o MySQL recusa CASCADE na FK da coluna-base de
 * uma coluna gerada STORED (erro 1215), e a versão desce em cascata com o plano
 * que ainda não tem histórico de medição. Nas versões que têm, as FKs RESTRICT
 * dos arquivos e dos pagamentos (migration seguinte) impedem a exclusão.
 *
 * Os planos existentes -- só de desenvolvimento e homologação: a Medição nunca
 * foi usada em produção -- ganham a V1 com o Fundo de Obra que tinham: vigente
 * quando o plano já estava em uso (cronograma, arquivo ou pagamento), rascunho
 * quando ainda estava vazio. A coluna sai do plano na migration seguinte. Cada
 * plano é decidido numa transação que trava a Operation primeiro, como a
 * aprovação da Engenharia; a DDL fica fora de transação (no SQLite, a
 * reconstrução de tabela dentro de uma apagaria filhos em cascata).
 *
 * Idempotente: no MySQL o DDL não se desfaz, e uma falha no meio não pode
 * deixar a reexecução sem saída.
 */
return new class extends Migration
{
    private const TABLE = 'measurement_plan_versions';

    private const PLAN_SET_VERSION_UNIQUE = 'mpv_plan_set_version_unique';

    private const ID_PLAN_SET_UNIQUE = 'mpv_id_plan_set_unique';

    private const ACTIVE_UNIQUE = 'mpv_active_plan_set_unique';

    private const DRAFT_UNIQUE = 'mpv_draft_plan_set_unique';

    private const OPERATION_STATUS_INDEX = 'mpv_operation_status_index';

    private const STATUS_CHECK = 'mpv_status_check';

    private const VERSION_NUMBER_CHECK = 'mpv_version_number_check';

    private const ACTIVE_EFFECTIVE_FROM_CHECK = 'mpv_active_effective_from_check';

    private const PLAN_CONSTRUCTION_UNIQUE = 'mps_operation_construction_unique';

    private const PLAN_ID_OPERATION_UNIQUE = 'mps_id_operation_unique';

    private const CHUNK_SIZE = 200;

    private const MIGRATION = '2026_10_07_200000_create_measurement_plan_versions_table';

    /**
     * @var array<string, array{columns: list<string>, on: string, references: list<string>, name: string, onDelete: string}>
     */
    private const FOREIGN_KEYS = [
        'operation_id' => ['columns' => ['operation_id'], 'on' => 'operations', 'references' => ['id'], 'name' => 'mpv_operation_foreign', 'onDelete' => 'cascade'],
        'plan_set_id' => ['columns' => ['plan_set_id'], 'on' => 'measurement_plan_sets', 'references' => ['id'], 'name' => 'mpv_plan_set_foreign', 'onDelete' => 'cascade'],
        'plan_set_operation' => ['columns' => ['plan_set_id', 'operation_id'], 'on' => 'measurement_plan_sets', 'references' => ['id', 'operation_id'], 'name' => 'mpv_plan_set_operation_foreign', 'onDelete' => 'cascade'],
        'previous_version_id' => ['columns' => ['previous_version_id'], 'on' => self::TABLE, 'references' => ['id'], 'name' => 'mpv_previous_version_foreign', 'onDelete' => 'set null'],
        'superseded_by_version_id' => ['columns' => ['superseded_by_version_id'], 'on' => self::TABLE, 'references' => ['id'], 'name' => 'mpv_superseded_by_version_foreign', 'onDelete' => 'set null'],
        'created_by' => ['columns' => ['created_by'], 'on' => 'users', 'references' => ['id'], 'name' => 'mpv_created_by_foreign', 'onDelete' => 'set null'],
        'activated_by' => ['columns' => ['activated_by'], 'on' => 'users', 'references' => ['id'], 'name' => 'mpv_activated_by_foreign', 'onDelete' => 'set null'],
        'cancelled_by' => ['columns' => ['cancelled_by'], 'on' => 'users', 'references' => ['id'], 'name' => 'mpv_cancelled_by_foreign', 'onDelete' => 'set null'],
    ];

    public function up(): void
    {
        // Alvo da FK composta (plano, operação) das versões e das linhas: a
        // operação copiada nelas é a do plano, garantido pelo banco.
        if (! $this->hasIndex('measurement_plan_sets', self::PLAN_ID_OPERATION_UNIQUE)) {
            Schema::table('measurement_plan_sets', function (Blueprint $table): void {
                $table->unique(['id', 'operation_id'], self::PLAN_ID_OPERATION_UNIQUE);
            });
        }

        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('operation_id');
                $table->unsignedBigInteger('plan_set_id');
                $table->unsignedInteger('version_number');
                $status = $table->string('status', 20);

                // No MySQL a collation padrão compara sem caixa: 'Active'
                // ocuparia a vaga de vigente e passaria no CHECK, e o SQLite
                // (que compara byte a byte) não. Binária e NO PAD nos dois: a
                // utf8mb4_bin é PAD SPACE e acharia 'active ' igual a 'active'.
                if (DB::getDriverName() === 'mysql') {
                    $status->collation('utf8mb4_0900_bin');
                }

                $table->unsignedBigInteger('previous_version_id')->nullable();
                $table->unsignedBigInteger('superseded_by_version_id')->nullable();
                $table->date('effective_from')->nullable();
                $table->decimal('construction_fund_amount', 18, 2)->nullable();
                $table->string('revision_category', 30)->nullable();
                $table->text('revision_reason')->nullable();
                $table->unsignedInteger('revision')->default(0);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('activated_by')->nullable();
                $table->timestamp('activated_at')->nullable();
                $table->decimal('activation_progress_percent', 5, 2)->nullable();
                // A última medição da operação quando a versão foi ativada. Não
                // é FK -- é um marco: a medição enviada até ali não precisa
                // cobrir um plano que ainda não valia (a ativação e o envio se
                // serializam pela Operation, então o id separa antes e depois).
                $table->unsignedBigInteger('last_measurement_id_at_activation')->nullable();
                $table->timestamp('superseded_at')->nullable();
                $table->unsignedBigInteger('cancelled_by')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->text('cancellation_reason')->nullable();
                $table->unsignedBigInteger('active_plan_set_id')->nullable()
                    ->virtualAs("CASE WHEN status = 'active' THEN plan_set_id END");
                $table->unsignedBigInteger('draft_plan_set_id')->nullable()
                    ->virtualAs("CASE WHEN status = 'draft' THEN plan_set_id END");
                $table->timestamps();

                $table->unique(['plan_set_id', 'version_number'], self::PLAN_SET_VERSION_UNIQUE);
                $table->unique(['id', 'plan_set_id'], self::ID_PLAN_SET_UNIQUE);
                $table->unique('active_plan_set_id', self::ACTIVE_UNIQUE);
                $table->unique('draft_plan_set_id', self::DRAFT_UNIQUE);
                $table->index(['operation_id', 'status'], self::OPERATION_STATUS_INDEX);

                foreach (self::FOREIGN_KEYS as $foreignKey) {
                    $this->foreignKey($table, $foreignKey);
                }
            });
        }

        if (! Schema::hasColumn(self::TABLE, 'last_measurement_id_at_activation')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unsignedBigInteger('last_measurement_id_at_activation')->nullable()->after('activation_progress_percent');
            });
        }

        foreach ([
            self::PLAN_SET_VERSION_UNIQUE => fn (Blueprint $table) => $table->unique(['plan_set_id', 'version_number'], self::PLAN_SET_VERSION_UNIQUE),
            self::ID_PLAN_SET_UNIQUE => fn (Blueprint $table) => $table->unique(['id', 'plan_set_id'], self::ID_PLAN_SET_UNIQUE),
            self::ACTIVE_UNIQUE => fn (Blueprint $table) => $table->unique('active_plan_set_id', self::ACTIVE_UNIQUE),
            self::DRAFT_UNIQUE => fn (Blueprint $table) => $table->unique('draft_plan_set_id', self::DRAFT_UNIQUE),
            self::OPERATION_STATUS_INDEX => fn (Blueprint $table) => $table->index(['operation_id', 'status'], self::OPERATION_STATUS_INDEX),
        ] as $name => $definition) {
            if (! $this->hasIndex(self::TABLE, $name)) {
                Schema::table(self::TABLE, $definition);
            }
        }

        foreach (self::FOREIGN_KEYS as $foreignKey) {
            if (! $this->hasForeignKeyOn(self::TABLE, $foreignKey['columns'])) {
                Schema::table(self::TABLE, fn (Blueprint $table) => $this->foreignKey($table, $foreignKey));
            }
        }

        $this->addMysqlChecks();
        $this->makeEachConstructionPlannedOncePerOperation();

        $this->createFirstVersionOfExistingPlans();
    }

    /**
     * Desfaz enquanto cada plano tiver só a V1 que esta migration criou (o
     * Fundo de Obra volta ao plano na reversão da migration que o tirou de
     * lá). Com revisão ou cancelamento gravados, a reversão apagaria histórico
     * de planejamento e é recusada: corrija para frente.
     */
    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if (DB::table(self::TABLE)->where(fn ($query) => $query
            ->where('version_number', '<>', 1)
            ->orWhereNotIn('status', ['active', 'draft']))->exists()) {
            throw new RuntimeException(
                'measurement_plan_versions possui revisões, rascunhos ou versões canceladas; '
                    .'a reversão apagaria o histórico de planejamento e não é admitida. Corrija com uma nova migration.'
            );
        }

        Schema::dropIfExists(self::TABLE);

        if ($this->hasIndex('measurement_plan_sets', self::PLAN_ID_OPERATION_UNIQUE)) {
            Schema::table('measurement_plan_sets', fn (Blueprint $table) => $table->dropUnique(self::PLAN_ID_OPERATION_UNIQUE));
        }

        if ($this->hasIndex('measurement_plan_sets', self::PLAN_CONSTRUCTION_UNIQUE)) {
            Schema::table('measurement_plan_sets', fn (Blueprint $table) => $table->dropUnique(self::PLAN_CONSTRUCTION_UNIQUE));
        }
    }

    /**
     * Um plano por obra na operação: é ele que carrega o avanço inicial e o teto
     * de 100% da obra, e a replanificação é uma versão dele, não outro plano. Sem
     * a unique, "Novo Plano" para uma obra já planejada abriria um segundo
     * inicial e um segundo teto. Plano sem obra (legado) continua livre -- nulo
     * não colide em unique, no MySQL e no SQLite. Duplicata existente é dado a
     * investigar, não algo a escolher por suposição.
     */
    private function makeEachConstructionPlannedOncePerOperation(): void
    {
        if ($this->hasIndex('measurement_plan_sets', self::PLAN_CONSTRUCTION_UNIQUE)) {
            return;
        }

        $duplicates = DB::table('measurement_plan_sets')
            ->whereNotNull('construction_id')
            ->groupBy('operation_id', 'construction_id')
            ->havingRaw('count(*) > 1')
            ->get(['operation_id', 'construction_id']);

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException(sprintf(
                'measurement_plan_sets possui %d obra(s) com mais de um plano na mesma operação (operação:obra %s). Investigue antes de versionar os planos.',
                $duplicates->count(),
                $duplicates->take(20)->map(fn (object $row): string => $row->operation_id.':'.$row->construction_id)->implode(', '),
            ));
        }

        Schema::table('measurement_plan_sets', function (Blueprint $table): void {
            $table->unique(['operation_id', 'construction_id'], self::PLAN_CONSTRUCTION_UNIQUE);
        });
    }

    private function createFirstVersionOfExistingPlans(): void
    {
        DB::table('measurement_plan_sets')
            ->select('id', 'operation_id')
            ->chunkById(self::CHUNK_SIZE, function (Collection $planSets): void {
                foreach ($planSets as $planSet) {
                    DB::transaction(fn () => $this->createFirstVersion((int) $planSet->id, (int) $planSet->operation_id));
                }
            });
    }

    private function createFirstVersion(int $planSetId, int $operationId): void
    {
        // Operation primeiro, como toda escrita de plano: a aplicação atende
        // durante o migrate do deploy.
        DB::table('operations')->where('id', $operationId)->lockForUpdate()->first();

        $planSet = DB::table('measurement_plan_sets')
            ->where('id', $planSetId)
            ->first(['id', 'operation_id', 'created_at', ...(Schema::hasColumn('measurement_plan_sets', 'construction_fund_amount') ? ['construction_fund_amount'] : [])]);

        if ($planSet === null || DB::table(self::TABLE)->where('plan_set_id', $planSetId)->exists()) {
            return;
        }

        // Plano em uso -- com cronograma, arquivo ou pagamento -- já valia: a V1
        // nasce vigente. Plano vazio, ainda sem medição prevista, nasce
        // rascunho, para receber o cronograma sem precisar de revisão.
        $inUse = DB::table('measurement_plan_lines')->where('plan_set_id', $planSetId)->exists()
            || DB::table('measurement_assets')->where('plan_set_id', $planSetId)->exists()
            || DB::table('measurement_payments')->where('plan_set_id', $planSetId)->exists();

        $firstLineDate = DB::table('measurement_plan_lines')
            ->where('plan_set_id', $planSetId)
            ->whereNotNull('measurement_date')
            ->min('measurement_date');
        $creationMonth = $this->businessMonthOf($planSet->created_at);

        // A vigência da V1 é o mês em que o plano passou a valer: o da
        // criação, ou o da primeira medição prevista quando ela é anterior.
        // Nunca depois do mês de criação -- uma revisão vale a partir do mês em
        // que é ativada e precisa vir depois da V1.
        [$effectiveFrom, $rule] = $firstLineDate !== null
            && CarbonImmutable::parse((string) $firstLineDate)->startOfMonth()->toDateString() < $creationMonth
                ? [CarbonImmutable::parse((string) $firstLineDate)->startOfMonth()->toDateString(), 'first_schedule_line_month']
                : [$creationMonth, 'plan_creation_month'];

        $now = now();
        $versionId = DB::table(self::TABLE)->insertGetId([
            'operation_id' => $operationId,
            'plan_set_id' => $planSetId,
            'version_number' => 1,
            'status' => $inUse ? 'active' : 'draft',
            'effective_from' => $inUse ? $effectiveFrom : null,
            'construction_fund_amount' => $planSet->construction_fund_amount ?? null,
            'revision' => 0,
            'activated_at' => $inUse ? ($planSet->created_at ?? $now) : null,
            'created_at' => $planSet->created_at ?? $now,
            'updated_at' => $now,
        ]);

        DB::table('activity_log')->insert([
            'log_name' => 'measurements',
            'description' => 'plan_version_backfilled',
            'event' => null,
            'subject_type' => 'App\Models\MeasurementPlanVersion',
            'subject_id' => $versionId,
            'attribute_changes' => null,
            'properties' => json_encode([
                'migration' => self::MIGRATION,
                'operation_id' => $operationId,
                'plan_set_id' => $planSetId,
                'version_number' => 1,
                'status' => $inUse ? 'active' : 'draft',
                'construction_fund_amount' => ($planSet->construction_fund_amount ?? null) === null ? null : (string) $planSet->construction_fund_amount,
                'effective_from' => $inUse ? $effectiveFrom : null,
                'effective_from_rule' => $inUse ? $rule : null,
            ], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Primeiro dia do mês, no calendário de negócio, do instante gravado em UTC.
     */
    private function businessMonthOf(mixed $createdAt): string
    {
        $instant = $createdAt === null
            ? CarbonImmutable::now()
            : CarbonImmutable::parse((string) $createdAt, config('app.timezone'));

        return BusinessTime::at($instant)->startOfMonth()->toDateString();
    }

    /**
     * No MySQL o banco também recusa situação desconhecida, número de versão
     * zero e versão vigente sem vigência, mesmo numa escrita que não passe pela
     * aplicação. O SQLite dos testes não aceita ADD CONSTRAINT; lá a regra fica
     * no domínio.
     */
    private function addMysqlChecks(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $checks = [
            self::STATUS_CHECK => "status IN ('draft', 'active', 'superseded', 'cancelled')",
            self::VERSION_NUMBER_CHECK => 'version_number >= 1',
            self::ACTIVE_EFFECTIVE_FROM_CHECK => "(status NOT IN ('active', 'superseded')) OR (effective_from IS NOT NULL AND activated_at IS NOT NULL)",
        ];

        foreach ($checks as $name => $expression) {
            if (! $this->hasCheck($name)) {
                DB::statement(sprintf('ALTER TABLE %s ADD CONSTRAINT %s CHECK (%s)', self::TABLE, $name, $expression));
            }
        }
    }

    /**
     * @param  array{columns: list<string>, on: string, references: list<string>, name: string, onDelete: string}  $foreignKey
     */
    private function foreignKey(Blueprint $table, array $foreignKey): void
    {
        $definition = $table->foreign($foreignKey['columns'], $foreignKey['name'])
            ->references($foreignKey['references'])
            ->on($foreignKey['on']);

        match ($foreignKey['onDelete']) {
            'cascade' => $definition->cascadeOnDelete(),
            'set null' => $definition->nullOnDelete(),
            default => $definition->restrictOnDelete(),
        };
    }

    private function hasIndex(string $table, string $name): bool
    {
        return collect(Schema::getIndexes($table))
            ->contains(fn (array $index): bool => $index['name'] === $name);
    }

    /**
     * @param  list<string>  $columns
     */
    private function hasForeignKeyOn(string $table, array $columns): bool
    {
        return collect(Schema::getForeignKeys($table))
            ->contains(fn (array $foreignKey): bool => $foreignKey['columns'] === $columns);
    }

    private function hasCheck(string $name): bool
    {
        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TABLE_NAME', self::TABLE)
            ->where('CONSTRAINT_NAME', $name)
            ->exists();
    }
};
