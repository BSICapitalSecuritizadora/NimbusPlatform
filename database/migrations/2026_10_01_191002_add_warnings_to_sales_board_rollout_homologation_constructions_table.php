<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'sales_board_rollout_homologation_constructions';

    /**
     * Os avisos da apuração de cada empreendimento na competência de comparação
     * da homologação.
     *
     * Gravados na avaliação e na reavaliação, como o resto do retrato derivado,
     * e na mesma forma canônica dos avisos congelados com a versão do ciclo.
     * Ficam fora do resumo da avaliação (`assessment_hash`) e do aceite de
     * diferença: aviso não decide nada, e um status de contrato alterado hoje não
     * pode invalidar uma homologação revisada sem mudança da fonte material.
     *
     * NULL é "avisos não registrados": a linha foi avaliada antes desta coluna, e
     * a próxima reavaliação grava a lista. Só homologação em rascunho é
     * reavaliável; as encerradas guardam o retrato em que foram decididas.
     *
     * Aditiva e nula, INSTANT no MySQL 8.4, sem tocar nenhuma linha: o guard que
     * recusa gravar em homologação encerrada mora no model, e DDL não passa por
     * ele. A guarda deixa a migration reexecutável.
     */
    public function up(): void
    {
        if (Schema::hasColumn(self::TABLE, 'warnings')) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->json('warnings')->nullable()->after('blocker_message');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn(self::TABLE, 'warnings')) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropColumn('warnings');
        });
    }
};
