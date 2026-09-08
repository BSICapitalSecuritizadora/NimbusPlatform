<?php

it('lets the cockpit select panel fall back to the trigger width', function () {
    $css = file_get_contents(resource_path('css/filament/admin/theme.css'));

    expect($css)
        ->toContain('.bsi-measurement-filters-section .fi-dropdown-panel:has(.fi-select-input-options-ctn)')
        ->toContain('min-width: 0 !important');
});

it('caps the cockpit select panel within the viewport', function () {
    $css = file_get_contents(resource_path('css/filament/admin/theme.css'));

    expect($css)
        ->toContain('max-width: min(26.25rem, calc(100vw - 2rem))')
        ->toContain('max-height: min(20rem, calc(100vh - 2rem))');
});

it('keeps cockpit select options compact with elegant truncation', function () {
    $css = file_get_contents(resource_path('css/filament/admin/theme.css'));

    expect($css)
        ->toContain('.bsi-measurement-filters-section .fi-dropdown-panel .fi-select-input-option')
        ->toContain('padding: 0.5rem 0.75rem !important')
        ->toContain('text-overflow: ellipsis !important');
});

it('preserves the global select panel identity outside the cockpit scope', function () {
    $css = file_get_contents(resource_path('css/filament/admin/theme.css'));

    expect($css)
        ->toContain('.fi-dropdown-panel:has(.fi-select-input-options-ctn)')
        ->toContain('z-index: 10050 !important')
        ->toContain('.fi-dropdown-panel .fi-select-input-search-ctn');
});
