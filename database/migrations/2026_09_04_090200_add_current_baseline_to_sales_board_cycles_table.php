<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O ponteiro do ciclo para a sua versão vigente.
     *
     * Migration própria porque a dependência é circular: o ciclo aponta para uma
     * das suas baselines, e toda baseline pertence a um ciclo. Criar a coluna
     * junto com a tabela de ciclos produziria uma FK para uma tabela que ainda
     * não existe; adicioná-la aqui resolve a ordem sem deixar a coluna sem
     * garantia em nenhum momento.
     *
     * O ponteiro é explícito em vez de `MAX(version)` descoberto por consumidor.
     * Um `MAX()` espalhado por telas e serviços é uma corrida esperando
     * acontecer -- a versão nova aparece para metade dos leitores antes de a
     * transação que a criou terminar de decidir se ela é mesmo a vigente -- e
     * obriga toda leitura de resumo a passar por uma agregação.
     *
     * `restrictOnDelete` acompanha o resto da árvore: nenhuma versão é
     * apagável, e a versão vigente menos ainda.
     */
    public function up(): void
    {
        Schema::table('sales_board_cycles', function (Blueprint $table) {
            $table->foreignId('current_baseline_id')
                ->nullable()
                ->after('status')
                ->constrained('sales_board_cycle_baselines')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales_board_cycles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_baseline_id');
        });
    }
};
