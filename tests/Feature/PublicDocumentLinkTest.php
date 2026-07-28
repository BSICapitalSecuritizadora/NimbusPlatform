<?php

use App\Models\Document;
use App\Models\Emission;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * O controller de download já era coberto por SiteDocumentDownloadTest, mas nada
 * garantia que as páginas públicas apontassem para ele. Foi por essa lacuna que os
 * links diretos para o disco (que resultavam em 403) passaram sem ser notados.
 */
it('links investor relations documents through the controlled download route', function () {
    $document = Document::factory()->public()->create([
        'title' => 'Fato Relevante',
        'category' => 'fatos_relevantes',
        'file_path' => 'documents/01RIDOC.pdf',
    ]);

    $this->get(route('site.ri'))
        ->assertOk()
        ->assertSee('href="'.route('site.documents.download', $document).'"', false)
        ->assertDontSee('/storage/documents/01RIDOC.pdf', false);
});

it('never exposes a raw storage path for documents on the emission detail page', function () {
    $emission = Emission::factory()->active()->create([
        'if_code' => 'IF-NO-RAW-PATH',
        'is_public' => true,
    ]);

    $document = Document::factory()->public()->create([
        'category' => 'documentos_operacao',
        'file_path' => 'documents/01RAWPATH.pdf',
    ]);

    $emission->documents()->attach($document->id);

    $this->get(route('site.emissions.show', $emission->if_code))
        ->assertOk()
        ->assertDontSee('01RAWPATH.pdf', false);
});
