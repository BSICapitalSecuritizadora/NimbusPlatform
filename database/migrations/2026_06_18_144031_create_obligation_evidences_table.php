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
        Schema::create('obligation_evidences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('obligation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('emission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('original_name');
            $table->string('path');
            $table->string('disk')->default('local');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->text('description')->nullable();
            $table->timestamp('uploaded_at');
            $table->timestamps();
            $table->softDeletes();

            $table->index('obligation_id');
            $table->index('emission_id');
            $table->index('uploaded_by');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('obligation_evidences');
    }
};
