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
        Schema::table('users', function (Blueprint $table) {
            $table->string('cargo')->nullable()->after('email');
            $table->string('departamento')->nullable()->after('cargo');
            $table->timestamp('approved_at')->nullable()->after('departamento');
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete()->after('approved_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invited_by');
            $table->dropColumn(['cargo', 'departamento', 'approved_at']);
        });
    }
};
