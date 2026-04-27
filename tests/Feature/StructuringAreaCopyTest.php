<?php

it('renders the revised origination copy on the structuring area page', function () {
    $this->get(route('site.servicos.originacao'))
        ->assertSuccessful()
        ->assertSee('Originação Estratégica')
        ->assertSee('Validação de Teses')
        ->assertSee('Posicionamento de Mercado');
});

it('renders the revised legal structure copy on the structuring area page', function () {
    $this->get(route('site.servicos.estrutura-juridica'))
        ->assertSuccessful()
        ->assertSee('Base jurídica para operações consistentes')
        ->assertSee('Engenharia Documental')
        ->assertSee('Rigor Regulatório');
});

it('renders the revised registration and distribution copy on the structuring area page', function () {
    $this->get(route('site.servicos.registro-distribuicao'))
        ->assertSuccessful()
        ->assertSee('Execução estratégica até a liquidação')
        ->assertSee('Gestão de Fluxo Regulatório')
        ->assertSee('Inteligência de Bookbuilding');
});
