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
        Schema::create('obligation_series', function (Blueprint $table) {
            $table->id();
            $table->foreignId('emission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('extracted_obligation_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('document_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->string('obligation_type')->nullable();
            $table->string('obligation_category')->nullable();
            $table->text('description')->nullable();
            $table->string('responsible_party')->nullable();
            $table->string('responsible_area')->nullable();
            $table->string('priority')->default('medium');
            $table->text('required_evidence')->nullable();
            $table->text('due_rule')->nullable();
            $table->text('source_clause')->nullable();
            $table->unsignedSmallInteger('source_page')->nullable();
            $table->text('source_excerpt')->nullable();
            $table->string('frequency')->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->string('due_rule_type')->nullable();
            $table->unsignedTinyInteger('due_day')->nullable();
            $table->smallInteger('due_offset_months')->default(0);
            $table->string('invalid_day_policy')->nullable();
            $table->string('calendar_code')->nullable();
            $table->unsignedSmallInteger('generation_horizon_days')->default(90);
            $table->string('status')->default('awaiting_configuration');
            $table->boolean('is_legacy_backfill')->default(false);
            $table->timestamp('configuration_confirmed_at')->nullable();
            $table->foreignId('configuration_confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paused_at')->nullable();
            $table->foreignId('paused_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('pause_reason')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('close_reason')->nullable();
            $table->timestamps();

            $table->index(['emission_id', 'status'], 'obligation_series_emission_status_index');
            $table->index(['status', 'ends_on'], 'obligation_series_status_end_index');
            $table->index(['frequency', 'status'], 'obligation_series_frequency_status_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('obligation_series');
    }
};
