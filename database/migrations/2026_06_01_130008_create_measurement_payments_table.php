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
        Schema::create('measurement_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('measurement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_set_id')->nullable()->constrained('measurement_plan_sets')->nullOnDelete();
            $table->date('pay_date');
            $table->decimal('amount', 18, 2);
            $table->string('method')->nullable();
            $table->text('notes')->nullable();
            $table->string('receipt_path', 500)->nullable();
            $table->timestamp('receipt_uploaded_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('operation_id');
            $table->index('measurement_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('measurement_payments');
    }
};
