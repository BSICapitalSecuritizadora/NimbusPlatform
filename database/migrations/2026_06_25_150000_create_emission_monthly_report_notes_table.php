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
        Schema::create('emission_monthly_report_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('emission_id')->constrained()->cascadeOnDelete();
            $table->date('reference_month');
            $table->string('category')->nullable();
            $table->string('title')->nullable();
            $table->text('content');
            $table->boolean('is_visible_on_report')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['emission_id', 'reference_month']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('emission_monthly_report_notes');
    }
};
