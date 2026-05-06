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
        Schema::table('constructions', function (Blueprint $table) {
            $table->string('development_cnpj', 14)->nullable()->change();
            $table->date('construction_start_date')->nullable()->change();
            $table->date('construction_end_date')->nullable()->change();
            $table->decimal('estimated_value', 15, 2)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('constructions', function (Blueprint $table) {
            $table->string('development_cnpj', 14)->nullable(false)->change();
            $table->date('construction_start_date')->nullable(false)->change();
            $table->date('construction_end_date')->nullable(false)->change();
            $table->decimal('estimated_value', 15, 2)->nullable(false)->change();
        });
    }
};
