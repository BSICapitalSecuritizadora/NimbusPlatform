<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Quem cancelou a competência, quando e por quê.
     *
     * O cancelamento é o único fim de um ciclo que não termina em publicação, e
     * sem estas colunas ele seria só um status: ninguém saberia dizer depois
     * por que aquela competência não tem quadro. Anuláveis porque quase todo
     * ciclo termina aprovado.
     */
    public function up(): void
    {
        Schema::table('sales_board_cycles', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('status');

            $table->foreignId('cancelled_by_user_id')->nullable()->after('cancelled_at')
                ->constrained('users', indexName: 'sales_board_cycles_cancelled_by_foreign')
                ->nullOnDelete();

            $table->text('cancellation_reason')->nullable()->after('cancelled_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('sales_board_cycles', function (Blueprint $table) {
            $table->dropForeign('sales_board_cycles_cancelled_by_foreign');
            $table->dropColumn(['cancelled_at', 'cancelled_by_user_id', 'cancellation_reason']);
        });
    }
};
