<?php

it('renders the partnerships institutional page copy', function () {
    $this->get(route('site.partnerships'))
        ->assertSuccessful()
        ->assertSee('Parcerias estratégicas para')
        ->assertSee('Modelos de parceria')
        ->assertSee('Vamos estruturar uma parceria com critério técnico e alinhamento comercial?')
        ->assertSee('Apresentar oportunidade de parceria');
});
