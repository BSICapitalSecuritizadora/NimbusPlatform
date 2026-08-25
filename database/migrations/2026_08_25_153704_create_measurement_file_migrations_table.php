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
        Schema::create('measurement_file_migrations', function (Blueprint $table) {
            $table->id();
            $table->string('migratable_type');
            $table->unsignedBigInteger('migratable_id');
            $table->string('file_role', 32);
            $table->string('source_disk', 32);
            $table->string('source_path', 500);
            $table->char('source_sha256', 64);
            $table->string('destination_disk', 32);
            $table->string('destination_path', 500);
            $table->string('recovery_path', 500);
            $table->string('state', 32);
            $table->text('last_error')->nullable();
            $table->timestamp('prepared_at')->nullable();
            $table->timestamp('switched_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('cleaned_at')->nullable();
            $table->timestamps();

            $table->unique(['migratable_type', 'migratable_id', 'file_role'], 'measurement_file_migrations_subject_unique');
            $table->index(['state', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('measurement_file_migrations');
    }
};
