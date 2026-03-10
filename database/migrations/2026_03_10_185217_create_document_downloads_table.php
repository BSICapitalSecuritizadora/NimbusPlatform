<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_downloads', function (Blueprint $table) {
            $table->id();

            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('investor_id')->constrained()->cascadeOnDelete();

            $table->string('ip', 45)->nullable();         // IPv4/IPv6
            $table->text('user_agent')->nullable();
            $table->string('referer')->nullable();

            $table->timestamp('downloaded_at');

            // opcional: para auditoria/segurança
            $table->string('session_id')->nullable();

            $table->timestamps();

            $table->index(['investor_id', 'downloaded_at']);
            $table->index(['document_id', 'downloaded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_downloads');
    }
};
