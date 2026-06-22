<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders the revised document ACL copy on the technology area page', function () {
    $this->get(route('site.servicos.documentos-acl'))
        ->assertSuccessful()
        ->assertSee('Governança documental, permissões e rastreabilidade')
        ->assertSee('Segregação por Operação')
        ->assertSee('Rastreabilidade de Custódia');
});

it('renders the revised access audit copy on the technology area page', function () {
    $this->get(route('site.servicos.auditoria-acessos'))
        ->assertSuccessful()
        ->assertSee('Governança, logs e evidências operacionais')
        ->assertSee('Monitoramento Fiduciário')
        ->assertSee('Relatórios para auditoria e compliance');
});

it('renders the revised integrations copy on the technology area page', function () {
    $this->get(route('site.servicos.integracoes'))
        ->assertSuccessful()
        ->assertSee('Integrações')
        ->assertSee('Conectividade operacional com o ecossistema da operação')
        ->assertSee('Conectividade para stakeholders e sistemas')
        ->assertDontSee('Portal Developer')
        ->assertDontSee('Arquitetura de Conectividade');
});
