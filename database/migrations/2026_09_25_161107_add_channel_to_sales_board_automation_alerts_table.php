<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O canal de cada linha do livro-razão de avisos.
     *
     * Cada canal (e-mail, sino do painel) é um job próprio na fila, e cada um
     * falha sozinho. Com uma linha por aviso, o e-mail que caía devolvia o aviso
     * inteiro e o sino ganhava uma cópia por hora enquanto o SMTP estivesse
     * fora. Com uma linha por canal, a falha devolve só o canal que falhou.
     *
     * Anulável porque linha anterior a esta coluna não tem canal para contar;
     * toda linha nova sai com ele.
     */
    public function up(): void
    {
        Schema::table('sales_board_automation_alerts', function (Blueprint $table) {
            $table->string('channel', 20)->nullable()->after('recipient_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('sales_board_automation_alerts', function (Blueprint $table) {
            $table->dropColumn('channel');
        });
    }
};
