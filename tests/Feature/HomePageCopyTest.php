<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders the revised institutional copy on the home page', function () {
    $this->get(route('site.home'))
        ->assertSuccessful()
        ->assertSee('Estruturação, gestão e acompanhamento de operações com padrão institucional.')
        ->assertSee('Soluções estruturadas por setor')
        ->assertSee('Estruturas desenhadas para operações reais')
        ->assertSee('Receba atualizações institucionais');
});
