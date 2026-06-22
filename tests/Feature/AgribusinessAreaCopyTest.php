<?php

it('renders the revised CRA copy on the agribusiness area page', function () {
    $this->get(route('site.agronegocio.cra'))
        ->assertSuccessful()
        ->assertSee('CRA e Securitização')
        ->assertSee('Inteligência Técnica Aplicada ao Agro')
        ->assertSee('Diversificação de Funding');
});

it('renders the revised cooperativas copy on the agribusiness area page', function () {
    $this->get(route('site.agronegocio.cooperativas'))
        ->assertSuccessful()
        ->assertSee('Funding Estruturado para')
        ->assertSee('Cooperativas do Agro')
        ->assertSee('Sincronia Operacional e Associativa');
});

it('renders the revised projetos copy on the agribusiness area page', function () {
    $this->get(route('site.agronegocio.projetos'))
        ->assertSuccessful()
        ->assertSee('Funding Estruturado para')
        ->assertSee('Energia e Sustentabilidade')
        ->assertSee('Infraestrutura Produtiva');
});
