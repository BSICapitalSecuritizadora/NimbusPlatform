<?php

use App\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('public site should only list published+public documents', function () {
    $pub = Document::factory()->create(['is_published' => true, 'is_public' => true, 'title' => 'OK']);
    Document::factory()->create(['is_published' => false, 'is_public' => true, 'title' => 'NO']); // não publicado
    Document::factory()->create(['is_published' => true, 'is_public' => false, 'title' => 'NO']); // não público

    // Ajuste a rota para a sua página pública real
    $response = $this->get('/documentos-publicos');

    // Se sua página renderiza títulos
    $response->assertSee('OK');
    $response->assertDontSee('NO');
});
