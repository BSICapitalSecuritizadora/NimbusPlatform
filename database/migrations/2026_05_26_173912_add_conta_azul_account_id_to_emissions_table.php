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
        Schema::table('emissions', function (Blueprint $table): void {
            $table->string('conta_azul_account_id')->nullable()->after('bsi_code');
        });
    }

    public function down(): void
    {
        Schema::table('emissions', function (Blueprint $table): void {
            $table->dropColumn('conta_azul_account_id');
        });
    }
};
