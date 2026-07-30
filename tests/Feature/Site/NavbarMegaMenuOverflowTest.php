<?php

/**
 * The closed mega menu panels stay in the layout (display: block) so they can animate.
 * With `left: auto` they landed at each nav link's static position and their 1080px width
 * pushed documentElement.scrollWidth past clientWidth, adding a horizontal scrollbar to
 * every page of the site.
 */
it('anchors the mega menu panels to the centre of the navbar on desktop', function () {
    $content = $this->get(route('site.home'))
        ->assertSuccessful()
        ->getContent();

    expect($content)->toContain('.navbar .mega-menu[data-bs-popper]')
        ->and($content)->toContain('transform: translate(-50%, 8px);')
        ->and($content)->toContain('transform: translate(-50%, 0);');
});

it('keeps the mega menu panel and its gutters inside the viewport', function () {
    $content = $this->get(route('site.home'))
        ->assertSuccessful()
        ->getContent();

    expect($content)->toContain('width: min(1080px, calc(100% - 2rem));')
        ->and($content)->toContain('--bs-gutter-x: 0;')
        ->and($content)->not->toContain('96vw');
});

it('keeps the horizontal overflow guard on the document as a safety net', function () {
    $content = $this->get(route('site.home'))
        ->assertSuccessful()
        ->getContent();

    expect($content)->toContain('overflow-x: clip;');
});
