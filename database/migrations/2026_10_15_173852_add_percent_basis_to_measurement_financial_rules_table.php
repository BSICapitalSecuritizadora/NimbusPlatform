<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A base do limite percentual da regra financeira, explícita em cada versão.
 *
 * Até aqui o percentual era sobre o saldo a pagar antes do pagamento -- uma
 * base que muda a cada pagamento parcial e fica negativa depois de pago a
 * maior. A partir desta coluna ele é do valor aprovado do empreendimento na
 * competência ('approved_amount'), gravado pela regra cadastrada a partir de
 * agora. A versão anterior fica com a coluna nula e o limite percentual dela
 * não autoriza divergência: reinterpretá-la em silêncio a tornaria mais
 * permissiva (o valor aprovado é maior que o saldo). Quem precisa do limite
 * percentual registra uma nova versão da regra. Nenhuma linha é reescrita.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('measurement_financial_rules', function (Blueprint $table) {
            $table->string('percent_basis', 20)->nullable()->after('maximum_difference_percent');
        });
    }

    public function down(): void
    {
        Schema::table('measurement_financial_rules', function (Blueprint $table) {
            $table->dropColumn('percent_basis');
        });
    }
};
