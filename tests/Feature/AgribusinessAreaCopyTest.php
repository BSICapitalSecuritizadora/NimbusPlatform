<?php

it('renders the revised CRA copy on the agribusiness area page', function () {
    $this->get(route('site.agronegocio.cra'))
        ->assertSuccessful()
        ->assertSee('Securitização do')
        ->assertSee('Inteligência técnica aplicada ao Agro')
        ->assertSee('Diversificação de Funding');
});

it('renders the revised cooperativas copy on the agribusiness area page', function () {
    $this->get(route('site.agronegocio.cooperativas'))
        ->assertSuccessful()
        ->assertSee('Capital Estratégico para')
        ->assertSee('Cooperativas')
        ->assertSee('Sincronia Operacional e Associativa');
});

it('renders the revised projetos copy on the agribusiness area page', function () {
    $this->get(route('site.agronegocio.projetos'))
        ->assertSuccessful()
        ->assertSee('Capital para expansão e verticalização')
        ->assertSee('Energia e Sustentabilidade')
        ->assertSee('Infraestrutura Produtiva');
});
