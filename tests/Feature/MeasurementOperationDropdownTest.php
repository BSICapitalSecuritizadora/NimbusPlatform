<?php

use App\Filament\Resources\Measurements\Pages\CreateMeasurement;
use App\Filament\Resources\Measurements\Schemas\MeasurementForm;
use App\Models\Measurement;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Component;
use Livewire\Livewire;
use Symfony\Component\Finder\SplFileInfo;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(makeAdminUser());
});

it('marks only the Operação select of the measurement form', function () {
    $markedFields = collect(measurementOperationDropdownSchema()->getFlatComponents(withHidden: true))
        ->filter(fn (mixed $component): bool => $component instanceof Field)
        ->filter(fn (Field $field): bool => in_array(
            MeasurementForm::OPERATION_SELECT_CLASS,
            explode(' ', $field->getExtraAttributes()['class'] ?? ''),
            true,
        ))
        ->map(fn (Field $field): string => $field->getName())
        ->values()
        ->all();

    expect($markedFields)->toBe(['operation_id']);
});

it('keeps the Operação select bound to the same relationship, search and behaviour', function () {
    $select = collect(measurementOperationDropdownSchema()->getFlatComponents(withHidden: true))
        ->first(fn (mixed $component): bool => $component instanceof Select && $component->getName() === 'operation_id');

    expect($select)->toBeInstanceOf(Select::class)
        ->and($select->getLabel())->toBe('Operação')
        ->and($select->getPlaceholder())->toBe('Selecione a operação...')
        ->and($select->getRelationshipName())->toBe('operation')
        ->and($select->isSearchable())->toBeTrue()
        ->and($select->isPreloaded())->toBeTrue()
        ->and($select->isRequired())->toBeTrue()
        ->and($select->isLive())->toBeTrue()
        ->and($select->getExtraAttributes()['class'])->toBe(MeasurementForm::OPERATION_SELECT_CLASS);
});

it('renders the marked Operação select on the send measurement page', function () {
    Livewire::test(CreateMeasurement::class)
        ->assertFormFieldExists('operation_id', fn (Select $field): bool => ($field->getExtraAttributes()['class'] ?? null) === MeasurementForm::OPERATION_SELECT_CLASS);
});

/**
 * A página carrega `bsi-construction-form-page`, que impõe `overflow: hidden`
 * nos dois wrappers do input: o popup era recortado à altura do trigger e só a
 * busca aparecia, espremida no campo. O campo reusa o bloco dos Responsáveis
 * pelo Fluxo, regra a regra.
 */
it('shares every popup rule of the workflow responsible selects', function () {
    $responsibleScope = '.fi-resource-create-record-page.fi-resource-operations';
    $responsibleMarker = '.bsi-measurement-workflow-responsible-select';

    $rules = collect(measurementOperationThemeRules())
        ->filter(fn (array $rule): bool => collect($rule['selectors'])
            ->contains(fn (string $selector): bool => str_starts_with($selector, $responsibleScope.' ') && str_contains($selector, $responsibleMarker)));

    expect($rules)->not->toBeEmpty();

    $rules->each(function (array $rule) use ($responsibleScope, $responsibleMarker): void {
        foreach ($rule['selectors'] as $selector) {
            if (str_contains($selector, $responsibleMarker)) {
                expect($rule['selectors'])->toContain(str_replace(
                    [$responsibleScope, $responsibleMarker],
                    [measurementOperationCreateScope(), '.'.MeasurementForm::OPERATION_SELECT_CLASS],
                    $selector,
                ));
            }
        }
    });
});

it('releases the popup from the clipping wrappers and scrolls only the list', function () {
    $scope = measurementOperationCreateScope().' .'.MeasurementForm::OPERATION_SELECT_CLASS;

    $wrapper = measurementOperationRuleWithSelector(measurementOperationCreateScope().' form.fi-sc-form .'.MeasurementForm::OPERATION_SELECT_CLASS.' > .fi-input-wrp-content-ctn');
    $panel = measurementOperationRuleWithSelector("{$scope} .fi-dropdown-panel");
    $list = measurementOperationRuleWithSelector("{$scope} .fi-dropdown-list");
    $option = measurementOperationRuleWithSelector("{$scope} .fi-select-input-option");

    expect($wrapper['declarations'])->toContain('overflow: visible !important');

    expect($panel['declarations'])
        ->toContain('min-width: 0 !important')
        ->toContain('max-height: min(20rem, 44vh) !important')
        ->not->toContain('max-width')
        ->not->toContain(' width:');

    expect($list['declarations'])
        ->toContain('overflow-y: auto !important')
        ->toContain('overflow-x: hidden !important');

    expect($option['declarations'])->toContain('overflow-wrap: anywhere !important');
});

it('confines the Operação theme rules to the send measurement page and the marker to its form', function () {
    $selectors = collect(measurementOperationThemeRules())
        ->flatMap(fn (array $rule): array => $rule['selectors'])
        ->filter(fn (string $selector): bool => str_contains($selector, MeasurementForm::OPERATION_SELECT_CLASS));

    expect($selectors)->not->toBeEmpty()
        ->and($selectors->reject(fn (string $selector): bool => str_starts_with($selector, measurementOperationCreateScope().' ')))
        ->toBeEmpty();

    $filesUsingMarker = collect([...File::allFiles(app_path()), ...File::allFiles(resource_path('views'))])
        ->filter(fn (SplFileInfo $file): bool => str_contains($file->getContents(), MeasurementForm::OPERATION_SELECT_CLASS))
        ->map(fn (SplFileInfo $file): string => str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()))
        ->values()
        ->all();

    expect($filesUsingMarker)->toBe(['app/Filament/Resources/Measurements/Schemas/MeasurementForm.php']);
});

function measurementOperationDropdownSchema(): Schema
{
    $livewire = new class extends Component implements HasSchemas
    {
        use InteractsWithSchemas;

        public function render(): string
        {
            return '<div></div>';
        }
    };

    return MeasurementForm::configure(Schema::make($livewire)->model(Measurement::class));
}

function measurementOperationCreateScope(): string
{
    return '.fi-resource-create-record-page.fi-resource-measurements';
}

/**
 * Every rule of the theme; `@media` blocks are dropped when `$topLevelOnly`.
 *
 * @return array<int, array{selectors: array<int, string>, declarations: string}>
 */
function measurementOperationThemeRules(bool $topLevelOnly = false): array
{
    $css = preg_replace('~/\*.*?\*/~s', '', file_get_contents(resource_path('css/filament/admin/theme.css')));

    if ($topLevelOnly) {
        $css = preg_replace('/@media\s*[^{]+\{(?:[^{}]*\{[^{}]*\})*\s*\}/', '', $css);
    }

    preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER);

    return array_map(fn (array $rule): array => [
        'selectors' => array_map(trim(...), preg_split('/,(?![^(]*\))/', $rule[1])),
        'declarations' => $rule[2],
    ], $rules);
}

/**
 * The single top-level rule whose selector list contains the given selector.
 *
 * @return array{selectors: array<int, string>, declarations: string}
 */
function measurementOperationRuleWithSelector(string $selector): array
{
    $matches = collect(measurementOperationThemeRules(topLevelOnly: true))
        ->filter(fn (array $rule): bool => in_array($selector, $rule['selectors'], true))
        ->values();

    expect($matches)->toHaveCount(1);

    return $matches->first();
}
