<?php

use App\Filament\Resources\Operations\OperationResource;
use App\Filament\Resources\Operations\Pages\CreateOperation;
use App\Filament\Resources\Operations\Pages\EditOperation;
use App\Filament\Resources\Operations\Schemas\OperationForm;
use App\Models\Emission;
use App\Models\Operation;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Forms\Components\DatePicker;
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

it('keeps the operation emission searchable config and validation unchanged', function () {
    $emissionSelect = collect(operationEmissionDropdownSchema()->getFlatComponents(withHidden: true))
        ->first(fn (mixed $component): bool => $component instanceof Select && $component->getName() === 'emission_id');

    expect($emissionSelect)->toBeInstanceOf(Select::class)
        ->and($emissionSelect->getLabel())->toBe('Emissão')
        ->and($emissionSelect->getPlaceholder())->toBe('Selecione a emissão...')
        ->and($emissionSelect->isSearchable())->toBeTrue()
        ->and($emissionSelect->isPreloaded())->toBeTrue()
        ->and($emissionSelect->isRequired())->toBeTrue()
        ->and($emissionSelect->isLive())->toBeTrue()
        ->and($emissionSelect->getStatePath())->toBe('emission_id')
        ->and($emissionSelect->getExtraAttributes()['class'])->not->toContain('fi-fixed-positioning-context')
        ->not->toContain('bsi-anchored-filter-dropdown');
});

it('limits the operation emission dropdown styling marker to the emission field', function () {
    $markedFields = collect(operationEmissionDropdownSchema()->getFlatComponents(withHidden: true))
        ->filter(fn (mixed $component): bool => $component instanceof Field)
        ->filter(fn (Field $field): bool => in_array(
            'bsi-operation-emission-select',
            explode(' ', $field->getExtraAttributes()['class'] ?? ''),
            true,
        ))
        ->map(fn (Field $field): string => $field->getName())
        ->values()
        ->all();

    expect($markedFields)->toBe(['emission_id']);
});

it('shares the same marked emission field between the operation create and edit pages', function () {
    expect(CreateOperation::getResource())->toBe(OperationResource::class)
        ->and(EditOperation::getResource())->toBe(OperationResource::class);

    $markedFields = collect(operationEmissionResourceSchema()->getFlatComponents(withHidden: true))
        ->filter(fn (mixed $component): bool => $component instanceof Field)
        ->filter(fn (Field $field): bool => in_array(
            'bsi-operation-emission-select',
            explode(' ', $field->getExtraAttributes()['class'] ?? ''),
            true,
        ))
        ->map(fn (Field $field): string => $field->getName())
        ->values()
        ->all();

    expect($markedFields)->toBe(['emission_id']);
});

it('renders the marked emission field with the record emission selected on the edit page', function () {
    $operation = Operation::factory()->create();

    Livewire::test(EditOperation::class, [
        'record' => $operation->getRouteKey(),
    ])
        ->assertFormFieldExists('emission_id', fn (Select $field): bool => in_array(
            'bsi-operation-emission-select',
            explode(' ', $field->getExtraAttributes()['class'] ?? ''),
            true,
        ))
        ->assertSchemaStateSet([
            'emission_id' => $operation->emission_id,
        ]);
});

it('renders the marked emission field on the create page', function () {
    Livewire::test(CreateOperation::class)
        ->assertFormFieldExists('emission_id', fn (Select $field): bool => in_array(
            'bsi-operation-emission-select',
            explode(' ', $field->getExtraAttributes()['class'] ?? ''),
            true,
        ));
});

/**
 * Mesma causa da Emissão da obra: as páginas de operação carregam
 * `bsi-construction-form-page`, que impõe `overflow: hidden` nos dois wrappers do
 * input. Sem a liberação, o popup absoluto é cortado na área do trigger e a busca
 * parece espremida dentro do próprio campo.
 */
it('releases the Emissão popup from the clipping input wrappers on the operation create and edit pages', function () {
    $rule = operationEmissionThemeRuleWithSelector(
        ':is(.fi-resource-create-record-page, .fi-resource-edit-record-page).fi-resource-operations form.fi-sc-form .bsi-operation-emission-select > .fi-input-wrp-content-ctn',
    );

    expect($rule['selectors'])
        ->toContain(':is(.fi-resource-create-record-page, .fi-resource-edit-record-page).fi-resource-operations form.fi-sc-form .bsi-operation-emission-select')
        ->and($rule['declarations'])->toContain('overflow: visible !important');
});

it('keeps the search on top and scrolls only the Emissão options list on create and edit', function () {
    $scope = ':is(.fi-resource-create-record-page, .fi-resource-edit-record-page).fi-resource-operations .bsi-operation-emission-select';

    $panel = operationEmissionThemeRuleWithSelector("{$scope} .fi-dropdown-panel");
    $list = operationEmissionThemeRuleWithSelector("{$scope} .fi-dropdown-list");
    $search = operationEmissionThemeRuleWithSelector("{$scope} .fi-select-input-search-ctn");

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
    $rule = operationEmissionThemeRuleWithSelector(
        ':is(.fi-resource-create-record-page, .fi-resource-edit-record-page).fi-resource-operations .bsi-operation-emission-select .fi-select-input-value-label',
    );

    expect($rule['declarations'])
        ->toContain('overflow: hidden')
        ->toContain('text-overflow: ellipsis')
        ->toContain('white-space: nowrap');
});

it('gives the operation Emissão popup the same panel treatment as the construction Emissão popup', function (string $part) {
    $operationRule = operationEmissionThemeRuleWithSelector(
        ":is(.fi-resource-create-record-page, .fi-resource-edit-record-page).fi-resource-operations .bsi-operation-emission-select {$part}",
    );

    $constructionRule = operationEmissionThemeRuleWithSelector(
        ":is(.fi-resource-create-record-page, .fi-resource-edit-record-page).fi-resource-constructions .bsi-construction-emission-select {$part}",
    );

    expect(normalizeOperationEmissionDeclarations($operationRule['declarations']))
        ->toBe(normalizeOperationEmissionDeclarations($constructionRule['declarations']));
})->with([
    'panel' => '.fi-dropdown-panel',
    'open panel layout' => '.fi-select-input-btn[aria-expanded="true"] + .fi-dropdown-panel',
    'search' => '.fi-select-input-search-ctn',
    'options list' => '.fi-dropdown-list',
]);

it('keeps the Vencimento auto-fill config unchanged', function () {
    $dueDate = collect(operationEmissionDropdownSchema()->getFlatComponents(withHidden: true))
        ->first(fn (mixed $component): bool => $component instanceof DatePicker && $component->getName() === 'due_date');

    expect($dueDate)->toBeInstanceOf(DatePicker::class)
        ->and($dueDate->getLabel())->toBe('Vencimento')
        ->and($dueDate->getPlaceholder())->toBe('Automático via emissão')
        ->and($dueDate->isDisabled())->toBeTrue()
        ->and($dueDate->isDehydrated())->toBeTrue();
});

it('fills Vencimento automatically from the selected Emissão maturity date', function () {
    $emission = Emission::factory()->create([
        'maturity_date' => '2031-06-15',
    ]);

    Livewire::test(CreateOperation::class)
        ->assertSee('Preenchido automaticamente a partir da data de vencimento da emissão.')
        ->set('data.emission_id', $emission->id)
        ->assertSchemaStateSet([
            'emission_id' => $emission->id,
            'due_date' => '2031-06-15',
        ]);
});

function operationEmissionDropdownSchema(): Schema
{
    $livewire = new class extends Component implements HasSchemas
    {
        use InteractsWithSchemas;

        public function render(): string
        {
            return '<div></div>';
        }
    };

    return OperationForm::configure(Schema::make($livewire));
}

function operationEmissionResourceSchema(): Schema
{
    $livewire = new class extends Component implements HasSchemas
    {
        use InteractsWithSchemas;

        public function render(): string
        {
            return '<div></div>';
        }
    };

    return OperationResource::form(Schema::make($livewire));
}

function normalizeOperationEmissionDeclarations(string $declarations): string
{
    return trim((string) preg_replace('/\s+/', ' ', $declarations));
}

/**
 * Returns the selector list and the declarations of the single theme rule whose
 * selector list contains the given selector verbatim.
 *
 * @return array{selectors: array<int, string>, declarations: string}
 */
function operationEmissionThemeRuleWithSelector(string $selector): array
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
