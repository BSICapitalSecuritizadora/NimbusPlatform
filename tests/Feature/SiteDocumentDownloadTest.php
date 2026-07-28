<?php

use App\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $disk = Document::defaultStorageDisk();

    Storage::set($disk, Storage::createLocalDriver([
        'root' => storage_path('framework/testing/disks/'.$disk.'-'.uniqid()),
        'throw' => false,
    ]));
});

it('downloads a public published document', function () {
    $document = Document::factory()->public()->create(['category' => 'governanca']);
    Storage::disk($document->resolved_storage_disk)->put($document->file_path, 'fake-pdf-content');

    $response = $this->get(route('site.documents.download', $document));

    $response->assertSuccessful();
    $response->assertHeader('Content-Disposition');
});

it('returns 404 for a document that is published but not public', function () {
    $document = Document::factory()->published()->create(['is_public' => false]);
    Storage::disk($document->resolved_storage_disk)->put($document->file_path, 'fake-pdf-content');

    $response = $this->get(route('site.documents.download', $document));

    $response->assertNotFound();
});

it('returns 404 for an unpublished document', function () {
    $document = Document::factory()->unpublished()->create();
    Storage::disk($document->resolved_storage_disk)->put($document->file_path, 'fake-pdf-content');

    $response = $this->get(route('site.documents.download', $document));

    $response->assertNotFound();
});

it('returns 404 when the file does not exist on disk', function () {
    $document = Document::factory()->public()->create(['category' => 'governanca']);

    $response = $this->get(route('site.documents.download', $document));

    $response->assertNotFound();
});

it('preserves the original file name and extension on download', function () {
    $document = Document::factory()->public()->create([
        'category' => 'governanca',
        'file_path' => 'documents/01ABCDEF.pdf',
        'file_name' => 'Termo de Securitização - 1ª Série.pdf',
    ]);
    Storage::disk($document->resolved_storage_disk)->put($document->file_path, 'fake-pdf-content');

    $response = $this->get(route('site.documents.download', $document));

    $response->assertSuccessful();

    /**
     * O nome acentuado viaja no parâmetro filename* (RFC 5987), que é o que os
     * navegadores usam; filename permanece como fallback ASCII. A extensão .pdf
     * é preservada nos dois.
     */
    expect($response->headers->get('Content-Disposition'))
        ->toContain('attachment')
        ->toContain('filename="Termo de Securitizacao - 1a Serie.pdf"')
        ->toContain("filename*=utf-8''".rawurlencode('Termo de Securitização - 1ª Série.pdf'));
});

it('returns 404 when the stored path is empty', function () {
    $document = Document::factory()->public()->create(['category' => 'governanca', 'file_path' => '']);

    $this->get(route('site.documents.download', $document))->assertNotFound();
});

it('logs the download context without leaking file contents when the file is missing', function () {
    Log::spy();

    $document = Document::factory()->public()->create(['category' => 'governanca']);

    $this->get(route('site.documents.download', $document))->assertNotFound();

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(function (string $message, array $context) use ($document): bool {
            return $context['document_id'] === $document->id
                && $context['reason'] === 'arquivo_ausente_no_disco'
                && $context['disk'] === $document->resolved_storage_disk
                && $context['relative_path'] === $document->file_path
                && $context['file_exists'] === false
                && $context['authorized'] === true
                && $context['environment'] === 'testing';
        });
});

it('logs an unauthorized attempt for a document that is not public', function () {
    Log::spy();

    $document = Document::factory()->published()->create(['is_public' => false]);

    $this->get(route('site.documents.download', $document))->assertNotFound();

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            return $context['reason'] === 'documento_nao_visivel_no_site_publico'
                && $context['authorized'] === false;
        });
});
