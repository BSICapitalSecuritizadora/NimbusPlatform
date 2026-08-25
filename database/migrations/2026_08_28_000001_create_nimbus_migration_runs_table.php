<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nimbus_migration_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('run_uuid')->unique();
            $table->string('source_system', 50)->default('nimbusdocs');
            $table->dateTime('source_snapshot_at')->nullable();
            $table->string('source_dump_sha256', 128)->nullable();
            $table->string('source_descriptor', 255)->nullable();
            $table->boolean('dry_run')->default(true);
            $table->string('status', 30)->default('PENDING');
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->json('summary')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('source_snapshot_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nimbus_migration_runs');
    }
};
