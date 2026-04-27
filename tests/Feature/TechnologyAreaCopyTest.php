<?php

it('renders the revised document ACL copy on the technology area page', function () {
    $this->get(route('site.servicos.documentos-acl'))
        ->assertSuccessful()
        ->assertSee('Governança documental e sigilo operacional')
        ->assertSee('Segregação por Operação')
        ->assertSee('Rastreabilidade de Custódia');
});

it('renders the revised access audit copy on the technology area page', function () {
    $this->get(route('site.servicos.auditoria-acessos'))
        ->assertSuccessful()
        ->assertSee('Governança e evidência de acesso')
        ->assertSee('Monitoramento Fiduciário')
        ->assertSee('Prontidão para Auditoria');
});

it('renders the revised integrations copy on the technology area page', function () {
    $this->get(route('site.servicos.integracoes'))
        ->assertSuccessful()
        ->assertSee('Integração para escala e eficiência')
        ->assertSee('Conexão com Ecossistema')
        ->assertSee('Arquitetura Flexível');
});
