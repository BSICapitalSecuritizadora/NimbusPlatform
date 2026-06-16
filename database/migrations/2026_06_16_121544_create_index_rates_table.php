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
        Schema::create('index_rates', function (Blueprint $table) {
            $table->id();
            $table->string('indexer', 20);
            $table->date('rate_date');
            $table->decimal('rate_value', 12, 8);
            $table->string('source')->nullable();
            $table->string('source_reference')->nullable();
            $table->timestamps();

            $table->unique(['indexer', 'rate_date']);
            $table->index('rate_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('index_rates');
    }
};
