<?php

/**
 * Phase 3 — Filament Core Primitives Contract & Theme Regression Tests
 *
 * Verifies that generic Filament primitives (sections, tables, headers, rows,
 * pagination, notifications, modal backdrop, and tooltips) consume canonical
 * semantic design tokens without falling back to framework default charcoal/gray.
 */
function adminThemePrimitiveRules(): array
{
    $css = preg_replace('#/\*.*?\*/#s', '', file_get_contents(resource_path('css/filament/admin/theme.css')));

    preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $matches, PREG_SET_ORDER);

    $rules = [];

    foreach ($matches as [, $selectorList, $body]) {
        $declarations = [];

        foreach (array_filter(array_map('trim', explode(';', $body))) as $declaration) {
            if (! str_contains($declaration, ':')) {
                continue;
            }

            [$property, $value] = array_map('trim', explode(':', $declaration, 2));
            $declarations[$property] = trim(str_replace('!important', '', $value));
        }

        $rules[] = [
            'selectors' => array_map('trim', preg_split('/,(?![^(]*\))/', trim($selectorList))),
            'declarations' => $declarations,
        ];
    }

    return $rules;
}

function findPrimitiveDeclarations(string $selectorNeedle): array
{
    $rules = adminThemePrimitiveRules();
    $matched = [];

    foreach ($rules as $rule) {
        foreach ($rule['selectors'] as $sel) {
            if ($sel === $selectorNeedle) {
                $matched[] = $rule['declarations'];
            }
        }
    }

    return array_merge(...$matched);
}

it('ensures generic sections consume semantic card and ground surfaces', function () {
    $light = findPrimitiveDeclarations('.fi-section:not(.fi-section-not-contained):not(.fi-aside)');
    $dark = findPrimitiveDeclarations('.dark .fi-section:not(.fi-section-not-contained):not(.fi-aside)');

    expect($light['background-color'] ?? null)->toBe('var(--surface-card)')
        ->and($light['border'] ?? null)->toContain('var(--border-subtle)')
        ->and($dark['background-color'] ?? null)->toBe('var(--surface-card)')
        ->and($dark['border'] ?? null)->toContain('var(--border-subtle)');

    $lightSecondary = findPrimitiveDeclarations('.fi-section.fi-secondary:not(.fi-section-not-contained):not(.fi-aside)');
    $darkSecondary = findPrimitiveDeclarations('.dark .fi-section.fi-secondary:not(.fi-section-not-contained):not(.fi-aside)');

    expect($lightSecondary['background-color'] ?? null)->toBe('var(--surface-ground)')
        ->and($darkSecondary['background-color'] ?? null)->toBe('var(--surface-ground)');

    $heading = findPrimitiveDeclarations('.fi-section-header-heading');
    $darkHeading = findPrimitiveDeclarations('.dark .fi-section-header-heading');
    expect($heading['color'] ?? null)->toBe('var(--text-primary)')
        ->and($darkHeading['color'] ?? null)->toBe('var(--text-primary)');

    $desc = findPrimitiveDeclarations('.fi-section-header-description');
    $darkDesc = findPrimitiveDeclarations('.dark .fi-section-header-description');
    expect($desc['color'] ?? null)->toBe('var(--text-secondary)')
        ->and($darkDesc['color'] ?? null)->toBe('var(--text-secondary)');
});

it('ensures generic tables consume semantic card, ground, and border tokens', function () {
    $lightCtn = findPrimitiveDeclarations('.fi-ta-ctn');
    $darkCtn = findPrimitiveDeclarations('.dark .fi-ta-ctn');

    expect($lightCtn['background-color'] ?? null)->toBe('var(--surface-card)')
        ->and($lightCtn['border'] ?? null)->toContain('var(--border-subtle)')
        ->and($darkCtn['background-color'] ?? null)->toBe('var(--surface-card)')
        ->and($darkCtn['border'] ?? null)->toContain('var(--border-subtle)');

    $lightHeader = findPrimitiveDeclarations('.fi-ta-header');
    $darkHeader = findPrimitiveDeclarations('.dark .fi-ta-header');
    expect($lightHeader['background-color'] ?? null)->toBe('var(--surface-ground)')
        ->and($darkHeader['background-color'] ?? null)->toBe('var(--surface-ground)');

    $tableHead = findPrimitiveDeclarations('.fi-ta-table > thead > tr');
    $darkTableHead = findPrimitiveDeclarations('.dark .fi-ta-table > thead > tr');
    expect($tableHead['background-color'] ?? null)->toBe('var(--surface-ground)')
        ->and($darkTableHead['background-color'] ?? null)->toBe('var(--surface-ground)');

    $headerCell = findPrimitiveDeclarations('.fi-ta-header-cell');
    $darkHeaderCell = findPrimitiveDeclarations('.dark .fi-ta-header-cell');
    expect($headerCell['color'] ?? null)->toBe('var(--text-primary)')
        ->and($darkHeaderCell['color'] ?? null)->toBe('var(--text-primary)');
});

it('ensures table rows consume semantic text, hover, and selection tokens', function () {
    $rowTd = findPrimitiveDeclarations('.fi-ta-row td');
    $darkRowTd = findPrimitiveDeclarations('.dark .fi-ta-row td');
    expect($rowTd['color'] ?? null)->toBe('var(--text-primary)')
        ->and($darkRowTd['color'] ?? null)->toBe('var(--text-primary)');

    $hover = findPrimitiveDeclarations('.fi-ta-row.fi-clickable:hover td');
    $darkHover = findPrimitiveDeclarations('.dark .fi-ta-row.fi-clickable:hover td');
    expect($hover['background-color'] ?? null)->toBe('var(--surface-highlight)')
        ->and($darkHover['background-color'] ?? null)->toBe('var(--surface-highlight)');

    $selected = findPrimitiveDeclarations('.fi-ta-row.fi-selected td');
    $darkSelected = findPrimitiveDeclarations('.dark .fi-ta-row.fi-selected td');
    expect($selected['background-color'] ?? null)->toBe('var(--accent-subtle)')
        ->and($darkSelected['background-color'] ?? null)->toBe('var(--accent-subtle)');

    $selectedHover = findPrimitiveDeclarations('.fi-ta-row.fi-selected:hover td');
    $darkSelectedHover = findPrimitiveDeclarations('.dark .fi-ta-row.fi-selected:hover td');
    expect($selectedHover['background-color'] ?? null)->toContain('color-mix')
        ->and($selectedHover['background-color'] ?? null)->toContain('var(--accent-subtle)')
        ->and($darkSelectedHover['background-color'] ?? null)->toContain('color-mix')
        ->and($darkSelectedHover['background-color'] ?? null)->toContain('var(--accent-subtle)');
});

it('ensures generic pagination consumes semantic ground, card, and accent tokens', function () {
    $pagination = findPrimitiveDeclarations('.fi-pagination');
    $darkPagination = findPrimitiveDeclarations('.dark .fi-pagination');
    expect($pagination['background-color'] ?? null)->toBe('var(--surface-ground)')
        ->and($pagination['border-top'] ?? null)->toContain('var(--border-subtle)')
        ->and($darkPagination['background-color'] ?? null)->toBe('var(--surface-ground)');

    $items = findPrimitiveDeclarations('.fi-pagination-items');
    $darkItems = findPrimitiveDeclarations('.dark .fi-pagination-items');
    expect($items['background-color'] ?? null)->toBe('var(--surface-card)')
        ->and($items['border'] ?? null)->toContain('var(--border-subtle)')
        ->and($darkItems['background-color'] ?? null)->toBe('var(--surface-card)');

    $activeBtn = findPrimitiveDeclarations('.fi-pagination-item.fi-active .fi-pagination-item-btn');
    $darkActiveBtn = findPrimitiveDeclarations('.dark .fi-pagination-item.fi-active .fi-pagination-item-btn');
    expect($activeBtn['background-color'] ?? null)->toBe('var(--accent-subtle)')
        ->and($darkActiveBtn['background-color'] ?? null)->toBe('var(--accent-subtle)');

    $activeLabel = findPrimitiveDeclarations('.fi-pagination-item.fi-active .fi-pagination-item-label');
    $darkActiveLabel = findPrimitiveDeclarations('.dark .fi-pagination-item.fi-active .fi-pagination-item-label');
    expect($activeLabel['color'] ?? null)->toBe('var(--accent)')
        ->and($darkActiveLabel['color'] ?? null)->toBe('var(--accent)');
});

it('ensures notifications consume semantic elevated surfaces and preserve status colors', function () {
    $light = findPrimitiveDeclarations('.fi-no-notification:not(.fi-inline)');
    $dark = findPrimitiveDeclarations('.dark .fi-no-notification:not(.fi-inline)');

    expect($light['background-color'] ?? null)->toBe('var(--surface-elevated)')
        ->and($light['border'] ?? null)->toContain('var(--border-subtle)')
        ->and($dark['background-color'] ?? null)->toBe('var(--surface-elevated)')
        ->and($dark['border'] ?? null)->toContain('var(--border-strong)');

    $title = findPrimitiveDeclarations('.fi-no-notification-title');
    $darkTitle = findPrimitiveDeclarations('.dark .fi-no-notification-title');
    expect($title['color'] ?? null)->toBe('var(--text-primary)')
        ->and($darkTitle['color'] ?? null)->toBe('var(--text-primary)');

    $body = findPrimitiveDeclarations('.fi-no-notification-body');
    $darkBody = findPrimitiveDeclarations('.dark .fi-no-notification-body');
    expect($body['color'] ?? null)->toBe('var(--text-secondary)')
        ->and($darkBody['color'] ?? null)->toBe('var(--text-secondary)');

    $lightTint = findPrimitiveDeclarations('.fi-no-notification.fi-color');
    $darkTint = findPrimitiveDeclarations('.dark .fi-no-notification.fi-color');
    expect($lightTint['background-color'] ?? null)->toContain('color-mix')
        ->and($lightTint['background-color'] ?? null)->toContain('var(--surface-elevated)')
        ->and($darkTint['background-color'] ?? null)->toContain('color-mix')
        ->and($darkTint['background-color'] ?? null)->toContain('var(--surface-elevated)');
});

it('ensures modal backdrop uses petroleum navy derived veil', function () {
    $light = findPrimitiveDeclarations('.fi-modal-close-overlay');
    $dark = findPrimitiveDeclarations('.dark .fi-modal-close-overlay');

    expect($light['background-color'] ?? null)->toBe('rgba(6, 21, 28, 0.45)')
        ->and($light['backdrop-filter'] ?? null)->toContain('blur')
        ->and($dark['background-color'] ?? null)->toBe('rgba(6, 21, 28, 0.75)')
        ->and($dark['backdrop-filter'] ?? null)->toContain('blur');
});

it('ensures tooltips consume surface-elevated and arrow matches tooltip box', function () {
    $box = findPrimitiveDeclarations('.tippy-box');
    $darkBox = findPrimitiveDeclarations('.dark .tippy-box');

    expect($box['background-color'] ?? null)->toBe('var(--surface-elevated)')
        ->and($box['--tippy-background-color'] ?? null)->toBe('var(--surface-elevated)')
        ->and($box['color'] ?? null)->toBe('var(--text-primary)')
        ->and($darkBox['background-color'] ?? null)->toBe('var(--surface-elevated)')
        ->and($darkBox['--tippy-background-color'] ?? null)->toBe('var(--surface-elevated)')
        ->and($darkBox['color'] ?? null)->toBe('var(--text-primary)');

    $arrow = findPrimitiveDeclarations('.tippy-arrow');
    $darkArrow = findPrimitiveDeclarations('.dark .tippy-arrow');
    expect($arrow['color'] ?? null)->toBe('var(--surface-elevated)')
        ->and($darkArrow['color'] ?? null)->toBe('var(--surface-elevated)');

    $arrowTop = findPrimitiveDeclarations(".tippy-box[data-placement^='top'] > .tippy-arrow::before");
    expect($arrowTop['border-top-color'] ?? null)->toBe('var(--surface-elevated)');
});

it('verifies that Phase 1 theme tokens file remains unmodified and frozen', function () {
    $tokensFile = file_get_contents(resource_path('css/theme-tokens.css'));
    expect($tokensFile)->toContain('--surface-canvas: #ece9e8;')
        ->and($tokensFile)->toContain('--surface-canvas: #06151c;')
        ->and($tokensFile)->toContain('--color-navy-950: #06151c;');
});

it('verifies that Phase 2 components remain intact and frozen', function () {
    $css = file_get_contents(resource_path('css/filament/admin/theme.css'));
    expect($css)->toContain('.fi-dropdown-panel')
        ->and($css)->toContain('.fi-ta-filters')
        ->and($css)->toContain('.fi-fo-date-time-picker-panel');

    $monthPicker = file_get_contents(resource_path('css/filament/admin/month-picker.css'));
    expect($monthPicker)->toContain('.bsi-month-picker-panel');
});
