<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Uma rodada de análise da Gestão sobre uma submissão da construtora.
     *
     * Ancorada nos três fatos que ela analisou: o ciclo, a versão congelada e a
     * validação enviada pela construtora. Guardar só o ciclo perderia, na
     * primeira recomposição, a pergunta que precisa continuar respondível --
     * "sobre qual quadro, e sobre qual declaração, a Gestão decidiu?".
     *
     * `snapshot_fingerprint` é copiado do baseline na abertura, pela mesma razão
     * da Fase D: ele é o que decide se a análise continua aplicável. Uma versão
     * nova que apresenta exatamente o mesmo quadro não invalida decisão nenhuma;
     * uma que apresenta outro quadro invalida todas, porque a Gestão nunca viu
     * esses fatos.
     *
     * Os dois fingerprints de aprovação são perguntas diferentes das do
     * baseline. `approved_source_fingerprint` é a origem material que produziu a
     * versão aprovada; `observed_source_fingerprint` é a que existia no instante
     * exato em que a Gestão aprovou. Quando as duas divergem, a aprovação passou
     * por um override consciente de fonte alterada -- e `source_change_reason`
     * guarda por quê. Derivar isso depois, comparando com a fonte viva, daria
     * uma resposta diferente a cada dia.
     *
     * Não há coluna de "correção necessária". Isso é conclusão sobre um item, e
     * vive na tabela de não conformidades; promovê-la a estado da rodada
     * obrigaria a desfazer o estado toda vez que a Gestão mudasse de ideia sobre
     * um único apontamento.
     *
     * FKs RESTRICT: a análise é registro de auditoria e não pode evaporar junto
     * com o ciclo, a versão ou a validação que ela analisou. Autoria é
     * `nullOnDelete`, porque perder a conta do usuário não pode apagar o fato.
     */
    public function up(): void
    {
        Schema::create('sales_board_management_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_board_cycle_id')
                ->constrained(indexName: 'management_reviews_cycle_foreign')
                ->restrictOnDelete();
            $table->foreignId('sales_board_cycle_baseline_id')
                ->constrained(indexName: 'management_reviews_baseline_foreign')
                ->restrictOnDelete();
            $table->foreignId('sales_board_builder_review_id')
                ->constrained(indexName: 'management_reviews_builder_review_foreign')
                ->restrictOnDelete();

            /**
             * Cada rodada é distinguível. Uma devolução não reaproveita nem
             * reescreve a análise anterior: abre-se a tentativa seguinte, e a
             * anterior continua consultável com as decisões da época.
             */
            $table->unsignedInteger('attempt');
            $table->string('status', 30);
            $table->char('snapshot_fingerprint', 64);

            $table->timestamp('opened_at');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->string('superseded_reason', 60)->nullable();

            $table->foreignId('opened_by_user_id')->nullable()
                ->constrained('users', indexName: 'management_reviews_opened_by_foreign')
                ->nullOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()
                ->constrained('users', indexName: 'management_reviews_approved_by_foreign')
                ->nullOnDelete();
            $table->foreignId('returned_by_user_id')->nullable()
                ->constrained('users', indexName: 'management_reviews_returned_by_foreign')
                ->nullOnDelete();

            $table->text('overall_comment')->nullable();
            $table->text('return_reason')->nullable();

            $table->boolean('source_changed')->default(false);
            $table->text('source_change_reason')->nullable();

            $table->char('approved_source_fingerprint', 64)->nullable();
            $table->char('observed_source_fingerprint', 64)->nullable();

            $table->string('approval_declaration_version', 20)->nullable();

            $table->timestamps();

            $table->unique(
                ['sales_board_cycle_id', 'attempt'],
                'management_reviews_cycle_attempt_unique',
            );

            /**
             * "Existe análise em andamento neste ciclo?" é a pergunta que a
             * abertura faz sob lock, e a única que precisa de índice próprio
             * além da unique. Baseline e validação entram pelos índices que o
             * InnoDB cria para as respectivas chaves estrangeiras.
             */
            $table->index(
                ['sales_board_cycle_id', 'status'],
                'management_reviews_cycle_status_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_board_management_reviews');
    }
};
