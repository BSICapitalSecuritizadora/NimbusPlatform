<?php

it('renders the revised contact copy', function () {
    $this->get(route('site.contact'))
        ->assertSuccessful()
        ->assertSee('Entre em contato com a')
        ->assertSee('Enviar mensagem institucional')
        ->assertSee('O que acontece após o envio')
        ->assertSee('Envie sua mensagem')
        ->assertSee('Selecione a área de interesse')
        ->assertSee('Abrir no Google Maps');
});
