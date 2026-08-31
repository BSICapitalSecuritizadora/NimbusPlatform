<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `stage_started_at` faz parte da chave que impede o alerta repetido -- medição,
 * etapa, tipo, destinatário e início do ciclo. Enquanto a coluna aceitava NULL,
 * a chave não protegia nada naquele estado: nem MySQL nem SQLite comparam NULL a
 * NULL, então linhas idênticas com o início do ciclo nulo coexistiam sem colidir.
 *
 * A nulidade nunca foi requisito: veio de a coluna ter sido acrescentada a uma
 * tabela já existente. A invariante do domínio sempre foi a oposta -- se existe
 * alerta, existe ciclo iniciado. Sem relógio de etapa a avaliação devolve
 * `NOT_CONFIGURED`, que não vira `approaching` nem `overdue`, e nenhuma linha é
 * gravada. O schema passa a dizer a mesma coisa.
 *
 * Sem backfill e sem sentinela: se alguma linha nula existir em algum ambiente, a
 * migration falha aqui, e é isso que se quer. Preencher com `now()`, `created_at`
 * ou uma data inferida inventaria um ciclo que ninguém observou.
 */
return new class extends Migration
{
    public function up(): void
    {
        $nulls = DB::table('measurement_sla_alerts')->whereNull('stage_started_at')->count();

        if ($nulls > 0) {
            throw new RuntimeException(
                "measurement_sla_alerts possui {$nulls} alerta(s) com stage_started_at nulo. "
                    .'A aplicação não grava esse estado, então cada linha é um caso a investigar -- '
                    .'esta migration não preenche a coluna com data inferida nem com sentinela.'
            );
        }

        Schema::table('measurement_sla_alerts', function (Blueprint $table): void {
            $table->dateTime('stage_started_at')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('measurement_sla_alerts', function (Blueprint $table): void {
            $table->dateTime('stage_started_at')->nullable()->change();
        });
    }
};
