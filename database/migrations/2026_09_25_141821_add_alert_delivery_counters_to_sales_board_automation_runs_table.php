<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Os avisos que não saíram também são fato da execução.
     *
     * O despachante já contava as falhas de envio e os avisos sem destinatário,
     * mas a execução só gravava os enviados e os deduplicados. Uma execução em
     * que nenhum e-mail saiu ficava idêntica a uma em que não havia nada a
     * avisar -- e o único rastro era uma exceção solta no log.
     */
    public function up(): void
    {
        Schema::table('sales_board_automation_runs', function (Blueprint $table) {
            $table->unsignedInteger('alerts_failed')->default(0)->after('alerts_deduped');
            $table->unsignedInteger('alerts_without_recipient')->default(0)->after('alerts_failed');
        });
    }

    public function down(): void
    {
        Schema::table('sales_board_automation_runs', function (Blueprint $table) {
            $table->dropColumn(['alerts_failed', 'alerts_without_recipient']);
        });
    }
};
