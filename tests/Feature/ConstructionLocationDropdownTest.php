<?php

use App\Filament\Resources\Constructions\ConstructionResource;
use App\Filament\Resources\Constructions\Pages\CreateConstruction;
use App\Filament\Resources\Constructions\Pages\EditConstruction;
use App\Filament\Resources\Constructions\Schemas\ConstructionForm;
use App\Models\Construction;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Component;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(makeAdminUser());
});

function normalizeConstructionThemeDeclarations(string $declarations): string
{
    return trim((string) preg_replace('/\s+/', ' ', $declarations));
}

function constructionFormSchema(): Schema
{
    $livewire = new class extends Component implements HasSchemas
    {
        use InteractsWithSchemas;

        public function render(): string
        {
            return '<div></div>';
        }
    };

    return ConstructionForm::configure(Schema::make($livewire));
}

function constructionResourceSchema(): Schema
{
    $livewire = new class extends Component implements HasSchemas
    {
        use InteractsWithSchemas;

        public function render(): string
        {
            return '<div></div>';
        }
    };

    return ConstructionResource::form(Schema::make($livewire));
}

function constructionLocationSection(): Section
{
    $section = collect(constructionFormSchema()->getComponents())
        ->first(fn (mixed $component): bool => $component instanceof Section && $component->getHeading() === 'Localização');

    expect($section)->not->toBeNull();

    return $section;
}

/**
 * Returns the selector list and the declarations of the single theme rule whose
 * selector list contains the given selector verbatim.
 *
 * @return array{selectors: array<int, string>, declarations: string}
 */
function constructionThemeRuleWithSelector(string $selector): array
{
    $css = preg_replace('~/\*.*?\*/~s', '', file_get_contents(resource_path('css/filament/admin/theme.css')));

    preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER);

    $matches = collect($rules)
        ->map(fn (array $rule): array => [
            'selectors' => array_map(trim(...), preg_split('/,(?![^(]*\))/', $rule[1])),
            'declarations' => $rule[2],
        ])
        ->filter(fn (array $rule): bool => in_array($selector, $rule['selectors'], true))
        ->values();

    expect($matches)->toHaveCount(1);

    return $matches->first();
}

/**
 * O select "Estado" já abriu um dropdown da largura da viewport: carregava a
 * classe `fi-fixed-positioning-context`, que força `position: fixed` no painel,
 * e com bloco de contenção na viewport o `min-width: 100%` do tema resolvia
 * para 100vw. O Floating UI então colava o painel gigante na borda esquerda.
 */
it('keeps the Estado dropdown anchored to its trigger instead of the viewport', function () {
    $stateSelect = collect(constructionLocationSection()->getChildComponents())
        ->first(fn (mixed $component): bool => $component instanceof Select && $component->getName() === 'state');

    expect($stateSelect)->not->toBeNull()
        ->and(json_encode($stateSelect->getExtraAttributes()))->not->toContain('fi-fixed-positioning-context');
});

it('scopes the Estado dropdown height cap to the location section', function () {
    $sectionAttributes = constructionLocationSection()->getExtraAttributes();

    expect(json_encode($sectionAttributes))->toContain('bsi-construction-location-section');

    $theme = file_get_contents(resource_path('css/filament/admin/theme.css'));

    expect($theme)->toContain('.bsi-construction-location-section .fi-select-input .fi-dropdown-panel {')
        ->and($theme)->toContain('max-height: min(20rem, calc(100vh - 2rem)) !important;');
});

it('keeps the Estado options, search and validation unchanged', function () {
    $stateSelect = collect(constructionLocationSection()->getChildComponents())
        ->first(fn (mixed $component): bool => $component instanceof Select && $component->getName() === 'state');

    expect($stateSelect)->toBeInstanceOf(Select::class)
        ->and($stateSelect->getLabel())->toBe('Estado')
        ->and($stateSelect->getOptions())->toBe(Construction::STATE_OPTIONS)
        ->and($stateSelect->isSearchable())->toBeTrue()
        ->and($stateSelect->isRequired())->toBeTrue()
        ->and($stateSelect->getStatePath())->toBe('state')
        ->and($stateSelect->getExtraAttributes()['class'])->not->toContain('bsi-anchored-filter-dropdown');
});

it('limits the Estado dropdown styling marker to the state field', function () {
    $markedFields = collect(constructionFormSchema()->getFlatComponents(withHidden: true))
        ->filter(fn (mixed $component): bool => $component instanceof Field)
        ->filter(fn (Field $field): bool => in_array(
            'bsi-field-state',
            explode(' ', $field->getExtraAttributes()['class'] ?? ''),
            true,
        ))
        ->map(fn (Field $field): string => $field->getName())
        ->values()
        ->all();

    expect($markedFields)->toBe(['state']);
});

/**
 * Os dois wrappers do input têm `overflow: hidden` para o recorte do campo e
 * cortavam o popup absoluto na área do trigger: a busca parecia espremida dentro
 * do próprio campo. A liberação vale no cadastro e na edição de obra, que
 * compartilham o mesmo schema.
 */
it('releases the Estado popup from the clipping input wrappers on the construction create and edit pages', function () {
    $rule = constructionThemeRuleWithSelector(
        ':is(.fi-resource-create-record-page, .fi-resource-edit-record-page).fi-resource-constructions form.fi-sc-form .bsi-field-state > .fi-input-wrp-content-ctn',
    );

    expect($rule['selectors'])
        ->toContain(':is(.fi-resource-create-record-page, .fi-resource-edit-record-page).fi-resource-constructions form.fi-sc-form .bsi-field-state')
        ->and($rule['declarations'])->toContain('overflow: visible !important');
});

it('gives the Estado popup the same panel treatment as the Emissão popup on create and edit', function (string $part) {
    $stateRule = constructionThemeRuleWithSelector(
        ":is(.fi-resource-create-record-page, .fi-resource-edit-record-page).fi-resource-constructions .bsi-field-state {$part}",
    );

    $emissionRule = constructionThemeRuleWithSelector(
        ":is(.fi-resource-create-record-page, .fi-resource-edit-record-page).fi-resource-constructions .bsi-construction-emission-select {$part}",
    );

    expect(normalizeConstructionThemeDeclarations($stateRule['declarations']))
        ->toBe(normalizeConstructionThemeDeclarations($emissionRule['declarations']));
})->with([
    'panel' => '.fi-dropdown-panel',
    'open panel layout' => '.fi-select-input-btn[aria-expanded="true"] + .fi-dropdown-panel',
    'search' => '.fi-select-input-search-ctn',
    'options list' => '.fi-dropdown-list',
]);

it('keeps the search on top and scrolls only the Estado options list on create and edit', function () {
    $scope = ':is(.fi-resource-create-record-page, .fi-resource-edit-record-page).fi-resource-constructions .bsi-field-state';

    $panel = constructionThemeRuleWithSelector("{$scope} .fi-dropdown-panel");
    $list = constructionThemeRuleWithSelector("{$scope} .fi-dropdown-list");
    $search = constructionThemeRuleWithSelector("{$scope} .fi-select-input-search-ctn");

    // A largura é o `width` inline do Filament (a do trigger); o tema só anula o piso de 100%.
    expect($panel['declarations'])
        ->toContain('min-width: 0 !important')
        ->toContain('max-height: min(22.5rem, 44vh) !important')
        ->toContain('overflow: hidden !important')
        ->not->toContain('max-width');

    expect($list['declarations'])
        ->toContain('overflow-y: auto')
        ->toContain('overflow-x: hidden')
        ->toContain('grid-auto-rows: min-content');

    expect($search['declarations'])->toContain('flex: 0 0 auto');
});

it('reserves space for the clear button and chevron on the selected Estado on create and edit', function () {
    $rule = constructionThemeRuleWithSelector(
        ':is(.fi-resource-create-record-page, .fi-resource-edit-record-page).fi-resource-constructions .bsi-field-state .fi-select-input-ctn-clearable .fi-select-input-btn',
    );

    expect($rule['declarations'])->toContain('padding-inline-end: 3.5rem !important');
});

it('shares the same marked state field between the construction create and edit pages', function () {
    expect(CreateConstruction::getResource())->toBe(ConstructionResource::class)
        ->and(EditConstruction::getResource())->toBe(ConstructionResource::class);

    $markedFields = collect(constructionResourceSchema()->getFlatComponents(withHidden: true))
        ->filter(fn (mixed $component): bool => $component instanceof Field)
        ->filter(fn (Field $field): bool => in_array(
            'bsi-field-state',
            explode(' ', $field->getExtraAttributes()['class'] ?? ''),
            true,
        ))
        ->map(fn (Field $field): string => $field->getName())
        ->values()
        ->all();

    expect($markedFields)->toBe(['state']);
});

it('renders the marked state field with the record state selected on the edit page', function () {
    $construction = Construction::factory()->create([
        'state' => 'PB',
    ]);

    Livewire::test(EditConstruction::class, [
        'record' => $construction->getRouteKey(),
    ])
        ->assertFormFieldExists('state', fn (Select $field): bool => in_array(
            'bsi-field-state',
            explode(' ', $field->getExtraAttributes()['class'] ?? ''),
            true,
        ))
        ->assertSchemaStateSet([
            'state' => 'PB',
        ]);
});

it('renders the marked state field on the create page', function () {
    Livewire::test(CreateConstruction::class)
        ->assertFormFieldExists('state', fn (Select $field): bool => in_array(
            'bsi-field-state',
            explode(' ', $field->getExtraAttributes()['class'] ?? ''),
            true,
        ));
});
