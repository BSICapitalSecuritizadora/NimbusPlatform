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
        Schema::create('sales_board_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_board_id')->constrained()->cascadeOnDelete();
            $table->date('reference_month');
            $table->unsignedInteger('stock_units');
            $table->unsignedInteger('financed_units');
            $table->unsignedInteger('paid_units');
            $table->unsignedInteger('exchanged_units');
            $table->unsignedInteger('total_units');
            $table->decimal('stock_value', 15, 2);
            $table->decimal('financed_value', 15, 2);
            $table->decimal('paid_value', 15, 2);
            $table->decimal('exchanged_value', 15, 2);
            $table->timestamps();

            $table->index(['sales_board_id', 'reference_month']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_board_histories');
    }
};
