<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A fronteira entre a governança e o quadro publicado.
     *
     * `sales_boards` é a tabela legada, e ela não tem -- nem deve ganhar --
     * colunas dizendo de qual ciclo, de qual versão, de qual validação e de qual
     * análise cada posição veio: ela continua aceitando posição digitada à mão,
     * e essas colunas seriam nulas na maioria das linhas. A ligação vive aqui, e
     * responde de uma vez a pergunta que a auditoria vai fazer: "quem aprovou
     * esta posição, sobre qual quadro, com base em qual declaração?".
     *
     * Os fingerprints são os dois lados da aprovação. `snapshot_fingerprint` é a
     * posição publicada; `source_fingerprint` é a origem material que a
     * produziu; `observed_source_fingerprint` é a origem que existia no instante
     * da publicação. Quando o terceiro difere do segundo, a Gestão aprovou
     * sabendo que a fonte havia mudado sem alterar o resultado -- e
     * `source_change_reason` é a justificativa que essa aprovação exigiu.
     *
     * Uma publicação por ciclo, e um ciclo por quadro publicado. Nesta fase não
     * existe republicação: corrigir uma posição publicada é decisão de rollout,
     * e antecipá-la aqui abriria a porta para sobrescrever silenciosamente um
     * quadro que alguém já leu.
     *
     * FKs RESTRICT em toda a cadeia, incluindo o próprio `SalesBoard`. Ele é
     * `cascadeOnDelete` a partir da emissão -- decisão da tabela legada, anterior
     * a tudo isto -- e este RESTRICT é o que impede que apagar uma emissão leve
     * junto a prova de que aquela posição passou por aprovação.
     */
    public function up(): void
    {
        Schema::create('sales_board_publications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_board_cycle_id')
                ->constrained(indexName: 'sales_board_publications_cycle_foreign')
                ->restrictOnDelete();
            $table->foreignId('sales_board_cycle_baseline_id')
                ->constrained(indexName: 'sales_board_publications_baseline_foreign')
                ->restrictOnDelete();
            $table->foreignId('sales_board_builder_review_id')
                ->constrained(indexName: 'sales_board_publications_builder_review_foreign')
                ->restrictOnDelete();
            $table->foreignId('sales_board_management_review_id')
                ->constrained(
                    table: 'sales_board_management_reviews',
                    indexName: 'sales_board_publications_management_review_foreign',
                )
                ->restrictOnDelete();
            $table->foreignId('sales_board_id')
                ->constrained(indexName: 'sales_board_publications_board_foreign')
                ->restrictOnDelete();

            $table->char('snapshot_fingerprint', 64);
            $table->char('source_fingerprint', 64);
            $table->char('observed_source_fingerprint', 64)->nullable();

            $table->boolean('source_changed')->default(false);
            $table->text('source_change_reason')->nullable();

            $table->foreignId('published_by_user_id')->nullable()
                ->constrained('users', indexName: 'sales_board_publications_published_by_foreign')
                ->nullOnDelete();
            $table->timestamp('published_at');

            $table->timestamps();

            $table->unique('sales_board_cycle_id', 'sales_board_publications_cycle_unique');
            $table->unique('sales_board_id', 'sales_board_publications_board_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_board_publications');
    }
};
