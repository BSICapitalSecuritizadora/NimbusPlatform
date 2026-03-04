<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emission_document', function (Blueprint $table) {
            $table->id();

            $table->foreignId('emission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();

            $table->timestamps();

            $table->unique(['emission_id', 'document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emission_document');
    }
};