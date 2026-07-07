<?php

it('renders the revised CRA copy on the agribusiness area page', function () {
    $this->get(route('site.agronegocio.cra'))
        ->assertSuccessful()
        ->assertSeeText('CRA e Securitização')
        ->assertSeeText('Inteligência técnica aplicada ao Agro')
        ->assertSeeText('Diversificação de Funding');
});

it('renders the revised cooperativas copy on the agribusiness area page', function () {
    $this->get(route('site.agronegocio.cooperativas'))
        ->assertSuccessful()
        ->assertSeeText('Funding Estruturado para')
        ->assertSeeText('Cooperativas do Agro')
        ->assertSeeText('Sincronia Operacional e Associativa');
});

it('renders the revised projetos copy on the agribusiness area page', function () {
    $this->get(route('site.agronegocio.projetos'))
        ->assertSuccessful()
        ->assertSeeText('Funding Estruturado para')
        ->assertSeeText('Energia e Sustentabilidade')
        ->assertSeeText('Infraestrutura Produtiva');
});
