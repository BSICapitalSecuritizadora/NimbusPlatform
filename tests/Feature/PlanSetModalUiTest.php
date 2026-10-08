<?php

use App\Enums\MeasurementPlanVersionStatus;
use App\Filament\Resources\Operations\Pages\ViewOperation;
use App\Filament\Resources\Operations\RelationManagers\PlanSetsRelationManager;
use App\Models\Construction;
use App\Models\Emission;
use App\Models\MeasurementPlanSet;
use App\Models\Operation;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(makeAdminUser());
});

function makePlanSetModalScenario(): array
{
    $emission = Emission::factory()->create();
    $construction = Construction::factory()->create([
        'emission_id' => $emission->id,
        'development_name' => 'Residencial Modal',
    ]);
    $operation = Operation::factory()->create(['emission_id' => $emission->id]);

    return compact('emission', 'construction', 'operation');
}

function planSetsRelationManager(Operation $operation)
{
    return Livewire::test(PlanSetsRelationManager::class, [
        'ownerRecord' => $operation,
        'pageClass' => ViewOperation::class,
    ]);
}

it('renders the create plan modal in portuguese with a hierarchical footer', function () {
    ['operation' => $operation] = makePlanSetModalScenario();

    $component = planSetsRelationManager($operation)
        ->mountTableAction('create')
        ->assertTableActionMounted('create');

    $action = $component->instance()->getMountedAction();

    expect($action->getModalHeading())->toBe('Criar Plano de Medição')
        ->and($action->getModalDescription())->toBe('Defina os dados do plano e cadastre o cronograma físico previsto.')
        ->and($action->getModalWidth())->toBe(Width::SevenExtraLarge)
        ->and($action->getModalSubmitActionLabel())->toBe('Criar')
        ->and($action->getModalCancelActionLabel())->toBe('Cancelar')
        ->and($action->getCreateAnotherAction()->isOutlined())->toBeTrue()
        ->and($action->getModalCancelAction()->isOutlined())->toBeTrue()
        ->and($action->getExtraModalWindowAttributes()['class'] ?? '')->toContain('bsi-plan-set-modal-window');
});

it('keeps the executive field structure with searchable development and table schedule', function () {
    ['operation' => $operation] = makePlanSetModalScenario();

    $livewire = planSetsRelationManager($operation)
        ->mountTableAction('create')
        ->assertTableActionMounted('create')
        ->instance();

    $html = $livewire->getSchema($livewire->getMountedActionSchemaName())->toHtml();

    // O Fundo de Obra e o cronograma do "Novo Plano" são os da V1, que nasce
    // em rascunho; replanejar um plano em vigor é revisá-lo.
    $orderedLabels = [
        'Nome do Plano',
        'Empreendimento',
        'Um plano por empreendimento. Para mudar o cronograma ou o Fundo de Obra de um plano em vigor, crie uma revisão na aba Versões dos Planos.',
        'Plano padrão',
        'Definir como plano padrão desta operação.',
        'Fundo de Obra (V1)',
        'Incorrido Inicial',
        'Cronograma físico — Previsto',
        'Pré-cadastre as medições previstas da V1 do plano, que nasce em rascunho e passa a valer quando for ativada (aba Versões dos Planos).',
        'Nenhuma medição prevista cadastrada.',
        'Adicione a primeira medição para estruturar o cronograma físico.',
        'Medições do plano',
        'Adicionar medição',
    ];

    foreach ($orderedLabels as $label) {
        expect($html)->toContain(e($label));
    }

    $positions = array_map(fn (string $label): int|false => strpos($html, e($label)), $orderedLabels);
    $sorted = $positions;
    sort($sorted);

    expect($positions)->toBe($sorted);

    $components = collect($livewire->getSchema($livewire->getMountedActionSchemaName())->getFlatComponents(withHidden: true));

    $select = $components->first(fn ($component): bool => $component instanceof Select && $component->getName() === 'construction_id');
    $toggle = $components->first(fn ($component): bool => $component instanceof Toggle && $component->getName() === 'is_default');
    $repeater = $components->first(fn ($component): bool => $component instanceof Repeater && $component->getName() === 'lines');

    expect($select->isSearchable())->toBeTrue()
        ->and($select->getPlaceholder())->toBe('Selecione o empreendimento...')
        ->and($toggle->isInline())->toBeTrue()
        ->and($repeater->isTable())->toBeTrue()
        ->and($repeater->getAddActionAlignment())->toBe(Alignment::End)
        ->and($repeater->getAddActionLabel())->toBe('Adicionar medição')
        ->and($html)->toContain('bsi-plan-lines-empty-icon')
        ->and($html)->toContain('bsi-plan-lines-empty-svg')
        ->and($html)->toContain('bsi-plan-default-toggle-wrp')
        ->and($html)->toContain('bsi-plan-money-field');
});

it('creates through the modal the plan and its V1 draft with lines, fund, default flag and incurred amount', function () {
    ['operation' => $operation, 'construction' => $construction] = makePlanSetModalScenario();

    planSetsRelationManager($operation)
        ->callTableAction('create', data: [
            'name' => 'Plano Executivo',
            'construction_id' => $construction->id,
            'is_default' => true,
            'construction_fund_amount' => 1500000.00,
            'initial_incurred_amount' => 250000.00,
            'lines' => [
                [
                    'sequence_number' => 1,
                    'planned_monthly_percent' => 15,
                    'planned_cumulative_percent' => 15,
                    'initial_realized_cumulative_percent' => 0,
                    'measurement_date' => '2026-10',
                ],
                [
                    'sequence_number' => 2,
                    'planned_monthly_percent' => 20,
                    'planned_cumulative_percent' => 35,
                    'initial_realized_cumulative_percent' => 0,
                    'measurement_date' => '2026-11',
                ],
            ],
        ])
        ->assertHasNoTableActionErrors();

    $planSet = MeasurementPlanSet::query()
        ->where('operation_id', $operation->id)
        ->where('name', 'Plano Executivo')
        ->firstOrFail();

    // O plano guarda a identidade e o incorrido inicial; o Fundo de Obra e o
    // cronograma são da V1, em rascunho até a ativação.
    $version = $planSet->versions()->sole();

    expect($planSet->construction_id)->toBe($construction->id)
        ->and($planSet->is_default)->toBeTrue()
        ->and((float) $planSet->initial_incurred_amount)->toEqual(250000.00)
        ->and($version->version_number)->toBe(1)
        ->and($version->status)->toBe(MeasurementPlanVersionStatus::Draft)
        ->and($version->construction_fund_amount)->toBe('1500000.00')
        ->and($planSet->currentConstructionFundAmount())->toBe('1500000.00')
        ->and($version->lines()->count())->toBe(2)
        ->and($planSet->lines()->count())->toBe(2)
        ->and($version->lines()->orderBy('sequence_number')->first()->measurement_date->format('Y-m'))->toBe('2026-10');
});

it('still requires the plan name', function () {
    ['operation' => $operation, 'construction' => $construction] = makePlanSetModalScenario();

    planSetsRelationManager($operation)
        ->callTableAction('create', data: [
            'name' => null,
            'construction_id' => $construction->id,
        ])
        ->assertHasTableActionErrors(['name' => 'required']);

    expect(MeasurementPlanSet::query()->where('operation_id', $operation->id)->count())->toBe(0);
});
