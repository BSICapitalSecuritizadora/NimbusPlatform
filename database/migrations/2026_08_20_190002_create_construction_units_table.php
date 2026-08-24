<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Individual units of a construction.
     *
     * The owning emission is reached through the construction, so it is not
     * repeated here. Block and unit are free text on purpose: identifications
     * such as "01", "A", "Torre 01", "Loja 01" or "Cobertura 02" must be kept
     * exactly as informed, without losing leading zeros.
     */
    public function up(): void
    {
        Schema::create('construction_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('construction_id')->constrained()->cascadeOnDelete();
            $table->string('block');
            $table->string('unit');
            $table->timestamps();

            $table->unique(['construction_id', 'block', 'unit']);
            $table->index(['construction_id', 'block']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('construction_units');
    }
};
