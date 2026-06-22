<?php

use App\Models\Document;
use App\Models\Emission;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders the revised institutional copy on the home page', function () {
    $this->get(route('site.home'))
        ->assertSuccessful()
        ->assertSeeText('Estruturação, emissão e gestão fiduciária de CRI, CRA e CR.')
        ->assertSeeText('Da estruturação à gestão: cobertura integral da operação')
        ->assertSeeText('Submeter Operação para Análise')
        ->assertSeeText('Ver Emissões')
        ->assertSeeText('Solicitar Análise de Estruturação')
        ->assertDontSeeText('Consultar Viabilidade')
        ->assertDontSeeText('Pipeline de Emissões')
        ->assertDontSeeText('Transparência e Mercado')
        ->assertDontSeeText('Explorar Emissões')
        ->assertDontSeeText('Portal de R.I.')
        ->assertDontSeeText('Divulgações ao mercado')
        ->assertSeeText('Relacionamento institucional')
        ->assertSeeText('Entre em contato com a BSI Capital');
});

it('renders the home hero video from a local project asset', function () {
    expect(public_path('videos/logo-animacao-bsi.mp4'))->toBeFile();

    $content = $this->get(route('site.home'))
        ->assertSuccessful()
        ->getContent();

    expect($content)->toContain(asset('videos/logo-animacao-bsi.mp4'))
        ->and($content)->not->toContain('https://opea.com.br/wp-content/themes/opeacapital/assets/video/nova_intro.mp4');
});

it('does not render emissions or ri snippets on the home page', function () {
    Emission::factory()->active()->create([
        'is_public' => true,
        'name' => 'Emissão Home Teste',
    ]);

    Document::factory()->public()->create([
        'title' => 'Documento Home Teste',
        'published_at' => '2026-04-13 15:00:00',
        'category' => 'fatos_relevantes',
    ]);

    $this->get(route('site.home'))
        ->assertSuccessful()
        ->assertDontSeeText('Emissão Home Teste')
        ->assertDontSeeText('Documento Home Teste')
        ->assertDontSeeText('Transparência e Mercado')
        ->assertDontSeeText('Divulgações ao mercado');
});
