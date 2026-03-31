<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders the revised institutional copy on the home page', function () {
    $this->get(route('site.home'))
        ->assertSuccessful()
        ->assertSeeText('Securitização e crédito estruturado com rigor técnico, governança e presença ativa ao longo de toda a operação.')
        ->assertSeeText('Atuação por setor, com aderência ao ativo e à operação')
        ->assertSeeText('Execução com padrão institucional, do fechamento ao acompanhamento')
        ->assertSeeText('Entre em contato com a BSI Capital');
});
