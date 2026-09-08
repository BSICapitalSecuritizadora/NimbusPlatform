<?php

use App\Enums\SalesBoardSource;
use App\Services\SalesBoards\SalesBoardPositionReader;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Qual workflow produz os próximos quadros mensais de cada Emissão.
     *
     * O default é `legacy`, e não é conservadorismo decorativo: esta migration
     * roda sobre uma base em que **toda** posição foi digitada à mão, e o
     * default é o único mecanismo que garante que nenhuma Emissão existente
     * comece a ser automatizada por efeito colateral de um deploy. Ativar é ato
     * explícito, com homologação, e acontece uma Emissão por vez.
     *
     * `sales_board_source` responde "quem escreve os próximos quadros", **não**
     * "de onde o leitor lê". O {@see SalesBoardPositionReader}
     * continua lendo `sales_boards` nos dois modos -- é essa fronteira que
     * mantém Garantias e Relatório sem saber que o rollout existe.
     *
     * `automation_start_reference_month` é anulável porque só existe no modo
     * automatizado, e nulo **nunca** significa "desde sempre": o provider
     * recusa um alvo sem competência de ativação. Sem esse piso, automatizar
     * uma Emissão com três anos de histórico dispararia trinta e seis
     * apurações que ninguém pediu.
     *
     * `active_homologation_id` fica anulável e sem FK declarada aqui pela ordem
     * das migrations -- a tabela de homologações nasce depois, e a FK é
     * acrescentada lá, quando as duas existem.
     */
    public function up(): void
    {
        Schema::table('emissions', function (Blueprint $table) {
            $table->string('sales_board_source', 20)
                ->default(SalesBoardSource::Legacy->value)
                ->after('status');

            $table->date('sales_board_automation_start_reference_month')
                ->nullable()
                ->after('sales_board_source');

            $table->boolean('sales_board_auto_open_builder_review')
                ->default(false)
                ->after('sales_board_automation_start_reference_month');

            $table->unsignedBigInteger('sales_board_active_homologation_id')
                ->nullable()
                ->after('sales_board_auto_open_builder_review');

            /**
             * A descoberta da Fase F entra por aqui a cada hora: "quais
             * Emissões estão automatizadas?". É a única consulta quente que
             * estas colunas criam.
             */
            $table->index('sales_board_source', 'emissions_sales_board_source_index');
        });
    }

    public function down(): void
    {
        Schema::table('emissions', function (Blueprint $table) {
            $table->dropIndex('emissions_sales_board_source_index');
            $table->dropColumn([
                'sales_board_source',
                'sales_board_automation_start_reference_month',
                'sales_board_auto_open_builder_review',
                'sales_board_active_homologation_id',
            ]);
        });
    }
};
