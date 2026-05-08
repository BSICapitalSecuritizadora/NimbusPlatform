<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receivables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('emission_id')->constrained()->cascadeOnDelete();
            $table->date('reference_month');
            $table->string('project');
            $table->string('block_or_quadra');
            $table->string('unit', 50);
            $table->string('customer_name');
            $table->string('customer_tax_id', 32);
            $table->string('eligibility', 50);
            $table->date('due_date');
            $table->decimal('corrected_amount', 15, 2);
            $table->decimal('penalty_amount', 15, 2)->default(0);
            $table->decimal('interest_amount', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->date('credit_date');
            $table->decimal('paid_amount', 15, 2);
            $table->string('fingerprint', 64)->unique();
            $table->timestamps();

            $table->index(['emission_id', 'reference_month']);
            $table->index(['credit_date', 'due_date']);
            $table->index('customer_tax_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receivables');
    }
};
