<?php

it('renders the revised contact copy', function () {
    $this->get(route('site.contact'))
        ->assertSuccessful()
        ->assertSee('Entre em contato com a')
        ->assertSee('Envie sua mensagem')
        ->assertSee('Selecione o assunto')
        ->assertSee('Enviar contato');
});
