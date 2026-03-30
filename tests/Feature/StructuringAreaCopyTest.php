<?php

it('renders the revised origination copy on the structuring area page', function () {
    $this->get(route('site.servicos.originacao'))
        ->assertSuccessful()
        ->assertSee('Originação com critério técnico')
        ->assertSee('Modelagem econômico-financeira')
        ->assertSee('Acesso qualificado ao mercado');
});

it('renders the revised legal structure copy on the structuring area page', function () {
    $this->get(route('site.servicos.estrutura-juridica'))
        ->assertSuccessful()
        ->assertSee('Base jurídica para operações consistentes')
        ->assertSee('Documentação da operação')
        ->assertSee('Conformidade regulatória');
});

it('renders the revised registration and distribution copy on the structuring area page', function () {
    $this->get(route('site.servicos.registro-distribuicao'))
        ->assertSuccessful()
        ->assertSee('Execução organizada até a liquidação')
        ->assertSee('Fluxo regulatório')
        ->assertSee('Bookbuilding e precificação');
});
