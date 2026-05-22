<?php

it('renders the partnerships institutional page copy', function () {
    $this->get(route('site.partnerships'))
        ->assertSuccessful()
        ->assertSee('Parcerias estruturadas para ampliar')
        ->assertSee('Modelos de parceria')
        ->assertSee('Vamos estruturar uma parceria com critério técnico e alinhamento comercial?')
        ->assertSee('Falar sobre parcerias');
});
