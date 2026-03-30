<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders the revised institutional copy on the home page', function () {
    $this->get(route('site.home'))
        ->assertSuccessful()
        ->assertSeeText('Estruturas de crédito com execução institucional, governança operacional e acompanhamento contínuo.')
        ->assertSeeText('Soluções estruturadas por setor e perfil de operação')
        ->assertSeeText('Estruturas desenhadas para operações reais')
        ->assertSeeText('Fale com a BSI sobre sua operação ou sua agenda com investidores');
});
