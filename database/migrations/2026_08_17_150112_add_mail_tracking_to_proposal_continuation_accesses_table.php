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
        Schema::table('proposal_continuation_accesses', function (Blueprint $table) {
            $table->timestamp('mail_queued_at')->nullable()->after('sent_at');
            $table->timestamp('mail_failed_at')->nullable()->after('mail_queued_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('proposal_continuation_accesses', function (Blueprint $table) {
            $table->dropColumn(['mail_queued_at', 'mail_failed_at']);
        });
    }
};
