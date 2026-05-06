<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('funds', function (Blueprint $table) {
            $table->timestamp('minimum_balance_alert_sent_at')->nullable()->after('balance_updated_at');
            $table->decimal('minimum_balance_alert_balance', 15, 2)->nullable()->after('minimum_balance_alert_sent_at');
            $table->decimal('minimum_balance_alert_minimum_balance', 15, 2)->nullable()->after('minimum_balance_alert_balance');
            $table->string('minimum_balance_alert_recipients_hash', 64)->nullable()->after('minimum_balance_alert_minimum_balance');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('funds', function (Blueprint $table) {
            $table->dropColumn([
                'minimum_balance_alert_sent_at',
                'minimum_balance_alert_balance',
                'minimum_balance_alert_minimum_balance',
                'minimum_balance_alert_recipients_hash',
            ]);
        });
    }
};
