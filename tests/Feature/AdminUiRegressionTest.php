<?php

use Filament\Support\Colors\Color;
use Illuminate\Support\Facades\File;

it('keeps the admin views free from placeholder links', function () {
    $files = File::allFiles(resource_path('views/filament'));

    foreach ($files as $file) {
        $contents = $file->getContents();

        expect($contents)
            ->not->toMatch('/href\s*=\s*["\']#["\']/')
            ->not->toContain("?? '#'")
            ->not->toContain('?? "#"');
    }
});

it('uses explicitly discoverable utility classes in admin views', function () {
    $files = File::allFiles(resource_path('views/filament'));

    foreach ($files as $file) {
        expect($file->getContents())
            ->not->toMatch('/(?:bg|text|border|ring|hover:ring|dark:bg|dark:text)-\{\{/');
    }
});

it('keeps gold primary controls at wcag aa contrast with navy text', function () {
    $primary = Color::hex('#b7832f');

    expect(Color::calculateContrastRatio($primary[500], '#091b23'))
        ->toBeGreaterThanOrEqual(Color::WCAG_AA_TEXT)
        ->and(Color::calculateContrastRatio('#96651f', '#f8f7f4'))
        ->toBeGreaterThanOrEqual(Color::WCAG_AA_TEXT);
});

it('renders a branded microsoft-only admin access flow', function () {
    $this->get('/admin/login')
        ->assertSuccessful()
        ->assertSee('BSI Capital Securitizadora')
        ->assertSee('Entrar no sistema')
        ->assertSee('Entrar com Microsoft 365')
        ->assertSee('Single Sign-On Corporativo (SSO)')
        ->assertSee('href="'.route('auth.azure.redirect').'"', false)
        ->assertSee('aria-label="Entrar com Microsoft 365"', false)
        ->assertSee('x-bind:aria-busy="isRedirecting"', false)
        ->assertDontSee('type="password"', false);
});

it('announces the redirected microsoft login error', function () {
    $this->withSession(['loginError' => 'Não foi possível autenticar a conta.'])
        ->get('/admin/login')
        ->assertSuccessful()
        ->assertSee('Não foi possível autenticar a conta.')
        ->assertSee('role="alert"', false)
        ->assertSee('aria-live="assertive"', false)
        ->assertSee('tabindex="-1"', false)
        ->assertSee('autofocus', false);
});
