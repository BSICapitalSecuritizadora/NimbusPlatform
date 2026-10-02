<?php

use App\DTOs\SalesBoards\SalesBoardBuilderDivergenceInput;
use App\Enums\SalesBoardBuilderDivergenceType;
use App\Enums\SalesBoardBuilderReviewSection;
use App\Enums\SalesBoardUnitClassification;
use App\Filament\Resources\ConstructionUnits\Pages\ListConstructionUnits;
use App\Filament\Resources\SalesBoardCycles\Pages\ManagementReviewWorkspace;
use App\Filament\Resources\SalesBoardCycles\Pages\ViewSalesBoardCycle;
use App\Filament\Resources\SalesBoardRollouts\Pages\PreviewSalesBoardReadiness;
use App\Models\ConstructionUnit;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\SalesBoards\BuilderReviewFixture;
use Tests\Support\SalesBoards\ExtemporaneousFixture;
use Tests\Support\SalesBoards\ManagementReviewFixture;
use Tests\Support\SalesBoards\RolloutFixture;

/**
 * Legibilidade das telas do Quadro nos dois temas, no que o PHP consegue provar:
 * as classes que as páginas imprimem e as regras do tema que as pintam. O
 * contraste medido no navegador fica com o smoke; aqui fica a regra que o
 * produz, para ela não voltar sem que um teste perceba.
 *
 * Nenhuma das correções usa `:not(.dark)`, que casa também no modo escuro: a
 * cor vale nos dois temas quando o fundo é escuro nos dois, e o gêmeo `.dark`
 * leva a cor clara quando só o modo escuro a pede.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(makeAdminUser());
});

/**
 * As declarações das regras que têm exatamente o seletor dado, na ordem do
 * arquivo, sem `!important`.
 *
 * @return array<string, string>
 */
function readabilityCssDeclarations(string $stylesheet, string $selector): array
{
    $css = preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(resource_path($stylesheet)));

    preg_match_all('/([^{}]+)\{([^{}]*)\}/', (string) $css, $matches, PREG_SET_ORDER);

    $declarations = [];

    foreach ($matches as [, $selectorList, $body]) {
        $selectors = array_map('trim', preg_split('/,(?![^(]*\))/', trim($selectorList)));

        if (! in_array($selector, $selectors, true)) {
            continue;
        }

        foreach (array_filter(array_map('trim', explode(';', $body))) as $declaration) {
            if (str_contains($declaration, ':')) {
                [$property, $value] = array_map('trim', explode(':', $declaration, 2));
                $declarations[$property] = trim(str_replace('!important', '', $value));
            }
        }
    }

    return $declarations;
}

/**
 * As classes do botão de cabeçalho com o rótulo dado.
 *
 * @return list<string>
 */
function readabilityHeaderButtonClasses(string $html, string $label): array
{
    preg_match('/<button\b[^>]*\bclass="([^"]*)"[^>]*>(?:(?!<\/button>).)*?'.preg_quote($label, '/').'/s', $html, $match);

    return array_values(array_filter(explode(' ', $match[1] ?? '')));
}

it('keeps the outlined actions of the dark cycle header on the dark-mode shade in both themes', function () {
    $stylesheet = 'css/filament/admin/sales-board-cycle.css';
    $button = '.bsi-sales-board-cycle-view-page .fi-header-actions-ctn .fi-btn.fi-outlined.fi-color';

    expect(readabilityCssDeclarations($stylesheet, $button))->toMatchArray([
        'color' => 'var(--dark-text)',
        '--tw-ring-color' => 'var(--color-500)',
    ])
        ->and(readabilityCssDeclarations($stylesheet, $button.' > .fi-icon'))->toMatchArray(['color' => 'var(--color-400)'])
        // O cabeçalho é escuro nos dois temas: nenhuma variante por tema.
        ->and(readabilityCssDeclarations($stylesheet, '.dark '.$button))->toBe([])
        ->and(readabilityCssDeclarations($stylesheet, ':not(.dark) '.$button))->toBe([]);

    // As ações são botões de contorno coloridos, e a classe de cor do modo
    // escuro -- a que define `--dark-text` -- vem em qualquer tema.
    $scenario = ExtemporaneousFixture::rectifiableJuly();

    $rectify = readabilityHeaderButtonClasses(
        Livewire::test(ViewSalesBoardCycle::class, ['record' => $scenario['july']->getKey()])->html(),
        'Retificar competência',
    );

    ExtemporaneousFixture::rectify($scenario['july']);

    $abandon = readabilityHeaderButtonClasses(
        Livewire::test(ViewSalesBoardCycle::class, ['record' => $scenario['july']->getKey()])->html(),
        'Desistir da retificação',
    );

    foreach ([$rectify, $abandon] as $classes) {
        expect($classes)->toContain('fi-outlined')
            ->toContain('fi-color')
            ->and(collect($classes)->contains(fn (string $class): bool => str_starts_with($class, 'dark:fi-text-color-')))->toBeTrue();
    }
});

it('paints the text of the non-native date picker light on the petrol wizard field in both themes', function () {
    $stylesheet = 'css/filament/admin/theme.css';
    $picker = '.bsi-cockpit-page .fi-sc-wizard input.fi-fo-date-time-picker-display-text-input';

    expect(readabilityCssDeclarations($stylesheet, '.bsi-cockpit-page .fi-sc-wizard .fi-input-wrp'))->toMatchArray(['background' => 'rgba(6, 21, 28, 0.7)'])
        ->and(readabilityCssDeclarations($stylesheet, $picker))->toMatchArray(['color' => '#fbfaf8'])
        ->and(readabilityCssDeclarations($stylesheet, '.dark '.$picker))->toMatchArray(['color' => '#fbfaf8'])
        ->and(readabilityCssDeclarations($stylesheet, ':not(.dark) '.$picker))->toBe([]);
});

it('keeps the readiness badge as wide as its label', function () {
    $scenario = RolloutFixture::emission(1);

    // Unidade sem valor: a prévia sai bloqueada.
    ConstructionUnit::factory()->create([
        'construction_id' => $scenario['constructions'][0]->id,
        'block' => '01',
        'unit' => '950',
        'base_value' => null,
        'base_value_reference_date' => null,
    ]);

    $html = Livewire::test(PreviewSalesBoardReadiness::class, ['record' => $scenario['emission']->getKey()])
        ->callAction('calculatePreview', data: ['reference_month' => '2026-07-01 00:00:00'])
        ->assertHasNoActionErrors()
        ->assertSee('Bloqueada')
        ->html();

    preg_match_all('/<span\b[^>]*\bclass="([^"]*\bfi-badge\b[^"]*)"[^>]*>\s*(?:<[^>]+>\s*)*Bloqueada\b/s', $html, $badges);

    expect($badges[1])->not->toBeEmpty();

    foreach ($badges[1] as $classes) {
        expect(explode(' ', $classes))->toContain('min-w-max');
    }
});

it('writes the divergence count of a validation section in a dark warning shade on the light theme', function () {
    $scenario = BuilderReviewFixture::generatedCycle();
    $builderReview = BuilderReviewFixture::open($scenario['cycle']);

    BuilderReviewFixture::declare($builderReview, SalesBoardBuilderReviewSection::PositionFinanced, new SalesBoardBuilderDivergenceInput(
        type: SalesBoardBuilderDivergenceType::StockMismatch,
        lineId: BuilderReviewFixture::lineFor($builderReview, $scenario['units']['financed'])->id,
        declaredClassification: SalesBoardUnitClassification::Stock,
        reason: 'A unidade foi distratada em junho e voltou ao estoque.',
    ));

    BuilderReviewFixture::confirmAll($builderReview);
    BuilderReviewFixture::submit($builderReview);
    ManagementReviewFixture::open($scenario['cycle']);

    $html = Livewire::test(ManagementReviewWorkspace::class, ['record' => $scenario['cycle']->getKey()])
        ->assertSee('1 divergência(s)')
        ->html();

    preg_match('/<p class="([^"]*)" data-section-divergences>/', $html, $match);

    // O 600 sobre o cartão claro ficava em 2,5:1; o 800 passa de 4,5:1.
    expect(explode(' ', $match[1] ?? ''))->toContain('text-warning-800')
        ->toContain('dark:text-warning-400')
        ->not->toContain('text-warning-600');
});

it('shows the unit number and block in the default color on the light theme and white only in dark mode', function () {
    ConstructionUnit::factory()->create(['block' => '02', 'unit' => '504']);

    $html = Livewire::test(ListConstructionUnits::class)
        ->assertSee('504')
        ->html();

    preg_match('/class="([^"]*\btracking-tight\b[^"]*\btabular-nums\b[^"]*)"/', $html, $match);

    $stylesheet = 'css/filament/admin/theme.css';
    $block = '.bsi-construction-units-list-page .fi-ta-cell-block .fi-badge';

    expect(explode(' ', $match[1] ?? ''))->toContain('dark:text-white')
        ->not->toContain('text-white')
        ->and(readabilityCssDeclarations($stylesheet, $block))->toMatchArray(['color' => 'var(--gray-700)'])
        ->and(readabilityCssDeclarations($stylesheet, '.dark '.$block))->toMatchArray(['color' => 'rgba(251, 250, 248, 0.9)']);
});
