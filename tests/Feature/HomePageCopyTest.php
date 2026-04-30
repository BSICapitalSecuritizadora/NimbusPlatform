<?php

use App\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders the revised institutional copy on the home page', function () {
    $this->get(route('site.home'))
        ->assertSuccessful()
        ->assertSeeText('Securitização e crédito estruturado com excelência técnica, governança rigorosa e presença ativa em todo o ciclo de vida da operação.')
        ->assertSeeText('Da estruturação à gestão: cobertura em todas as fases')
        ->assertSeeText('Relacionamento institucional')
        ->assertSeeText('Entre em contato com a BSI Capital');
});

it('shows the five most recent public ri documents on the home page', function () {
    collect([
        ['title' => 'Comunicado Ao Mercado', 'published_at' => '2026-04-13 15:00:00', 'category' => 'fatos_relevantes'],
        ['title' => '2025', 'published_at' => '2026-04-13 14:00:00', 'category' => 'demonstracoes_financeiras'],
        ['title' => '2024', 'published_at' => '2026-04-13 13:00:00', 'category' => 'demonstracoes_financeiras'],
        ['title' => 'Relatório Mensal', 'published_at' => '2026-04-13 12:00:00', 'category' => 'relatorios_anuais'],
        ['title' => 'Ata de Assembleia', 'published_at' => '2026-04-13 11:00:00', 'category' => 'assembleias'],
        ['title' => 'Documento Antigo', 'published_at' => '2026-04-13 10:00:00', 'category' => 'societarios'],
    ])->each(function (array $document): void {
        Document::factory()->public()->create($document);
    });

    $this->get(route('site.home'))
        ->assertSuccessful()
        ->assertSeeText('Comunicado Ao Mercado')
        ->assertSeeText('2025')
        ->assertSeeText('2024')
        ->assertSeeText('Relatório Mensal')
        ->assertSeeText('Ata de Assembleia')
        ->assertDontSeeText('Documento Antigo');
});
