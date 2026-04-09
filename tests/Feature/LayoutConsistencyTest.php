<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

dataset('public-layout-routes', [
    'home' => [
        'site.home',
        [
            'Envie sua proposta',
            'Securitização e crédito estruturado com rigor técnico, governança e presença ativa ao longo de toda a operação.',
            'Atuação por setor, com aderência ao ativo e à operação',
        ],
    ],
    'services' => [
        'site.services',
        [
            'Uma plataforma de serviços para cada etapa da operação',
            'Converse com a BSI sobre o desenho da sua operação',
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
            'Operações estruturadas e coordenadas pela BSI Capital',
            'Tipo de emissão',
        ],
    ],
    'investor-relations' => [
        'site.ri',
        [
            'Relações com Investidores',
            'Documentos públicos organizados para consulta rápida',
            'Precisa de apoio sobre documentos públicos ou comunicados?',
        ],
    ],
    'careers' => [
        'site.vacancies.index',
        [
            'Construa sua trajetória com a BSI Capital',
            'No momento, não temos vagas abertas.',
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
        ->assertSeeText('Nova proposta')
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
