<?php

use App\Models\Vacancy;
use App\Services\Security\RichTextSanitizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('strips scripts and event handlers when saving a vacancy', function () {
    $vacancy = Vacancy::factory()->create([
        'description' => '<p>Vaga real</p><script>alert(1)</script>',
        'requirements' => '<p onclick="alert(1)">Requisito</p>',
        'benefits' => '<p>Benefício</p><iframe src="https://evil.example"></iframe>',
    ]);

    expect($vacancy->description)
        ->toContain('<p>Vaga real</p>')
        ->not->toContain('<script')
        ->and($vacancy->requirements)
        ->toContain('Requisito')
        ->not->toContain('onclick')
        ->and($vacancy->benefits)
        ->toContain('Benefício')
        ->not->toContain('<iframe');
});

it('does not deliver injected HTML on the public vacancy page, even for content already stored', function () {
    $vacancy = Vacancy::factory()->create(['is_active' => true]);

    // Bypasses the model setters to simulate rows persisted before sanitization on write.
    DB::table('vacancies')
        ->where('id', $vacancy->id)
        ->update([
            'description' => '<p>Descrição</p><script>alert(1)</script>',
            'requirements' => '<p>Requisitos</p><img src=x onerror="alert(2)">',
            'benefits' => '<p>Benefícios</p><a href="javascript:alert(3)">clique</a>',
        ]);

    $response = $this->get(route('site.vacancies.show', $vacancy->slug));
    $content = $response->getContent();

    $response->assertSuccessful()
        ->assertSee('<p>Descrição</p>', false)
        ->assertSee('<p>Requisitos</p>', false)
        ->assertSee('<p>Benefícios</p>', false);

    expect($content)
        ->not->toContain('<script>alert(1)</script>')
        ->not->toContain('onerror')
        ->not->toContain('javascript:alert(3)');
});

it('keeps the rich text formatting produced by the editor', function () {
    $html = '<p class="lead">Intro</p><h3>Título</h3><ul><li><strong>Item</strong></li></ul>'
        .'<blockquote>Citação</blockquote><a href="https://bsicapital.com.br">site</a>';

    expect(RichTextSanitizer::sanitize($html))
        ->toContain('class="lead"')
        ->toContain('<h3>Título</h3>')
        ->toContain('<strong>Item</strong>')
        ->toContain('<blockquote>')
        ->toContain('href="https://bsicapital.com.br"');
});

it('renders the case study page without unescaped script tags', function () {
    $response = $this->get(route('site.cases.show', 'estruturacao-cri'));

    $response->assertSuccessful()
        ->assertSee('O Desafio', false)
        ->assertSee('class="lead"', false);
});

it('returns null for missing rich text instead of an empty tag soup', function () {
    expect(RichTextSanitizer::sanitize(null))->toBeNull();
});
