<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A unidade de decisão da Gestão.
     *
     * Não confundir com `sales_board_builder_divergences`: aquela é o que a
     * construtora **declarou**, esta é o que a Gestão precisa **decidir**. Uma
     * declaração vira não conformidade ao ser recebida para análise, e é por
     * isso que existem as duas tabelas -- fundi-las faria a declaração da
     * construtora e a conclusão interna sobre ela virarem a mesma linha, e não
     * haveria mais como responder "o que ela disse?" separado de "o que
     * decidimos?".
     *
     * Nenhum valor congelado é copiado para cá. O fato já está do outro lado da
     * FK -- na divergência ou no movimento -- e duplicá-lo criaria duas versões
     * do mesmo número para divergirem com o tempo. O que é desta tabela é
     * exclusivamente a decisão.
     *
     * As duas uniques são por origem, e as colunas de âncora são anuláveis
     * justamente para isso: uma pendência declarada pela construtora aponta uma
     * divergência e nenhum movimento; uma venda fora da política aponta um
     * movimento e nenhuma divergência. Índice único com nulos admite vários
     * nulos nos dois bancos, então cada unique restringe só as linhas da sua
     * origem. Que a âncora certa esteja presente -- e só ela -- é garantido pelo
     * model, que recusa a gravação, e não por um CHECK que os dois bancos
     * escreveriam de formas diferentes.
     *
     * Não é apagável. Uma decisão da Gestão que some sob demanda não é decisão.
     */
    public function up(): void
    {
        Schema::create('sales_board_management_nonconformities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_board_management_review_id')
                ->constrained(
                    table: 'sales_board_management_reviews',
                    indexName: 'management_nonconformities_review_foreign',
                )
                ->restrictOnDelete();

            $table->string('origin', 30);

            $table->foreignId('sales_board_builder_divergence_id')->nullable()
                ->constrained(
                    table: 'sales_board_builder_divergences',
                    indexName: 'management_nonconformities_divergence_foreign',
                )
                ->restrictOnDelete();
            $table->foreignId('sales_board_cycle_movement_id')->nullable()
                ->constrained(
                    table: 'sales_board_cycle_movements',
                    indexName: 'management_nonconformities_movement_foreign',
                )
                ->restrictOnDelete();

            $table->string('decision', 30);
            $table->text('decision_reason')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by_user_id')->nullable()
                ->constrained('users', indexName: 'management_nonconformities_decided_by_foreign')
                ->nullOnDelete();

            $table->timestamps();

            /**
             * Uma declaração da construtora produz exatamente uma pendência, e
             * uma venda fora da política também. Reabrir a tela de análise não
             * pode multiplicar itens, e é aqui que isso deixa de depender de o
             * código estar certo.
             */
            $table->unique(
                ['sales_board_management_review_id', 'sales_board_builder_divergence_id'],
                'management_nonconformities_divergence_unique',
            );
            $table->unique(
                ['sales_board_management_review_id', 'sales_board_cycle_movement_id'],
                'management_nonconformities_movement_unique',
            );

            /**
             * O portão de publicação pergunta uma coisa só -- "sobrou alguma
             * pendente ou alguma exigindo correção?" -- e é essa a leitura que
             * precisa de índice próprio.
             */
            $table->index(
                ['sales_board_management_review_id', 'decision'],
                'management_nonconformities_review_decision_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_board_management_nonconformities');
    }
};
