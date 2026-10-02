<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'sales_board_cycle_baselines';

    /**
     * Os avisos da apuração, congelados com a versão.
     *
     * A migration que criou esta tabela dispensou a coluna porque os avisos que
     * sobreviveriam à prontidão -- a venda fora da política -- "já estão
     * congelados, um a um, nos movimentos". Deixou de ser verdade: o status do
     * contrato que diverge do cronograma, a venda datada no futuro, a parcela
     * paga abaixo do previsto, o pagamento com data futura e os valores atípicos
     * dependem de fatos que não viram linha nem movimento (o status de hoje, o
     * relógio de negócio), e não se reconstroem a partir do que foi congelado.
     * Guardá-los com a versão registra o que o Nimbus sinalizou quando a
     * construtora e a Gestão foram perguntadas.
     *
     * Lista canônica em JSON, gravada só na criação da versão e imutável como o
     * resto dela: a coluna não entra nos campos mutáveis do model. Fica fora dos
     * fingerprints -- aviso não decide nada.
     *
     * NULL é "avisos não registrados": a versão foi congelada antes desta
     * coluna. Não há backfill, porque reconstruir os avisos de uma versão
     * passada exigiria a fonte daquela época. Uma versão nova sem aviso grava a
     * lista vazia.
     *
     * Aditiva e nula: no MySQL 8.4 o ADD COLUMN é INSTANT, numa tabela com uma
     * linha por versão. A guarda deixa a migration reexecutável -- o MySQL não
     * desfaz DDL.
     */
    public function up(): void
    {
        if (Schema::hasColumn(self::TABLE, 'warnings')) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->json('warnings')->nullable()->after('is_complete');
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
