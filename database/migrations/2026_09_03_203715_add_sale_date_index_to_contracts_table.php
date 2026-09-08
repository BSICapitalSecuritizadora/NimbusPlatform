<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Índice para a varredura de contratos de um empreendimento por data.
     *
     * A derivação do Quadro de Vendas carrega, de uma vez, todos os contratos de
     * uma obra vendidos até a data da posição, ordenados por `sale_date`. Os
     * índices que existiam não servem: o único é
     * `(construction_id, code_normalized)` e o outro é
     * `(construction_id, status)` -- nenhum dos dois cobre o intervalo de datas
     * nem a ordenação, e o `EXPLAIN` mostrava `type=ALL` com `Using filesort`
     * mesmo com o `construction_id` fixado.
     *
     * Não é índice por opinião: `(construction_id, sale_date)` é exatamente a
     * forma da consulta, e é a única do motor que varria a tabela inteira.
     *
     * `cancellation_date` fica de fora de propósito. Os distratos da competência
     * são filtrados em memória sobre o mesmo conjunto já carregado por
     * `sale_date`, então um segundo índice não teria consulta para servir --
     * seria peso de escrita sem leitura correspondente.
     */
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->index(['construction_id', 'sale_date'], 'contracts_construction_id_sale_date_index');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropIndex('contracts_construction_id_sale_date_index');
        });
    }
};
