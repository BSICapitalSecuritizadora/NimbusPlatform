<?php

it('renders the local public logo in the site navbar and footer', function () {
    expect(public_path('images/bsi-logo.png'))->toBeFile();

    $content = $this->get(route('site.about'))
        ->assertSuccessful()
        ->getContent();

    expect(substr_count($content, 'images/bsi-logo.png'))->toBeGreaterThanOrEqual(2)
        ->and($content)->not->toContain('images/logo-mob.png');
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
