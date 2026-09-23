<?php

use App\Filament\Resources\ExpenseServiceProviders\Pages\ListExpenseServiceProviders;
use App\Models\ExpenseServiceProvider;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * Rules of the admin theme whose selector list mentions the given class, in source order.
 *
 * @return list<array{selectors: list<string>, declarations: array<string, string>}>
 */
function perPageCssRules(string $selectorNeedle = '.fi-pagination-records-per-page-btn'): array
{
    $css = preg_replace('#/\*.*?\*/#s', '', file_get_contents(resource_path('css/filament/admin/theme.css')));

    preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $matches, PREG_SET_ORDER);

    $rules = [];

    foreach ($matches as [, $selectorList, $body]) {
        if (! str_contains($selectorList, $selectorNeedle)) {
            continue;
        }

        $declarations = [];

        foreach (array_filter(array_map('trim', explode(';', $body))) as $declaration) {
            [$property, $value] = array_map('trim', explode(':', $declaration, 2));
            $declarations[$property] = trim(str_replace('!important', '', $value));
        }

        $rules[] = [
            'selectors' => array_map('trim', preg_split('/,(?![^(]*\))/', $selectorList)),
            'declarations' => $declarations,
        ];
    }

    return $rules;
}

/**
 * Selectors that match the trigger element itself (not its prefix, value or chevron).
 */
function selectsPerPageTriggerElement(string $selector): bool
{
    return (bool) preg_match('/\.fi-pagination-records-per-page-btn(:[\w-]+|\[[^\]]+\])*$/', $selector);
}

/**
 * XPath over a real table page whose pagination renders the "por página" dropdown.
 *
 * @return array{xpath: DOMXPath, componentId: string}
 */
function renderedPerPagePagination(): array
{
    $user = makeAdminUser();
    $user->assignRole('super-admin');
    ExpenseServiceProvider::factory()->count(3)->create();

    $component = Livewire::actingAs($user)
        ->test(ListExpenseServiceProviders::class)
        ->assertSuccessful();

    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8"?>'.$component->html());

    return ['xpath' => new DOMXPath($document), 'componentId' => $component->id()];
}

function xpathHasClass(string $class): string
{
    return "contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')";
}

it('declares a dark twin after every light-mode rule of the trigger', function () {
    $rules = perPageCssRules();
    $lightSelectors = [];

    foreach ($rules as $index => $rule) {
        foreach ($rule['selectors'] as $selector) {
            if (str_starts_with($selector, ':not(.dark) ') && selectsPerPageTriggerElement($selector)) {
                $lightSelectors[$selector] = $index;
            }
        }
    }

    expect($lightSelectors)->toHaveKeys([
        ':not(.dark) .fi-pagination-records-per-page-btn',
        ':not(.dark) .fi-pagination-records-per-page-btn:hover',
    ]);

    foreach ($lightSelectors as $lightSelector => $lightIndex) {
        $darkSelector = '.dark '.substr($lightSelector, strlen(':not(.dark) '));

        $darkTwinDeclaredLater = collect($rules)
            ->filter(fn (array $rule, int $index): bool => $index > $lightIndex && in_array($darkSelector, $rule['selectors'], true))
            ->isNotEmpty();

        expect($darkTwinDeclaredLater)->toBeTrue("`{$lightSelector}` also matches in dark mode and needs `{$darkSelector}` declared after it.");
    }
});

it('keeps every dark-mode state of the trigger on the petrol blue background', function () {
    $petrolBackgrounds = ['#091b23', '#0d252e'];
    $checkedSelectors = [];

    foreach (perPageCssRules() as $rule) {
        if (! isset($rule['declarations']['background-color'])) {
            continue;
        }

        foreach ($rule['selectors'] as $selector) {
            if (str_starts_with($selector, ':not(.dark) ') || ! selectsPerPageTriggerElement($selector)) {
                continue;
            }

            expect($rule['declarations']['background-color'])->toBeIn($petrolBackgrounds, "`{$selector}` paints the trigger with a light background in dark mode.");

            $checkedSelectors[] = $selector;
        }
    }

    expect($checkedSelectors)->toContain(
        '.dark .fi-pagination-records-per-page-btn',
        '.dark .fi-pagination-records-per-page-btn:hover',
        '.dark .fi-pagination-records-per-page-btn[aria-expanded="true"]',
    );
});

it('styles the open state from the aria-expanded of the trigger button itself', function () {
    $openRule = collect(perPageCssRules())->first(
        fn (array $rule): bool => in_array('.dark .fi-pagination-records-per-page-btn[aria-expanded="true"]', $rule['selectors'], true),
    );

    expect($openRule['declarations'])->toMatchArray(['background-color' => '#0d252e']);
});

it('keeps a visible gold ring when the trigger is focused from the keyboard', function () {
    $focusVisibleRule = collect(perPageCssRules())->first(
        fn (array $rule): bool => collect($rule['selectors'])->contains(
            fn (string $selector): bool => selectsPerPageTriggerElement($selector) && str_ends_with($selector, ':focus-visible'),
        ),
    );

    expect($focusVisibleRule['declarations'])->toMatchArray([
        'outline' => '2px solid #b7832f',
        'border-color' => '#b7832f',
    ]);
});

it('scopes the trigger state rules to the pagination per-page component', function () {
    $stateSelectors = collect(perPageCssRules())
        ->pluck('selectors')
        ->flatten()
        ->filter(fn (string $selector): bool => (bool) preg_match('/:(hover|focus|focus-visible|active)\b|\[aria-expanded/', $selector));

    expect($stateSelectors)->not->toBeEmpty();

    $stateSelectors->each(fn (string $selector) => expect($selector)->toContain('.fi-pagination-records-per-page-btn'));
});

it('makes the dropdown wrapper the containing block of its floating panel', function () {
    $wrapperRule = collect(perPageCssRules('.fi-pagination-records-per-page-dropdown'))->first(
        fn (array $rule): bool => in_array('.fi-pagination-records-per-page-dropdown', $rule['selectors'], true),
    );

    expect($wrapperRule['declarations'])->toMatchArray(['contain' => 'layout']);
});

it('renders the trigger button inside the per-page container and the dropdown trigger', function () {
    ['xpath' => $xpath] = renderedPerPagePagination();

    $triggers = $xpath->query(
        '//div['.xpathHasClass('fi-pagination-records-per-page-select-ctn').']'
        .'//div['.xpathHasClass('fi-dropdown-trigger').']'
        .'/button['.xpathHasClass('fi-pagination-records-per-page-btn').']',
    );

    expect($triggers->length)->toBe(1);
});

it('keeps the dropdown panel element across Livewire re-renders', function () {
    ['xpath' => $xpath, 'componentId' => $componentId] = renderedPerPagePagination();

    $panels = $xpath->query(
        '//div['.xpathHasClass('fi-pagination-records-per-page-dropdown').']'
        .'/div['.xpathHasClass('fi-dropdown-panel').']',
    );

    expect($panels->length)->toBe(1)
        ->and($panels->item(0)->hasAttribute('wire:ignore.self'))->toBeTrue()
        ->and($panels->item(0)->getAttribute('wire:key'))->toBe("{$componentId}.pagination.records-per-page.panel");
});
