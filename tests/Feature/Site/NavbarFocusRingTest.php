<?php

/**
 * Bootstrap's Dropdown.show() focuses the toggle, so opening a mega menu with the
 * pointer used to paint the keyboard focus ring (a gold halo) on hover.
 */
it('suppresses the focus ring only when a mega menu is opened by pointer', function () {
    $content = $this->get(route('site.about'))
        ->assertSuccessful()
        ->getContent();

    expect($content)->toContain('.navbar .nav-link[data-pointer-open]:focus-visible')
        ->and($content)->toContain("toggle.dataset.pointerOpen = 'true'")
        ->and($content)->toContain('delete toggle.dataset.pointerOpen')
        ->and($content)->toContain("toggle.addEventListener('pointerdown', markPointerOpen)")
        ->and($content)->toContain("toggle.addEventListener('keydown', clearPointerOpen)")
        ->and($content)->toContain("toggle.addEventListener('blur', clearPointerOpen)");
});

it('keeps the shared focus-visible indicator for keyboard navigation', function () {
    $content = $this->get(route('site.about'))
        ->assertSuccessful()
        ->getContent();

    expect($content)->toContain('.nav-link:focus-visible')
        ->and($content)->toContain('box-shadow: 0 0 0 0.24rem rgba(9, 27, 35, 0.14), 0 0 0 0.42rem rgba(160, 110, 40, 0.18) !important;');
});

it('keeps the three mega menu toggles wired to bootstrap dropdowns', function () {
    $content = $this->get(route('site.about'))
        ->assertSuccessful()
        ->getContent();

    expect(substr_count($content, 'nav-item dropdown dropdown-mega'))->toBe(3)
        ->and(substr_count($content, 'nav-link dropdown-toggle'))->toBe(3)
        ->and(substr_count($content, 'data-bs-toggle="dropdown"'))->toBe(3);

    foreach (['Soluções', 'Serviços', 'Institucional'] as $label) {
        expect($content)->toContain($label);
    }
});
