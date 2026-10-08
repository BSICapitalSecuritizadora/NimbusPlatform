<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * O arquivo de medição guarda para sempre a versão do plano em que nasceu, e
 * as referências históricas a plano deixam de virar nulo.
 *
 * - `measurement_assets.plan_version_id`: a versão vigente quando a medição foi
 *   enviada. Uma versão nova, ativada depois, não a alcança. Duas FKs compostas
 *   amarram o arquivo à mesma versão da linha e ao mesmo plano da versão.
 * - `measurement_assets.line_claim_key`: a linhagem da linha enquanto a medição
 *   a ocupa (vazia depois da recusa terminal). A unique garante no banco que uma
 *   medição prevista -- em qualquer versão do plano -- tem no máximo uma
 *   medição de pé; as recusadas continuam gravadas, só soltam a linha.
 * - Plano, linha e versão referenciados por arquivo, e o plano referenciado por
 *   pagamento, passam de SET NULL para RESTRICT: excluir o plano (ou a versão
 *   e suas linhas) com histórico de medição é recusado pelo próprio banco, e
 *   nenhum vínculo histórico vira nulo por fora do domínio.
 * - `measurements.plan_set_id` sai: nunca foi gravada nem lida -- a medição
 *   cobre um plano por empreendimento, pelos arquivos.
 *
 * Os dados existentes são só de desenvolvimento e homologação. A ocupação de
 * linha é recalculada pelo mesmo predicado de `scopeAvailableForMeasurement`;
 * se duas medições de pé ocupam a mesma linha (a corrida das duas abas), fica
 * com a ocupação a de Engenharia vigente, depois a paga, depois a mais antiga --
 * a outra continua gravada e será recusada pela Engenharia. Idempotente.
 */
return new class extends Migration
{
    private const CHUNK_SIZE = 500;

    private const CLAIM_UNIQUE = 'ma_line_claim_unique';

    /**
     * @var list<string>
     */
    private const HOLDING_STATUSES = ['pending', 'in_review', 'paused', 'approved', 'awaiting_payment', 'awaiting_receipt', 'finalized'];

    public function up(): void
    {
        $this->refuseFilesWhoseLineBelongsToAnotherPlan();
        $this->refuseFilesOfAnotherOperation();

        if (! Schema::hasColumn('measurement_assets', 'plan_version_id')) {
            Schema::table('measurement_assets', function (Blueprint $table): void {
                $table->unsignedBigInteger('plan_version_id')->nullable()->after('plan_set_id');
            });
        }

        if (! Schema::hasColumn('measurement_assets', 'line_claim_key')) {
            Schema::table('measurement_assets', function (Blueprint $table): void {
                $table->char('line_claim_key', 26)->nullable()->after('plan_line_id');
            });
        }

        $this->captureTheVersionOfEachFile();
        $this->recordTheCurrentLineClaims();

        if (! $this->hasIndex('measurement_assets', self::CLAIM_UNIQUE)) {
            Schema::table('measurement_assets', function (Blueprint $table): void {
                $table->unique('line_claim_key', self::CLAIM_UNIQUE);
            });
        }

        $this->restrictAssetReferences();
        $this->restrictPaymentPlanReference();
        $this->dropUnusedMeasurementPlanColumn();
        $this->assertSqliteForeignKeysHold();
    }

    public function down(): void
    {
        if (! Schema::hasColumn('measurements', 'plan_set_id')) {
            Schema::table('measurements', function (Blueprint $table): void {
                $table->foreignId('plan_set_id')
                    ->nullable()
                    ->after('operation_id')
                    ->constrained('measurement_plan_sets')
                    ->nullOnDelete();
            });
        }

        $paymentForeign = $this->foreignKeyOnDelete('measurement_payments', ['plan_set_id']);

        if ($paymentForeign !== 'set null') {
            Schema::table('measurement_payments', function (Blueprint $table) use ($paymentForeign): void {
                if ($paymentForeign !== null) {
                    $table->dropForeign(['plan_set_id']);
                }

                $table->foreign('plan_set_id')->references('id')->on('measurement_plan_sets')->nullOnDelete();
            });
        }

        // O SQLite não guarda nome de FK (só se derruba pelas colunas); o MySQL
        // precisa do nome com que ela foi criada.
        Schema::table('measurement_assets', function (Blueprint $table): void {
            foreach ([
                'ma_plan_set_foreign' => ['plan_set_id'],
                'ma_plan_line_foreign' => ['plan_line_id'],
                'ma_plan_version_foreign' => ['plan_version_id'],
                'ma_version_plan_set_foreign' => ['plan_version_id', 'plan_set_id'],
                'ma_line_version_foreign' => ['plan_line_id', 'plan_version_id'],
            ] as $name => $columns) {
                if ($this->foreignKeyOnDelete('measurement_assets', $columns) !== null) {
                    $table->dropForeign(DB::getDriverName() === 'sqlite' ? $columns : $name);
                }
            }
        });

        $restoreSetNull = collect([
            'plan_set_id' => 'measurement_plan_sets',
            'plan_line_id' => 'measurement_plan_lines',
        ])->filter(fn (string $parent, string $column): bool => $this->foreignKeyOnDelete('measurement_assets', [$column]) === null);

        if ($restoreSetNull->isNotEmpty()) {
            Schema::table('measurement_assets', function (Blueprint $table) use ($restoreSetNull): void {
                foreach ($restoreSetNull as $column => $parent) {
                    $table->foreign($column)->references('id')->on($parent)->nullOnDelete();
                }
            });
        }

        if ($this->hasIndex('measurement_assets', self::CLAIM_UNIQUE)) {
            Schema::table('measurement_assets', fn (Blueprint $table) => $table->dropUnique(self::CLAIM_UNIQUE));
        }

        Schema::table('measurement_assets', function (Blueprint $table): void {
            $table->dropColumn(['line_claim_key', 'plan_version_id']);
        });
    }

    /**
     * Arquivo cuja linha é de outro plano não tem versão certa a herdar: a
     * Engenharia o recusaria, e as FKs compostas também. A aplicação não grava
     * esse estado; cada caso é um dado a investigar antes desta migration, e
     * não algo a corrigir por suposição.
     */
    private function refuseFilesWhoseLineBelongsToAnotherPlan(): void
    {
        $inconsistent = DB::table('measurement_assets')
            ->join('measurement_plan_lines', 'measurement_plan_lines.id', '=', 'measurement_assets.plan_line_id')
            ->whereColumn('measurement_plan_lines.plan_set_id', '<>', 'measurement_assets.plan_set_id')
            ->orderBy('measurement_assets.id')
            ->pluck('measurement_assets.id');

        if ($inconsistent->isNotEmpty()) {
            throw new RuntimeException(sprintf(
                'measurement_assets possui %d arquivo(s) cuja linha do cronograma é de outro plano (ids: %s). Investigue antes de vincular os arquivos às versões do plano.',
                $inconsistent->count(),
                $inconsistent->take(20)->implode(', '),
            ));
        }
    }

    /**
     * O plano do arquivo precisa ser da operação da medição. A aplicação não
     * grava o contrário; cada caso é um dado a investigar.
     */
    private function refuseFilesOfAnotherOperation(): void
    {
        $inconsistent = DB::table('measurement_assets')
            ->join('measurements', 'measurements.id', '=', 'measurement_assets.measurement_id')
            ->join('measurement_plan_sets', 'measurement_plan_sets.id', '=', 'measurement_assets.plan_set_id')
            ->whereColumn('measurement_plan_sets.operation_id', '<>', 'measurements.operation_id')
            ->orderBy('measurement_assets.id')
            ->pluck('measurement_assets.id');

        if ($inconsistent->isNotEmpty()) {
            throw new RuntimeException(sprintf(
                'measurement_assets possui %d arquivo(s) cujo plano é de outra operação (ids: %s). Investigue antes de vincular os arquivos às versões do plano.',
                $inconsistent->count(),
                $inconsistent->take(20)->implode(', '),
            ));
        }
    }

    /**
     * No SQLite a reconstrução das tabelas roda com as FKs desligadas; aqui se
     * confere que nenhuma referência ficou quebrada.
     */
    private function assertSqliteForeignKeysHold(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        $violations = DB::select('PRAGMA foreign_key_check');

        if ($violations !== []) {
            throw new RuntimeException('A reconstrução dos arquivos e pagamentos de medição deixou referências quebradas: '.json_encode($violations, JSON_THROW_ON_ERROR));
        }
    }

    private function captureTheVersionOfEachFile(): void
    {
        DB::table('measurement_assets')
            ->whereNull('plan_version_id')
            ->whereNotNull('plan_set_id')
            ->select('id', 'plan_set_id', 'plan_line_id')
            ->chunkById(self::CHUNK_SIZE, function (Collection $assets): void {
                $lineVersions = DB::table('measurement_plan_lines')
                    ->whereIn('id', $assets->pluck('plan_line_id')->filter()->unique()->all())
                    ->pluck('plan_version_id', 'id');
                $firstVersions = DB::table('measurement_plan_versions')
                    ->whereIn('plan_set_id', $assets->pluck('plan_set_id')->unique()->all())
                    ->where('version_number', 1)
                    ->pluck('id', 'plan_set_id');

                foreach ($assets as $asset) {
                    $versionId = $asset->plan_line_id !== null
                        ? $lineVersions->get($asset->plan_line_id)
                        : $firstVersions->get($asset->plan_set_id);

                    if ($versionId !== null) {
                        DB::table('measurement_assets')->where('id', $asset->id)->update(['plan_version_id' => $versionId]);
                    }
                }
            });
    }

    /**
     * Quem ocupa cada linha hoje, pelo predicado de disponibilidade: medição
     * aberta ou finalizada, com Engenharia vigente ou com pagamento.
     */
    private function recordTheCurrentLineClaims(): void
    {
        $taken = DB::table('measurement_assets')->whereNotNull('line_claim_key')->pluck('line_claim_key')->flip();

        $candidates = DB::table('measurement_assets')
            ->join('measurements', 'measurements.id', '=', 'measurement_assets.measurement_id')
            ->join('measurement_plan_lines', 'measurement_plan_lines.id', '=', 'measurement_assets.plan_line_id')
            ->whereNull('measurement_assets.line_claim_key')
            ->select([
                'measurement_assets.id',
                'measurement_assets.measurement_id',
                'measurement_plan_lines.lineage_key',
                'measurements.status',
            ])
            ->selectRaw('exists (select 1 from measurement_reviews where measurement_reviews.measurement_id = measurements.id and measurement_reviews.stage = 1 and measurement_reviews.status = ?) as has_current_engineering', ['approved'])
            ->selectRaw('exists (select 1 from measurement_payments where measurement_payments.measurement_id = measurements.id) as has_payments')
            ->get()
            ->filter(fn (object $asset): bool => in_array($asset->status, self::HOLDING_STATUSES, true)
                || (bool) $asset->has_current_engineering
                || (bool) $asset->has_payments)
            ->sortBy([
                fn (object $left, object $right): int => (int) $right->has_current_engineering <=> (int) $left->has_current_engineering,
                fn (object $left, object $right): int => (int) $right->has_payments <=> (int) $left->has_payments,
                fn (object $left, object $right): int => [(int) $left->measurement_id, (int) $left->id] <=> [(int) $right->measurement_id, (int) $right->id],
            ]);

        foreach ($candidates as $asset) {
            if ($taken->has($asset->lineage_key)) {
                continue;
            }

            DB::table('measurement_assets')->where('id', $asset->id)->update(['line_claim_key' => $asset->lineage_key]);
            $taken->put($asset->lineage_key, true);
        }
    }

    /**
     * Arquivo de medição não perde o plano, a linha nem a versão: RESTRICT nas
     * três, mais as compostas que amarram arquivo, linha, versão e plano. Uma
     * alteração de tabela só, para o SQLite reconstruí-la uma vez.
     */
    private function restrictAssetReferences(): void
    {
        $setNull = collect([['plan_set_id'], ['plan_line_id']])
            ->filter(fn (array $columns): bool => $this->foreignKeyOnDelete('measurement_assets', $columns) === 'set null');

        Schema::table('measurement_assets', function (Blueprint $table) use ($setNull): void {
            foreach ($setNull as $columns) {
                $table->dropForeign($columns);
            }

            if ($setNull->contains(['plan_set_id']) || $this->foreignKeyOnDelete('measurement_assets', ['plan_set_id']) === null) {
                $table->foreign('plan_set_id', 'ma_plan_set_foreign')->references('id')->on('measurement_plan_sets')->restrictOnDelete();
            }

            if ($setNull->contains(['plan_line_id']) || $this->foreignKeyOnDelete('measurement_assets', ['plan_line_id']) === null) {
                $table->foreign('plan_line_id', 'ma_plan_line_foreign')->references('id')->on('measurement_plan_lines')->restrictOnDelete();
            }

            if ($this->foreignKeyOnDelete('measurement_assets', ['plan_version_id']) === null) {
                $table->foreign('plan_version_id', 'ma_plan_version_foreign')->references('id')->on('measurement_plan_versions')->restrictOnDelete();
            }

            if ($this->foreignKeyOnDelete('measurement_assets', ['plan_version_id', 'plan_set_id']) === null) {
                $table->foreign(['plan_version_id', 'plan_set_id'], 'ma_version_plan_set_foreign')
                    ->references(['id', 'plan_set_id'])
                    ->on('measurement_plan_versions')
                    ->restrictOnDelete();
            }

            if ($this->foreignKeyOnDelete('measurement_assets', ['plan_line_id', 'plan_version_id']) === null) {
                $table->foreign(['plan_line_id', 'plan_version_id'], 'ma_line_version_foreign')
                    ->references(['id', 'plan_version_id'])
                    ->on('measurement_plan_lines')
                    ->restrictOnDelete();
            }
        });
    }

    /**
     * No MySQL a troca são duas instruções, cada uma confirmada por si:
     * interrompida entre elas, a coluna fica sem FK, e a nova execução a recria
     * em vez de seguir sem nenhuma.
     */
    private function restrictPaymentPlanReference(): void
    {
        $current = $this->foreignKeyOnDelete('measurement_payments', ['plan_set_id']);

        if ($current === 'restrict') {
            return;
        }

        Schema::table('measurement_payments', function (Blueprint $table) use ($current): void {
            if ($current !== null) {
                $table->dropForeign(['plan_set_id']);
            }

            $table->foreign('plan_set_id')->references('id')->on('measurement_plan_sets')->restrictOnDelete();
        });
    }

    private function dropUnusedMeasurementPlanColumn(): void
    {
        if (! Schema::hasColumn('measurements', 'plan_set_id')) {
            return;
        }

        Schema::table('measurements', function (Blueprint $table): void {
            if ($this->foreignKeyOnDelete('measurements', ['plan_set_id']) !== null) {
                $table->dropForeign(['plan_set_id']);
            }

            $table->dropColumn('plan_set_id');
        });
    }

    /**
     * Ação de exclusão da FK sobre as colunas, normalizada ('set null',
     * 'restrict', 'cascade'...), ou `null` sem FK. O SQLite devolve "no action"
     * para o RESTRICT implícito; aqui toda FK sem ação explícita conta como
     * restrita.
     *
     * @param  list<string>  $columns
     */
    private function foreignKeyOnDelete(string $table, array $columns): ?string
    {
        $foreignKey = collect(Schema::getForeignKeys($table))
            ->first(fn (array $foreignKey): bool => $foreignKey['columns'] === $columns);

        if ($foreignKey === null) {
            return null;
        }

        $action = strtolower((string) ($foreignKey['on_delete'] ?? ''));

        return in_array($action, ['', 'no action'], true) ? 'restrict' : $action;
    }

    private function hasIndex(string $table, string $name): bool
    {
        return collect(Schema::getIndexes($table))
            ->contains(fn (array $index): bool => $index['name'] === $name);
    }
};
