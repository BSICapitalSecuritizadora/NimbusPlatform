<?php

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * Pela especificação da CSP, um `nonce-source` na política faz o
 * `'unsafe-inline'` ser ignorado. Como o Filament não emite nonce nos scripts
 * que injeta, enviar nonce no painel bloqueava 10 dos 11 scripts e o deixava
 * inutilizável — sem ganho de segurança nenhum, já que nada passava a ser
 * validado por nonce.
 *
 * Estes testes fixam o comportamento nos dois lados: painel sem nonce (para
 * funcionar) e site público com nonce (onde ele de fato protege).
 */
it('omits the csp nonce on the admin panel so its inline scripts are not blocked', function () {
    $csp = $this->get('/admin/login')->headers->get('Content-Security-Policy');

    expect($csp)
        ->not->toContain('nonce-')
        ->toContain("'unsafe-inline'");
});

it('omits the nonce on authenticated admin pages too', function () {
    $csp = $this->actingAs(makeAdminUser())->get('/admin')
        ->headers->get('Content-Security-Policy');

    expect($csp)->not->toContain('nonce-');
});

it('renders every admin panel script without blocking it', function () {
    $content = $this->get('/admin/login')->getContent();
    $csp = $this->get('/admin/login')->headers->get('Content-Security-Policy');

    $scriptCount = preg_match_all('/<script\b/i', $content);

    // Havendo scripts inline sem nonce, a política precisa permitir inline.
    expect($scriptCount)->toBeGreaterThan(0)
        ->and($csp)->toContain("'unsafe-inline'")
        ->and($csp)->not->toContain('nonce-');
});

/**
 * O site público é onde o visitante é anônimo e a CSP realmente protege — ali o
 * nonce continua obrigatório, e é o que torna o `'unsafe-inline'` inócuo.
 */
it('keeps the csp nonce on the public site', function (string $path) {
    $csp = $this->get($path)->headers->get('Content-Security-Policy');

    expect($csp)->toContain('nonce-');
})->with(['/', '/contato', '/emissoes', '/canal-de-etica']);

it('keeps the public site free of unsafe-eval', function () {
    $csp = $this->get('/')->headers->get('Content-Security-Policy');

    expect($csp)->not->toContain("'unsafe-eval'");
});

it('keeps every other security header on the admin panel', function () {
    $this->get('/admin/login')
        ->assertSuccessful()
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()')
        ->assertHeader('Content-Security-Policy');
});

it('still confines the admin panel with the non-script directives', function () {
    $csp = $this->get('/admin/login')->headers->get('Content-Security-Policy');

    expect($csp)
        ->toContain("frame-ancestors 'self'")
        ->toContain("object-src 'none'")
        ->toContain("base-uri 'self'")
        ->toContain("form-action 'self'");
});
