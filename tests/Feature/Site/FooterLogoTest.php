<?php

it('renders the local mobile logo in the public site layout', function () {
    expect(public_path('images/logo-mob.png'))->toBeFile();

    $content = $this->get(route('site.about'))
        ->assertSuccessful()
        ->getContent();

    expect(substr_count($content, 'images/logo-mob.png'))->toBeGreaterThanOrEqual(2)
        ->and($content)->not->toContain('images/bsi-logo.png');
});

it('renders the local anbima seal in the public site footer', function () {
    expect(public_path('images/selo-anbima.jpg'))->toBeFile();

    $content = $this->get(route('site.about'))
        ->assertSuccessful()
        ->getContent();

    expect($content)->toContain('images/selo-anbima.jpg')
        ->and($content)->not->toContain('https://www.anbima.com.br')
        ->and($content)->not->toContain('ofertas-securitizadora-provisorio.jpg');
});
