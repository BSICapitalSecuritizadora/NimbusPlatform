<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Evolui `guarantees` de cadastro manual para garantia juridicamente rastreável.
 *
 * As colunas originais são preservadas: `guarantee_type` continua sendo o
 * rótulo livre digitado historicamente e `minimum_value` continua guardando o
 * mínimo absoluto. O que muda é que passam a conviver com um tipo tipado, uma
 * regra contratual computável e uma situação jurídica.
 *
 * As colunas que eram NOT NULL viram opcionais porque o módulo passa a importar
 * garantias de documentos, e um instrumento pode simplesmente não declarar
 * prazo de validade ou periodicidade — inventar a data seria pior do que não tê-la.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guarantees', function (Blueprint $table): void {
            $table->string('type')->nullable()->after('emission_id');
            $table->string('name')->nullable()->after('type');
            $table->string('legal_status')->default('pending_confirmation')->after('name');

            $table->foreignId('construction_id')->nullable()->after('legal_status')
                ->constrained()->nullOnDelete();
            $table->foreignId('fund_id')->nullable()->after('construction_id')
                ->constrained()->nullOnDelete();

            $table->json('identification')->nullable()->after('fund_id');

            $table->decimal('contracted_value', 18, 2)->nullable()->after('identification');
            $table->decimal('documentary_value', 18, 2)->nullable()->after('contracted_value');

            $table->string('requirement_basis')->default('none')->after('documentary_value');
            $table->decimal('requirement_value', 18, 2)->nullable()->after('requirement_basis');
            $table->decimal('requirement_percentage', 12, 6)->nullable()->after('requirement_value');
            $table->string('requirement_base')->nullable()->after('requirement_percentage');
            $table->decimal('requirement_multiplier', 12, 4)->nullable()->after('requirement_base');
            $table->text('requirement_formula')->nullable()->after('requirement_multiplier');
            $table->text('requirement_conditions')->nullable()->after('requirement_formula');

            $table->decimal('eligibility_factor', 12, 6)->nullable()->after('requirement_conditions');
            $table->string('value_source')->nullable()->after('eligibility_factor');
            $table->boolean('counts_toward_coverage')->default(true)->after('value_source');

            $table->date('constituted_at')->nullable()->after('counts_toward_coverage');
            $table->date('registered_at')->nullable()->after('constituted_at');
            $table->date('released_at')->nullable()->after('registered_at');

            $table->text('notes')->nullable()->after('description');

            $table->index(['emission_id', 'legal_status']);
            $table->index(['emission_id', 'type']);
        });

        $this->relaxLegacyRequiredColumns();
        $this->backfillExistingGuarantees();
    }

    public function down(): void
    {
        Schema::table('guarantees', function (Blueprint $table): void {
            $table->dropIndex(['emission_id', 'legal_status']);
            $table->dropIndex(['emission_id', 'type']);

            $table->dropConstrainedForeignId('construction_id');
            $table->dropConstrainedForeignId('fund_id');

            $table->dropColumn([
                'type',
                'name',
                'legal_status',
                'identification',
                'contracted_value',
                'documentary_value',
                'requirement_basis',
                'requirement_value',
                'requirement_percentage',
                'requirement_base',
                'requirement_multiplier',
                'requirement_formula',
                'requirement_conditions',
                'eligibility_factor',
                'value_source',
                'counts_toward_coverage',
                'constituted_at',
                'registered_at',
                'released_at',
                'notes',
            ]);
        });
    }

    /**
     * Torna opcionais as colunas que o cadastro manual exigia. Não há perda de
     * dado: registros existentes continuam com os mesmos valores.
     */
    private function relaxLegacyRequiredColumns(): void
    {
        Schema::table('guarantees', function (Blueprint $table): void {
            $table->string('guarantee_type')->nullable()->change();
            $table->decimal('minimum_value', 18, 2)->nullable()->change();
            $table->date('validity_start_date')->nullable()->change();
            $table->date('validity_end_date')->nullable()->change();
            $table->string('evaluation_frequency')->nullable()->change();
        });
    }

    /**
     * Converte o cadastro antigo para a nova estrutura sem inventar dados.
     *
     * Só o mínimo absoluto é migrável com certeza: uma garantia antiga tinha
     * `minimum_value` obrigatório, então é seguro lê-la como regra de valor
     * absoluto. O tipo permanece nulo — mapear o texto livre "Alienação
     * fiduciária" para imóvel ou quotas seria adivinhação, e o escopo (§41)
     * pede explicitamente que o indeterminável fique pendente de classificação.
     */
    private function backfillExistingGuarantees(): void
    {
        DB::table('guarantees')
            ->whereNotNull('minimum_value')
            ->update([
                'requirement_basis' => 'absolute',
                'requirement_value' => DB::raw('minimum_value'),
            ]);

        DB::table('guarantees')
            ->whereNull('name')
            ->update(['name' => DB::raw('guarantee_type')]);

        DB::table('guarantees')
            ->whereNull('constituted_at')
            ->update(['constituted_at' => DB::raw('validity_start_date')]);
    }
};
