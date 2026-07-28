<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('cri-page');

it('renders the revised CRI copy on the real estate area page', function () {
    $this->get(route('site.imobiliario.cri'))
        ->assertSuccessful()
        ->assertSee('CRI e Real Estate')
        ->assertSee('Inteligência técnica em cada fase da operação')
        ->assertSee('Monitoramento e Diligência');
});

it('renders the revised loteamentos copy on the real estate area page', function () {
    $this->get(route('site.imobiliario.loteamentos'))
        ->assertSuccessful()
        ->assertSee('Loteamentos')
        ->assertSee('Soluções para cada dimensão da operação')
        ->assertSee('Liquidez e Monetização');
});

it('renders the revised incorporacao copy on the real estate area page', function () {
    $this->get(route('site.imobiliario.incorporacao'))
        ->assertSuccessful()
        ->assertSee('Incorporação')
        ->assertSee('Ciclo de Crédito Inteligente')
        ->assertSee('Governança do Lastro');
});

it('no longer renders the institutional indicators band on loteamentos', function () {
    $this->get(route('site.imobiliario.loteamentos'))
        ->assertSuccessful()
        ->assertDontSee('Emissões Realizadas')
        ->assertDontSee('Projetos Financiados')
        ->assertDontSee('VGV Sob Gestão')
        ->assertDontSee('+R$ 1Bi')
        ->assertDontSee('linear-gradient(135deg, var(--brand-strong), var(--brand))', false)
        // O card "Presença em todo o território nacional" é outro componente e permanece.
        ->assertSee('Presença em todo o território nacional')
        ->assertSee('VGV Estruturado');
});

it('no longer renders the institutional indicators band on incorporacao', function () {
    $this->get(route('site.imobiliario.incorporacao'))
        ->assertSuccessful()
        ->assertDontSee('Emissões Realizadas')
        ->assertDontSee('Projetos Financiados')
        ->assertDontSee('Estados Atendidos')
        ->assertDontSee('VGV Sob Gestão')
        ->assertDontSee('+R$ 1Bi')
        ->assertDontSee('linear-gradient(135deg, var(--brand-strong), var(--brand))', false);
});

it('keeps the shared institutional indicators band on the CRI page', function () {
    $this->get(route('site.imobiliario.cri'))
        ->assertSuccessful()
        ->assertSee('Emissões Realizadas')
        ->assertSee('Projetos Financiados')
        ->assertSee('Estados Atendidos')
        ->assertSee('VGV Sob Gestão')
        ->assertSee('+R$ 1Bi')
        ->assertSee('linear-gradient(135deg, var(--brand-strong), var(--brand))', false);
});
