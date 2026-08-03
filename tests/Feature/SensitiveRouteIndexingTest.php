<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('disallows crawler access to sensitive route prefixes', function () {
    $directives = file(
        public_path('robots.txt'),
        FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES,
    );

    expect($directives)->toBe([
        'User-agent: *',
        'Disallow: /admin',
        'Disallow: /auth',
        'Disallow: /dashboard',
        'Disallow: /gestao-documental-externa',
        'Disallow: /healthcheck',
        'Disallow: /investidor',
        'Disallow: /login',
        'Disallow: /nimbus',
        'Disallow: /operacional',
        'Disallow: /pending-approval',
        'Disallow: /proposta/continuar',
        'Disallow: /settings',
        'Disallow: /up',
        'Allow: /',
    ]);
});

it('keeps the public pages crawlable', function (string $path) {
    $disallowed = collect(file(public_path('robots.txt'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))
        ->filter(fn (string $directive): bool => str_starts_with($directive, 'Disallow: '))
        ->map(fn (string $directive): string => str_replace('Disallow: ', '', $directive));

    expect($disallowed->filter(fn (string $prefix): bool => str_starts_with($path, $prefix))->all())->toBe([]);
})->with([
    'home' => '/',
    'public proposal form' => '/proposals/create',
    'legacy proposal url' => '/proposta',
    'public documents' => '/documentos-publicos',
    'emissions' => '/emissoes',
    'careers' => '/trabalhe-conosco',
    'contact' => '/contato',
]);

it('marks login pages as noindex and nofollow', function (string $path) {
    $this->get($path)
        ->assertSuccessful()
        ->assertSee('<meta name="robots" content="noindex,nofollow">', false);
})->with([
    'administrative portal' => '/login',
    'Filament admin panel' => '/admin/login',
    'external document portal' => '/gestao-documental-externa/login',
    'investor portal' => '/investidor/login',
]);

it('marks the password confirmation page as noindex and nofollow', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('password.confirm'))
        ->assertSuccessful()
        ->assertSee('<meta name="robots" content="noindex,nofollow">', false);
});

it('keeps the public proposal form indexable', function () {
    $this->get(route('proposal.create'))
        ->assertSuccessful()
        ->assertDontSee('name="robots"', false);
});
