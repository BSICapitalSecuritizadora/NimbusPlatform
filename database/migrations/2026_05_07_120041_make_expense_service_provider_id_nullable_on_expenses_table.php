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
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropForeign(['expense_service_provider_id']);
            $table->unsignedBigInteger('expense_service_provider_id')->nullable()->change();
            $table->foreign('expense_service_provider_id')
                ->references('id')
                ->on('expense_service_providers')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropForeign(['expense_service_provider_id']);
            $table->unsignedBigInteger('expense_service_provider_id')->nullable(false)->change();
            $table->foreign('expense_service_provider_id')
                ->references('id')
                ->on('expense_service_providers')
                ->restrictOnDelete();
        });
    }
};
