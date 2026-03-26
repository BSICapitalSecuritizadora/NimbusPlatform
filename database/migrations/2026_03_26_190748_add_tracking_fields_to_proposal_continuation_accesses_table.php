<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proposal_continuation_accesses', function (Blueprint $table) {
            $table->text('code_encrypted')->nullable()->after('code_hash');
            $table->timestamp('sent_at')->nullable()->after('code_encrypted');
            $table->timestamp('first_accessed_at')->nullable()->after('sent_at');
            $table->timestamp('last_accessed_at')->nullable()->after('first_accessed_at');
        });
    }

    public function down(): void
    {
        Schema::table('proposal_continuation_accesses', function (Blueprint $table) {
            $table->dropColumn([
                'code_encrypted',
                'sent_at',
                'first_accessed_at',
                'last_accessed_at',
            ]);
        });
    }
};
