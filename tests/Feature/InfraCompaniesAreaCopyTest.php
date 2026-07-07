<?php

it('renders the revised CR copy on the infra and companies area page', function () {
    $this->get(route('site.infra.cr'))
        ->assertSuccessful()
        ->assertSeeText('Acesse o mercado de capitais via Certificado de Recebíveis.')
        ->assertSeeText('Ativos de Longo Prazo')
        ->assertSeeText('Inteligência Regulatória');
});

it('renders the revised receivables copy on the infra and companies area page', function () {
    $this->get(route('site.infra.recebiveis'))
        ->assertSuccessful()
        ->assertSeeText('Liquidez estruturada para expansão empresarial')
        ->assertSeeText('Estrutura de garantias')
        ->assertSeeText('Programas recorrentes');
});

it('renders the revised bespoke structuring copy on the infra and companies area page', function () {
    $this->get(route('site.infra.estruturacao'))
        ->assertSuccessful()
        ->assertSeeText('Estruturação alinhada à sua operação')
        ->assertSeeText('Instrumentos Estratégicos')
        ->assertSeeText('Assessoria de Ponta a Ponta');
});
