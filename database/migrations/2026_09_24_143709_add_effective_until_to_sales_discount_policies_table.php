<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fim explícito da vigência da política de desconto.
     *
     * `effective_until` é inclusivo: a política vale para vendas feitas de
     * `effective_from` até `effective_until`, os dois dias incluídos. É o fim que
     * a aprovação comercial declarou, gravado junto com ela; não é reescrito
     * depois, então o histórico continua append-only.
     *
     * Anulável só por causa das linhas registradas antes desta coluna. Elas não
     * tinham fim: valiam até a próxima política começar, e a última valia sem
     * prazo. Não há backfill -- deduzir o fim da próxima política produziria
     * `fim < início` nas correções registradas para a mesma data, e a última
     * linha de cada obra não tem data de fim nenhuma para ser descoberta. Com o
     * fim nulo, o resolvedor segue aplicando a regra antiga a essas linhas e as
     * vendas passadas mantêm exatamente a mesma política.
     *
     * Registros novos sempre informam o fim; a obrigatoriedade e `fim >= início`
     * são validadas na aplicação, pelo mesmo motivo do intervalo do percentual:
     * um CHECK se comportaria diferente no SQLite dos testes e no MySQL.
     *
     * Sem índice novo: a consulta continua escolhendo a política pelo índice
     * `(construction_id, effective_from)` e só então confere o fim.
     */
    public function up(): void
    {
        Schema::table('sales_discount_policies', function (Blueprint $table) {
            $table->date('effective_until')->nullable()->after('effective_from');
        });
    }

    public function down(): void
    {
        Schema::table('sales_discount_policies', function (Blueprint $table) {
            $table->dropColumn('effective_until');
        });
    }
};
