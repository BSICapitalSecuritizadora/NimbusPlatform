<?php

use App\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('public site should only list published+public documents', function () {
    Document::factory()->create([
        'is_published' => true,
        'is_public' => true,
        'title' => 'OK',
        'category' => 'fatos_relevantes',
    ]);
    Document::factory()->create(['is_published' => false, 'is_public' => true, 'title' => 'NO']); // não publicado
    Document::factory()->create(['is_published' => true, 'is_public' => false, 'title' => 'NO']); // não público

    $this->get('/documentos-publicos')
        ->assertRedirect(route('site.ri'));

    $this->followingRedirects()
        ->get('/documentos-publicos')
        ->assertSee('OK')
        ->assertDontSee('NO');
});
