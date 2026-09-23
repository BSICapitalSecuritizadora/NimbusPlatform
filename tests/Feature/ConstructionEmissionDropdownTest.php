<?php

use App\Filament\Resources\Constructions\ConstructionResource;
use App\Filament\Resources\Constructions\Pages\CreateConstruction;
use App\Filament\Resources\Constructions\Pages\EditConstruction;
use App\Filament\Resources\Constructions\Schemas\ConstructionForm;
use App\Models\Construction;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
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

it('keeps the construction emission searchable relationship and validation unchanged', function () {
    $emissionSelect = collect(constructionEmissionDropdownSchema()->getFlatComponents(withHidden: true))
        ->first(fn (mixed $component): bool => $component instanceof Select && $component->getName() === 'emission_id');

    expect($emissionSelect)->toBeInstanceOf(Select::class)
        ->and($emissionSelect->getRelationshipName())->toBe('emission')
        ->and($emissionSelect->getRelationshipTitleAttribute())->toBe('name')
        ->and($emissionSelect->isSearchable())->toBeTrue()
        ->and($emissionSelect->isPreloaded())->toBeTrue()
        ->and($emissionSelect->isRequired())->toBeTrue()
        ->and($emissionSelect->getStatePath())->toBe('emission_id')
        ->and($emissionSelect->getExtraAttributes()['class'])->not->toContain('fi-fixed-positioning-context')
        ->not->toContain('bsi-anchored-filter-dropdown');
});

it('limits the construction emission dropdown styling marker to the emission field', function () {
    $markedFields = collect(constructionEmissionDropdownSchema()->getFlatComponents(withHidden: true))
        ->filter(fn (mixed $component): bool => $component instanceof Field)
        ->filter(fn (Field $field): bool => in_array(
            'bsi-construction-emission-select',
            explode(' ', $field->getExtraAttributes()['class'] ?? ''),
            true,
        ))
        ->map(fn (Field $field): string => $field->getName())
        ->values()
        ->all();

    expect($markedFields)->toBe(['emission_id']);
});

it('shares the same marked emission field between the construction create and edit pages', function () {
    expect(CreateConstruction::getResource())->toBe(ConstructionResource::class)
        ->and(EditConstruction::getResource())->toBe(ConstructionResource::class);

    $markedFields = collect(constructionEmissionResourceSchema()->getFlatComponents(withHidden: true))
        ->filter(fn (mixed $component): bool => $component instanceof Field)
        ->filter(fn (Field $field): bool => in_array(
            'bsi-construction-emission-select',
            explode(' ', $field->getExtraAttributes()['class'] ?? ''),
            true,
        ))
        ->map(fn (Field $field): string => $field->getName())
        ->values()
        ->all();

    expect($markedFields)->toBe(['emission_id']);
});

it('renders the marked emission field with the record emission selected on the edit page', function () {
    $construction = Construction::factory()->create();

    Livewire::test(EditConstruction::class, [
        'record' => $construction->getRouteKey(),
    ])
        ->assertFormFieldExists('emission_id', fn (Select $field): bool => in_array(
            'bsi-construction-emission-select',
            explode(' ', $field->getExtraAttributes()['class'] ?? ''),
            true,
        ))
        ->assertSchemaStateSet([
            'emission_id' => $construction->emission_id,
        ]);
});

it('renders the marked emission field on the create page', function () {
    Livewire::test(CreateConstruction::class)
        ->assertFormFieldExists('emission_id', fn (Select $field): bool => in_array(
            'bsi-construction-emission-select',
            explode(' ', $field->getExtraAttributes()['class'] ?? ''),
            true,
        ));
});

/**
 * Os dois wrappers do input têm `overflow: hidden` para o recorte do campo e
 * cortavam o popup absoluto na área do trigger: a busca parecia espremida dentro
 * do próprio campo. A liberação vale no cadastro e na edição de obra, que
 * compartilham o mesmo schema.
 */
it('releases the Emissão popup from the clipping input wrappers on the construction create and edit pages', function () {
    $rule = constructionEmissionThemeRuleWithSelector(
        ':is(.fi-resource-create-record-page, .fi-resource-edit-record-page).fi-resource-constructions form.fi-sc-form .bsi-construction-emission-select > .fi-input-wrp-content-ctn',
    );

    expect($rule['selectors'])
        ->toContain(':is(.fi-resource-create-record-page, .fi-resource-edit-record-page).fi-resource-constructions form.fi-sc-form .bsi-construction-emission-select')
        ->and($rule['declarations'])->toContain('overflow: visible !important');
});

it('keeps the search on top and scrolls only the Emissão options list on create and edit', function () {
    $scope = ':is(.fi-resource-create-record-page, .fi-resource-edit-record-page).fi-resource-constructions .bsi-construction-emission-select';

    $panel = constructionEmissionThemeRuleWithSelector("{$scope} .fi-dropdown-panel");
    $list = constructionEmissionThemeRuleWithSelector("{$scope} .fi-dropdown-list");
    $search = constructionEmissionThemeRuleWithSelector("{$scope} .fi-select-input-search-ctn");

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

it('truncates the selected Emissão label on create and edit without growing the trigger', function () {
    $rule = constructionEmissionThemeRuleWithSelector(
        ':is(.fi-resource-create-record-page, .fi-resource-edit-record-page).fi-resource-constructions .bsi-construction-emission-select .fi-select-input-value-label',
    );

    expect($rule['declarations'])
        ->toContain('overflow: hidden')
        ->toContain('text-overflow: ellipsis')
        ->toContain('white-space: nowrap');
});

function constructionEmissionDropdownSchema(): Schema
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

function constructionEmissionResourceSchema(): Schema
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

/**
 * Returns the selector list and the declarations of the single theme rule whose
 * selector list contains the given selector verbatim.
 *
 * @return array{selectors: array<int, string>, declarations: string}
 */
function constructionEmissionThemeRuleWithSelector(string $selector): array
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
