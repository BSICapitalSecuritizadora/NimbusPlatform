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
        Schema::create('obligation_series_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('obligation_series_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->date('effective_from');
            $table->string('frequency');
            $table->string('due_rule_type')->nullable();
            $table->unsignedTinyInteger('due_day')->nullable();
            $table->smallInteger('due_offset_months')->default(0);
            $table->string('invalid_day_policy')->nullable();
            $table->string('calendar_code')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('change_reason')->nullable();
            $table->timestamps();

            $table->unique(['obligation_series_id', 'effective_from'], 'obligation_series_rule_effective_unique');
            $table->unique(['obligation_series_id', 'version'], 'obligation_series_rule_version_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('obligation_series_rules');
    }
};
