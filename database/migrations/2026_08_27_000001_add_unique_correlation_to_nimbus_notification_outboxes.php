<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nimbus_notification_outboxes', function (Blueprint $table) {
            // Unique correlation for idempotency; nullable values remain non-unique in MySQL.
            $table->unique('correlation_id', 'nimbus_outboxes_correlation_unique');
        });
    }

    public function down(): void
    {
        Schema::table('nimbus_notification_outboxes', function (Blueprint $table) {
            $table->dropUnique('nimbus_outboxes_correlation_unique');
        });
    }
};
