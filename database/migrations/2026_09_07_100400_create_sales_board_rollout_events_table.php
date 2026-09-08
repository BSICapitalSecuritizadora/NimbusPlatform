<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * As mudanças de modo de uma Emissão.
     *
     * Append-only. É a trilha que responde "desde quando esta Emissão é
     * automatizada, com base em qual homologação, e por decisão de quem" -- e a
     * pergunta simétrica quando ela volta ao legado. As colunas da Emissão
     * guardam o presente; sem esta tabela, um retorno ao legado apagaria a
     * evidência de que a automação existiu.
     *
     * `from_source` e `to_source` são persistidos em vez de inferidos do tipo do
     * evento. Parecem redundantes hoje, com dois tipos; deixam de parecer no dia
     * em que um terceiro modo existir, e o custo de gravá-los agora é duas
     * colunas curtas.
     *
     * Nenhum rollback apaga história: publicações, ciclos, revisões e quadros
     * permanecem. O rollout decide apenas qual workflow produz os **próximos**
     * quadros.
     */
    public function up(): void
    {
        Schema::create('sales_board_rollout_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('emission_id')
                ->constrained(indexName: 'sb_rollout_events_emission_foreign')
                ->restrictOnDelete();

            $table->string('event_type', 30);
            $table->string('from_source', 20);
            $table->string('to_source', 20);

            $table->foreignId('sales_board_rollout_homologation_id')->nullable()
                ->constrained(
                    table: 'sales_board_rollout_homologations',
                    indexName: 'sb_rollout_events_homologation_foreign',
                )
                ->restrictOnDelete();

            $table->date('start_reference_month')->nullable();
            $table->text('reason')->nullable();

            $table->foreignId('actor_user_id')->nullable()
                ->constrained('users', indexName: 'sb_rollout_events_actor_foreign')
                ->nullOnDelete();

            $table->timestamp('created_at')->nullable();

            $table->index(['emission_id', 'created_at'], 'sb_rollout_events_emission_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_board_rollout_events');
    }
};
