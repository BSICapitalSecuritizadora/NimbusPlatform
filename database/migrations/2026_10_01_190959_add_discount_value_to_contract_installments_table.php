<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'contract_installments';

    /**
     * "Desconto concedido": o abatimento dado na baixa da parcela -- pontualidade,
     * antecipação, quitação com desconto.
     *
     * Até aqui a parcela recebida abaixo do previsto ficava em aberto para
     * sempre: o pagamento a menor é pagamento parcial
     * (`ContractInstallmentStatus::PartiallyPaid`), e não havia onde registrar
     * que a diferença foi perdoada. Com a coluna, a parcela conta como paga
     * quando o pago mais o desconto registrado cobre o previsto -- em Parcelas e
     * no Quadro de Vendas, pela mesma regra.
     *
     * Nula e sem default: nulo é "sem desconto", e toda parcela existente
     * continua exatamente como era. Não há backfill -- inferir desconto de um
     * pagamento a menor seria afirmar um perdão que ninguém registrou.
     *
     * Sem `AFTER` de propósito: a coluna nula acrescentada ao fim da tabela é a
     * forma de `ADD COLUMN` que o MySQL 8.4 faz em `ALGORITHM=INSTANT` (só
     * metadado, sem copiar a tabela), a mesma já validada nesta tabela -- com a
     * coluna gerada STORED `active_contract_id` e o unique presentes -- para
     * `import_run_id`. A posição da coluna não muda nada para a aplicação. O
     * ALTER ainda espera o lock de metadados de uma transação aberta na tabela
     * (uma importação de parcelas em curso): publique fora de uma importação.
     *
     * A guarda deixa a migration reexecutável: o MySQL não desfaz DDL.
     */
    public function up(): void
    {
        if (Schema::hasColumn(self::TABLE, 'discount_value')) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->decimal('discount_value', 15, 2)->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn(self::TABLE, 'discount_value')) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropColumn('discount_value');
        });
    }
};
