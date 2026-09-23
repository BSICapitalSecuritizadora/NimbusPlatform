<?php

use App\Filament\Resources\Constructions\ConstructionResource;
use App\Filament\Resources\Constructions\Pages\CreateConstruction;
use App\Filament\Resources\Constructions\Pages\EditConstruction;
use App\Filament\Resources\Constructions\Schemas\ConstructionForm;
use App\Models\Construction;
use App\Models\ExpenseServiceProvider;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
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

it('keeps the measurement company relationship, search, quick creation and validation unchanged', function () {
    $components = collect(constructionMeasurementCompanyDropdownSchema()->getFlatComponents(withHidden: true));

    $companySelect = $components->first(fn (mixed $component): bool => $component instanceof Select && $component->getName() === 'measurement_company_id');
    $cnpjInput = $components->first(fn (mixed $component): bool => $component instanceof TextInput && $component->getName() === 'measurement_company_cnpj');

    expect($companySelect)->toBeInstanceOf(Select::class)
        ->and($companySelect->getLabel())->toBe('Empresa de medição')
        ->and($companySelect->getRelationshipName())->toBe('measurementCompany')
        ->and($companySelect->getRelationshipTitleAttribute())->toBe('name')
        ->and($companySelect->isSearchable())->toBeTrue()
        ->and($companySelect->getSearchColumns())->toBe(['name', 'cnpj'])
        ->and($companySelect->isPreloaded())->toBeTrue()
        ->and($companySelect->isRequired())->toBeTrue()
        ->and($companySelect->isLive())->toBeTrue()
        ->and($companySelect->getCreateOptionUsing())->not->toBeNull()
        ->and($companySelect->hasCreateOptionActionFormSchema())->toBeTrue()
        ->and($companySelect->getStatePath())->toBe('measurement_company_id');

    expect($cnpjInput)->toBeInstanceOf(TextInput::class)
        ->and($cnpjInput->isReadOnly())->toBeTrue()
        ->and($cnpjInput->isDehydrated())->toBeFalse();
});

/**
 * Os wrappers do campo têm `overflow: hidden` para o recorte do trigger com o `+`.
 * É o contexto fixo que tira o popup desse recorte; a correção de largura no tema
 * depende dele e não o substitui.
 */
it('keeps the fixed positioning context that lets the measurement company popup escape the clipping wrappers', function () {
    $companySelect = collect(constructionMeasurementCompanyDropdownSchema()->getFlatComponents(withHidden: true))
        ->first(fn (mixed $component): bool => $component instanceof Select && $component->getName() === 'measurement_company_id');

    expect(explode(' ', $companySelect->getExtraAttributes()['class'] ?? ''))
        ->toContain('fi-fixed-positioning-context')
        ->toContain('bsi-field-measurement-company')
        ->not->toContain('bsi-anchored-filter-dropdown');
});

it('limits the measurement company dropdown styling marker to the measurement company field', function () {
    $markedFields = collect(constructionMeasurementCompanyDropdownSchema()->getFlatComponents(withHidden: true))
        ->filter(fn (mixed $component): bool => $component instanceof Field)
        ->filter(fn (Field $field): bool => in_array(
            'bsi-field-measurement-company',
            explode(' ', $field->getExtraAttributes()['class'] ?? ''),
            true,
        ))
        ->map(fn (Field $field): string => $field->getName())
        ->values()
        ->all();

    expect($markedFields)->toBe(['measurement_company_id']);
});

it('renders the marked measurement company field on the create page', function () {
    Livewire::test(CreateConstruction::class)
        ->assertFormFieldExists('measurement_company_id', fn (Select $field): bool => in_array(
            'bsi-field-measurement-company',
            explode(' ', $field->getExtraAttributes()['class'] ?? ''),
            true,
        ));
});

it('shares the same marked measurement company field between the construction create and edit pages', function () {
    expect(CreateConstruction::getResource())->toBe(ConstructionResource::class)
        ->and(EditConstruction::getResource())->toBe(ConstructionResource::class);

    $markedFields = collect(constructionMeasurementCompanyResourceSchema()->getFlatComponents(withHidden: true))
        ->filter(fn (mixed $component): bool => $component instanceof Field)
        ->filter(fn (Field $field): bool => in_array(
            'bsi-field-measurement-company',
            explode(' ', $field->getExtraAttributes()['class'] ?? ''),
            true,
        ))
        ->map(fn (Field $field): string => $field->getName())
        ->values()
        ->all();

    expect($markedFields)->toBe(['measurement_company_id']);
});

it('renders the marked measurement company field with the record company and CNPJ on the edit page', function () {
    $construction = Construction::factory()->create();

    Livewire::test(EditConstruction::class, [
        'record' => $construction->getRouteKey(),
    ])
        ->assertFormFieldExists('measurement_company_id', fn (Select $field): bool => in_array(
            'fi-fixed-positioning-context',
            explode(' ', $field->getExtraAttributes()['class'] ?? ''),
            true,
        ) && in_array(
            'bsi-field-measurement-company',
            explode(' ', $field->getExtraAttributes()['class'] ?? ''),
            true,
        ))
        ->assertSchemaStateSet([
            'measurement_company_id' => $construction->measurement_company_id,
            'measurement_company_cnpj' => ExpenseServiceProvider::formatCnpj($construction->measurementCompany->cnpj),
        ]);
});

/**
 * Em `position: fixed` o bloco contentor é a viewport, e o `min-width: 100%`
 * global do tema fazia o popup abrir com a largura da tela inteira por cima da
 * sidebar. Sem o piso, vale o `width` inline do Filament, o do trigger. O cadastro
 * e a edição usam o mesmo campo, então a regra é uma só para as duas páginas.
 */
it('anchors the measurement company popup to its trigger on the construction create and edit pages', function () {
    $scope = ':is(.fi-resource-create-record-page, .fi-resource-edit-record-page).fi-resource-constructions ';

    $panel = constructionMeasurementCompanyThemeRuleWithSelector(
        "{$scope}.bsi-field-measurement-company .fi-dropdown-panel",
    );
    $list = constructionMeasurementCompanyThemeRuleWithSelector(
        "{$scope}.bsi-field-measurement-company .fi-dropdown-list",
    );

    expect($panel['selectors'])->each->toStartWith($scope)
        ->and($list['selectors'])->each->toStartWith($scope);

    expect($panel['declarations'])
        ->toContain('min-width: 0 !important')
        ->toContain('max-height: min(22.5rem, 44vh) !important')
        ->toContain('overflow: hidden !important')
        ->not->toContain('max-width')
        ->not->toContain('100vw');

    expect($list['declarations'])
        ->toContain('overflow-y: auto')
        ->toContain('overflow-x: hidden')
        ->toContain('grid-auto-rows: min-content');
});

it('gives the measurement company popup the same panel treatment as the Emissão popup', function (string $part) {
    $companyRule = constructionMeasurementCompanyThemeRuleWithSelector(
        ":is(.fi-resource-create-record-page, .fi-resource-edit-record-page).fi-resource-constructions .bsi-field-measurement-company {$part}",
    );

    $emissionRule = constructionMeasurementCompanyThemeRuleWithSelector(
        ":is(.fi-resource-create-record-page, .fi-resource-edit-record-page).fi-resource-constructions .bsi-construction-emission-select {$part}",
    );

    expect(normalizeConstructionMeasurementCompanyDeclarations($companyRule['declarations']))
        ->toBe(normalizeConstructionMeasurementCompanyDeclarations($emissionRule['declarations']));
})->with([
    'panel' => '.fi-dropdown-panel',
    'open panel layout' => '.fi-select-input-btn[aria-expanded="true"] + .fi-dropdown-panel',
    'search' => '.fi-select-input-search-ctn',
    'options list' => '.fi-dropdown-list',
]);

function constructionMeasurementCompanyDropdownSchema(): Schema
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

function constructionMeasurementCompanyResourceSchema(): Schema
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

function normalizeConstructionMeasurementCompanyDeclarations(string $declarations): string
{
    return trim((string) preg_replace('/\s+/', ' ', $declarations));
}

/**
 * Returns the selector list and the declarations of the single theme rule whose
 * selector list contains the given selector verbatim.
 *
 * @return array{selectors: array<int, string>, declarations: string}
 */
function constructionMeasurementCompanyThemeRuleWithSelector(string $selector): array
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
