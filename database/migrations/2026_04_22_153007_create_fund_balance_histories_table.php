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
        Schema::create('fund_balance_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fund_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->decimal('balance', 15, 2);
            $table->timestamps();

            $table->unique(['fund_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fund_balance_histories');
    }
};
