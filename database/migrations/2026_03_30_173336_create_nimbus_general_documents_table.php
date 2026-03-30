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
        Schema::create('nimbus_document_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->timestamps();
        });

        Schema::create('nimbus_general_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nimbus_category_id')->constrained('nimbus_document_categories')->cascadeOnDelete();
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('file_path', 500);
            $table->string('file_original_name', 255);
            $table->unsignedInteger('file_size');
            $table->string('file_mime', 120);
            $table->boolean('is_active')->default(true);
            $table->dateTime('published_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nimbus_general_documents');
        Schema::dropIfExists('nimbus_document_categories');
    }
};
