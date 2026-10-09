<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Diferença de cada revisão de medição, por empreendimento: o que a revisão
 * substituída aprovou, o que a revisão aprova, a diferença física e a
 * financeira e o que a família já tinha pago -- tudo derivado dos snapshots
 * congelados da Engenharia e dos pagamentos, que não mudam.
 *
 * As linhas são evidência: não se alteram (só `superseded_at`, uma vez) nem se
 * excluem. Quando a revisão vigente volta à Engenharia e é reaprovada, o
 * conjunto anterior é marcado como substituído e outro é gravado com o número
 * de cálculo seguinte; um só conjunto corrente por revisão e empreendimento,
 * garantido pela coluna gerada `current_measurement_id` com unique (o padrão
 * das versões do plano). O hash do snapshot é dado, não identidade: o mesmo
 * snapshot reaprovado gera um conjunto novo sem colidir.
 *
 * FKs compostas amarram a revisão, o plano e a versão à mesma operação e ao
 * mesmo plano, como nas tabelas da Fase 2.
 */
return new class extends Migration
{
    private const TABLE = 'measurement_revision_differences';

    private const DIFFERENCE_TYPE_CHECK = 'mrd_difference_type_check';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('measurement_id');
                $table->unsignedBigInteger('previous_measurement_id');
                $table->unsignedBigInteger('revision_family_id');
                $table->unsignedBigInteger('operation_id');
                $table->unsignedBigInteger('plan_set_id');
                $table->unsignedBigInteger('plan_version_id');
                $table->unsignedBigInteger('settled_measurement_id')->nullable();
                $table->unsignedInteger('computation');
                $table->char('engineering_snapshot_sha256', 64);
                $table->decimal('previous_realized_monthly_percent', 5, 2);
                $table->decimal('revised_realized_monthly_percent', 5, 2);
                $table->decimal('physical_difference_percent', 6, 2);
                $table->decimal('previous_fund_amount', 18, 2)->nullable();
                $table->decimal('revised_fund_amount', 18, 2)->nullable();
                $table->decimal('previous_approved_amount', 18, 2)->nullable();
                $table->decimal('revised_approved_amount', 18, 2)->nullable();
                $table->decimal('financial_difference_amount', 18, 2)->nullable();
                $type = $table->string('difference_type', 32);

                if (DB::getDriverName() === 'mysql') {
                    $type->collation('utf8mb4_0900_bin');
                }

                $table->decimal('historical_paid_amount', 20, 2)->default(0);
                $table->decimal('settled_approved_amount', 18, 2)->nullable();
                $table->decimal('unresolved_overpayment_amount', 20, 2)->default(0);
                $table->timestamp('superseded_at')->nullable();
                $table->timestamps();
                $table->unsignedBigInteger('current_measurement_id')->nullable()
                    ->virtualAs('CASE WHEN superseded_at IS NULL THEN measurement_id END');

                $table->unique(['current_measurement_id', 'plan_set_id'], 'mrd_current_unique');
                $table->unique(['measurement_id', 'plan_set_id', 'computation'], 'mrd_computation_unique');
                $table->index('revision_family_id', 'mrd_family_index');

                $table->foreign('measurement_id', 'mrd_measurement_foreign')->references('id')->on('measurements')->restrictOnDelete();
                $table->foreign('previous_measurement_id', 'mrd_previous_foreign')->references('id')->on('measurements')->restrictOnDelete();
                $table->foreign('revision_family_id', 'mrd_family_foreign')->references('id')->on('measurements')->restrictOnDelete();
                $table->foreign('settled_measurement_id', 'mrd_settled_foreign')->references('id')->on('measurements')->restrictOnDelete();
                $table->foreign(['measurement_id', 'operation_id'], 'mrd_measurement_operation_foreign')
                    ->references(['id', 'operation_id'])->on('measurements')->restrictOnDelete();
                $table->foreign(['plan_set_id', 'operation_id'], 'mrd_plan_set_operation_foreign')
                    ->references(['id', 'operation_id'])->on('measurement_plan_sets')->restrictOnDelete();
                $table->foreign(['plan_version_id', 'plan_set_id'], 'mrd_version_plan_set_foreign')
                    ->references(['id', 'plan_set_id'])->on('measurement_plan_versions')->restrictOnDelete();
            });
        }

        if (DB::getDriverName() === 'mysql' && ! $this->hasCheck(self::DIFFERENCE_TYPE_CHECK)) {
            DB::statement(sprintf(
                "ALTER TABLE %s ADD CONSTRAINT %s CHECK (difference_type IN ('no_difference', 'positive_difference', 'negative_difference', 'reference_unavailable'))",
                self::TABLE,
                self::DIFFERENCE_TYPE_CHECK,
            ));
        }
    }

    /**
     * A diferença registrada é evidência da revisão: com linhas, a reversão
     * apagaria o histórico financeiro.
     */
    public function down(): void
    {
        if (Schema::hasTable(self::TABLE) && DB::table(self::TABLE)->exists()) {
            throw new RuntimeException('Existem diferenças de revisão registradas: a tabela não pode ser removida sem perder o histórico financeiro das revisões.');
        }

        Schema::dropIfExists(self::TABLE);
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
