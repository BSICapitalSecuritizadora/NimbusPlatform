<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A resposta da construtora que sustenta a validação registrada aqui dentro.
     *
     * Enquanto a construtora não tem canal próprio, quem envia a validação é um
     * operador interno. O que dá a esse registro o peso de "validação da
     * construtora" é a prova de que ela respondeu: quem respondeu por ela, por
     * qual canal e em que dia. Os arquivos da resposta ficam na tabela de
     * anexos, um por linha.
     *
     * Larguras de nome e e-mail iguais às de `users`, como as de
     * `reviewer_name`/`reviewer_email`: um snapshot não escolhe o tamanho do que
     * recebe, e o MySQL estrito recusaria o envio inteiro por causa de um nome
     * longo que o SQLite dos testes aceitaria.
     *
     * As validações enviadas antes desta regra ficam com as quatro colunas nulas,
     * e é assim que a tela as reconhece: "registrada antes da exigência de
     * evidência". Não há backfill -- inventar uma resposta para elas seria
     * afirmar um fato que ninguém registrou.
     *
     * Aditiva, nula e sem índice: no MySQL 8.4 cada ADD COLUMN, inclusive com
     * AFTER, é INSTANT, sem reconstruir a tabela. A guarda por coluna deixa a
     * migration reexecutável depois de uma falha no meio -- o MySQL não desfaz
     * DDL.
     */
    public function up(): void
    {
        $columns = [
            'builder_respondent_name' => fn (Blueprint $table) => $table->string('builder_respondent_name')->nullable()->after('reviewer_email'),
            'builder_respondent_email' => fn (Blueprint $table) => $table->string('builder_respondent_email')->nullable()->after('builder_respondent_name'),
            'builder_response_channel' => fn (Blueprint $table) => $table->string('builder_response_channel', 30)->nullable()->after('builder_respondent_email'),
            'builder_response_received_on' => fn (Blueprint $table) => $table->date('builder_response_received_on')->nullable()->after('builder_response_channel'),
        ];

        foreach ($columns as $column => $define) {
            if (Schema::hasColumn('sales_board_builder_reviews', $column)) {
                continue;
            }

            Schema::table('sales_board_builder_reviews', function (Blueprint $table) use ($define): void {
                $define($table);
            });
        }
    }

    public function down(): void
    {
        $existing = array_values(array_filter(
            ['builder_respondent_name', 'builder_respondent_email', 'builder_response_channel', 'builder_response_received_on'],
            fn (string $column): bool => Schema::hasColumn('sales_board_builder_reviews', $column),
        ));

        if ($existing === []) {
            return;
        }

        Schema::table('sales_board_builder_reviews', function (Blueprint $table) use ($existing): void {
            $table->dropColumn($existing);
        });
    }
};
