<?php

it('renders the revised CR copy on the infra and companies area page', function () {
    $this->get(route('site.infra.cr'))
        ->assertSuccessful()
        ->assertSee('Preparação para o próximo ciclo de investimentos')
        ->assertSee('Ativos de Longo Prazo')
        ->assertSee('Inteligência Regulatória');
});

it('renders the revised receivables copy on the infra and companies area page', function () {
    $this->get(route('site.infra.recebiveis'))
        ->assertSuccessful()
        ->assertSee('Liquidez estruturada para expansão empresarial')
        ->assertSee('Estrutura de garantias')
        ->assertSee('Programas recorrentes');
});

it('renders the revised bespoke structuring copy on the infra and companies area page', function () {
    $this->get(route('site.infra.estruturacao'))
        ->assertSuccessful()
        ->assertSee('Estruturação alinhada à sua operação')
        ->assertSee('Instrumentos Estratégicos')
        ->assertSee('Assessoria de Ponta a Ponta');
});
