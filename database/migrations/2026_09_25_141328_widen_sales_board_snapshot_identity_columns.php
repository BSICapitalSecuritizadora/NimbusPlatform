<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Alinha as colunas congeladas à largura da fonte que elas copiam.
     *
     * Um snapshot não escolhe o que recebe: bloco e unidade vêm de
     * `construction_units`, que aceita 255 caracteres pelo formulário e sem
     * limite pela planilha; nome e e-mail do revisor vêm de `users`, também com
     * 255. Com 50 (e 160) no destino, o MySQL em modo estrito recusava o insert
     * em lote e a competência inteira do empreendimento deixava de ser gerada,
     * enquanto o SQLite dos testes, que ignora a largura do varchar, gravava
     * tudo. O snapshot fica exatamente tão largo quanto a fonte -- truncar
     * congelaria uma identificação diferente da que a construtora conferiu.
     *
     * O desconto efetivo vira BIGINT pelo mesmo motivo: ele é calculado em
     * BCMath sem teto (ágio contra um valor de referência irrisório passa de
     * ±2,1 bilhões de basis points) e só é exibido, então o banco não pode ser
     * o lugar onde a geração morre por causa dele. O desconto autorizado
     * continua INT: vem de um percentual `decimal(5,2)`, que não passa de
     * 99.999 basis points.
     *
     * Cada coluna repete todos os atributos que já tinha (hoje só `nullable`),
     * porque `change()` redefine a coluna inteira.
     */
    public function up(): void
    {
        Schema::table('sales_board_cycle_lines', function (Blueprint $table) {
            $table->string('block')->nullable()->change();
            $table->string('unit')->nullable()->change();
        });

        Schema::table('sales_board_cycle_movements', function (Blueprint $table) {
            $table->string('block')->nullable()->change();
            $table->string('unit')->nullable()->change();
            $table->bigInteger('effective_discount_basis_points')->nullable()->change();
        });

        Schema::table('sales_board_builder_reviews', function (Blueprint $table) {
            $table->string('reviewer_name')->nullable()->change();
            $table->string('reviewer_email')->nullable()->change();
        });
    }

    /**
     * Volta às larguras antigas. Com algum valor gravado acima delas o MySQL
     * recusa a reversão em vez de truncar, e é isso que deve acontecer: desfazer
     * a migration não pode reescrever um snapshot.
     */
    public function down(): void
    {
        Schema::table('sales_board_builder_reviews', function (Blueprint $table) {
            $table->string('reviewer_name', 160)->nullable()->change();
            $table->string('reviewer_email', 160)->nullable()->change();
        });

        Schema::table('sales_board_cycle_movements', function (Blueprint $table) {
            $table->string('block', 50)->nullable()->change();
            $table->string('unit', 50)->nullable()->change();
            $table->integer('effective_discount_basis_points')->nullable()->change();
        });

        Schema::table('sales_board_cycle_lines', function (Blueprint $table) {
            $table->string('block', 50)->nullable()->change();
            $table->string('unit', 50)->nullable()->change();
        });
    }
};
