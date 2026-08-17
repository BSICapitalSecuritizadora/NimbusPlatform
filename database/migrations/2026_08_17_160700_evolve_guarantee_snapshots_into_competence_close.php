<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Promove `guarantee_snapshots` de "valor das quotas do mês" para o fechamento
 * consolidado da competência (§23 do escopo).
 *
 * `quota_value` e `outstanding_balance` continuam existindo e alimentando a
 * leitura legada da cobertura; o que entra é o resultado do motor — bruto,
 * elegível, exigido, cobertura, excedente/déficit e enquadramento.
 *
 * `quota_value` deixa de ser obrigatório: com o valor atual vindo das fontes
 * operacionais, uma emissão sem AF de quotas não tem o que informar ali, e
 * exigir zero criaria um dado falso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guarantee_snapshots', function (Blueprint $table): void {
            $table->decimal('total_gross_value', 18, 2)->nullable()->after('outstanding_balance');
            $table->decimal('total_eligible_value', 18, 2)->nullable()->after('total_gross_value');
            $table->decimal('total_required_value', 18, 2)->nullable()->after('total_eligible_value');

            $table->decimal('coverage_ratio', 18, 6)->nullable()->after('total_required_value');
            $table->decimal('required_ratio', 18, 6)->nullable()->after('coverage_ratio');
            $table->decimal('surplus_deficit', 18, 2)->nullable()->after('required_ratio');

            $table->string('coverage_status')->nullable()->after('surplus_deficit');
            $table->unsignedInteger('active_guarantees_count')->nullable()->after('coverage_status');

            $table->json('pending_sources')->nullable()->after('active_guarantees_count');
            $table->json('metadata')->nullable()->after('pending_sources');

            $table->timestamp('computed_at')->nullable()->after('metadata');
            $table->timestamp('closed_at')->nullable()->after('computed_at');
            $table->foreignId('closed_by')->nullable()->after('closed_at')
                ->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->after('closed_by')
                ->constrained('users')->nullOnDelete();
        });

        Schema::table('guarantee_snapshots', function (Blueprint $table): void {
            $table->decimal('quota_value', 18, 2)->nullable()->change();
            $table->decimal('outstanding_balance', 18, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('guarantee_snapshots', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('closed_by');
            $table->dropConstrainedForeignId('updated_by');

            $table->dropColumn([
                'total_gross_value',
                'total_eligible_value',
                'total_required_value',
                'coverage_ratio',
                'required_ratio',
                'surplus_deficit',
                'coverage_status',
                'active_guarantees_count',
                'pending_sources',
                'metadata',
                'computed_at',
                'closed_at',
            ]);
        });
    }
};
