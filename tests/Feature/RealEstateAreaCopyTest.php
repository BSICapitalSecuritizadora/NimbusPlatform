<?php

it('renders the revised CRI copy on the real estate area page', function () {
    $this->get(route('site.imobiliario.cri'))
        ->assertSuccessful()
        ->assertSee('CRI e Real Estate')
        ->assertSee('Estruturação com governança e previsibilidade')
        ->assertSee('Acompanhamento ao longo de toda a operação');
});

it('renders the revised loteamentos copy on the real estate area page', function () {
    $this->get(route('site.imobiliario.loteamentos'))
        ->assertSuccessful()
        ->assertSee('Estruturação para')
        ->assertSee('Loteamentos')
        ->assertSee('Estrutura de capital alinhada ao projeto');
});

it('renders the revised incorporacao copy on the real estate area page', function () {
    $this->get(route('site.imobiliario.incorporacao'))
        ->assertSuccessful()
        ->assertSee('Capital estruturado para')
        ->assertSee('Incorporação')
        ->assertSee('Estruturas compatíveis com cada fase da incorporação');
});
