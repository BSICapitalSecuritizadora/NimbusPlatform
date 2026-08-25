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
        Schema::create('business_calendar_legal_rules', function (Blueprint $table) {
            $table->id();
            $table->string('calendar_code', 20);
            $table->string('rule_key', 100);
            $table->string('rule_type', 50);
            $table->string('name');
            $table->unsignedTinyInteger('month')->nullable();
            $table->unsignedTinyInteger('day')->nullable();
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->string('norm_identification', 120);
            $table->string('article_reference', 80);
            $table->text('source_url');
            $table->char('source_fingerprint', 64);
            $table->timestamp('verified_at');
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['calendar_code', 'rule_key'], 'business_calendar_legal_rules_key_unique');
            $table->index(
                ['calendar_code', 'rule_type', 'effective_from', 'effective_until'],
                'business_calendar_legal_rules_effective_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('business_calendar_legal_rules');
    }
};
