<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nimbus_migration_run_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('migration_run_id')->constrained('nimbus_migration_runs')->cascadeOnDelete();
            $table->string('source_system', 50)->default('nimbusdocs');
            $table->string('source_entity', 80);
            $table->string('source_id', 80);
            $table->string('target_entity', 80)->default('NO_TARGET');
            $table->string('target_role', 40)->default('PRIMARY');
            $table->string('source_fingerprint', 128)->nullable();
            $table->string('status', 40);
            $table->string('disposition', 255)->nullable();
            $table->string('error_code', 80)->nullable();
            $table->json('metadata')->nullable();
            $table->string('legacy_sha256', 128)->nullable();
            $table->string('actual_source_sha256', 128)->nullable();
            $table->string('target_sha256', 128)->nullable();
            $table->string('target_id', 80)->nullable();
            $table->timestamps();

            // Per-run uniqueness — never relies on NULL (target_entity/role are NOT NULL)
            $table->unique(
                ['migration_run_id', 'source_system', 'source_entity', 'source_id', 'target_entity', 'target_role'],
                'nmri_unique_per_run'
            );
            $table->index(['source_system', 'source_entity', 'source_id'], 'nmri_source_idx');
            $table->index(['target_entity', 'target_id'], 'nmri_target_idx');
            $table->index('status', 'nmri_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nimbus_migration_run_items');
    }
};
