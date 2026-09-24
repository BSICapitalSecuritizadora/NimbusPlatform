<?php

use App\Filament\Resources\Constructions\Pages\EditConstruction;
use App\Filament\Resources\Constructions\RelationManagers\SalesDiscountPoliciesRelationManager;
use App\Filament\Resources\Constructions\Schemas\ConstructionForm;
use App\Models\Construction;
use App\Models\SalesDiscountPolicy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Contracts\TranslatableContentDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Component as LivewireComponent;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(makeAdminUser());
});

it('tucks the destructive delete action into a secondary menu with confirmation', function () {
    $construction = Construction::factory()->create();

    $page = Livewire::test(EditConstruction::class, [
        'record' => $construction->getRouteKey(),
    ])->instance();

    $headerActions = invade($page)->getHeaderActions();

    expect($headerActions)->toHaveCount(1)
        ->and($headerActions[0])->toBeInstanceOf(ActionGroup::class)
        ->and($headerActions[0]->getLabel())->toBe('Mais ações');

    $deleteAction = collect($headerActions[0]->getFlatActions())
        ->first(fn ($action): bool => $action instanceof DeleteAction);

    expect($deleteAction)->not->toBeNull()
        ->and($deleteAction->getLabel())->toBe('Excluir obra')
        ->and($deleteAction->isConfirmationRequired())->toBeTrue();
});

it('presents a discreet subheading under the page title', function () {
    $construction = Construction::factory()->create();

    $page = Livewire::test(EditConstruction::class, [
        'record' => $construction->getRouteKey(),
    ])->instance();

    expect($page->getSubheading())->toBe('Atualize os dados cadastrais, o cronograma e as informações financeiras da obra.')
        ->and($page->getExtraBodyAttributes()['class'])->toContain('bsi-construction-form-page')
        ->and(invade($page)->hasUnsavedDataChangesAlert())->toBeTrue();
});

it('uses proportional grids instead of uniform columns', function () {
    $livewire = makeConstructionSchemaLivewire();

    $identification = mountConstructionSection(ConstructionForm::identificationSection(), $livewire);
    $location = mountConstructionSection(ConstructionForm::locationSection(), $livewire);
    $schedule = mountConstructionSection(ConstructionForm::scheduleAndValuesSection(), $livewire);
    $measurement = mountConstructionSection(ConstructionForm::measurementSection(), $livewire);

    expect($identification)->toBeInstanceOf(Section::class)
        ->and($identification->getColumns('lg'))->toBe(4)
        ->and(sectionColumnSpan($identification, 'development_name', 'lg'))->toBe(3)
        ->and(sectionColumnSpan($identification, 'development_cnpj', 'lg'))->toBe(1)
        ->and(sectionColumnSpan($identification, 'development_trade_name', 'lg'))->toBe(3)
        ->and($location->getColumns('sm'))->toBe(3)
        ->and(sectionColumnSpan($location, 'city', 'sm'))->toBe(2)
        ->and(sectionColumnSpan($location, 'state', 'sm'))->toBe(1)
        ->and($schedule->getColumns('lg'))->toBe(4)
        ->and(sectionColumnSpan($schedule, 'estimated_value', 'lg'))->toBe(2)
        ->and(sectionColumnSpan($schedule, 'construction_start_date', 'lg'))->toBe(1)
        ->and($measurement->getColumns('lg'))->toBe(5)
        ->and(sectionColumnSpan($measurement, 'measurement_company_id', 'lg'))->toBe(3)
        ->and(sectionColumnSpan($measurement, 'measurement_company_cnpj', 'lg'))->toBe(2);
});

it('marks the measurement company cnpj as intentionally read-only', function () {
    $construction = Construction::factory()->create();

    Livewire::test(EditConstruction::class, [
        'record' => $construction->getRouteKey(),
    ])
        ->assertFormFieldExists('measurement_company_cnpj', function (TextInput $field): bool {
            return $field->isReadOnly() && ! $field->isDisabled();
        })
        ->assertSee('Preenchido automaticamente a partir da empresa de medição selecionada.');
});

it('configures the sales discount policy relation manager with proper title, description, and actions', function () {
    $construction = Construction::factory()->create();

    $component = Livewire::test(SalesDiscountPoliciesRelationManager::class, [
        'ownerRecord' => $construction,
        'pageClass' => EditConstruction::class,
    ]);

    $table = $component->instance()->getTable();

    expect(SalesDiscountPoliciesRelationManager::getTitle($construction, EditConstruction::class))->toBe('Política Comercial de Desconto')
        ->and($table->getDescription())->toBe('Regras comerciais e limites aplicáveis aos descontos da obra.')
        ->and($table->getEmptyStateHeading())->toBe('Nenhuma política registrada');

    $component
        ->assertTableActionExists('newPolicy');
});

it('shows registered policy rows with viewReason action', function () {
    $construction = Construction::factory()->create();
    $policy = SalesDiscountPolicy::factory()->forConstruction($construction)->effectiveFrom('2026-01-01')->create([
        'reason' => 'Aprovação de diretoria',
    ]);

    Livewire::test(SalesDiscountPoliciesRelationManager::class, [
        'ownerRecord' => $construction,
        'pageClass' => EditConstruction::class,
    ])
        ->assertCanSeeTableRecords([$policy])
        ->assertTableActionExists('viewReason', record: $policy);
});

function mountConstructionSection(Section $section, object $livewire): Section
{
    Schema::make($livewire)->components([$section])->getComponents();

    return $section;
}

function sectionColumnSpan(Section $section, string $componentName, string $breakpoint): mixed
{
    $component = collect($section->getChildComponents())
        ->first(fn (Component $component): bool => $component->getName() === $componentName);

    expect($component)->not->toBeNull();

    return $component->getColumnSpan($breakpoint);
}

function makeConstructionSchemaLivewire(): LivewireComponent&HasSchemas
{
    return new class extends LivewireComponent implements HasSchemas
    {
        public function __construct()
        {
            $this->setId('construction-edit-page-test');
            $this->setName('construction-edit-page-test');
        }

        public function makeFilamentTranslatableContentDriver(): ?TranslatableContentDriver
        {
            return null;
        }

        public function getOldSchemaState(string $statePath): mixed
        {
            return null;
        }

        public function getSchemaComponent(string $key, bool $withHidden = false, array $skipComponentsChildContainersWhileSearching = []): Component|Action|ActionGroup|null
        {
            return null;
        }

        public function getSchema(string $name): ?Schema
        {
            return null;
        }

        public function currentlyValidatingSchema(?Schema $schema): void {}

        public function getDefaultTestingSchemaName(): ?string
        {
            return null;
        }
    };
}
