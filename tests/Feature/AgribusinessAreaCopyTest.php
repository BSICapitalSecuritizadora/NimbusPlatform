<?php

it('renders the revised CRA copy on the agribusiness area page', function () {
    $this->get(route('site.agronegocio.cra'))
        ->assertSuccessful()
        ->assertSee('CRA e')
        ->assertSee('Recebíveis do Agro')
        ->assertSee('Estruturação compatível com a dinâmica do agro');
});

it('renders the revised cooperativas copy on the agribusiness area page', function () {
    $this->get(route('site.agronegocio.cooperativas'))
        ->assertSuccessful()
        ->assertSee('Soluções para')
        ->assertSee('Cooperativas')
        ->assertSee('Estruturas aderentes ao sistema cooperativista');
});

it('renders the revised projetos copy on the agribusiness area page', function () {
    $this->get(route('site.agronegocio.projetos'))
        ->assertSuccessful()
        ->assertSee('Projetos Estratégicos')
        ->assertSee('Capital para crescimento e transformação operacional')
        ->assertSee('Energia e sustentabilidade');
});
