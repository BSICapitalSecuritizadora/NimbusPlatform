<?php

use App\Filament\Resources\FundNames\Pages\CreateFundName;
use App\Filament\Resources\FundNames\Pages\EditFundName;
use App\Filament\Resources\Funds\Pages\CreateFund;
use App\Filament\Resources\Funds\Schemas\FundForm;
use App\Models\Fund;
use App\Models\FundName;
use App\Models\FundType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
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

it('marks only Operação, Tipo de fundo, Nome do fundo, Aplicação and Banco of the fund form', function () {
    $markedFields = collect(fundClassificationDropdownSchema()->getFlatComponents(withHidden: true))
        ->filter(fn (mixed $component): bool => $component instanceof Field)
        ->filter(fn (Field $field): bool => in_array(
            FundForm::CLASSIFICATION_SELECT_CLASS,
            explode(' ', $field->getExtraAttributes()['class'] ?? ''),
            true,
        ))
        ->map(fn (Field $field): string => $field->getName())
        ->values()
        ->all();

    expect($markedFields)->toBe(['emission_id', 'fund_type_id', 'fund_name_id', 'fund_application_id', 'bank_id']);
});

it('keeps the marked selects bound to the same relationship, search and quick create', function (string $name, string $label, string $relationship, bool $hasCreateOption) {
    $select = collect(fundClassificationDropdownSchema()->getFlatComponents(withHidden: true))
        ->first(fn (mixed $component): bool => $component instanceof Select && $component->getName() === $name);

    expect($select)->toBeInstanceOf(Select::class)
        ->and($select->getLabel())->toBe($label)
        ->and($select->getRelationshipName())->toBe($relationship)
        ->and($select->isSearchable())->toBeTrue()
        ->and($select->isPreloaded())->toBeTrue()
        ->and($select->isRequired())->toBeTrue()
        ->and($select->hasCreateOptionActionFormSchema())->toBe($hasCreateOption)
        ->and($select->getExtraAttributes()['class'])->toBe(FundForm::CLASSIFICATION_SELECT_CLASS);
})->with([
    'Operação' => ['emission_id', 'Operação', 'emission', false],
    'Tipo de fundo' => ['fund_type_id', 'Tipo de fundo', 'fundType', true],
    'Nome do fundo' => ['fund_name_id', 'Nome do fundo', 'fundName', true],
    'Aplicação' => ['fund_application_id', 'Aplicação', 'fundApplication', true],
    'Banco' => ['bank_id', 'Banco', 'bank', true],
]);

it('renders the marked selects on the create fund page', function () {
    $isMarked = fn (Select $field): bool => ($field->getExtraAttributes()['class'] ?? null) === FundForm::CLASSIFICATION_SELECT_CLASS;

    Livewire::test(CreateFund::class)
        ->assertFormFieldExists('emission_id', $isMarked)
        ->assertFormFieldExists('fund_type_id', $isMarked)
        ->assertFormFieldExists('fund_name_id', $isMarked)
        ->assertFormFieldExists('fund_application_id', $isMarked)
        ->assertFormFieldExists('bank_id', $isMarked)
        ->assertFormFieldExists('agency', fn (TextInput $field): bool => ! array_key_exists('class', $field->getExtraAttributes()))
        ->assertFormFieldExists('account', fn (TextInput $field): bool => ! array_key_exists('class', $field->getExtraAttributes()))
        ->assertFormFieldExists('conta_azul_account_id', fn (TextInput $field): bool => ! array_key_exists('class', $field->getExtraAttributes()));
});

it('keeps Nome do fundo dependent on Tipo de fundo', function () {
    $credit = FundType::factory()->create(['name' => 'Crédito']);
    $reserve = FundType::factory()->create(['name' => 'Reserva']);
    $atlas = FundName::factory()->create(['fund_type_id' => $credit->id, 'name' => 'Fundo Atlas']);
    $boreal = FundName::factory()->create(['fund_type_id' => $reserve->id, 'name' => 'Fundo Boreal']);

    Livewire::test(CreateFund::class)
        ->assertFormFieldDisabled('fund_name_id')
        ->assertSee('Selecione primeiro o tipo de fundo para listar apenas os nomes compatíveis.')
        ->fillForm(['fund_type_id' => $credit->id])
        ->assertFormFieldEnabled('fund_name_id')
        ->assertFormFieldExists('fund_name_id', fn (Select $field): bool => $field->getOptions() === [$atlas->id => 'Fundo Atlas']
            && $field->getSearchResults('Fundo') === [$atlas->id => 'Fundo Atlas'])
        ->fillForm(['fund_name_id' => $atlas->id])
        ->fillForm(['fund_type_id' => $reserve->id])
        ->assertSchemaStateSet(['fund_name_id' => null])
        ->assertFormFieldExists('fund_name_id', fn (Select $field): bool => $field->getOptions() === [$boreal->id => 'Fundo Boreal']);
});

/**
 * A página carrega `bsi-construction-form-page`, que impõe `overflow: hidden`
 * nos dois wrappers do input: o popup era recortado à altura do trigger e só a
 * busca aparecia, espremida no campo. Os campos reusam o bloco dos Responsáveis
 * pelo Fluxo, regra a regra.
 */
it('shares every popup rule of the workflow responsible selects', function (string $pageScope) {
    $responsibleScope = '.fi-resource-create-record-page.fi-resource-operations';
    $responsibleMarker = '.bsi-measurement-workflow-responsible-select';

    $rules = collect(fundClassificationThemeRules())
        ->filter(fn (array $rule): bool => collect($rule['selectors'])
            ->contains(fn (string $selector): bool => str_starts_with($selector, $responsibleScope.' ') && str_contains($selector, $responsibleMarker)));

    expect($rules)->not->toBeEmpty();

    $rules->each(function (array $rule) use ($responsibleScope, $responsibleMarker, $pageScope): void {
        foreach ($rule['selectors'] as $selector) {
            if (str_contains($selector, $responsibleMarker)) {
                expect($rule['selectors'])->toContain(str_replace(
                    [$responsibleScope, $responsibleMarker],
                    [$pageScope, '.'.FundForm::CLASSIFICATION_SELECT_CLASS],
                    $selector,
                ));
            }
        }
    });
})->with([
    'criar fundo' => '.fi-resource-create-record-page.fi-resource-funds',
    'editar nome de fundo' => '.fi-resource-edit-record-page.fi-resource-fund-names',
]);

it('releases the popup from the clipping wrappers and scrolls only the list', function (string $pageScope) {
    $scope = $pageScope.' .'.FundForm::CLASSIFICATION_SELECT_CLASS;

    $wrapper = fundClassificationRuleWithSelector($pageScope.' form.fi-sc-form .'.FundForm::CLASSIFICATION_SELECT_CLASS.' > .fi-input-wrp-content-ctn');
    $panel = fundClassificationRuleWithSelector("{$scope} .fi-dropdown-panel");
    $list = fundClassificationRuleWithSelector("{$scope} .fi-dropdown-list");

    expect($wrapper['declarations'])->toContain('overflow: visible !important');

    expect($panel['declarations'])
        ->toContain('min-width: 0 !important')
        ->toContain('max-height: min(20rem, 44vh) !important')
        ->not->toContain('max-width')
        ->not->toContain(' width:');

    expect($list['declarations'])
        ->toContain('overflow-y: auto !important')
        ->toContain('overflow-x: hidden !important');
})->with([
    'criar fundo' => '.fi-resource-create-record-page.fi-resource-funds',
    'editar nome de fundo' => '.fi-resource-edit-record-page.fi-resource-fund-names',
]);

it('confines the shared classification rules to create fund and edit fund name', function () {
    $selectors = collect(fundClassificationThemeRules())
        ->flatMap(fn (array $rule): array => $rule['selectors'])
        ->filter(fn (string $selector): bool => str_contains($selector, FundForm::CLASSIFICATION_SELECT_CLASS));

    expect($selectors)->not->toBeEmpty()
        ->and($selectors->reject(fn (string $selector): bool => str_starts_with($selector, fundClassificationCreateScope().' ')
            || str_starts_with($selector, '.fi-resource-edit-record-page.fi-resource-fund-names ')))
        ->toBeEmpty();

    $filesUsingMarker = collect([...File::allFiles(app_path()), ...File::allFiles(resource_path('views'))])
        ->filter(fn (SplFileInfo $file): bool => str_contains($file->getContents(), FundForm::CLASSIFICATION_SELECT_CLASS)
            || str_contains($file->getContents(), 'FundForm::CLASSIFICATION_SELECT_CLASS'))
        ->map(fn (SplFileInfo $file): string => str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()))
        ->sort()
        ->values()
        ->all();

    expect($filesUsingMarker)->toBe([
        'app/Filament/Resources/FundNames/Schemas/FundNameForm.php',
        'app/Filament/Resources/Funds/Schemas/FundForm.php',
    ]);
});

it('keeps the existing fund name and type while loading and searching its edit form', function () {
    $type = FundType::factory()->create(['name' => 'Uchoa-Saito Tipo']);
    $longType = FundType::factory()->create(['name' => str_repeat('Reserva imobiliária ', 8)]);
    $fundName = FundName::factory()->for($type)->create(['name' => 'Nome já cadastrado']);
    $originalAttributes = $fundName->refresh()->getAttributes();

    Livewire::test(EditFundName::class, ['record' => $fundName->getRouteKey()])
        ->assertFormFieldExists('fund_type_id', function (Select $field) use ($type, $longType): bool {
            expect($field->getExtraAttributes()['class'])->toBe(FundForm::CLASSIFICATION_SELECT_CLASS)
                ->and($field->getRelationshipName())->toBe('fundType')
                ->and($field->getRelationshipTitleAttribute())->toBe('name')
                ->and($field->isSearchable())->toBeTrue()
                ->and($field->isPreloaded())->toBeTrue()
                ->and($field->isRequired())->toBeTrue()
                ->and($field->isMultiple())->toBeFalse()
                ->and($field->getStatePath())->toBe('data.fund_type_id')
                ->and($field->hasCreateOptionActionFormSchema())->toBeTrue()
                ->and($field->hasEditOptionActionFormSchema())->toBeTrue()
                ->and($field->getOptions())->toEqual([
                    $longType->id => $longType->name,
                    $type->id => $type->name,
                ])
                ->and($field->getSearchResults('Reserva'))->toBe([$longType->id => $longType->name])
                ->and($field->getSearchResults('inexistente'))->toBe([]);

            return true;
        })
        ->assertFormFieldExists('name', fn (TextInput $field): bool => $field->getExtraAttributes() === [] && $field->isRequired())
        ->call('$refresh')
        ->assertSchemaStateSet(['fund_type_id' => $type->id, 'name' => $fundName->name]);

    expect($fundName->refresh()->getAttributes())->toBe($originalAttributes);

    Livewire::test(CreateFundName::class)
        ->assertFormFieldExists('fund_type_id', fn (Select $field): bool => $field->getExtraAttributes() === []);
});

it('keeps the fund type actions outside the searchable container on the edit page', function () {
    $fundName = FundName::factory()->create();
    $component = Livewire::test(EditFundName::class, ['record' => $fundName->getRouteKey()]);
    $document = new DOMDocument;
    $document->loadHTML($component->html(), LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($document);
    $wrapper = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " '.FundForm::CLASSIFICATION_SELECT_CLASS.' ")]');

    expect($wrapper->length)->toBe(1);

    $select = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " fi-select-input ")]', $wrapper->item(0));
    $actions = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " fi-input-wrp-actions ")]//button', $wrapper->item(0));

    expect($select->length)->toBe(1)
        ->and($actions->length)->toBe(2)
        ->and($select->item(0)->getAttribute('x-data'))->toContain('selectFormComponent(')
        ->and($xpath->query('.//button', $select->item(0))->length)->toBe(0)
        ->and($xpath->query('ancestor::*[contains(@class, "fi-fixed-positioning-context")]', $select->item(0))->length)->toBe(0);

    foreach ($actions as $action) {
        expect($action->getAttribute('type'))->toBe('button');
    }

    $innerWrapper = fundClassificationRuleWithSelector('.fi-resource-edit-record-page.fi-resource-fund-names form.fi-sc-form .'.FundForm::CLASSIFICATION_SELECT_CLASS.' > .fi-input-wrp-content-ctn > .fi-select-input');

    expect($innerWrapper['declarations'])->toContain('overflow: visible !important');
});

it('persists a changed fund type without changing the fund name', function () {
    $fundName = FundName::factory()->create();
    $newType = FundType::factory()->create();

    Livewire::test(EditFundName::class, ['record' => $fundName->getRouteKey()])
        ->fillForm(['fund_type_id' => $newType->id])
        ->assertSchemaStateSet(['fund_type_id' => $newType->id, 'name' => $fundName->name])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($fundName->fresh()->fund_type_id)->toBe($newType->id)
        ->and($fundName->fresh()->name)->toBe($fundName->name);

    Livewire::test(EditFundName::class, ['record' => $fundName->getRouteKey()])
        ->assertSchemaStateSet(['fund_type_id' => $newType->id, 'name' => $fundName->name]);
});

it('keeps required validation when clearing the edited fund name type', function () {
    $fundName = FundName::factory()->create();

    Livewire::test(EditFundName::class, ['record' => $fundName->getRouteKey()])
        ->fillForm(['fund_type_id' => null])
        ->call('save')
        ->assertHasFormErrors(['fund_type_id' => 'required']);

    expect($fundName->fresh()->fund_type_id)->toBe($fundName->fund_type_id)
        ->and($fundName->fresh()->name)->toBe($fundName->name);
});

it('creates a type inline on the edit fund name page and only saves the new link on submit', function () {
    $fundName = FundName::factory()->create();
    $create = TestAction::make('createOption')->schemaComponent('fund_type_id');

    $component = Livewire::test(EditFundName::class, ['record' => $fundName->getRouteKey()])
        ->assertActionHasLabel($create, 'Cadastrar tipo')
        ->mountAction($create)
        ->fillForm(['name' => 'Novo tipo para o nome cadastrado'])
        ->callMountedAction()
        ->assertHasNoFormErrors();

    $newType = FundType::query()->where('name', 'Novo tipo para o nome cadastrado')->sole();

    $component->assertSchemaStateSet(['fund_type_id' => $newType->id, 'name' => $fundName->name])
        ->assertFormFieldExists('fund_type_id', fn (Select $field): bool => array_key_exists($newType->id, $field->getOptions()));

    expect($fundName->fresh()->fund_type_id)->toBe($fundName->fund_type_id);

    $component->call('save')->assertHasNoFormErrors();

    expect($fundName->fresh()->fund_type_id)->toBe($newType->id)
        ->and($fundName->fresh()->name)->toBe($fundName->name);
});

it('edits only the selected type through the inline action without changing the fund name', function () {
    $fundName = FundName::factory()->create();
    $otherType = FundType::factory()->create();
    $edit = TestAction::make('editOption')->schemaComponent('fund_type_id');

    Livewire::test(EditFundName::class, ['record' => $fundName->getRouteKey()])
        ->assertActionHasLabel($edit, 'Editar tipo')
        ->mountAction($edit)
        ->assertActionDataSet(['name' => $fundName->fundType->name])
        ->fillForm(['name' => 'Tipo atualizado pela ação'])
        ->callMountedAction()
        ->assertHasNoFormErrors()
        ->assertSchemaStateSet(['fund_type_id' => $fundName->fund_type_id, 'name' => $fundName->name]);

    expect($fundName->fundType->fresh()->name)->toBe('Tipo atualizado pela ação')
        ->and($otherType->fresh()->name)->toBe($otherType->name)
        ->and($fundName->fresh()->fund_type_id)->toBe($fundName->fund_type_id)
        ->and($fundName->fresh()->name)->toBe($fundName->name);
});

function fundClassificationDropdownSchema(): Schema
{
    $livewire = new class extends Component implements HasSchemas
    {
        use InteractsWithSchemas;

        public function render(): string
        {
            return '<div></div>';
        }
    };

    return FundForm::configure(Schema::make($livewire)->model(Fund::class));
}

function fundClassificationCreateScope(): string
{
    return '.fi-resource-create-record-page.fi-resource-funds';
}

/**
 * Every rule of the theme; `@media` blocks are dropped when `$topLevelOnly`.
 *
 * @return array<int, array{selectors: array<int, string>, declarations: string}>
 */
function fundClassificationThemeRules(bool $topLevelOnly = false): array
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
function fundClassificationRuleWithSelector(string $selector): array
{
    $matches = collect(fundClassificationThemeRules(topLevelOnly: true))
        ->filter(fn (array $rule): bool => in_array($selector, $rule['selectors'], true))
        ->values();

    expect($matches)->toHaveCount(1);

    return $matches->first();
}
