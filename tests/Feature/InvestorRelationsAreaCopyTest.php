<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Schema::dropIfExists('documents');

    Schema::create('documents', function (Blueprint $table): void {
        $table->id();
        $table->string('title')->nullable();
        $table->string('category')->nullable();
        $table->string('file_path')->nullable();
        $table->string('file_name')->nullable();
        $table->string('mime_type')->nullable();
        $table->unsignedBigInteger('file_size')->nullable();
        $table->string('storage_disk')->nullable();
        $table->boolean('is_published')->default(false);
        $table->boolean('is_public')->default(false);
        $table->string('version')->nullable();
        $table->unsignedBigInteger('parent_document_id')->nullable();
        $table->timestamp('replaced_at')->nullable();
        $table->timestamp('published_at')->nullable();
        $table->unsignedBigInteger('published_by')->nullable();
        $table->timestamps();
    });
});

it('renders the revised investor relations copy', function () {
    $this->get(route('site.ri'))
        ->assertSuccessful()
        ->assertSee('Relações com Investidores')
        ->assertSee('Pesquisar documentos e comunicados...')
        ->assertSee('Canal de contato com investidores')
        ->assertSee('Nenhum documento foi localizado.');
});
