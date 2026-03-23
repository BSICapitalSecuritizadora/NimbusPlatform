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
        Schema::create('integralization_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('emission_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->decimal('quantity', 18, 4);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('integralization_histories');
    }
};
