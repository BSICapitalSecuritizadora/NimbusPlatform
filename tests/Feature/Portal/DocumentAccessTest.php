<?php

use App\Models\Document;
use App\Models\Investor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function portalDownloadUrl(Document $doc): string {
    return "/investidor/documentos/{$doc->id}/download";
}

it('blocks investor A from downloading document linked only to investor B (403)', function () {
    Storage::fake(); // evita acessar filesystem real

    $a = Investor::factory()->create(['email' => 'a@test.com']);
    $b = Investor::factory()->create(['email' => 'b@test.com']);

    $doc = Document::factory()->create([
        'is_published' => true,
        'is_public' => false,
        'file_path' => 'documents/tests/a.pdf',
    ]);

    // Vincula somente ao investidor B
    $doc->investors()->attach($b->id);

    // Loga como investidor A
    $this->actingAs($a, 'investor');

    $this->get(portalDownloadUrl($doc))
        ->assertStatus(403);
});

it('returns 404 for unpublished documents even if linked (anti-leak)', function () {
    Storage::fake();

    $inv = Investor::factory()->create();
    $doc = Document::factory()->create([
        'is_published' => false,
        'is_public' => false,
        'file_path' => 'documents/tests/unpub.pdf',
    ]);

    $doc->investors()->attach($inv->id);

    $this->actingAs($inv, 'investor');

    $this->get(portalDownloadUrl($doc))
        ->assertStatus(404);
});

it('allows investor to download published document linked directly (200 or redirect)', function () {
    Storage::fake();
    Storage::put('documents/tests/ok.pdf', 'demo'); // simula arquivo

    $inv = Investor::factory()->create();
    $doc = Document::factory()->create([
        'is_published' => true,
        'is_public' => false,
        'file_path' => 'documents/tests/ok.pdf',
    ]);

    $doc->investors()->attach($inv->id);

    $this->actingAs($inv, 'investor');

    $res = $this->get(portalDownloadUrl($doc));

    // depende da sua implementação:
    // - download() => 200
    // - temporaryUrl() => redirect 302
    expect(in_array($res->status(), [200, 302]))->toBeTrue();
});

it('allows public+published documents in portal only if your rule permits', function () {
    Storage::fake();
    Storage::put('documents/tests/pub.pdf', 'demo');

    $inv = Investor::factory()->create();
    $doc = Document::factory()->create([
        'is_published' => true,
        'is_public' => true,
        'file_path' => 'documents/tests/pub.pdf',
    ]);

    $this->actingAs($inv, 'investor');
    $res = $this->get(portalDownloadUrl($doc));

    // Se no seu ACL você permite "public" no portal, deve ser 200/302.
    // Se NÃO permite, deve ser 403.
    expect(in_array($res->status(), [200, 302, 403]))->toBeTrue();
});

it('does not allow guest to access portal download route', function () {
    $doc = Document::factory()->create(['is_published' => true]);

    $this->get(portalDownloadUrl($doc))
        ->assertRedirect(); // normalmente redireciona para login do portal
});