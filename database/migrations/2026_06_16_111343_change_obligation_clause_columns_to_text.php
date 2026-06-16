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
        foreach (['extracted_obligations', 'obligations'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->text('due_rule')->nullable()->change();
                $table->text('source_clause')->nullable()->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['extracted_obligations', 'obligations'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->string('due_rule')->nullable()->change();
                $table->string('source_clause')->nullable()->change();
            });
        }
    }
};
