<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investor_document', function (Blueprint $table) {
            $table->id();

            $table->foreignId('investor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();

            $table->timestamps();

            $table->unique(['investor_id', 'document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investor_document');
    }
};
