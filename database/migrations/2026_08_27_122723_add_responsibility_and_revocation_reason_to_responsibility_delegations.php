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
        Schema::table('responsibility_delegations', function (Blueprint $table): void {
            $table->string('scope_responsibility', 40)->nullable()->after('scope_stage');
            $table->text('revocation_reason')->nullable()->after('revoked_by');
            $table->index(
                ['delegator_user_id', 'scope_type', 'scope_operation_id', 'scope_stage', 'scope_responsibility'],
                'rd_delegator_scope_idx',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('responsibility_delegations', function (Blueprint $table): void {
            $table->dropIndex('rd_delegator_scope_idx');
            $table->dropColumn(['scope_responsibility', 'revocation_reason']);
        });
    }
};
