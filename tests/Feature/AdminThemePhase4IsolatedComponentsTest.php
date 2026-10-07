<?php

/**
 * Phase 4 — Critical Isolated Components Contract & Theme Regression Tests
 *
 * Verifies that specialized / isolated components (Spreadsheet Templates,
 * Expense Calendar, Permission Matrix, Job Applications KPI strip,
 * Custom Wizard / Steppers, and Activity Timeline) strictly consume
 * canonical Phase 1 semantic tokens and avoid hardcoded dark petroleum / slate traps.
 */
function phase4CssRules(): array
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

function findPhase4Declarations(string $selectorNeedle): array
{
    $rules = phase4CssRules();
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

it('ensures Spreadsheet Templates UI consumes semantic tokens without hardcoded dark petroleum surfaces', function () {
    $pageView = file_get_contents(resource_path('views/filament/pages/spreadsheet-templates.blade.php'));
    $cardComponent = file_get_contents(resource_path('views/components/spreadsheet-template-card.blade.php'));

    expect($pageView)->not->toContain('#091b23')
        ->and($pageView)->not->toContain('#0d252e')
        ->and($pageView)->not->toContain('#07181f')
        ->and($pageView)->toContain('var(--surface-card)')
        ->and($pageView)->toContain('var(--surface-ground)')
        ->and($pageView)->toContain('var(--border-subtle)');

    expect($cardComponent)->not->toContain('#091b23')
        ->and($cardComponent)->not->toContain('#0d252e')
        ->and($cardComponent)->toContain('var(--surface-card)')
        ->and($cardComponent)->toContain('var(--surface-ground)')
        ->and($cardComponent)->toContain('var(--border-subtle)');
});

it('ensures Expense Calendar consumes semantic tokens and has no white text on light surfaces', function () {
    $calendarView = file_get_contents(resource_path('views/filament/resources/expenses/pages/expense-calendar.blade.php'));

    expect($calendarView)->not->toContain('#0d252e')
        ->and($calendarView)->not->toContain('bsi-navy-900')
        ->and($calendarView)->not->toContain('text-slate-400')
        ->and($calendarView)->toContain('text-[var(--text-secondary)]')
        ->and($calendarView)->toContain('var(--surface-card)')
        ->and($calendarView)->toContain('var(--surface-ground)')
        ->and($calendarView)->toContain('var(--border-subtle)')
        ->and($calendarView)->toContain('var(--accent)');
});

it('ensures Permission Matrix consumes semantic tokens in theme CSS and Blade view', function () {
    $toolbar = findPhase4Declarations('.bsi-permission-matrix .bsi-perm-toolbar');
    $moduleCard = findPhase4Declarations('.bsi-permission-matrix .bsi-perm-module-card');
    $moduleHeader = findPhase4Declarations('.bsi-permission-matrix .bsi-perm-module-header');
    $item = findPhase4Declarations('.bsi-permission-matrix .bsi-perm-item');
    $activeItem = findPhase4Declarations('.bsi-permission-matrix .bsi-perm-item:has(input:checked)');

    expect($toolbar['background'] ?? null)->toBe('var(--surface-card)')
        ->and($toolbar['border'] ?? null)->toContain('var(--border-subtle)')
        ->and($moduleCard['background'] ?? null)->toBe('var(--surface-card)')
        ->and($moduleCard['border'] ?? null)->toContain('var(--border-subtle)')
        ->and($moduleHeader['background'] ?? null)->toBe('var(--surface-ground)')
        ->and($moduleHeader['border-bottom'] ?? null)->toContain('var(--border-subtle)')
        ->and($item['background'] ?? null)->toBe('var(--surface-ground)')
        ->and($item['border'] ?? null)->toContain('var(--border-subtle)')
        ->and($activeItem['background'] ?? null)->toBe('var(--accent-subtle)')
        ->and($activeItem['border-color'] ?? null)->toBe('var(--accent)');

    $matrixView = file_get_contents(resource_path('views/filament/forms/components/permission-matrix.blade.php'));
    expect($matrixView)->toContain('var(--surface-card)')
        ->and($matrixView)->toContain('var(--surface-ground)')
        ->and($matrixView)->toContain('var(--border-subtle)');
});

it('ensures Job Applications KPI bar consumes semantic tokens', function () {
    $overviewView = file_get_contents(resource_path('views/filament/widgets/recruitment/job-applications-overview.blade.php'));

    expect($overviewView)->not->toContain('#0d252e')
        ->and($overviewView)->toContain('var(--surface-card)')
        ->and($overviewView)->toContain('var(--border-subtle)');

    $kpiBarCss = findPhase4Declarations('.bsi-job-applications-list-page .bsi-job-applications-kpi-bar');
    expect($kpiBarCss['background'] ?? null)->toBe('var(--surface-card)')
        ->and($kpiBarCss['border'] ?? null)->toContain('var(--border-subtle)');
});

it('ensures Cockpit and Batch Create Wizard steppers consume semantic card, ground, and accent-foreground tokens', function () {
    $wizardContained = findPhase4Declarations('.bsi-cockpit-page .fi-sc-wizard.fi-contained');
    $wizardHeader = findPhase4Declarations('.bsi-cockpit-page .fi-sc-wizard-header');
    $wizardActiveStep = findPhase4Declarations('.bsi-cockpit-page .fi-sc-wizard-header-step.fi-active');
    $wizardActiveIcon = findPhase4Declarations('.bsi-cockpit-page .fi-sc-wizard-header-step.fi-active .fi-sc-wizard-header-step-number');
    $batchHeader = findPhase4Declarations('.bsi-batch-create-documents-page .fi-sc-wizard-header');
    $batchActive = findPhase4Declarations('.bsi-batch-create-documents-page .fi-sc-wizard-header-step.fi-active');
    $batchActiveIcon = findPhase4Declarations('.bsi-batch-create-documents-page .fi-sc-wizard-header-step.fi-active .fi-sc-wizard-header-step-icon-ctn');

    expect($wizardContained['background'] ?? null)->toBe('var(--surface-card)')
        ->and($wizardContained['border'] ?? null)->toContain('var(--border-subtle)')
        ->and($wizardHeader['background'] ?? null)->toBe('var(--surface-ground)')
        ->and($wizardHeader['border-bottom'] ?? null)->toContain('var(--border-subtle)')
        ->and($wizardActiveStep['background'] ?? null)->toBe('var(--accent-subtle)')
        ->and($wizardActiveIcon['color'] ?? null)->toBe('var(--accent-foreground)')
        ->and($batchHeader['background'] ?? null)->toBe('var(--surface-card)')
        ->and($batchHeader['border'] ?? null)->toContain('var(--border-subtle)')
        ->and($batchActive['background'] ?? null)->toBe('var(--accent-subtle)')
        ->and($batchActiveIcon['color'] ?? null)->toBe('var(--accent-foreground)');
});

it('ensures Activity Timeline consumes semantic tokens for card and rails', function () {
    $cardCss = findPhase4Declarations('.fi-activity-card');

    expect($cardCss['background'] ?? null)->toBe('var(--surface-card)')
        ->and($cardCss['border'] ?? null)->toContain('var(--border-subtle)');

    $timelineView = file_get_contents(resource_path('views/filament/tables/activity-timeline.blade.php'));
    expect($timelineView)->toContain('var(--surface-card)')
        ->and($timelineView)->toContain('var(--border-subtle)')
        ->and($timelineView)->not->toContain('dark:bg-[#0c222b]');
});
