<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nimbus_migration_maps', function (Blueprint $table) {
            $table->id();
            $table->string('source_system', 50)->default('nimbusdocs');
            $table->string('source_entity', 80);
            $table->string('source_id', 80);
            $table->string('source_role', 40)->default('PRIMARY');
            $table->string('target_entity', 80);
            $table->string('target_role', 40)->default('PRIMARY');
            $table->string('target_id', 80);
            $table->string('source_fingerprint', 128)->nullable();
            $table->unsignedBigInteger('first_migration_run_id')->nullable();
            $table->unsignedBigInteger('last_verified_run_id')->nullable();
            $table->string('status', 40)->default('MIGRATED');
            $table->timestamps();

            // Canonical identity — cross-run, no nullable columns in unique
            $table->unique(
                ['source_system', 'source_entity', 'source_id', 'source_role', 'target_entity', 'target_role'],
                'nmm_canonical_unique'
            );
            $table->index(['target_entity', 'target_id'], 'nmm_canonical_target_idx');
            $table->index('source_fingerprint', 'nmm_canonical_fp_idx');

            $table->foreign('first_migration_run_id')->references('id')->on('nimbus_migration_runs')->nullOnDelete();
            $table->foreign('last_verified_run_id')->references('id')->on('nimbus_migration_runs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nimbus_migration_maps');
    }
};
