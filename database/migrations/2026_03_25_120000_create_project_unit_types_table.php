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
        Schema::create('project_unit_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('characteristic_id')->constrained('project_characteristics')->onDelete('cascade');
            $table->integer('order')->default(1);
            $table->integer('total_units')->nullable();
            $table->string('bedrooms')->nullable();
            $table->string('parking_spaces')->nullable();
            $table->decimal('useful_area', 10, 2)->nullable();
            $table->decimal('average_price', 15, 2)->nullable();
            $table->decimal('price_per_m2', 15, 2)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_unit_types');
    }
};
