<?php

/**
 * Contraste do tema do painel nos dois modos.
 *
 * Dois defeitos do tema claro: o título branco-sobre-branco nas páginas com o
 * cabeçalho escuro de formulário (o título herdava o texto escuro sobre o
 * gradiente escuro) e o select nativo com texto branco sobre o fundo claro dos
 * modais (a regra global pintava o texto de branco nos dois modos). A
 * correção não usa `:not(.dark)`, que casa também no modo escuro: a cor clara
 * do título vale nos dois temas, porque o cabeçalho é escuro nos dois, e a do
 * select só existe no gêmeo `.dark`.
 */

/**
 * Regras do tema, na ordem do arquivo, com os seletores e as declarações.
 *
 * @return list<array{selectors: list<string>, declarations: array<string, string>}>
 */
function adminThemeRules(): array
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

/**
 * Os índices das regras que declaram exatamente o seletor dado.
 *
 * @return list<int>
 */
function adminThemeRuleIndexes(string $selector): array
{
    return array_keys(array_filter(
        adminThemeRules(),
        fn (array $rule): bool => in_array($selector, $rule['selectors'], true),
    ));
}

it('gives the heading a light color on every page with the dark form header', function () {
    $pages = ['.bsi-construction-form-page', '.bsi-operation-form-page', '.bsi-measurement-form-page', '.bsi-fund-form-page'];
    $rules = adminThemeRules();

    foreach ($pages as $page) {
        $header = collect(adminThemeRuleIndexes($page.' .fi-header'))
            ->map(fn (int $index): array => $rules[$index]['declarations'])
            ->first(fn (array $declarations): bool => isset($declarations['background']));

        $headingColors = collect(adminThemeRuleIndexes($page.' .fi-header-heading'))
            ->map(fn (int $index): ?string => $rules[$index]['declarations']['color'] ?? null)
            ->filter()
            ->values()
            ->all();

        expect($header['background'] ?? null)->toContain('#091b23')
            ->and($headingColors)->toBe(['#fbfaf8']);

        // Nenhuma regra de modo claro sobrescreve a cor do título.
        expect(adminThemeRuleIndexes(':not(.dark) '.$page.' .fi-header-heading'))->toBe([]);
    }
});

it('paints native selects light only in dark mode', function () {
    $rules = adminThemeRules();

    $global = adminThemeRuleIndexes('select.fi-select-input');
    $dark = adminThemeRuleIndexes('.dark select.fi-select-input');

    expect($global)->not->toBeEmpty()
        ->and($dark)->not->toBeEmpty();

    foreach ($global as $index) {
        expect($rules[$index]['declarations'])->not->toHaveKey('color')
            ->and($rules[$index]['declarations'])->not->toHaveKey('color-scheme');
    }

    $darkDeclarations = collect($dark)->map(fn (int $index): array => $rules[$index]['declarations'])->collapse()->all();

    expect($darkDeclarations['color'] ?? null)->toBe('#fbfaf8')
        ->and($darkDeclarations['color-scheme'] ?? null)->toBe('dark')
        // O gêmeo escuro vem depois da regra global.
        ->and(min($dark))->toBeGreaterThan(max($global));

    // As opções do dropdown nativo também só são escuras no modo escuro.
    expect(adminThemeRuleIndexes('select.fi-select-input option'))->toBe([])
        ->and(adminThemeRuleIndexes('select.fi-select-input option:checked'))->toBe([])
        ->and(adminThemeRuleIndexes('.dark select.fi-select-input option'))->not->toBeEmpty()
        ->and(adminThemeRuleIndexes('.dark select.fi-select-input option:checked'))->not->toBeEmpty();
});

it('keeps the table filters native select readable on light and dark panels', function () {
    $rules = adminThemeRules();

    $filters = adminThemeRuleIndexes('.fi-ta-filters select.fi-select-input');
    $darkFilters = adminThemeRuleIndexes('.dark .fi-ta-filters select.fi-select-input');

    expect($filters)->not->toBeEmpty()
        ->and(collect($filters)->map(fn (int $index): ?string => $rules[$index]['declarations']['color'] ?? null)->filter()->values()->all())
        ->toBe(['var(--text-primary)'])
        ->and($darkFilters)->not->toBeEmpty()
        ->and(collect($darkFilters)->map(fn (int $index): ?string => $rules[$index]['declarations']['color'] ?? null)->filter()->values()->all())
        ->toBe(['#fbfaf8']);
});
