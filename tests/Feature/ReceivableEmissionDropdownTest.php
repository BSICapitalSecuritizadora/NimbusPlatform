<?php

use App\Filament\Resources\Receivables\Pages\CreateReceivable;
use App\Filament\Resources\Receivables\Schemas\ReceivableForm;
use App\Models\Emission;
use App\Models\Receivable;
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

it('marks only the Emissão select of the receivable form', function () {
    $markedFields = collect(receivableEmissionDropdownSchema()->getFlatComponents(withHidden: true))
        ->filter(fn (mixed $component): bool => $component instanceof Field)
        ->filter(fn (Field $field): bool => in_array(
            ReceivableForm::EMISSION_SELECT_CLASS,
            explode(' ', $field->getExtraAttributes()['class'] ?? ''),
            true,
        ))
        ->map(fn (Field $field): string => $field->getName())
        ->values()
        ->all();

    expect($markedFields)->toBe(['emission_id']);
});

it('keeps the Emissão select bound to the same relationship, search and behaviour', function () {
    $select = collect(receivableEmissionDropdownSchema()->getFlatComponents(withHidden: true))
        ->first(fn (mixed $component): bool => $component instanceof Select && $component->getName() === 'emission_id');

    expect($select)->toBeInstanceOf(Select::class)
        ->and($select->getLabel())->toBe('Emissão')
        ->and($select->getRelationshipName())->toBe('emission')
        ->and($select->getRelationshipTitleAttribute())->toBe('name')
        ->and($select->isSearchable())->toBeTrue()
        ->and($select->isPreloaded())->toBeTrue()
        ->and($select->isRequired())->toBeTrue()
        ->and($select->isLive())->toBeFalse()
        ->and($select->isMultiple())->toBeFalse()
        ->and($select->getValidationMessages())->toBe(['required' => 'Selecione a emissão.'])
        ->and($select->getExtraAttributes()['class'])->toBe(ReceivableForm::EMISSION_SELECT_CLASS);
});

it('renders the marked Emissão select on the create receivable summary page', function () {
    Livewire::test(CreateReceivable::class)
        ->assertFormFieldExists('emission_id', fn (Select $field): bool => ($field->getExtraAttributes()['class'] ?? null) === ReceivableForm::EMISSION_SELECT_CLASS)
        ->assertSeeHtml(ReceivableForm::EMISSION_SELECT_CLASS);
});

it('keeps the Emissão required and its state untouched by the marker', function () {
    $emission = Emission::factory()->create(['name' => '[UAT F] Emissão']);

    Livewire::test(CreateReceivable::class)
        ->assertSchemaStateSet(['emission_id' => null])
        ->call('create')
        ->assertHasFormErrors(['emission_id' => 'required'])
        ->fillForm(['emission_id' => $emission->getKey()])
        ->assertSchemaStateSet(['emission_id' => $emission->getKey()])
        ->call('create')
        ->assertHasNoFormErrors(['emission_id']);
});

/**
 * Os wrappers do input já são visíveis nesta página; quem recortava o popup era
 * a seção "Identificação do resumo". O campo reusa o bloco dos Responsáveis pelo
 * Fluxo, regra a regra.
 */
it('shares every popup rule of the workflow responsible selects', function () {
    $responsibleScope = '.fi-resource-create-record-page.fi-resource-operations';
    $responsibleMarker = '.bsi-measurement-workflow-responsible-select';

    $rules = collect(receivableEmissionThemeRules())
        ->filter(fn (array $rule): bool => collect($rule['selectors'])
            ->contains(fn (string $selector): bool => str_starts_with($selector, $responsibleScope.' ') && str_contains($selector, $responsibleMarker)));

    expect($rules)->not->toBeEmpty();

    $rules->each(function (array $rule) use ($responsibleScope, $responsibleMarker): void {
        foreach ($rule['selectors'] as $selector) {
            if (str_contains($selector, $responsibleMarker)) {
                expect($rule['selectors'])->toContain(str_replace(
                    [$responsibleScope, $responsibleMarker],
                    [receivableEmissionCreateScope(), '.'.ReceivableForm::EMISSION_SELECT_CLASS],
                    $selector,
                ));
            }
        }
    });
});

it('releases the popup from the clipping section and scrolls only the list', function () {
    $scope = receivableEmissionCreateScope().' .'.ReceivableForm::EMISSION_SELECT_CLASS;
    $section = receivableEmissionCreateScope().' .fi-section:has(.'.ReceivableForm::EMISSION_SELECT_CLASS.')';

    $sectionRule = receivableEmissionRuleWithSelector($section);
    $headerRule = receivableEmissionRuleWithSelector("{$section} > .fi-section-header");
    $panel = receivableEmissionRuleWithSelector("{$scope} .fi-dropdown-panel");
    $list = receivableEmissionRuleWithSelector("{$scope} .fi-dropdown-list");
    $option = receivableEmissionRuleWithSelector("{$scope} .fi-select-input-option");

    expect($sectionRule['selectors'])->toBe([$section])
        ->and($sectionRule['declarations'])->toContain('overflow: visible !important');

    expect($headerRule['declarations'])
        ->toContain('border-top-left-radius: 15px')
        ->toContain('border-top-right-radius: 15px');

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

it('confines the Emissão theme rules to the create receivable summary page and the marker to its form', function () {
    $selectors = collect(receivableEmissionThemeRules())
        ->flatMap(fn (array $rule): array => $rule['selectors'])
        ->filter(fn (string $selector): bool => str_contains($selector, ReceivableForm::EMISSION_SELECT_CLASS));

    expect($selectors)->not->toBeEmpty()
        ->and($selectors->reject(fn (string $selector): bool => str_starts_with($selector, receivableEmissionCreateScope().' ')))
        ->toBeEmpty();

    $filesUsingMarker = collect([...File::allFiles(app_path()), ...File::allFiles(resource_path('views'))])
        ->filter(fn (SplFileInfo $file): bool => str_contains($file->getContents(), ReceivableForm::EMISSION_SELECT_CLASS))
        ->map(fn (SplFileInfo $file): string => str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()))
        ->values()
        ->all();

    expect($filesUsingMarker)->toBe(['app/Filament/Resources/Receivables/Schemas/ReceivableForm.php']);
});

function receivableEmissionDropdownSchema(): Schema
{
    $livewire = new class extends Component implements HasSchemas
    {
        use InteractsWithSchemas;

        public function render(): string
        {
            return '<div></div>';
        }
    };

    return ReceivableForm::configure(Schema::make($livewire)->model(Receivable::class));
}

function receivableEmissionCreateScope(): string
{
    return '.fi-resource-create-record-page.fi-resource-receivables';
}

/**
 * Every rule of the theme; `@media` blocks are dropped when `$topLevelOnly`.
 *
 * @return array<int, array{selectors: array<int, string>, declarations: string}>
 */
function receivableEmissionThemeRules(bool $topLevelOnly = false): array
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
function receivableEmissionRuleWithSelector(string $selector): array
{
    $matches = collect(receivableEmissionThemeRules(topLevelOnly: true))
        ->filter(fn (array $rule): bool => in_array($selector, $rule['selectors'], true))
        ->values();

    expect($matches)->toHaveCount(1);

    return $matches->first();
}
