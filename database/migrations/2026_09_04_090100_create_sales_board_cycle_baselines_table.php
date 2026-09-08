<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Uma versão congelada da posição de um ciclo.
     *
     * O conteúdo apurado é imutável. Recalcular nunca reescreve a V1: cria a V2,
     * e o ponteiro do ciclo passa a apontar para ela. A pergunta "o que o Nimbus
     * calculou naquele momento?" continua respondível para sempre, inclusive
     * depois de a fonte mudar.
     *
     * Os nomes dos baldes são os da semântica V2. `settled_*` não é `paid_*`:
     * uma coluna chamada "pago" num quadro em que o que se apura é a *quitação
     * do contrato* convidaria, na primeira leitura apressada, a somar recebíveis
     * com posição. O mapeamento para o vocabulário legado do `SalesBoard` é
     * trabalho da fase que publicar, e é lá que ele deve aparecer, explícito.
     *
     * Valores monetários são anuláveis de propósito. `0,00` é um valor real
     * apurado; `NULL` é "não foi possível saber". Um default zero transformaria
     * ausência de dado em fato, que é exatamente a meia verdade que estas fases
     * existem para eliminar. Na prática a geração só ocorre com readiness
     * aprovado, então um baseline gerado tem os quatro valores preenchidos -- a
     * nulidade da coluna existe para o domínio não poder mentir, não porque se
     * espere usá-la.
     *
     * Não há coluna de readiness. Ela seria constante ("pronto") em toda linha
     * gerável, e os avisos que sobreviveriam a ela -- venda fora da política --
     * já estão congelados, um a um, nos movimentos. Guardar uma contagem
     * derivável ao lado do fato de onde ela sai só criaria duas versões da mesma
     * verdade.
     *
     * Os fingerprints são as duas perguntas distintas: `source_fingerprint`
     * resume os fatos materiais observados na fonte; `snapshot_fingerprint`
     * resume o que foi derivado deles. Duas fontes diferentes podem produzir o
     * mesmo snapshot, e é essa diferença que separa "mudou algo irrelevante" de
     * "a posição mudou".
     *
     * Os metadados de stale são a única parte mutável desta tabela: eles não
     * descrevem a apuração, descrevem a relação entre ela e o mundo depois dela.
     */
    public function up(): void
    {
        Schema::create('sales_board_cycle_baselines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_board_cycle_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');

            $table->unsignedInteger('units_total');
            $table->unsignedInteger('stock_units');
            $table->decimal('stock_value', 15, 2)->nullable();
            $table->unsignedInteger('financed_units');
            $table->decimal('financed_value', 15, 2)->nullable();
            $table->unsignedInteger('settled_units');
            $table->decimal('settled_value', 15, 2)->nullable();
            $table->unsignedInteger('exchanged_units');
            $table->decimal('exchanged_value', 15, 2)->nullable();
            $table->unsignedInteger('undetermined_units');
            $table->boolean('is_complete');

            $table->char('source_fingerprint', 64);
            $table->char('snapshot_fingerprint', 64);

            $table->timestamp('computed_at');
            $table->foreignId('computed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();

            $table->boolean('is_stale')->default(false);
            $table->string('stale_impact', 20)->nullable();
            $table->timestamp('stale_detected_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->char('last_observed_source_fingerprint', 64)->nullable();
            $table->char('last_observed_snapshot_fingerprint', 64)->nullable();

            $table->timestamps();

            /**
             * Última defesa contra dois recálculos simultâneos produzirem duas
             * V2. A primeira é o lock do ciclo; esta é a que não depende de o
             * código estar certo.
             */
            $table->unique(['sales_board_cycle_id', 'version'], 'sales_board_cycle_baselines_version_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_board_cycle_baselines');
    }
};
