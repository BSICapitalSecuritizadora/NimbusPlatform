<?php

/**
 * Phase 5 — Page-Specific Styles Contract & Theme Regression Tests
 *
 * Verifies that page-specific CSS files and list/resource wrappers strictly consume
 * canonical Phase 1 semantic tokens (--surface-*, --text-*, --border-*, --accent-*),
 * eliminate private color palettes and forced dark headers in Light Mode,
 * and eliminate redundant Phase 3 table and pagination overrides.
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
        ->and($tokensContent)->toContain('--accent')
        ->and($tokensContent)->toContain('--accent-subtle');
});

it('ensures Areas and Responsibles CSS has purged private navy palette and consumes canonical semantic tokens', function () {
    $filePath = resource_path('css/filament/admin/areas-and-responsibles.css');
    expect(file_exists($filePath))->toBeTrue();

    $content = file_get_contents($filePath);

    // Private palette must not be defined
    expect($content)->not->toContain('--areas-navy-950')
        ->and($content)->not->toContain('--areas-navy-900')
        ->and($content)->not->toContain('--areas-navy-800')
        ->and($content)->not->toContain('--areas-white');

    // Semantic tokens must be consumed
    expect($content)->toContain('var(--surface-card)')
        ->and($content)->toContain('var(--surface-ground)')
        ->and($content)->toContain('var(--surface-highlight)')
        ->and($content)->toContain('var(--text-primary)')
        ->and($content)->toContain('var(--text-secondary)')
        ->and($content)->toContain('var(--border-subtle)')
        ->and($content)->toContain('var(--accent)');
});

it('ensures Construction Unit View CSS consumes canonical tokens without forced navy header in light mode', function () {
    $filePath = resource_path('css/filament/admin/construction-unit-view.css');
    expect(file_exists($filePath))->toBeTrue();

    $content = file_get_contents($filePath);

    expect($content)->toContain('--unit-surface: var(--surface-card)')
        ->and($content)->toContain('--unit-toolbar: var(--surface-ground)')
        ->and($content)->toContain('--unit-accent: var(--accent)')
        ->and($content)->not->toContain('background: var(--color-bsi-navy-900)');
});

it('ensures Receivable View CSS consumes canonical tokens without forced dark gradient header in light mode', function () {
    $filePath = resource_path('css/filament/admin/receivable-view.css');
    expect(file_exists($filePath))->toBeTrue();

    $content = file_get_contents($filePath);

    expect($content)->toContain('--rcv-paper: var(--surface-card)')
        ->and($content)->toContain('--rcv-stone-50: var(--surface-ground)')
        ->and($content)->toContain('--rcv-gold-500: var(--accent)')
        ->and($content)->not->toContain('linear-gradient(135deg, #091b23 0%, #0d252e 100%)');
});

it('ensures Sales Board Cycle CSS consumes canonical tokens without forced navy header in light mode', function () {
    $filePath = resource_path('css/filament/admin/sales-board-cycle.css');
    expect(file_exists($filePath))->toBeTrue();

    $content = file_get_contents($filePath);

    expect($content)->toContain('--cycle-surface: var(--surface-card)')
        ->and($content)->toContain('--cycle-hover: var(--surface-highlight)')
        ->and($content)->toContain('--cycle-gold: var(--accent)')
        ->and($content)->not->toContain('background: var(--color-bsi-navy-900)');
});

it('ensures Sales Board Rollout CSS consumes canonical tokens', function () {
    $filePath = resource_path('css/filament/admin/sales-board-rollout.css');
    expect(file_exists($filePath))->toBeTrue();

    $content = file_get_contents($filePath);

    expect($content)->toContain('--rollout-surface: var(--surface-card)')
        ->and($content)->toContain('--rollout-inset: var(--surface-ground)')
        ->and($content)->toContain('--rollout-gold: var(--accent)');
});

it('ensures Job Applications list page has purged legacy hardcodes and redundant Phase 3 table overrides', function () {
    $themeCss = file_get_contents(resource_path('css/filament/admin/theme.css'));

    // Redundant dark overrides on thead, th, td, row hover, and pagination must be deleted
    expect($themeCss)->not->toContain('.dark .bsi-job-applications-list-page thead.fi-ta-header')
        ->and($themeCss)->not->toContain('.dark .bsi-job-applications-list-page .fi-ta-table td')
        ->and($themeCss)->not->toContain('.dark .bsi-job-applications-list-page tr.fi-ta-row:hover')
        ->and($themeCss)->not->toContain('.dark .bsi-job-applications-list-page .fi-ta-pagination');

    // Semantic tokens applied to container, header, tabs, and empty state
    expect($themeCss)->toContain('.bsi-job-applications-list-page .fi-header-heading {')
        ->and($themeCss)->toContain('.bsi-job-applications-list-page .fi-header-subheading {')
        ->and($themeCss)->toContain('.bsi-job-applications-list-page .fi-tabs {')
        ->and($themeCss)->toContain('.bsi-job-applications-list-page .fi-ta-ctn {')
        ->and($themeCss)->toContain('.bsi-job-applications-list-page .fi-ta-empty-state {');
});

it('ensures Business Holidays list page has purged legacy hardcodes and redundant Phase 3 table overrides', function () {
    $themeCss = file_get_contents(resource_path('css/filament/admin/theme.css'));

    expect($themeCss)->not->toContain('.dark .bsi-business-holidays-list-page thead.fi-ta-header')
        ->and($themeCss)->not->toContain('.dark .bsi-business-holidays-list-page .fi-ta-table td')
        ->and($themeCss)->not->toContain('.dark .bsi-business-holidays-list-page tr.fi-ta-row:hover')
        ->and($themeCss)->not->toContain('.dark .bsi-business-holidays-list-page .fi-ta-pagination');

    expect($themeCss)->toContain('.bsi-business-holidays-list-page .fi-header-heading {')
        ->and($themeCss)->toContain('.bsi-business-holidays-list-page .fi-ta {');
});

it('ensures resource list page subheadings do not leak white text on light mode', function () {
    $themeCss = file_get_contents(resource_path('css/filament/admin/theme.css'));

    // Check that subheading group uses semantic text-secondary rather than rgba(251, 250, 248, 0.72)
    preg_match('/\.bsi-users-list-page \.fi-header-subheading[\s\S]*?\{([^}]+)\}/', $themeCss, $matches);
    expect($matches)->not->toBeEmpty();
    expect($matches[1])->toContain('var(--text-secondary)')
        ->and($matches[1])->not->toContain('rgba(251, 250, 248');
});

it('ensures receivables empty state icon bg consumes canonical semantic text-muted token without raw light/dark pair', function () {
    $themeCss = file_get_contents(resource_path('css/filament/admin/theme.css'));

    // Must declare canonical var(--text-muted)
    preg_match('/\.bsi-receivables-list-page \.fi-ta-empty-state-icon-bg\s*\{([^}]+)\}/', $themeCss, $matches);
    expect($matches)->not->toBeEmpty();
    expect($matches[1])->toContain('var(--text-muted)')
        ->and($matches[1])->not->toContain('#64748b');

    // Separate dark mode rule with raw rgba must no longer exist for receivables
    expect($themeCss)->not->toContain('.dark .bsi-receivables-list-page .fi-ta-empty-state-icon-bg');
});

it('ensures notification outboxes empty state icon bg consumes canonical semantic text-muted token without raw light/dark pair', function () {
    $themeCss = file_get_contents(resource_path('css/filament/admin/theme.css'));

    // Must declare canonical var(--text-muted)
    preg_match('/\.bsi-notification-outboxes-list-page \.fi-ta-empty-state-icon-bg\s*\{([^}]+)\}/', $themeCss, $matches);
    expect($matches)->not->toBeEmpty();
    expect($matches[1])->toContain('var(--text-muted)')
        ->and($matches[1])->not->toContain('#64748b');

    // Separate dark mode rule with raw rgba must no longer exist for notification outboxes
    expect($themeCss)->not->toContain('.dark .bsi-notification-outboxes-list-page .fi-ta-empty-state-icon-bg');
});

it('ensures announcements empty state icon bg consumes canonical semantic text-muted token without raw light/dark pair', function () {
    $themeCss = file_get_contents(resource_path('css/filament/admin/theme.css'));

    // Must declare canonical var(--text-muted)
    preg_match('/\.bsi-announcements-list-page \.fi-ta-empty-state-icon-bg\s*\{([^}]+)\}/', $themeCss, $matches);
    expect($matches)->not->toBeEmpty();
    expect($matches[1])->toContain('var(--text-muted)')
        ->and($matches[1])->not->toContain('#64748b');

    // Separate dark mode rule with raw rgba must no longer exist for announcements
    expect($themeCss)->not->toContain('.dark .bsi-announcements-list-page .fi-ta-empty-state-icon-bg');
});
