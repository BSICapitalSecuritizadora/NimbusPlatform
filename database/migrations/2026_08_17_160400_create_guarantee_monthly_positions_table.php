<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Posição de cada garantia numa competência (§23 do escopo).
 *
 * É o snapshot que torna o histórico reproduzível: o relatório de julho precisa
 * mostrar o mesmo número daqui a dois anos, mesmo que a carteira de recebíveis,
 * o quadro de vendas ou a curva de PU sejam corrigidos depois. Por isso os
 * valores são gravados, não recalculados na leitura, e `metadata` guarda a
 * memória de cálculo (base usada, avaliação vigente, conta consultada).
 *
 * `current_value` nulo com `value_status = pending` é semanticamente diferente
 * de `current_value = 0`: o primeiro é dado que falta, o segundo é garantia que
 * de fato não vale nada na competência.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guarantee_monthly_positions', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('guarantee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('emission_id')->constrained()->cascadeOnDelete();
            $table->date('reference_month');

            $table->decimal('current_value', 18, 2)->nullable();
            $table->decimal('eligible_value', 18, 2)->nullable();
            $table->decimal('required_value', 18, 2)->nullable();
            $table->decimal('eligibility_factor', 12, 6)->nullable();

            $table->decimal('coverage_ratio', 18, 6)->nullable();
            $table->decimal('surplus_deficit', 18, 2)->nullable();

            $table->string('value_source')->nullable();
            $table->string('value_status')->default('pending');
            $table->string('coverage_status')->nullable();
            $table->string('legal_status')->nullable();

            $table->decimal('outstanding_balance', 18, 2)->nullable();
            $table->json('metadata')->nullable();

            $table->timestamp('computed_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['guarantee_id', 'reference_month'], 'guarantee_positions_guarantee_month_unique');
            $table->index(['emission_id', 'reference_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guarantee_monthly_positions');
    }
};
