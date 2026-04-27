<?php

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
        ->assertSee('Estrutura de capital sob medida')
        ->assertSee('Liquidez e Monetização');
});

it('renders the revised incorporacao copy on the real estate area page', function () {
    $this->get(route('site.imobiliario.incorporacao'))
        ->assertSuccessful()
        ->assertSee('Incorporação')
        ->assertSee('Ciclo de crédito inteligente')
        ->assertSee('Governança Fiduciária');
});
