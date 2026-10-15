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
            // Nullable and unfilled on purpose: every existing row keeps
            // working with NULL references, and nothing is backfilled here.
            $table->foreignId('bank_account_id')->nullable()->after('conta_azul_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('bank_application_id')->nullable()->after('bank_account_id')->constrained()->restrictOnDelete();
            // A fund may point at an application only when the pair belongs
            // together; rows with a NULL side skip the check (MATCH SIMPLE),
            // which is exactly the legacy and direct-link states. The key
            // keeps its conventional name so down() resolves it on every
            // driver (SQLite cannot drop foreign keys by explicit name).
            $table->foreign(['bank_account_id', 'bank_application_id'])
                ->references(['bank_account_id', 'id'])
                ->on('bank_applications')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('funds', function (Blueprint $table) {
            $table->dropForeign(['bank_account_id', 'bank_application_id']);
            $table->dropForeign(['bank_application_id']);
            $table->dropForeign(['bank_account_id']);
            $table->dropColumn(['bank_application_id', 'bank_account_id']);
        });
    }
};
