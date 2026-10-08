<?php

/**
 * Phase 6 — Charts, Analytics & Visualizations Theme Contract & Regression Tests
 *
 * Verifies that administrative chart widgets, canvas visualizations, legends,
 * tooltips, axes, grid lines, and empty states strictly consume canonical
 * semantic tokens (--surface-*, --text-*, --border-*, --accent-*),
 * eliminate private color palettes and forced dark backgrounds in Light Mode,
 * and preserve functional financial and status colors.
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

it('ensures global Filament ChartWidget semantic primitives are declared in theme.css', function () {
    $themeCssPath = resource_path('css/filament/admin/theme.css');
    expect(file_exists($themeCssPath))->toBeTrue();

    $content = file_get_contents($themeCssPath);

    // Global .fi-wi-chart semantic configuration block
    expect($content)->toContain('.fi-wi-chart {')
        ->and($content)->toContain('--chart-tooltip-corner-radius: 8px;')
        ->and($content)->toContain('--chart-tooltip-border-width: 1px;')
        ->and($content)->toContain('.fi-wi-chart .fi-wi-chart-bg-color')
        ->and($content)->toContain('color: var(--surface-card);')
        ->and($content)->toContain('.fi-wi-chart .fi-wi-chart-border-color')
        ->and($content)->toContain('color: var(--border-subtle);')
        ->and($content)->toContain('.fi-wi-chart .fi-wi-chart-grid-color')
        ->and($content)->toContain('color: var(--border-subtle);')
        ->and($content)->toContain('.fi-wi-chart .fi-wi-chart-text-color')
        ->and($content)->toContain('color: var(--text-secondary);')
        ->and($content)->toContain('.fi-wi-chart .fi-wi-chart-tooltip-bg-color')
        ->and($content)->toContain('color: var(--surface-elevated);')
        ->and($content)->toContain('.fi-wi-chart .fi-wi-chart-tooltip-text-color')
        ->and($content)->toContain('color: var(--text-primary);')
        ->and($content)->toContain('.fi-wi-chart .fi-wi-chart-tooltip-border-color')
        ->and($content)->toContain('color: var(--border-subtle);');
});

it('ensures Obligation Dashboard chart cards and headers consume canonical semantic tokens', function () {
    $themeCssPath = resource_path('css/filament/admin/theme.css');
    $content = file_get_contents($themeCssPath);

    // Cards consume semantic tokens without legacy hardcoded colors
    expect($content)->toContain('.bsi-obligation-dashboard .fi-wi-chart > .fi-section {')
        ->and($content)->toContain('background: var(--surface-card) !important;')
        ->and($content)->toContain('border: 1px solid var(--border-subtle) !important;')
        ->and($content)->toContain('.dark .bsi-obligation-dashboard .fi-wi-chart > .fi-section {')
        ->and($content)->toContain('background: var(--surface-card) !important;')
        ->and($content)->toContain('border-color: var(--border-subtle) !important;')
        ->and($content)->not->toMatch('/\.bsi-obligation-dashboard \.fi-wi-chart > \.fi-section\s*\{[^}]*background:\s*#fbfaf8/s')
        ->and($content)->not->toMatch('/\.dark \.bsi-obligation-dashboard \.fi-wi-chart > \.fi-section\s*\{[^}]*background:\s*#0d252e/s');

    // Section headers consume semantic tokens
    expect($content)->toContain('.bsi-obligation-dashboard .fi-wi-chart .fi-section-header {')
        ->and($content)->toContain('background: var(--surface-ground) !important;')
        ->and($content)->toContain('border-bottom: 1px solid var(--border-subtle) !important;')
        ->and($content)->toContain('.dark .bsi-obligation-dashboard .fi-wi-chart .fi-section-header {')
        ->and($content)->toContain('background: var(--surface-ground) !important;')
        ->and($content)->toContain('color: var(--text-primary) !important;')
        ->and($content)->toContain('color: var(--text-secondary) !important;');
});

it('ensures Proposal Volume Widget period filter consumes canonical semantic tokens', function () {
    $themeCssPath = resource_path('css/filament/admin/theme.css');
    $content = file_get_contents($themeCssPath);

    expect($content)->toContain('.bsi-proposal-volume-widget .fi-wi-chart-filter.fi-input-wrp {')
        ->and($content)->toContain('background-color: var(--surface-card) !important;')
        ->and($content)->toContain('border: 1px solid var(--border-subtle) !important;')
        ->and($content)->toContain('border-color: var(--accent) !important;')
        ->and($content)->toContain('color: var(--text-primary) !important;')
        ->and($content)->toContain('background-color: var(--surface-highlight) !important;');
});

it('ensures all custom chart widget blade views contain tooltip sentinel elements', function () {
    $views = [
        resource_path('views/filament/widgets/proposals/proposal-volume-chart-widget.blade.php'),
        resource_path('views/filament/widgets/proposals/proposal-status-distribution-chart-widget.blade.php'),
        resource_path('views/filament/widgets/nimbus/nimbus-volume-chart-widget.blade.php'),
        resource_path('views/filament/widgets/nimbus/nimbus-status-distribution-widget.blade.php'),
    ];

    foreach ($views as $viewPath) {
        expect(file_exists($viewPath))->toBeTrue();
        $content = file_get_contents($viewPath);

        expect($content)->toContain('x-ref="tooltipBackgroundColorElement"')
            ->and($content)->toContain('class="fi-wi-chart-tooltip-bg-color"')
            ->and($content)->toContain('x-ref="tooltipTextColorElement"')
            ->and($content)->toContain('class="fi-wi-chart-tooltip-text-color"')
            ->and($content)->toContain('x-ref="tooltipBorderColorElement"')
            ->and($content)->toContain('class="fi-wi-chart-tooltip-border-color"');
    }
});

it('ensures Nimbus dashboard chart blade views have eliminated forced dark slate containers in light mode', function () {
    $volumeViewPath = resource_path('views/filament/widgets/nimbus/nimbus-volume-chart-widget.blade.php');
    $statusViewPath = resource_path('views/filament/widgets/nimbus/nimbus-status-distribution-widget.blade.php');

    $volumeContent = file_get_contents($volumeViewPath);
    $statusContent = file_get_contents($statusViewPath);

    // No hardcoded dark slate backgrounds for cards or empty states in Light Mode
    expect($volumeContent)->not->toContain('bg-slate-800/60')
        ->and($volumeContent)->not->toContain('bg-slate-900/60')
        ->and($statusContent)->not->toContain('bg-slate-800/60')
        ->and($statusContent)->not->toContain('class="h-1.5 w-full overflow-hidden rounded-full bg-slate-800"');
});

it('ensures ChartWidget PHP classes have purged hardcoded petroleum borders and white grid colors', function () {
    $widgets = [
        app_path('Filament/Widgets/Obligations/ObligationPriorityDistributionChartWidget.php'),
        app_path('Filament/Widgets/Obligations/ObligationStatusDistributionChartWidget.php'),
        app_path('Filament/Widgets/Obligations/ObligationOverdueAgingChartWidget.php'),
        app_path('Filament/Widgets/Obligations/ObligationsByAreaChartWidget.php'),
        app_path('Filament/Widgets/Obligations/ObligationsByEmissionChartWidget.php'),
        app_path('Filament/Widgets/Obligations/ObligationsByResponsibleChartWidget.php'),
        app_path('Filament/Widgets/Proposals/ProposalStatusDistributionChartWidget.php'),
        app_path('Filament/Widgets/Proposals/ProposalVolumeChartWidget.php'),
        app_path('Filament/NimbusWidgets/NimbusStatusDistribution.php'),
        app_path('Filament/NimbusWidgets/NimbusVolumeChart.php'),
    ];

    foreach ($widgets as $widgetPath) {
        expect(file_exists($widgetPath))->toBeTrue();
        $content = file_get_contents($widgetPath);

        // No hardcoded petroleum border colors on datasets
        expect($content)->not->toContain("'borderColor' => '#0d252e'")
            ->and($content)->not->toContain("'borderColor' => '#091b23'")
            ->and($content)->not->toContain("'pointBorderColor' => '#0d252e'")
            // No hardcoded grid colors forcing dark-mode assumptions in options
            ->and($content)->not->toContain("grid: { color: 'rgba(255, 255, 255, 0.05)' }")
            ->and($content)->not->toContain("'color' => 'rgba(148, 163, 184, 0.08)'");
    }
});

it('ensures functional status colors and brand accents are strictly preserved in chart datasets', function () {
    $statusContent = file_get_contents(app_path('Filament/Widgets/Obligations/ObligationStatusDistributionChartWidget.php'));
    expect($statusContent)->toContain('#10b981') // Em dia
        ->and($statusContent)->toContain('#38bdf8') // A vencer
        ->and($statusContent)->toContain('#ef4444') // Vencida
        ->and($statusContent)->toContain('#14b8a6') // Concluída
        ->and($statusContent)->toContain('#f59e0b') // Em análise
        ->and($statusContent)->toContain('#64748b'); // Não aplicável

    $priorityContent = file_get_contents(app_path('Filament/Widgets/Obligations/ObligationPriorityDistributionChartWidget.php'));
    expect($priorityContent)->toContain('#94a3b8') // Baixa
        ->and($priorityContent)->toContain('#38bdf8') // Média
        ->and($priorityContent)->toContain('#f59e0b') // Alta
        ->and($priorityContent)->toContain('#ef4444'); // Crítica

    $volumeContent = file_get_contents(app_path('Filament/Widgets/Proposals/ProposalVolumeChartWidget.php'));
    expect($volumeContent)->toContain('#b7832f') // Novos Envios (Gold)
        ->and($volumeContent)->toContain('#059669'); // Formalizações Concluídas (Green)
});

it('ensures custom Blade visualization views consume semantic theme tokens and eliminate raw neutral dark utilities', function () {
    $views = [
        resource_path('views/filament/widgets/proposals/proposal-volume-chart-widget.blade.php'),
        resource_path('views/filament/widgets/proposals/proposal-status-distribution-chart-widget.blade.php'),
        resource_path('views/filament/widgets/nimbus/nimbus-volume-chart-widget.blade.php'),
        resource_path('views/filament/widgets/nimbus/nimbus-status-distribution-widget.blade.php'),
    ];

    foreach ($views as $viewPath) {
        expect(file_exists($viewPath))->toBeTrue();
        $content = file_get_contents($viewPath);

        // Raw non-canonical color #06161d must be completely eliminated
        expect($content)->not->toContain('#06161d')
            ->and($content)->not->toContain('#091b23');

        // Manual neutral light/dark pairs must be eliminated
        expect($content)->not->toContain('text-gray-950 dark:text-white')
            ->and($content)->not->toContain('text-gray-500 dark:text-gray-400')
            ->and($content)->not->toContain('bg-gray-200/60 dark:bg-white/10')
            ->and($content)->not->toContain('bg-gray-200/60 dark:bg-gray-800')
            ->and($content)->not->toContain('bg-white/70');

        // Views must consume canonical semantic CSS variables
        expect($content)->toContain('var(--border-subtle)')
            ->and($content)->toContain('var(--text-primary)')
            ->and($content)->toContain('var(--text-secondary)')
            ->and($content)->toContain('var(--surface-ground)');
    }

    // Check specific semantic progress tracks and hover surfaces in distribution views
    $proposalDistContent = file_get_contents(resource_path('views/filament/widgets/proposals/proposal-status-distribution-chart-widget.blade.php'));
    expect($proposalDistContent)->toContain('bg-[var(--surface-highlight)]')
        ->and($proposalDistContent)->toContain('hover:bg-[var(--surface-highlight)]');

    $nimbusDistContent = file_get_contents(resource_path('views/filament/widgets/nimbus/nimbus-status-distribution-widget.blade.php'));
    expect($nimbusDistContent)->toContain('bg-[var(--surface-highlight)]')
        ->and($nimbusDistContent)->toContain('hover:bg-[var(--surface-highlight)]');

    // Functional status colors and badges remain intact
    $proposalVolumeContent = file_get_contents(resource_path('views/filament/widgets/proposals/proposal-volume-chart-widget.blade.php'));
    expect($proposalVolumeContent)->toContain('bg-emerald-500/15 text-emerald-600 dark:text-emerald-400')
        ->and($proposalVolumeContent)->toContain('bg-blue-500/15 text-blue-600 dark:text-blue-400')
        ->and($proposalVolumeContent)->toContain('bg-bsi-gold-500/15 text-bsi-gold-500');

    $nimbusVolumeContent = file_get_contents(resource_path('views/filament/widgets/nimbus/nimbus-volume-chart-widget.blade.php'));
    expect($nimbusVolumeContent)->toContain('bg-sky-500/15 text-sky-500 dark:text-sky-400')
        ->and($nimbusVolumeContent)->toContain('bg-amber-500/15 text-amber-500 dark:text-amber-400')
        ->and($nimbusVolumeContent)->toContain('bg-emerald-500/15 text-emerald-500 dark:text-emerald-400');
});
