<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Buyers of the construction units.
     *
     * Deliberately independent from any construction: the link between a client
     * and a unit belongs to the future contract entity, which is what preserves
     * the commercial history (sale, distrato, resale, change of buyer).
     *
     * The document holds digits only and is unique across the whole table,
     * soft-deleted rows included, so the same person always maps to the same id.
     */
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('person_type', 2);
            $table->string('name');
            $table->string('trade_name')->nullable();
            $table->string('document', 14)->unique();
            $table->string('email')->nullable();
            $table->string('phone', 20)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('person_type');
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
