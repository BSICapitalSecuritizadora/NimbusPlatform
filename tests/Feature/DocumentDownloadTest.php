<?php

use App\Models\Document;
use App\Models\Investor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake(config('filesystems.default'));
});

it('allows investor to download a document they have access to', function () {
    $investor = Investor::factory()->create();
    $document = Document::factory()->published()->create();
    $document->investors()->attach($investor);

    Storage::disk(config('filesystems.default'))->put($document->file_path, 'fake-content');

    $response = $this->actingAs($investor, 'investor')
        ->get(route('investor.documents.download', $document));

    $response->assertRedirect();
});

it('forbids investor from downloading a document they do not have access to', function () {
    $investor = Investor::factory()->create();
    $document = Document::factory()->published()->create(['is_public' => false]);

    Storage::disk(config('filesystems.default'))->put($document->file_path, 'fake-content');

    $response = $this->actingAs($investor, 'investor')
        ->get(route('investor.documents.download', $document));

    $response->assertForbidden();
});

it('redirects unauthenticated user when attempting download', function () {
    $document = Document::factory()->published()->create();

    $response = $this->get(route('investor.documents.download', $document));

    $response->assertRedirect();
});
