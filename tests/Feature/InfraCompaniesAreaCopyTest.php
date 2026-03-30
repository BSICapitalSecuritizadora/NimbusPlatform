<?php

it('renders the revised CR copy on the infra and companies area page', function () {
    $this->get(route('site.infra.cr'))
        ->assertSuccessful()
        ->assertSee('Estrutura em evolução para viabilizar captação de longo prazo')
        ->assertSee('Preparação para o próximo ciclo de investimentos')
        ->assertSee('Preparação regulatória');
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
        ->assertSee('Estruturação alinhada à realidade da operação')
        ->assertSee('Modelagem integrada')
        ->assertSee('Instrumentos sob medida');
});
