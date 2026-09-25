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
        Schema::create('measurement_financial_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('emission_id')->constrained()->restrictOnDelete();
            $table->foreignId('construction_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('name');
            $table->text('description');
            $table->string('direction', 8);
            $table->decimal('maximum_difference_amount', 18, 2)->nullable();
            $table->decimal('maximum_difference_percent', 10, 4)->nullable();
            $table->boolean('requires_document')->default(false);
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('supersedes_id')->nullable()->unique('financial_rule_predecessor_unique')
                ->constrained('measurement_financial_rules', indexName: 'financial_rule_predecessor_fk')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('retired_at')->nullable();
            $table->index(['emission_id', 'construction_id', 'effective_from'], 'financial_rules_scope_index');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('measurement_financial_rules');
    }
};
