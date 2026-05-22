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
        Schema::create('guarantee_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('emission_id')->constrained()->cascadeOnDelete();
            $table->date('reference_month');
            $table->decimal('quota_value', 18, 2);
            $table->decimal('outstanding_balance', 18, 2);
            $table->timestamps();

            $table->unique(['emission_id', 'reference_month'], 'guarantee_snapshots_emission_month_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('guarantee_snapshots');
    }
};
