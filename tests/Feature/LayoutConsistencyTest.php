<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

dataset('public-layout-routes', [
    'home' => [
        'site.home',
        [
            'Securitização e crédito estruturado com rigor técnico, governança e acompanhamento ponta a ponta.',
            'Atuação por setor, com aderência ao ativo e à operação',
            'Da estruturação à gestão: cobertura integral da operação',
        ],
    ],
    'services' => [
        'site.services',
        [
            'Uma plataforma de serviços para cada etapa da operação',
            'Converse com a BSI',
        ],
    ],
    'contact' => [
        'site.contact',
        [
            'Atendimento institucional',
            'Atendimento claro e direcionado à área responsável',
            'São Paulo',
        ],
    ],
    'emissions' => [
        'site.emissions',
        [
            'Mercado primário',
            'Tipo de emissão',
        ],
    ],
    'investor-relations' => [
        'site.ri',
        [
            'Relações com Investidores',
            'Repositório Institucional',
            'Precisa de apoio sobre documentos públicos ou comunicados?',
        ],
    ],
    'careers' => [
        'site.vacancies.index',
        [
            'Integre o time da',
            'Nossas vagas são abertas sob demanda técnica pontual.',
        ],
    ],
]);

it('renders the unified public layout across key public experiences', function (string $routeName, array $expectedStrings) {
    $response = $this->get(route($routeName));

    $response
        ->assertSuccessful()
        ->assertSeeText('BSI Capital');

    foreach ($expectedStrings as $expectedString) {
        $response->assertSeeText($expectedString);
    }
})->with('public-layout-routes');

it('renders the branded internal dashboard experience for authenticated users', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSeeText('Ambiente interno')
        ->assertSeeText('Operação clara')
        ->assertSeeText('Enviar proposta')
        ->assertSeeText('Relações com investidores');
});

it('renders the public layout without dark mode assets or theme scripts', function () {
    $this->get(route('site.home'))
        ->assertSuccessful()
        ->assertDontSee('prefers-color-scheme')
        ->assertDontSee('data-theme')
        ->assertDontSee('window.BSITheme')
        ->assertDontSee('bsi_theme')
        ->assertDontSee('brand-dark')
        ->assertDontSee('anbima-dark')
        ->assertSee('Selo ANBIMA Securitizadora');
});
