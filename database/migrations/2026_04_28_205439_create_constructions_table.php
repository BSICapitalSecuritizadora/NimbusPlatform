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
        Schema::create('constructions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('emission_id')->constrained()->cascadeOnDelete();
            $table->string('development_name');
            $table->string('development_cnpj', 14);
            $table->string('city');
            $table->string('state', 2);
            $table->date('construction_start_date');
            $table->date('construction_end_date');
            $table->decimal('estimated_value', 15, 2);
            $table->foreignId('measurement_company_id')
                ->constrained('expense_service_providers')
                ->restrictOnDelete();
            $table->timestamps();

            $table->index(['emission_id', 'development_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('constructions');
    }
};
