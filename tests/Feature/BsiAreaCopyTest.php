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

it('renders the revised about copy on the bsi area page', function () {
    $this->get(route('site.about'))
        ->assertSuccessful()
        ->assertSee('Desde 2009, transformamos ativos em oportunidades estratégicas')
        ->assertSee('Missão, visão e valores')
        ->assertSee('Ética inegociável');
});

it('renders the revised governance copy on the bsi area page', function () {
    $this->get(route('site.governance'))
        ->assertSuccessful()
        ->assertSee('Nossa governança combina estrutura decisória, controles internos e disciplina regulatória')
        ->assertSee('Regras e Procedimentos Internos')
        ->assertSee('Mitigação de Riscos');
});

it('renders the revised compliance copy on the bsi area page', function () {
    $this->get(route('site.compliance'))
        ->assertSuccessful()
        ->assertSee('Pilares do Nosso Compliance')
        ->assertSee('Tratamento confidencial')
        ->assertSee('Canal de Denúncia');
});
