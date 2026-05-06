<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nimbus_access_tokens', function (Blueprint $table) {
            // Add HMAC-SHA256 digest for secure token lookup; plaintext code never persisted again
            $table->string('code_hash', 64)->nullable()->unique()->after('code');

            // Revoke all PENDING tokens — they used plaintext storage and cannot be migrated
            // safely without the plaintext values. Admins must reissue tokens.
        });

        DB::table('nimbus_access_tokens')
            ->where('status', 'PENDING')
            ->update(['status' => 'REVOKED']);

        // Make the plaintext code column nullable so new rows don't store it
        Schema::table('nimbus_access_tokens', function (Blueprint $table) {
            $table->string('code')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('nimbus_access_tokens', function (Blueprint $table) {
            $table->dropColumn('code_hash');
            $table->string('code')->nullable(false)->change();
        });
    }
};
