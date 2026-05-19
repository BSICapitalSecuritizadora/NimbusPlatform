<?php

use App\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
