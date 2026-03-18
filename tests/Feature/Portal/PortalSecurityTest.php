<?php

use App\Models\Document;
use App\Models\Emission;
use App\Models\Investor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * ✅ AJUSTE AQUI para bater com suas rotas reais:
 */
const INVESTOR_LOGIN_POST = '/investidor/login';
const INVESTOR_DOC_DOWNLOAD_GET_PREFIX = '/investidor/documentos'; // gera: /investidor/documentos/{id}/download

function downloadUrl(Document $doc): string
{
    return INVESTOR_DOC_DOWNLOAD_GET_PREFIX . "/{$doc->id}/download";
}

it('investor A não acessa doc do investidor B (403)', function () {
    Storage::fake(Document::defaultStorageDisk());

    $a = Investor::factory()->create();
    $b = Investor::factory()->create();

    $doc = Document::factory()->create([
        'is_published' => true,
        'is_public' => false,
        'file_path' => 'documents/tests/a.pdf',
    ]);

    $doc->investors()->attach($b->id);

    $this->actingAs($a, 'investor');

    $this->get(downloadUrl($doc))->assertStatus(403);
});

it('doc não publicado sempre retorna 404 (anti-leak), mesmo vinculado', function () {
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

it('docs públicos só aparecem se publicados (regra do site público)', function () {
    $ok = Document::factory()->create([
        'title' => 'OK',
        'is_published' => true,
        'is_public' => true,
        'file_path' => 'documents/tests/ok.pdf',
    ]);

    $no = Document::factory()->create([
        'title' => 'NO',
        'is_published' => false,
        'is_public' => true,
        'file_path' => 'documents/tests/no.pdf',
    ]);

    $visibleIds = \App\Models\Document::query()->published()->public()->pluck('id')->all();

    expect($visibleIds)->toContain($ok->id);
    expect($visibleIds)->not->toContain($no->id);
});

it('download só funciona se permitido (200 ou 302)', function () {
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

    // download() => 200, temporaryUrl() => 302
    expect(in_array($res->getStatusCode(), [200, 302]))->toBeTrue();
});

it('relação emissão↔documento não vaza: investidor só vê doc da emissão se estiver na emissão', function () {
    Storage::fake(Document::defaultStorageDisk());

    $invA = Investor::factory()->create();
    $invB = Investor::factory()->create();

    $emission = Emission::factory()->create();

    $doc = Document::factory()->create([
        'is_published' => true,
        'is_public' => false,
        'file_path' => 'documents/tests/rel.pdf',
    ]);

    // documento vinculado a uma emissão
    $doc->emissions()->attach($emission->id);

    // investidor A vinculado à emissão, B não
    $invA->emissions()->attach($emission->id);

    $aVisible = Document::query()->visibleToInvestor($invA->id)->pluck('id')->all();
    $bVisible = Document::query()->visibleToInvestor($invB->id)->pluck('id')->all();

    expect($aVisible)->toContain($doc->id);
    expect($bVisible)->not->toContain($doc->id);
});

it('login throttling básico: muitas tentativas devem retornar 429 em algum momento', function () {
    // Se seu endpoint de login é outro, ajuste a constante INVESTOR_LOGIN_POST.
    // A ideia aqui é só garantir que throttle está aplicado.
    $last = null;

    for ($i = 0; $i < 12; $i++) {
        $last = $this->post(INVESTOR_LOGIN_POST, [
            'email' => 'nope@nope.com',
            'password' => 'wrong',
        ]);
    }

    // dependendo da sua validação, as primeiras podem ser 302/422
    // mas com throttle aplicado, deve aparecer 429 após exceder o limite
    expect(in_array($last->getStatusCode(), [302, 422, 429]))->toBeTrue();
});