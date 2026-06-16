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
        Schema::create('obligations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('emission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('extracted_obligation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->string('obligation_type')->nullable();
            $table->string('obligation_category')->nullable();
            $table->text('description')->nullable();
            $table->string('responsible_party')->nullable();
            $table->string('responsible_area')->nullable();
            $table->string('recurrence')->nullable();
            $table->string('due_rule')->nullable();
            $table->date('due_date')->nullable();
            $table->string('priority')->default('medium');
            $table->string('status')->default('em_dia');
            $table->text('required_evidence')->nullable();
            $table->string('source_clause')->nullable();
            $table->unsignedSmallInteger('source_page')->nullable();
            $table->text('source_excerpt')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['emission_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('obligations');
    }
};
