<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Quem é avisado sobre uma Emissão automatizada.
     *
     * A Fase F deixou a resolução de destinatários deliberadamente diferida
     * porque não havia fonte confiável: `Emission` e `Construction` não têm
     * responsável, e o de `Operation` responde pelo fluxo de medição -- outro
     * papel. Esta tabela é a fonte que faltava, e ela é **explícita**: alguém
     * escolhe, por Emissão e por papel.
     *
     * Explícita em vez de derivada porque as alternativas derivadas são as
     * tentadoras e as erradas: notificar todos os administradores, ou todos que
     * tenham `sales-boards.update`. As duas transformam alerta operacional em
     * ruído para gente que não tem o que fazer com ele, e as duas contrariam o
     * mínimo privilégio que o resto do sistema respeita.
     *
     * Ser destinatário **não** concede permissão. Esta tabela diz para quem o
     * aviso vai; quem abre a tela continua passando pelas permissões de sempre.
     *
     * FK do usuário em RESTRICT, e não CASCADE: apagar uma conta não pode
     * silenciosamente esvaziar a configuração de avisos de uma Emissão
     * automatizada e deixá-la operando sem ninguém a avisar.
     */
    public function up(): void
    {
        Schema::create('sales_board_rollout_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('emission_id')
                ->constrained(indexName: 'sb_rollout_recipients_emission_foreign')
                ->restrictOnDelete();

            $table->string('role', 20);

            $table->foreignId('user_id')
                ->constrained(indexName: 'sb_rollout_recipients_user_foreign')
                ->restrictOnDelete();

            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users', indexName: 'sb_rollout_recipients_created_by_foreign')
                ->nullOnDelete();

            $table->timestamps();

            $table->unique(
                ['emission_id', 'role', 'user_id'],
                'sb_rollout_recipients_emission_role_user_unique',
            );

            /**
             * "Quem são os operacionais desta Emissão?" é a pergunta que o
             * resolvedor faz a cada alerta.
             */
            $table->index(['emission_id', 'role'], 'sb_rollout_recipients_emission_role_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_board_rollout_recipients');
    }
};
