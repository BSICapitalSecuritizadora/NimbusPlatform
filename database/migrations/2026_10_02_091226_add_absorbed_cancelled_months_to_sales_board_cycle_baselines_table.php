<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'sales_board_cycle_baselines';

    private const COLUMN = 'absorbed_cancelled_months';

    /**
     * As competências canceladas cujos fatos a versão absorveu.
     *
     * Com `previous_competence_baseline_id` -- a versão da âncora, e por ela o
     * ciclo âncora --, é a estrutura da cadeia da versão: de onde os movimentos
     * dela partem e quais meses cancelados a janela cobriu. A verificação de
     * alterações compara essa estrutura com a cadeia de hoje. Reabrir ou
     * cancelar uma competência anterior muda a janela, os avisos de baixa e de
     * reativação e a ponte da seguinte mesmo quando nenhum número dela muda, e
     * os fingerprints não enxergam isso. Sem âncora -- a primeira competência
     * automatizada cancelada --, a âncora nula não diz quais meses a versão
     * absorveu, e por isso a coluna.
     *
     * Lista JSON de meses `Y-m`, do mais recente para o mais antigo, gravada só
     * na criação da versão e imutável como o resto dela: fora dos campos
     * mutáveis do model e fora dos fingerprints. A lista vazia é "nenhuma
     * competência cancelada absorvida". NULL é "cadeia não registrada": a
     * versão foi congelada antes desta coluna, e a verificação não compara a
     * cadeia dela. Não há backfill -- a cadeia de uma versão passada não se
     * reconstrói com a fonte de hoje, e marcar como alterada toda versão
     * congelada antes do deploy seria ruído.
     *
     * Aditiva e nula: no MySQL 8.4 o ADD COLUMN é INSTANT, numa tabela com uma
     * linha por versão. A guarda deixa a migration reexecutável -- o MySQL não
     * desfaz DDL.
     */
    public function up(): void
    {
        if (Schema::hasColumn(self::TABLE, self::COLUMN)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->json(self::COLUMN)->nullable()->after('previous_competence_baseline_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn(self::TABLE, self::COLUMN)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropColumn(self::COLUMN);
        });
    }
};
