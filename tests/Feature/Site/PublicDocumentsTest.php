<?php

use App\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('public site should only list published+public documents', function () {
    Document::factory()->create([
        'is_published' => true,
        'is_public' => true,
        'title' => 'Documento Publico OK',
        'category' => 'fatos_relevantes',
    ]);
    Document::factory()->create([
        'is_published' => false,
        'is_public' => true,
        'title' => 'Documento Nao Publicado',
    ]);
    Document::factory()->create([
        'is_published' => true,
        'is_public' => false,
        'title' => 'Documento Privado Interno',
    ]);

    $this->get('/documentos-publicos')
        ->assertRedirect(route('site.ri'));

    $this->followingRedirects()
        ->get('/documentos-publicos')
        ->assertSeeText('Documento Publico OK')
        ->assertDontSeeText('Documento Nao Publicado')
        ->assertDontSeeText('Documento Privado Interno');
});

it('shows only the custom document summary on the investor relations paginator', function () {
    Document::factory()
        ->count(16)
        ->public()
        ->create([
            'category' => 'fatos_relevantes',
        ]);

    $this->get(route('site.ri'))
        ->assertSuccessful()
        ->assertSeeText('Exibindo 1 a 15 de 16 documentos')
        ->assertSee('site-pagination-mobile-list', false)
        ->assertSee('site-pagination-mobile-link', false)
        ->assertDontSeeText('resultados');
});
