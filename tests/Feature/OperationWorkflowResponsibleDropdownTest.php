<?php

use App\Filament\Resources\Operations\Pages\CreateOperation;
use App\Filament\Resources\Operations\Schemas\OperationForm;
use App\Models\Construction;
use App\Models\Emission;
use App\Models\Operation;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Livewire\Component;
use Livewire\Livewire;
use Symfony\Component\Finder\SplFileInfo;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(makeAdminUser());
});

it('marks exactly the eight searchable selects of the measurement workflow section', function () {
    $markedFields = collect(workflowResponsibleDropdownSchema()->getFlatComponents(withHidden: true))
        ->filter(fn (mixed $component): bool => $component instanceof Field)
        ->filter(fn (Field $field): bool => hasWorkflowResponsibleMarker($field))
        ->map(fn (Field $field): string => $field->getName())
        ->values()
        ->all();

    expect($markedFields)->toBe(array_keys(workflowResponsibleSelects()));
});

it('keeps each workflow responsible select bound to the same relationship, search and placeholder', function (string $name) {
    $expected = workflowResponsibleSelects()[$name];

    $select = collect(workflowResponsibleDropdownSchema()->getFlatComponents(withHidden: true))
        ->first(fn (mixed $component): bool => $component instanceof Select && $component->getName() === $name);

    expect($select)->toBeInstanceOf(Select::class)
        ->and($select->getLabel())->toBe($expected['label'])
        ->and($select->getPlaceholder())->toBe($expected['placeholder'])
        ->and($select->getRelationshipName())->toBe($expected['relationship'])
        ->and($select->isSearchable())->toBeTrue()
        ->and($select->isPreloaded())->toBeTrue()
        ->and($select->isMultiple())->toBe($expected['multiple'])
        ->and($select->getExtraAttributes()['class'])->toBe(OperationForm::WORKFLOW_RESPONSIBLE_SELECT_CLASS);
})->with(array_keys(workflowResponsibleSelects()));

it('renders the eight marked selects on the create operation page', function () {
    $page = Livewire::test(CreateOperation::class);

    foreach (array_keys(workflowResponsibleSelects()) as $name) {
        $page->assertFormFieldExists($name, fn (Select $field): bool => hasWorkflowResponsibleMarker($field));
    }
});

it('persists every responsible and the rejection recipients chosen on the create page', function () {
    $emission = Emission::factory()->create();
    $construction = Construction::factory()->create(['emission_id' => $emission->id]);
    $users = User::factory()->count(9)->create();

    Livewire::test(CreateOperation::class)
        ->fillForm([
            'emission_id' => $emission->id,
            'developments' => [
                ['construction_id' => $construction->id, 'construction_fund_amount' => 250000.00],
            ],
            'responsible_user_id' => $users[0]->id,
            'stage2_reviewer_user_id' => $users[1]->id,
            'stage3_reviewer_user_id' => $users[2]->id,
            'payment_manager_user_id' => $users[3]->id,
            'payment_receipt_uploader_user_id' => $users[4]->id,
            'payment_finalizer_user_id' => $users[5]->id,
            'assigned_user_id' => $users[6]->id,
            'rejectionNotifyUsers' => [$users[7]->id, $users[8]->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $operation = Operation::query()->where('emission_id', $emission->id)->sole();

    expect($operation->responsible_user_id)->toBe($users[0]->id)
        ->and($operation->stage2_reviewer_user_id)->toBe($users[1]->id)
        ->and($operation->stage3_reviewer_user_id)->toBe($users[2]->id)
        ->and($operation->payment_manager_user_id)->toBe($users[3]->id)
        ->and($operation->payment_receipt_uploader_user_id)->toBe($users[4]->id)
        ->and($operation->payment_finalizer_user_id)->toBe($users[5]->id)
        ->and($operation->assigned_user_id)->toBe($users[6]->id)
        ->and($operation->rejectionNotifyUsers()->pluck('users.id')->sort()->values()->all())
        ->toBe(collect([$users[7]->id, $users[8]->id])->sort()->values()->all());
});

/**
 * Mesma causa da Emissão da operação: `bsi-construction-form-page` impõe
 * `overflow: hidden` nos dois wrappers do input. Sem a liberação, o popup
 * absoluto é cortado na área do trigger e a busca parece espremida no campo.
 */
it('releases the workflow responsible popups from the clipping input wrappers on the create page', function () {
    $rule = workflowResponsibleThemeRuleWithSelector(
        workflowResponsibleCreateScope().' form.fi-sc-form .bsi-measurement-workflow-responsible-select > .fi-input-wrp-content-ctn',
    );

    expect($rule['selectors'])
        ->toContain(workflowResponsibleCreateScope().' form.fi-sc-form .bsi-measurement-workflow-responsible-select')
        ->and($rule['declarations'])->toContain('overflow: visible !important');
});

it('keeps the search on top and scrolls only the options list, with the width left to the trigger', function () {
    $scope = workflowResponsibleCreateScope().' .bsi-measurement-workflow-responsible-select';

    $panel = workflowResponsibleThemeRuleWithSelector("{$scope} .fi-dropdown-panel");
    $openPanel = workflowResponsibleThemeRuleWithSelector("{$scope} .fi-select-input-btn[aria-expanded=\"true\"] + .fi-dropdown-panel");
    $search = workflowResponsibleThemeRuleWithSelector("{$scope} .fi-select-input-search-ctn");
    $list = workflowResponsibleThemeRuleWithSelector("{$scope} .fi-dropdown-list");

    // A largura é o `width` inline do Filament (a do trigger); o tema só anula o piso de 100%.
    expect($panel['declarations'])
        ->toContain('min-width: 0 !important')
        ->toContain('max-height: min(20rem, 44vh) !important')
        ->toContain('overflow: hidden !important')
        ->not->toContain('max-width')
        ->not->toContain(' width:');

    expect($openPanel['declarations'])->toContain('display: flex !important');
    expect($search['declarations'])->toContain('flex: 0 0 auto !important');

    expect($list['declarations'])
        ->toContain('overflow-y: auto !important')
        ->toContain('overflow-x: hidden !important')
        ->toContain('grid-auto-rows: min-content !important')
        ->toContain('max-height: none !important');
});

/**
 * A regra legada do filtro Responsável (`[wire:key*="responsible_user_id"]`)
 * também casa com "1 · Engenharia" e impõe `nowrap`, reticências e teto de
 * altura na opção; o bloco do formulário precisa desfazer isso nos oito campos.
 */
it('wraps long user names inside the trigger width for all eight selects', function () {
    $scope = workflowResponsibleCreateScope().' .bsi-measurement-workflow-responsible-select';

    $option = workflowResponsibleThemeRuleWithSelector("{$scope} .fi-select-input-option");
    $label = workflowResponsibleThemeRuleWithSelector("{$scope} .fi-select-input-option > span");
    $selectedValue = workflowResponsibleThemeRuleWithSelector("{$scope} .fi-select-input-value-label");

    expect($option['declarations'])
        ->toContain('white-space: normal !important')
        ->toContain('overflow-wrap: anywhere !important')
        ->toContain('max-height: none !important');

    expect($label['declarations'])
        ->toContain('white-space: normal !important')
        ->toContain('text-overflow: clip !important');

    expect($selectedValue['declarations'])
        ->toContain('text-overflow: ellipsis')
        ->toContain('white-space: nowrap');
});

it('enlarges the option touch target on coarse pointers', function () {
    $option = workflowResponsibleThemeRuleWithSelector(
        workflowResponsibleCreateScope().' .bsi-measurement-workflow-responsible-select .fi-select-input-option',
        media: '(pointer: coarse)',
    );

    expect($option['declarations'])->toContain('min-height: 2.75rem !important');
});

it('confines the workflow responsible theme rules to the create operation page', function () {
    $selectors = workflowResponsibleThemeSelectors()
        ->filter(fn (string $selector): bool => str_contains($selector, OperationForm::WORKFLOW_RESPONSIBLE_SELECT_CLASS))
        ->values();

    expect($selectors)->not->toBeEmpty()
        ->and($selectors->reject(fn (string $selector): bool => str_starts_with($selector, workflowResponsibleCreateScope().' ')))
        ->toBeEmpty();
});

it('keeps the marker out of table filters and every other schema', function () {
    $filesUsingMarker = collect([...File::allFiles(app_path()), ...File::allFiles(resource_path('views'))])
        ->filter(fn (SplFileInfo $file): bool => str_contains($file->getContents(), OperationForm::WORKFLOW_RESPONSIBLE_SELECT_CLASS))
        ->map(fn (SplFileInfo $file): string => str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()))
        ->values()
        ->all();

    expect($filesUsingMarker)->toBe(['app/Filament/Resources/Operations/Schemas/OperationForm.php']);
});

/**
 * Os oito selects da seção, na ordem do formulário.
 *
 * @return array<string, array{label: string, placeholder: string, relationship: string, multiple: bool}>
 */
function workflowResponsibleSelects(): array
{
    $responsible = fn (string $label, string $relationship): array => [
        'label' => $label,
        'placeholder' => 'Selecione o responsável...',
        'relationship' => $relationship,
        'multiple' => false,
    ];

    return [
        'responsible_user_id' => $responsible('1 · Engenharia', 'responsibleUser'),
        'stage2_reviewer_user_id' => $responsible('2 · Gestão', 'stage2Reviewer'),
        'stage3_reviewer_user_id' => $responsible('3 · Compliance', 'stage3Reviewer'),
        'payment_manager_user_id' => $responsible('4 · Pagamentos', 'paymentManager'),
        'payment_receipt_uploader_user_id' => $responsible('Comprovantes', 'paymentReceiptUploader'),
        'payment_finalizer_user_id' => $responsible('5 · Finalização', 'paymentFinalizer'),
        'assigned_user_id' => $responsible('Responsável Geral (Coordenação)', 'assignedUser'),
        'rejectionNotifyUsers' => [
            'label' => 'Notificar em Caso de Recusa',
            'placeholder' => 'Selecione os usuários a notificar...',
            'relationship' => 'rejectionNotifyUsers',
            'multiple' => true,
        ],
    ];
}

function hasWorkflowResponsibleMarker(Field $field): bool
{
    return in_array(
        OperationForm::WORKFLOW_RESPONSIBLE_SELECT_CLASS,
        explode(' ', $field->getExtraAttributes()['class'] ?? ''),
        true,
    );
}

function workflowResponsibleDropdownSchema(): Schema
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

function workflowResponsibleCreateScope(): string
{
    return '.fi-resource-create-record-page.fi-resource-operations';
}

function workflowResponsibleThemeCss(): string
{
    return preg_replace('~/\*.*?\*/~s', '', file_get_contents(resource_path('css/filament/admin/theme.css')));
}

/**
 * Captures one-level `@media` blocks: group 1 is the query, group 2 its rules.
 */
function workflowResponsibleMediaBlockPattern(): string
{
    return '/@media\s*([^{]+)\{((?:[^{}]*\{[^{}]*\})*)\s*\}/';
}

/**
 * @return array<int, array{selectors: array<int, string>, declarations: string}>
 */
function workflowResponsibleParseRules(string $css): array
{
    preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER);

    return array_map(fn (array $rule): array => [
        'selectors' => array_map(trim(...), preg_split('/,(?![^(]*\))/', $rule[1])),
        'declarations' => $rule[2],
    ], $rules);
}

/**
 * Every selector of the theme, including the ones nested in `@media` blocks.
 *
 * @return Collection<int, string>
 */
function workflowResponsibleThemeSelectors(): Collection
{
    return collect(workflowResponsibleParseRules(workflowResponsibleThemeCss()))
        ->flatMap(fn (array $rule): array => $rule['selectors']);
}

/**
 * Returns the selector list and the declarations of the single theme rule whose
 * selector list contains the given selector verbatim — at the top level, or
 * inside the `@media` block with the given query.
 *
 * @return array{selectors: array<int, string>, declarations: string}
 */
function workflowResponsibleThemeRuleWithSelector(string $selector, ?string $media = null): array
{
    $css = workflowResponsibleThemeCss();

    preg_match_all(workflowResponsibleMediaBlockPattern(), $css, $blocks, PREG_SET_ORDER);

    $scopedCss = $media === null
        ? preg_replace(workflowResponsibleMediaBlockPattern(), '', $css)
        : collect($blocks)
            ->filter(fn (array $block): bool => trim($block[1]) === $media)
            ->map(fn (array $block): string => $block[2])
            ->implode("\n");

    $matches = collect(workflowResponsibleParseRules($scopedCss))
        ->filter(fn (array $rule): bool => in_array($selector, $rule['selectors'], true))
        ->values();

    expect($matches)->toHaveCount(1);

    return $matches->first();
}
