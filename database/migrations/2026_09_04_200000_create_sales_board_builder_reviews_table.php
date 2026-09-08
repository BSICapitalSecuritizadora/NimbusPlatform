<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A validação da construtora sobre uma versão congelada da posição.
     *
     * A revisão pertence ao **baseline**, não apenas ao ciclo. V1 e V2 podem
     * apresentar fatos diferentes, e a pergunta que precisa continuar
     * respondível daqui a um ano é "qual versão a construtora efetivamente
     * viu?". Guardar só o ciclo perderia isso na primeira recomposição.
     *
     * `snapshot_fingerprint` é copiado do baseline no momento da abertura. Ele é
     * o que decide se a revisão continua aplicável: se a versão vigente passar a
     * ter outro resumo de posição, o quadro que a construtora conferiu deixou de
     * existir e a validação precisa ser refeita. Um recálculo que só troca a
     * origem material -- mesmo resumo de posição -- não invalida nada, porque a
     * construtora viu exatamente o mesmo quadro.
     *
     * A identidade do revisor é congelada em texto ao lado da FK do usuário
     * interno. A conta pode ser desativada, renomeada ou removida da operação, e
     * a trilha de quem assinou a validação não pode depender disso. O `email` é
     * o único dado pessoal guardado, e existe porque uma auditoria de validação
     * precisa saber a quem perguntar.
     *
     * Não há campo de decisão da Gestão. Aprovar, rejeitar ou devolver é da fase
     * seguinte, e uma coluna disponível antes da regra é um convite a preenchê-la
     * sem ela.
     *
     * FKs RESTRICT: a submissão da construtora é registro de auditoria e não
     * pode evaporar junto com o ciclo ou a versão que ela revisou.
     */
    public function up(): void
    {
        Schema::create('sales_board_builder_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_board_cycle_id')
                ->constrained(indexName: 'builder_reviews_cycle_foreign')
                ->restrictOnDelete();
            $table->foreignId('sales_board_cycle_baseline_id')
                ->constrained(indexName: 'builder_reviews_baseline_foreign')
                ->restrictOnDelete();

            /**
             * Cada rodada de validação é distinguível. Uma revisão substituída
             * por nova versão material não é reaproveitada nem reescrita: abre-se
             * a tentativa seguinte, e a anterior continua consultável com o que
             * foi declarado na época.
             */
            $table->unsignedInteger('attempt');
            $table->string('status', 20);
            $table->char('snapshot_fingerprint', 64);

            $table->timestamp('opened_at');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->string('superseded_reason', 60)->nullable();

            $table->foreignId('opened_by_user_id')->nullable()
                ->constrained('users', indexName: 'builder_reviews_opened_by_foreign')
                ->nullOnDelete();
            $table->foreignId('submitted_by_user_id')->nullable()
                ->constrained('users', indexName: 'builder_reviews_submitted_by_foreign')
                ->nullOnDelete();

            $table->string('reviewer_type', 30)->nullable();
            $table->string('reviewer_key', 120)->nullable();
            $table->string('reviewer_name', 160)->nullable();
            $table->string('reviewer_email', 160)->nullable();

            $table->text('overall_comment')->nullable();
            $table->string('declaration_version', 20)->nullable();

            $table->timestamps();

            /**
             * Só a unique. As buscas por ciclo entram por ela, e as buscas por
             * baseline entram pelo índice que o InnoDB cria sozinho para a
             * própria chave estrangeira -- declarar de novo seria manter dois
             * índices para o mesmo acesso.
             */
            $table->unique(['sales_board_cycle_id', 'attempt'], 'builder_reviews_cycle_attempt_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_board_builder_reviews');
    }
};
