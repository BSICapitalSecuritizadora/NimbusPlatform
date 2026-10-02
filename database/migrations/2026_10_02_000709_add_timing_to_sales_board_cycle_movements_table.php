<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'sales_board_cycle_movements';

    /**
     * Quando o fato do movimento aconteceu em relação à competência.
     *
     * NULL é o movimento do mês, como todo movimento congelado até aqui. Os
     * outros valores marcam o que a competência recebeu de antes dela:
     * `extemporaneo` (venda, distrato ou quitação de uma competência já
     * fechada, lançados depois), `revisao_venda` (valor ou data de uma venda já
     * publicada mudou) e `competencia_sem_posicao` (fato de uma competência
     * cancelada, absorvido pela seguinte). A competência de origem não é
     * gravada: sai da data da venda ou do distrato.
     *
     * Nenhuma linha muda de significado, e o resumo das versões existentes
     * continua o mesmo -- o timing só entra na linha canônica do movimento
     * quando não é nulo. Sem índice: é filtro de tela sobre os movimentos de uma
     * versão, que já vêm pela unique `(versão, tipo, contrato)`.
     *
     * ADD COLUMN nula e sem default é INSTANT no MySQL 8.4. Guardada pela
     * coluna, porque o MySQL não desfaz DDL.
     */
    public function up(): void
    {
        if (Schema::hasColumn(self::TABLE, 'timing')) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->string('timing', 30)->nullable()->after('movement_type');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn(self::TABLE, 'timing')) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropColumn('timing');
        });
    }
};
