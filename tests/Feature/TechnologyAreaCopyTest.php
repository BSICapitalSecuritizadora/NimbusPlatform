<?php

it('renders the revised document ACL copy on the technology area page', function () {
    $this->get(route('site.servicos.documentos-acl'))
        ->assertSuccessful()
        ->assertSee('Governança documental com controle efetivo')
        ->assertSee('Permissões por perfil')
        ->assertSee('Distribuição protegida');
});

it('renders the revised access audit copy on the technology area page', function () {
    $this->get(route('site.servicos.auditoria-acessos'))
        ->assertSuccessful()
        ->assertSee('Rastreabilidade operacional e evidência de acesso')
        ->assertSee('Eventos registrados')
        ->assertSee('Relatórios de controle');
});

it('renders the revised integrations copy on the technology area page', function () {
    $this->get(route('site.servicos.integracoes'))
        ->assertSuccessful()
        ->assertSee('Integração para escala e disciplina operacional')
        ->assertSee('Automação de processamento')
        ->assertSee('APIs e eventos');
});
