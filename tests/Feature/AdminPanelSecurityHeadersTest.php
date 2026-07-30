<?php

use App\Http\Middleware\SetSecurityHeaders;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

// ── A-2: painel Filament servido sem cabeçalhos de segurança ─────────────────

it('registers the security headers middleware in the admin panel stack (A-2)', function () {
    expect(Filament::getPanel('admin')->getMiddleware())
        ->toContain(SetSecurityHeaders::class);
});

it('sends the security headers on the unauthenticated admin login page (A-2)', function () {
    $response = $this->get('/admin/login');

    $response->assertSuccessful()
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()')
        ->assertHeader('Content-Security-Policy');

    expect($response->headers->get('Content-Security-Policy'))
        ->toContain("frame-ancestors 'self'")
        ->toContain("object-src 'none'")
        ->toContain("base-uri 'self'");
});

it('sends the security headers on authenticated admin panel pages (A-2)', function () {
    $response = $this->actingAs(makeAdminUser())->get('/admin');

    $response->assertSuccessful()
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()')
        ->assertHeader('Content-Security-Policy');
});

it('keeps unsafe-eval allowed for the Alpine-driven admin panel (A-2)', function () {
    $response = $this->get('/admin/login');

    expect($response->headers->get('Content-Security-Policy'))->toContain("'unsafe-eval'");
});

it('sends HSTS on admin routes in production (A-2)', function () {
    $this->app['env'] = 'production';

    $this->get('/admin/login')
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});
