<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_downloads', function (Blueprint $table) {
            $table->foreignId('investor_id')->nullable()->change();
            $table->foreignId('admin_user_id')->nullable()->constrained('users')->nullOnDelete()->after('investor_id');
            $table->string('source')->default('portal')->after('admin_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('document_downloads', function (Blueprint $table) {
            $table->dropColumn('source');
            $table->dropConstrainedForeignId('admin_user_id');
            $table->foreignId('investor_id')->nullable(false)->change();
        });
    }
};
