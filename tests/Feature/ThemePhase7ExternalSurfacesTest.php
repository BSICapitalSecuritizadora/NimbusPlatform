<?php

/**
 * Phase 7 — Portals & External Surfaces Theme Contract & Regression Tests
 *
 * Verifies that all non-admin and externally facing surfaces of NimbusPlatform:
 * - Public marketing site (resources/views/site)
 * - Investor portal (resources/views/investor)
 * - Nimbus client portal (resources/views/nimbus)
 * - Auth and app shell layouts (resources/views/layouts/auth, resources/views/layouts/app)
 * - External portal tokens and styles (resources/css/portal-tokens.css, resources/css/app.css)
 *
 * Strictly consume canonical BSI design language and semantic tokens
 * (--surface-*, --text-*, --border-*, --accent-*), eliminate private unaliased
 * color palettes, and preserve frozen admin architecture and concurrent work isolation.
 */
it('ensures Phase 1 theme tokens canonical file remains frozen and untouched', function () {
    $tokensPath = resource_path('css/theme-tokens.css');
    expect(file_exists($tokensPath))->toBeTrue();

    $tokensContent = file_get_contents($tokensPath);
    expect($tokensContent)->toContain('--surface-canvas')
        ->and($tokensContent)->toContain('--surface-ground')
        ->and($tokensContent)->toContain('--surface-card')
        ->and($tokensContent)->toContain('--surface-elevated')
        ->and($tokensContent)->toContain('--surface-highlight')
        ->and($tokensContent)->toContain('--text-primary')
        ->and($tokensContent)->toContain('--text-secondary')
        ->and($tokensContent)->toContain('--text-muted')
        ->and($tokensContent)->toContain('--border-subtle')
        ->and($tokensContent)->toContain('--border-strong')
        ->and($tokensContent)->toContain('--accent')
        ->and($tokensContent)->toContain('--accent-hover')
        ->and($tokensContent)->toContain('--accent-subtle');
});

it('ensures portal-tokens.css consumes canonical tokens without overriding core theme colors', function () {
    $portalTokensPath = resource_path('css/portal-tokens.css');
    expect(file_exists($portalTokensPath))->toBeTrue();

    $content = file_get_contents($portalTokensPath);

    // Overriding color tokens must not exist in portal @theme
    expect($content)->not->toContain('--color-navy-800: #0a1f28;')
        ->and($content)->not->toContain('--color-navy-700: #0d2834;')
        ->and($content)->not->toContain('--color-navy-600: #123340;')
        ->and($content)->not->toContain('--color-gold-400: #be935d;');

    // Ink palette is mapped to canonical BSI tokens
    expect($content)->toContain('--color-ink-900: var(--color-navy-900);')
        ->and($content)->toContain('--color-ink-700: var(--color-navy-700);')
        ->and($content)->toContain('--color-ink-500: var(--color-navy-500);')
        ->and($content)->toContain('--color-portal-bg: var(--surface-canvas);');

    // Portal components consume canonical variables
    expect($content)->toContain('background: var(--surface-card);')
        ->and($content)->toContain('border: 1px solid var(--border-subtle);')
        ->and($content)->toContain('color: var(--accent);');
});

it('ensures app.css bases its canvas and focus rings on canonical semantic tokens', function () {
    $appCssPath = resource_path('css/app.css');
    expect(file_exists($appCssPath))->toBeTrue();

    $content = file_get_contents($appCssPath);

    expect($content)->toContain('var(--surface-canvas);')
        ->and($content)->toContain('color: var(--text-primary);')
        ->and($content)->toContain('ring-accent');
});

it('ensures auth layouts consume canonical semantic tokens and support light/dark modes', function () {
    $simplePath = resource_path('views/layouts/auth/simple.blade.php');
    $cardPath = resource_path('views/layouts/auth/card.blade.php');
    $splitPath = resource_path('views/layouts/auth/split.blade.php');

    expect(file_exists($simplePath))->toBeTrue()
        ->and(file_exists($cardPath))->toBeTrue()
        ->and(file_exists($splitPath))->toBeTrue();

    $simpleContent = file_get_contents($simplePath);
    expect($simpleContent)->toContain('var(--surface-ground)')
        ->and($simpleContent)->toContain('var(--surface-canvas)')
        ->and($simpleContent)->toContain('var(--text-primary)');

    $cardContent = file_get_contents($cardPath);
    expect($cardContent)->toContain('var(--surface-ground)')
        ->and($cardContent)->toContain('var(--surface-canvas)')
        ->and($cardContent)->toContain('var(--surface-card)')
        ->and($cardContent)->toContain('var(--border-subtle)');

    $splitContent = file_get_contents($splitPath);
    expect($splitContent)->toContain('var(--surface-ground)')
        ->and($splitContent)->toContain('var(--surface-canvas)')
        ->and($splitContent)->toContain('var(--border-subtle)');
});

it('ensures app layouts consume canonical semantic tokens for backgrounds and borders', function () {
    $sidebarPath = resource_path('views/layouts/app/sidebar.blade.php');
    $headerPath = resource_path('views/layouts/app/header.blade.php');

    expect(file_exists($sidebarPath))->toBeTrue()
        ->and(file_exists($headerPath))->toBeTrue();

    $sidebarContent = file_get_contents($sidebarPath);
    expect($sidebarContent)->toContain('var(--surface-ground)')
        ->and($sidebarContent)->toContain('var(--surface-canvas)')
        ->and($sidebarContent)->toContain('var(--surface-card)')
        ->and($sidebarContent)->toContain('var(--border-subtle)');

    $headerContent = file_get_contents($headerPath);
    expect($headerContent)->toContain('var(--surface-ground)')
        ->and($headerContent)->toContain('var(--surface-canvas)')
        ->and($headerContent)->toContain('var(--surface-card)')
        ->and($headerContent)->toContain('var(--border-subtle)');
});

it('ensures investor portal views consume canonical tokens and eliminate legacy colors', function () {
    $layoutPath = resource_path('views/investor/layout.blade.php');
    $loginPath = resource_path('views/investor/auth/login.blade.php');
    $docListPath = resource_path('views/livewire/investor/document-list.blade.php');

    expect(file_exists($layoutPath))->toBeTrue()
        ->and(file_exists($loginPath))->toBeTrue()
        ->and(file_exists($docListPath))->toBeTrue();

    $layoutContent = file_get_contents($layoutPath);
    expect($layoutContent)->not->toContain('rgba(0,32,91')
        ->and($layoutContent)->not->toContain('rgba(212,175,55');

    $loginContent = file_get_contents($loginPath);
    expect($loginContent)->toContain('var(--text-primary)')
        ->and($loginContent)->toContain('var(--surface-card)')
        ->and($loginContent)->toContain('var(--border-strong)')
        ->and($loginContent)->toContain('var(--accent)')
        ->and($loginContent)->not->toContain('rgba(0,32,91');

    $docListContent = file_get_contents($docListPath);
    expect($docListContent)->not->toContain('rgba(0,32,91');
});

it('ensures nimbus client portal views consume canonical tokens and eliminate private variables', function () {
    $loginPath = resource_path('views/nimbus/auth/login.blade.php');
    $dashboardPath = resource_path('views/nimbus/dashboard.blade.php');
    $wizardPath = resource_path('views/nimbus/submissions/create.blade.php');

    expect(file_exists($loginPath))->toBeTrue()
        ->and(file_exists($dashboardPath))->toBeTrue()
        ->and(file_exists($wizardPath))->toBeTrue();

    $loginContent = file_get_contents($loginPath);
    expect($loginContent)->toContain('--nd-navy-900: var(--color-navy-950, #06151c);')
        ->and($loginContent)->toContain('--nd-gold-500: var(--accent, #a06e28);')
        ->and($loginContent)->toContain('--nd-gold-600: var(--accent-hover, #7b541e);')
        ->and($loginContent)->toContain('--nd-white: var(--color-offwhite, #e6e4e4);')
        ->and($loginContent)->not->toContain('#be935d');

    $dashboardContent = file_get_contents($dashboardPath);
    expect($dashboardContent)->toContain('var(--color-gold-400)')
        ->and($dashboardContent)->toContain('var(--accent-subtle)');

    $wizardContent = file_get_contents($wizardPath);
    expect($wizardContent)->toContain('.nd-step-box.border-warning')
        ->and($wizardContent)->toContain('border-color: var(--accent) !important;')
        ->and($wizardContent)->toContain('color: var(--accent) !important;');
});

it('ensures public marketing site views do not contain legacy raw rgba palettes', function () {
    $siteFiles = glob(resource_path('views/site/**/*.blade.php'));
    $siteFiles = array_merge($siteFiles, glob(resource_path('views/site/*.blade.php')));

    expect(count($siteFiles))->toBeGreaterThan(10);

    foreach ($siteFiles as $file) {
        $content = file_get_contents($file);
        expect($content)->not->toContain('rgba(0,32,91')
            ->and($content)->not->toContain('rgba(212,175,55');
    }
});
