<?php

use App\Models\Document;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('exposes the finalized document categories', function () {
    expect(Document::CATEGORY_OPTIONS)->toBe([
        'anuncios' => 'Anúncios',
        'assembleias' => 'Assembleias',
        'convocacoes_assembleias' => 'Convocações para Assembleias',
        'demonstracoes_financeiras' => 'Demonstrações Financeiras',
        'documentos_operacao' => 'Documentos da Operação',
        'fatos_relevantes' => 'Fatos Relevantes',
        'relatorios_anuais' => 'Relatórios Anuais',
    ]);
});

it('stores the finalized document defaults', function () {
    $document = Document::query()->create([
        'title' => 'Fato Relevante 001',
        'category' => 'fatos_relevantes',
        'file_path' => 'documents/fato-relevante-001.pdf',
    ]);

    $document->refresh();

    expect($document->is_published)->toBeFalse()
        ->and($document->is_public)->toBeFalse()
        ->and($document->storage_disk)->toBeNull()
        ->and($document->resolved_storage_disk)->toBe(Document::defaultStorageDisk())
        ->and($document->workflow_status_label)->toBe('Rascunho');
});

it('requires a category for documents', function () {
    expect(fn () => Document::query()->create([
        'title' => 'Documento sem categoria',
        'file_path' => 'documents/sem-categoria.pdf',
    ]))->toThrow(QueryException::class);
});

it('supports metadata workflow and publisher fields', function () {
    $publisher = User::factory()->create();

    $document = Document::query()->create([
        'title' => 'Comunicado ao Mercado',
        'category' => 'anuncios',
        'file_path' => 'documents/comunicado-mercado.pdf',
        'file_name' => 'comunicado-mercado.pdf',
        'mime_type' => 'application/pdf',
        'file_size' => 2048,
        'storage_disk' => 'public',
        'is_published' => true,
        'is_public' => true,
        'published_at' => '2026-03-16 12:34:56',
        'published_by' => $publisher->id,
    ]);

    $document->refresh();

    expect($document->category_label)->toBe('Anúncios')
        ->and($document->workflow_status_label)->toBe('Público')
        ->and($document->published_at?->format('Y-m-d H:i:s'))->toBe('2026-03-16 12:34:56')
        ->and($document->publisher?->is($publisher))->toBeTrue()
        ->and($document->only([
            'title',
            'category',
            'file_path',
            'file_name',
            'mime_type',
            'file_size',
            'storage_disk',
            'is_published',
            'is_public',
            'published_by',
        ]))->toMatchArray([
            'title' => 'Comunicado ao Mercado',
            'category' => 'anuncios',
            'file_path' => 'documents/comunicado-mercado.pdf',
            'file_name' => 'comunicado-mercado.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 2048,
            'storage_disk' => 'public',
            'is_published' => true,
            'is_public' => true,
            'published_by' => $publisher->id,
        ]);
});
