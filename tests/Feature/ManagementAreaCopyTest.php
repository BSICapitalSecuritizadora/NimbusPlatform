<?php

it('renders the revised investor portal copy on the management area page', function () {
    $this->get(route('site.servicos.portal-investidor'))
        ->assertSuccessful()
        ->assertSee('Transparência e acesso contínuo à informação')
        ->assertSee('Documentação centralizada')
        ->assertSee('Acompanhamento da operação');
});

it('renders the revised reports copy on the management area page', function () {
    $this->get(route('site.servicos.relatorios'))
        ->assertSuccessful()
        ->assertSee('Prestação de informações com rigor técnico')
        ->assertSee('Relatórios periódicos')
        ->assertSee('Visualização complementar');
});

it('renders the revised compliance copy on the management area page', function () {
    $this->get(route('site.servicos.compliance'))
        ->assertSuccessful()
        ->assertSee('Controles e governança ao longo da operação')
        ->assertSee('PLD/FTP e diligência cadastral')
        ->assertSee('Monitoramento de obrigações');
});
