<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nimbus_submission_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nimbus_submission_id')
                ->constrained('nimbus_submissions')
                ->cascadeOnDelete();

            // Nullable old_status for initial creation record.
            $table->string('old_status', 50)->nullable();
            $table->string('new_status', 50);
            $table->string('actor_type', 100)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->index('nimbus_submission_id', 'nsh_submission_id_index');
            $table->index(['nimbus_submission_id', 'created_at'], 'nsh_submission_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nimbus_submission_status_histories');
    }
};
