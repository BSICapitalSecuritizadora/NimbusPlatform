<?php

it('renders the revised investor portal copy on the management area page', function () {
    $this->get(route('site.servicos.portal-investidor'))
        ->assertSuccessful()
        ->assertSee('Transparência ativa e controle total sobre suas posições')
        ->assertSee('Repositório Estratégico')
        ->assertSee('Inteligência de Ativos');
});

it('renders the revised reports copy on the management area page', function () {
    $this->get(route('site.servicos.relatorios'))
        ->assertSuccessful()
        ->assertSee('Transparência e rigor técnico')
        ->assertSee('Visibilidade de Performance')
        ->assertSee('Rigor e Conformidade');
});

it('renders the revised compliance copy on the management area page', function () {
    $this->get(route('site.servicos.compliance'))
        ->assertSuccessful()
        ->assertSee('Vigilância ativa ao longo da operação')
        ->assertSee('Vigilância de Covenants')
        ->assertSee('Monitoramento contínuo das obrigações');
});
