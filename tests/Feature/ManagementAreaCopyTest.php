<?php

it('renders the revised investor portal copy on the management area page', function () {
    $this->get(route('site.servicos.portal-investidor'))
        ->assertSuccessful()
        ->assertSee('Transparência ativa e visibilidade operacional sobre suas posições')
        ->assertSee('Acesso Segregado')
        ->assertSee('Governança da Informação');
});

it('renders the revised reports copy on the management area page', function () {
    $this->get(route('site.servicos.relatorios'))
        ->assertSuccessful()
        ->assertSee('Informação estruturada para acompanhamento da operação')
        ->assertSee('Visibilidade de Performance')
        ->assertSee('Rigor e Conformidade');
});

it('renders the revised monitoramento regulatorio copy on the management area page', function () {
    $this->get(route('site.servicos.monitoramento-regulatorio'))
        ->assertSuccessful()
        ->assertSee('Governança regulatória ao longo da operação')
        ->assertSee('Prevenção e Gestão de Riscos')
        ->assertSee('Vigilância de Covenants')
        ->assertSee('Acompanhamento contínuo das obrigações contratuais');
});
