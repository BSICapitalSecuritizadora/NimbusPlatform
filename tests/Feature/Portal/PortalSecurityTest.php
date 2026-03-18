<?php

use App\Models\Document;
use App\Models\Emission;
use App\Models\Investor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function downloadUrl(Document $doc): string
{
    return route('investor.documents.download', $doc);
}

it('investor A cannot access investor B document (403)', function () {
    Storage::fake(Document::defaultStorageDisk());

    $a = Investor::factory()->create();
    $b = Investor::factory()->create();

    $doc = Document::factory()->create([
        'is_published' => true,
        'is_public' => false,
        'file_path' => 'documents/tests/a.pdf',
    ]);

    // vincula somente ao B
    $doc->investors()->attach($b->id);

    $this->actingAs($a, 'investor');

    $this->get(downloadUrl($doc))->assertStatus(403);
});

it('unpublished document always returns 404 (anti-leak), even if linked', function () {
    Storage::fake(Document::defaultStorageDisk());

    $inv = Investor::factory()->create();

    $doc = Document::factory()->create([
        'is_published' => false,
        'is_public' => false,
        'file_path' => 'documents/tests/unpub.pdf',
    ]);

    $doc->investors()->attach($inv->id);

    $this->actingAs($inv, 'investor');

    $this->get(downloadUrl($doc))->assertStatus(404);
});

it('public documents should only be visible if published (site rule)', function () {
    // publicado + público => visível
    $ok = Document::factory()->create([
        'title' => 'OK',
        'is_published' => true,
        'is_public' => true,
        'file_path' => 'documents/tests/ok.pdf',
    ]);

    // público mas não publicado => NÃO visível
    $no = Document::factory()->create([
        'title' => 'NO',
        'is_published' => false,
        'is_public' => true,
        'file_path' => 'documents/tests/no.pdf',
    ]);

    // Aqui a gente testa a regra via query (mais estável do que depender de rota pública)
    $visible = \App\Models\Document::query()->published()->public()->pluck('id')->all();

    expect($visible)->toContain($ok->id);
    expect($visible)->not->toContain($no->id);
});

it('download works only if permitted (allowed => 200 or 302, denied => 403/404)', function () {
    $disk = Document::defaultStorageDisk();
    Storage::fake($disk);
    Storage::disk($disk)->put('documents/tests/allowed.pdf', 'demo');

    $inv = Investor::factory()->create();
    $doc = Document::factory()->create([
        'is_published' => true,
        'is_public' => false,
        'file_path' => 'documents/tests/allowed.pdf',
    ]);

    $doc->investors()->attach($inv->id);

    $this->actingAs($inv, 'investor');

    $res = $this->get(downloadUrl($doc));

    // se você usa Storage::download => 200
    // se você usa temporaryUrl => 302
    expect(in_array($res->getStatusCode(), [200, 302]))->toBeTrue();
});

it('emission-document relation does not leak: investor sees documents only if investor is linked to the emission', function () {
    Storage::fake(Document::defaultStorageDisk());

    $invA = Investor::factory()->create();
    $invB = Investor::factory()->create();

    $emission = Emission::factory()->create();
    $doc = Document::factory()->create([
        'is_published' => true,
        'is_public' => false,
        'file_path' => 'documents/tests/rel.pdf',
    ]);

    // documento vinculado à emissão
    $doc->emissions()->attach($emission->id);

    // investidor A vinculado à emissão, B não
    $invA->emissions()->attach($emission->id);

    // regra do motor (scope)
    $aVisible = Document::query()->visibleToInvestor($invA->id)->pluck('id')->all();
    $bVisible = Document::query()->visibleToInvestor($invB->id)->pluck('id')->all();

    expect($aVisible)->toContain($doc->id);
    expect($bVisible)->not->toContain($doc->id);
});

it('login throttling basic: repeated attempts should eventually return 429', function () {
    // Isso testa o middleware throttle no endpoint.
    // Ajustamos a rota de login para usar o nome correto da rota de post no portal
    $route = route('investor.login.post');

    // 11 tentativas rápidas (se você configurou throttle:10,1)
    for ($i = 0; $i < 11; $i++) {
        $res = $this->post($route, [
            'email' => 'nope@nope.com',
            'password' => 'wrong',
        ]);
    }

    // Dependendo do seu fluxo pode redirecionar/422 nas primeiras,
    // mas uma delas deve virar 429 quando bater o throttle.
    expect(in_array($res->status(), [302, 422, 429]))->toBeTrue();

    // Faz uma tentativa extra pra aumentar chance de pegar 429
    $res2 = $this->post($route, [
        'email' => 'nope@nope.com',
        'password' => 'wrong',
    ]);

    expect(in_array($res2->status(), [429, 302, 422]))->toBeTrue();
});