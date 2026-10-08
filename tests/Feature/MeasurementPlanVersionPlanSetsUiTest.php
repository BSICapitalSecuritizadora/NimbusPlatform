<?php

use App\Enums\MeasurementPlanRevisionCategory;
use App\Enums\MeasurementPlanVersionStatus;
use App\Filament\Resources\Operations\Pages\EditOperation;
use App\Filament\Resources\Operations\RelationManagers\PlanSetsRelationManager;
use App\Models\Construction;
use App\Models\Emission;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Models\MeasurementPlanVersion;
use App\Models\Operation;
use App\Models\User;
use App\Services\MeasurementPlanVersionService;
use App\Services\OperationLifecycleService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Text;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\MeasurementPhysicalProgressScenario;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    Notification::fake();
    // A medição do teste de exclusão guarda o arquivo no disco privado falso.
    config()->set('filesystems.private_disk', 'local');
});

/**
 * Operação em andamento de uma emissão com três obras e o administrador em
 * todos os papéis do fluxo, já logado: a autorização só é assunto onde o
 * teste troca o usuário.
 *
 * @param  array<string, mixed>  $operationAttributes
 * @return array{actor: User, operation: Operation, construction: Construction, otherConstruction: Construction, thirdConstruction: Construction}
 */
function planSetsVersioningUiScenario(array $operationAttributes = []): array
{
    $actor = makeAdminUser();
    $emission = Emission::factory()->create();
    $construction = Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => 'Torre Aurora']);
    $otherConstruction = Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => 'Torre Boreal']);
    $thirdConstruction = Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => 'Torre Cruzeiro']);
    $operation = Operation::factory()->forEmission($emission)->create([
        ...array_fill_keys(Operation::RESPONSIBILITY_FIELDS, $actor->id),
        ...$operationAttributes,
    ]);
    test()->actingAs($actor);

    return compact('actor', 'operation', 'construction', 'otherConstruction', 'thirdConstruction');
}

/**
 * Cronograma de 10% ao mês nas competências dadas, como o serviço o recebe.
 *
 * @param  list<string>  $months
 * @return list<array{sequence_number: int, planned_monthly_percent: string, planned_cumulative_percent: string, measurement_date: string}>
 */
function planSetsVersioningUiSchedule(array $months): array
{
    $schedule = [];

    foreach (array_values($months) as $index => $month) {
        $schedule[] = [
            'sequence_number' => $index + 1,
            'planned_monthly_percent' => '10.00',
            'planned_cumulative_percent' => sprintf('%d.00', 10 * ($index + 1)),
            'measurement_date' => $month,
        ];
    }

    return $schedule;
}

/**
 * Plano criado pelo serviço: a V1 nasce em rascunho com o Fundo de Obra e o
 * cronograma informados. O primeiro plano da operação é o padrão.
 *
 * @param  array{actor: User, operation: Operation}  $scenario
 * @param  list<string>  $months
 */
function planSetsVersioningUiPlan(array $scenario, Construction $construction, string $fund = '1000000.00', array $months = ['2026-05', '2026-06', '2026-07']): MeasurementPlanSet
{
    return app(MeasurementPlanVersionService::class)->createPlan($scenario['operation'], $scenario['actor'], [
        'name' => 'Plano '.$construction->development_name,
        'construction_id' => $construction->id,
        'is_default' => ! $scenario['operation']->planSets()->exists(),
        'initial_incurred_amount' => '0.00',
    ], ['construction_fund_amount' => $fund], planSetsVersioningUiSchedule($months));
}

/**
 * Ativa pelo serviço o rascunho do plano como a pessoa o viu: o contador
 * atual.
 */
function planSetsVersioningUiActivate(MeasurementPlanSet $planSet, User $actor): MeasurementPlanVersion
{
    $draft = $planSet->draftVersion()->firstOrFail();

    return app(MeasurementPlanVersionService::class)->activate($draft, $actor, (int) $draft->revision);
}

/**
 * Abre pelo serviço a próxima revisão do plano, como outra pessoa faria.
 */
function planSetsVersioningUiRevise(MeasurementPlanSet $planSet, User $actor): MeasurementPlanVersion
{
    return app(MeasurementPlanVersionService::class)->createRevision($planSet->fresh(), $actor, [
        'revision_category' => MeasurementPlanRevisionCategory::Schedule->value,
        'revision_reason' => 'Fundação atrasou; cronograma refeito com a construtora.',
    ]);
}

/**
 * Plano da Torre Aurora com a V1 vigente desde 05/2026, ativada pelo serviço
 * em 15/05/2026.
 *
 * @return array{actor: User, operation: Operation, construction: Construction, otherConstruction: Construction, thirdConstruction: Construction, planSet: MeasurementPlanSet, v1: MeasurementPlanVersion}
 */
function planSetsVersioningUiActivePlan(): array
{
    test()->travelTo(CarbonImmutable::parse('2026-05-15 12:00:00'));

    $scenario = planSetsVersioningUiScenario();
    $planSet = planSetsVersioningUiPlan($scenario, $scenario['construction']);
    $v1 = planSetsVersioningUiActivate($planSet, $scenario['actor']);

    return [...$scenario, 'planSet' => $planSet->fresh(), 'v1' => $v1];
}

/**
 * A aba de planos como a página de edição da operação a monta.
 */
function planSetsVersioningUiTable(Operation $operation): Testable
{
    return Livewire::test(PlanSetsRelationManager::class, [
        'ownerRecord' => $operation,
        'pageClass' => EditOperation::class,
    ]);
}

/**
 * Quem acompanha a operação sem poder alterá-la: participa dela, mas só tem a
 * permissão de ver operações.
 */
function planSetsVersioningUiReader(Operation $operation): User
{
    $reader = User::factory()->withTwoFactor()->create();
    $reader->givePermissionTo('operations.view');
    $operation->update(['assigned_user_id' => $reader->id]);

    return $reader;
}

/**
 * Campo do modal montado, pela chave relativa ao modal.
 */
function planSetsVersioningUiModalField(Testable $component, string $key): Field
{
    $livewire = $component->instance();

    return $livewire->getSchema($livewire->getMountedActionSchemaName())->getFlatFields(withHidden: true)[$key];
}

/**
 * Texto de ajuda do campo. No Filament v5 o `helperText()` vira um `Text` no
 * conteúdo abaixo do campo; `null` quando o campo não mostra nenhum.
 */
function planSetsVersioningUiHelperText(Field $field): ?string
{
    $texts = collect($field->getChildSchema(Field::BELOW_CONTENT_SCHEMA_KEY)?->getComponents() ?? [])
        ->filter(fn (mixed $component): bool => $component instanceof Text)
        ->map(fn (Text $text): string => (string) $text->getContent());

    return $texts->isEmpty() ? null : $texts->implode(' ');
}

/**
 * Acrescenta pelo botão do Repeater as medições previstas do "Novo Plano",
 * como o navegador faz: cada item ganha a chave própria (UUID) do Repeater.
 *
 * @return list<string> as chaves dos itens, na ordem em que entraram
 */
function planSetsVersioningUiAddLines(Testable $component, int $count): array
{
    for ($added = 0; $added < $count; $added++) {
        $component->callAction(TestAction::make('add')->schemaComponent('lines'));
    }

    return array_map(strval(...), array_keys($component->get('mountedActions.0.data.lines')));
}

/**
 * Notificações do Filament na sessão, lidas sem consumir -- o
 * `assertNotified()` compara só o título e esvazia a sessão.
 *
 * @return list<array<string, mixed>>
 */
function planSetsVersioningUiNotifications(): array
{
    return array_values(session()->get('filament.claimed_notifications') ?? session()->get('filament.notifications') ?? []);
}

/**
 * Título, corpo e situação da notificação com o título dado.
 *
 * @return array{title: string, body: string|null, status: string|null}|null
 */
function planSetsVersioningUiNotification(string $title): ?array
{
    $notification = collect(planSetsVersioningUiNotifications())->firstWhere('title', $title);

    return $notification === null ? null : [
        'title' => (string) $notification['title'],
        'body' => $notification['body'] === null ? null : (string) $notification['body'],
        'status' => $notification['status'] ?? null,
    ];
}

/**
 * Todas as notificações enviadas desde a última limpeza, com o que a pessoa
 * lê de cada uma: a lista inteira, para provar que não saiu outra -- um
 * "Salvo" ao lado da recusa, por exemplo.
 *
 * @return list<array{title: string, body: string|null, status: string|null}>
 */
function planSetsVersioningUiSentNotifications(): array
{
    return array_map(fn (array $notification): array => [
        'title' => (string) $notification['title'],
        'body' => $notification['body'] === null ? null : (string) $notification['body'],
        'status' => $notification['status'] ?? null,
    ], planSetsVersioningUiNotifications());
}

function planSetsVersioningUiForgetNotifications(): void
{
    session()->forget(['filament.notifications', 'filament.claimed_notifications']);
}

/**
 * O Fundo de Obra do empreendimento como o formulário da operação o mostra e
 * os marcadores que ele devolve ao gravar: o fundo que mostrou e o contador do
 * rascunho que leu. A linha é relida a cada chamada -- a página remonta o
 * Repeater depois de salvar.
 *
 * @return array{construction_fund_amount: mixed, construction_fund_original: mixed, construction_fund_revision: mixed}
 */
function planSetsVersioningUiFundTokens(Testable $page): array
{
    $developments = $page->get('data.developments');

    return Arr::only($developments[array_key_first($developments)], [
        'construction_fund_amount',
        'construction_fund_original',
        'construction_fund_revision',
    ]);
}

/**
 * Os planos, as versões, as linhas e os arquivos exatamente como estão no
 * banco, e o tamanho da trilha: depois de uma recusa nada pode ter mudado.
 *
 * @return array<string, mixed>
 */
function planSetsVersioningUiDatabaseState(): array
{
    $rows = fn (string $table): array => DB::table($table)
        ->orderBy('id')
        ->get()
        ->map(fn (object $row): array => (array) $row)
        ->all();

    return [
        'measurement_plan_sets' => $rows('measurement_plan_sets'),
        'measurement_plan_versions' => $rows('measurement_plan_versions'),
        'measurement_plan_lines' => $rows('measurement_plan_lines'),
        'measurement_assets' => $rows('measurement_assets'),
        'activity_log' => DB::table('activity_log')->count(),
    ];
}

/**
 * O previsto de cada linha da versão, com a linhagem que a identifica entre
 * as versões.
 *
 * @return list<array{lineage_key: string, sequence_number: int, planned_monthly_percent: string, planned_cumulative_percent: string, measurement_date: string}>
 */
function planSetsVersioningUiPlannedLines(MeasurementPlanVersion $version): array
{
    return $version->lines()
        ->orderBy('sequence_number')
        ->get()
        ->map(fn (MeasurementPlanLine $line): array => [
            'lineage_key' => (string) $line->lineage_key,
            'sequence_number' => (int) $line->sequence_number,
            'planned_monthly_percent' => $line->planned_monthly_percent,
            'planned_cumulative_percent' => $line->planned_cumulative_percent,
            'measurement_date' => $line->measurement_date->toDateString(),
        ])
        ->all();
}

/**
 * O evento de ciclo de vida na trilha protegida `measurements`.
 */
function planSetsVersioningUiEvent(string $event, MeasurementPlanSet|MeasurementPlanVersion $subject): Activity
{
    return Activity::query()
        ->where('log_name', 'measurements')
        ->where('event', $event)
        ->where('subject_type', $subject->getMorphClass())
        ->where('subject_id', $subject->getKey())
        ->sole();
}

// ── "Novo Plano" ─────────────────────────────────────────────────────────────

it('creates through the modal the plan and its V1 draft with the fund and the rows added in the repeater', function () {
    ['actor' => $actor, 'operation' => $operation, 'construction' => $construction] = planSetsVersioningUiScenario();

    $component = planSetsVersioningUiTable($operation)->mountTableAction('create');
    [$first, $second] = planSetsVersioningUiAddLines($component, 2);

    // Valores digitados como o navegador os manda: dinheiro com a máscara
    // pt-BR e o mês no formato do campo 'month'.
    $component->fillForm([
        'name' => 'Plano Torre Aurora',
        'construction_id' => $construction->id,
        'is_default' => true,
        'construction_fund_amount' => '1.500.000,00',
        'initial_incurred_amount' => '250.000,00',
        'lines' => [
            $first => ['sequence_number' => 1, 'planned_monthly_percent' => 15, 'planned_cumulative_percent' => 15, 'measurement_date' => '2026-10'],
            $second => ['sequence_number' => 2, 'planned_monthly_percent' => 20, 'planned_cumulative_percent' => 35, 'measurement_date' => '2026-11'],
        ],
    ])->callMountedTableAction()
        ->assertHasNoTableActionErrors()
        ->assertActionNotMounted();

    $planSet = $operation->planSets()->sole();
    $version = MeasurementPlanVersion::query()->where('plan_set_id', $planSet->id)->sole();
    $lines = MeasurementPlanLine::query()->where('plan_set_id', $planSet->id)->orderBy('sequence_number')->get();

    // O plano guarda a identidade e o incorrido; o Fundo de Obra e o
    // cronograma são da V1, que só passa a valer quando for ativada.
    expect($planSet->only(['name', 'construction_id', 'is_default', 'initial_incurred_amount']))->toBe([
        'name' => 'Plano Torre Aurora',
        'construction_id' => $construction->id,
        'is_default' => true,
        'initial_incurred_amount' => '250000.00',
    ])
        ->and($version->version_number)->toBe(1)
        ->and($version->status)->toBe(MeasurementPlanVersionStatus::Draft)
        ->and($version->construction_fund_amount)->toBe('1500000.00')
        ->and($version->revision)->toBe(0)
        ->and($version->created_by)->toBe($actor->id)
        ->and($version->effective_from)->toBeNull()
        ->and($lines->pluck('plan_version_id')->all())->toBe([$version->id, $version->id])
        ->and($lines->map(fn (MeasurementPlanLine $line): array => [
            $line->sequence_number,
            $line->planned_monthly_percent,
            $line->planned_cumulative_percent,
            $line->measurement_date->toDateString(),
        ])->all())->toBe([
            [1, '15.00', '15.00', '2026-10-01'],
            [2, '20.00', '35.00', '2026-11-01'],
        ])
        // Cada medição prevista nova ganha a própria linhagem.
        ->and($lines->pluck('lineage_key')->filter()->unique())->toHaveCount(2);

    $created = planSetsVersioningUiEvent('plan_version_created', $version);

    expect($created->causer_id)->toBe($actor->id)
        ->and(Arr::only($created->properties->all(), ['version_number', 'status', 'construction_fund_amount', 'line_count', 'actor_user_id']))->toBe([
            'version_number' => 1,
            'status' => 'draft',
            'construction_fund_amount' => '1500000.00',
            'line_count' => 2,
            'actor_user_id' => $actor->id,
        ]);
});

it('keeps the field errors of the schedule on the repeater rows, keyed by the item key and not by position', function () {
    ['operation' => $operation, 'construction' => $construction] = planSetsVersioningUiScenario();

    $component = planSetsVersioningUiTable($operation)->mountTableAction('create');
    [$first, $second] = planSetsVersioningUiAddLines($component, 2);

    $component->fillForm([
        'name' => 'Plano Torre Aurora',
        'construction_id' => $construction->id,
        'lines' => [
            $first => ['sequence_number' => null, 'planned_monthly_percent' => 150, 'planned_cumulative_percent' => 10, 'measurement_date' => '2026-10'],
            $second => ['sequence_number' => 2, 'planned_monthly_percent' => 10, 'planned_cumulative_percent' => -1, 'measurement_date' => '2026-11'],
        ],
    ])->callMountedTableAction();

    // O Repeater identifica cada item por UUID: é nesse caminho que o campo
    // desenha o erro (com a posição, ".0.", nenhum campo o mostraria).
    expect(Str::isUuid($first))->toBeTrue()
        ->and(Str::isUuid($second))->toBeTrue()
        ->and($component->errors()->toArray())->toBe([
            "mountedActions.0.data.lines.{$first}.sequence_number" => ['O campo medição # é obrigatório.'],
            "mountedActions.0.data.lines.{$first}.planned_monthly_percent" => ['O campo previsto mensal (%) não deve ser maior que 100.'],
            "mountedActions.0.data.lines.{$second}.planned_cumulative_percent" => ['O campo previsto acum. (%) deve ser pelo menos 0.'],
        ]);

    $component->assertActionMounted(TestAction::make('create')->table());

    expect(MeasurementPlanSet::query()->count())->toBe(0)
        ->and(MeasurementPlanVersion::query()->count())->toBe(0)
        ->and(planSetsVersioningUiNotifications())->toBe([]);
});

it('puts a schedule refusal of the plan service on the repeater row that caused it, keeping the modal open', function () {
    ['operation' => $operation, 'construction' => $construction] = planSetsVersioningUiScenario();

    $component = planSetsVersioningUiTable($operation)->mountTableAction('create');
    [$first, $second] = planSetsVersioningUiAddLines($component, 2);

    // O campo aceita cada sequência sozinha; a repetição só o serviço de
    // versões enxerga, e devolve o erro pela posição da linha (lines.1).
    $component->fillForm([
        'name' => 'Plano Torre Aurora',
        'construction_id' => $construction->id,
        'lines' => [
            $first => ['sequence_number' => 1, 'planned_monthly_percent' => 10, 'planned_cumulative_percent' => 10, 'measurement_date' => '2026-10'],
            $second => ['sequence_number' => 1, 'planned_monthly_percent' => 10, 'planned_cumulative_percent' => 20, 'measurement_date' => '2026-11'],
        ],
    ])->callMountedTableAction();

    expect($component->errors()->toArray())->toBe([
        "mountedActions.0.data.lines.{$second}.sequence_number" => ['A medição prevista 1 aparece mais de uma vez no cronograma.'],
    ]);

    $component->assertActionMounted(TestAction::make('create')->table());

    expect(MeasurementPlanSet::query()->count())->toBe(0)
        ->and(MeasurementPlanVersion::query()->count())->toBe(0);
});

it('offers in the plan modal only the constructions that have no plan in the operation yet', function () {
    $scenario = planSetsVersioningUiScenario();
    $aurora = planSetsVersioningUiPlan($scenario, $scenario['construction']);
    planSetsVersioningUiPlan($scenario, $scenario['otherConstruction']);

    // Um plano por obra: replanejar a Torre Aurora é revisar o plano dela.
    $create = planSetsVersioningUiTable($scenario['operation'])->mountTableAction('create');
    $createSelect = planSetsVersioningUiModalField($create, 'construction_id');

    expect($createSelect)->toBeInstanceOf(Select::class)
        ->and($createSelect->getOptions())->toBe([$scenario['thirdConstruction']->id => 'Torre Cruzeiro'])
        ->and($createSelect->getSearchResults('Torre'))->toBe([$scenario['thirdConstruction']->id => 'Torre Cruzeiro']);

    // Na edição, a obra do próprio plano continua na lista.
    $edit = planSetsVersioningUiTable($scenario['operation'])->mountTableAction('edit', $aurora);
    $editSelect = planSetsVersioningUiModalField($edit, 'construction_id');

    expect($editSelect->getOptions())->toBe([
        $scenario['construction']->id => 'Torre Aurora',
        $scenario['thirdConstruction']->id => 'Torre Cruzeiro',
    ]);
});

it('refuses on the construction field a forged plan for a construction already planned in the operation', function () {
    $scenario = planSetsVersioningUiScenario();
    planSetsVersioningUiPlan($scenario, $scenario['construction']);
    $before = planSetsVersioningUiDatabaseState();

    planSetsVersioningUiTable($scenario['operation'])
        ->callTableAction('create', data: [
            'name' => 'Segundo plano da Torre Aurora',
            'construction_id' => $scenario['construction']->id,
            'construction_fund_amount' => '900.000,00',
        ])
        ->assertHasTableActionErrors(['construction_id' => MeasurementPlanSet::CONSTRUCTION_ALREADY_PLANNED_REFUSAL]);

    expect(planSetsVersioningUiDatabaseState())->toBe($before);
});

it('offers Novo Plano only while the operation still plans', function (string $status, bool $isOffered) {
    ['operation' => $operation] = planSetsVersioningUiScenario(['status' => $status]);

    $component = planSetsVersioningUiTable($operation);

    $isOffered
        ? $component->assertTableActionVisible('create')
        : $component->assertTableActionHidden('create');
})->with([
    'rascunho' => ['draft', true],
    'em andamento' => ['active', true],
    // Concluída ou cancelada não planeja até ser reaberta.
    'concluída' => ['completed', false],
    'cancelada' => ['canceled', false],
]);

it('hides Novo Plano from who cannot update the operation', function (string $profile) {
    ['operation' => $operation] = planSetsVersioningUiScenario();

    $this->actingAs($profile === 'reader'
        // Participa da operação, mas só pode vê-la.
        ? planSetsVersioningUiReader($operation)
        // Pode editar operações, mas não participa desta.
        : User::factory()->withTwoFactor()->create()->assignRole('editor'));

    planSetsVersioningUiTable($operation->fresh())->assertTableActionHidden('create');
})->with(['participante só leitor' => ['reader'], 'editor de fora da operação' => ['editor']]);

// ── Edição do plano ──────────────────────────────────────────────────────────

it('edits only the name and the default flag of a plan in force, ignoring a forged fund, schedule or context', function () {
    ['operation' => $operation, 'construction' => $construction, 'otherConstruction' => $otherConstruction, 'planSet' => $planSet, 'v1' => $v1] = planSetsVersioningUiActivePlan();
    $before = planSetsVersioningUiDatabaseState();

    $component = planSetsVersioningUiTable($operation)
        ->mountTableAction('edit', $planSet)
        // A obra e o incorrido de um plano que já valeu ficam presos; o fundo
        // e o cronograma são da versão e nem aparecem no modal do plano.
        ->assertFormFieldDisabled('construction_id')
        ->assertFormFieldDisabled('initial_incurred_amount')
        ->assertFormFieldDisabled('initial_physical_progress_percent')
        ->assertFormFieldDoesNotExist('construction_fund_amount')
        ->assertFormFieldDoesNotExist('lines');

    $component->set('mountedActions.0.data.name', 'Plano Aurora — Torre A')
        ->set('mountedActions.0.data.is_default', false)
        // Payload forjado: destrava a obra e o incorrido e manda fundo e
        // cronograma que o modal não tem.
        ->set('mountedActions.0.data.construction_id', $otherConstruction->id)
        ->set('mountedActions.0.data.initial_incurred_amount', '1,00')
        ->set('mountedActions.0.data.construction_fund_amount', '99.999,00')
        ->set('mountedActions.0.data.lines', [
            ['sequence_number' => 9, 'planned_monthly_percent' => 50, 'planned_cumulative_percent' => 50, 'measurement_date' => '2026-12'],
        ])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    expect($planSet->fresh()->only(['name', 'is_default', 'construction_id', 'initial_incurred_amount']))->toBe([
        'name' => 'Plano Aurora — Torre A',
        'is_default' => false,
        'construction_id' => $construction->id,
        'initial_incurred_amount' => '0.00',
    ])
        ->and(Arr::only(planSetsVersioningUiDatabaseState(), ['measurement_plan_versions', 'measurement_plan_lines']))
        ->toBe(Arr::only($before, ['measurement_plan_versions', 'measurement_plan_lines']))
        ->and($planSet->fresh()->currentConstructionFundAmount())->toBe('1000000.00')
        ->and($v1->fresh()->status)->toBe(MeasurementPlanVersionStatus::Active);
});

it('lets the construction and the initial incurred amount change while the plan was never activated, still without fund or schedule', function () {
    $scenario = planSetsVersioningUiScenario();
    $planSet = planSetsVersioningUiPlan($scenario, $scenario['construction']);
    $before = planSetsVersioningUiDatabaseState();

    planSetsVersioningUiTable($scenario['operation'])
        ->mountTableAction('edit', $planSet)
        ->assertFormFieldEnabled('construction_id')
        ->assertFormFieldEnabled('initial_incurred_amount')
        ->assertFormFieldDoesNotExist('construction_fund_amount')
        ->assertFormFieldDoesNotExist('lines')
        ->setTableActionData([
            'construction_id' => $scenario['otherConstruction']->id,
            'initial_incurred_amount' => '300.000,00',
        ])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    expect($planSet->fresh()->construction_id)->toBe($scenario['otherConstruction']->id)
        ->and($planSet->fresh()->initial_incurred_amount)->toBe('300000.00')
        // A V1 em rascunho e o cronograma dela não mudam pela edição do plano.
        ->and(Arr::only(planSetsVersioningUiDatabaseState(), ['measurement_plan_versions', 'measurement_plan_lines']))
        ->toBe(Arr::only($before, ['measurement_plan_versions', 'measurement_plan_lines']));
});

it('refuses the construction and incurred change of a plan activated while the edit modal was open, saving nothing of the edit', function () {
    $this->travelTo(CarbonImmutable::parse('2026-05-15 12:00:00'));
    $scenario = planSetsVersioningUiScenario();
    $planSet = planSetsVersioningUiPlan($scenario, $scenario['construction']);

    // O modal abre com a V1 ainda em rascunho: obra e incorrido livres, e a
    // trava guardada como estava na abertura.
    $component = planSetsVersioningUiTable($scenario['operation'])
        ->mountTableAction('edit', $planSet)
        ->assertTableActionDataSet(['context_locked' => false])
        ->assertFormFieldEnabled('construction_id')
        ->assertFormFieldEnabled('initial_incurred_amount')
        ->setTableActionData([
            'name' => 'Plano Torre Boreal',
            'construction_id' => $scenario['otherConstruction']->id,
            'initial_incurred_amount' => '300.000,00',
        ]);

    // Com o modal aberto, outra pessoa ativa a V1 pelo serviço.
    $v1 = planSetsVersioningUiActivate($planSet, makeAdminUser());
    $before = planSetsVersioningUiDatabaseState();
    planSetsVersioningUiForgetNotifications();

    // Os campos seguem editáveis (vale a trava da abertura): a troca chega ao
    // serviço e é recusada com o motivo, em vez de descartada calada sob um
    // "Salvo". A gravação do plano é uma só, então o nome também não muda.
    $component->callMountedTableAction()
        ->assertHasNoTableActionErrors()
        ->assertActionMounted(TestAction::make('edit')->table($planSet));

    expect(planSetsVersioningUiSentNotifications())->toBe([[
        'title' => 'Plano não atualizado.',
        'body' => MeasurementPlanSet::PLAN_CONTEXT_LOCKED_REFUSAL,
        'status' => 'danger',
    ]])
        ->and(planSetsVersioningUiDatabaseState())->toBe($before)
        ->and($planSet->fresh()->only(['name', 'construction_id', 'initial_incurred_amount']))->toBe([
            'name' => 'Plano Torre Aurora',
            'construction_id' => $scenario['construction']->id,
            'initial_incurred_amount' => '0.00',
        ])
        ->and($v1->fresh()->status)->toBe(MeasurementPlanVersionStatus::Active);
});

it('saves the same plan edit when nobody activated the plan while the modal was open', function () {
    $this->travelTo(CarbonImmutable::parse('2026-05-15 12:00:00'));
    $scenario = planSetsVersioningUiScenario();
    $planSet = planSetsVersioningUiPlan($scenario, $scenario['construction']);
    $before = planSetsVersioningUiDatabaseState();
    planSetsVersioningUiForgetNotifications();

    planSetsVersioningUiTable($scenario['operation'])
        ->mountTableAction('edit', $planSet)
        ->setTableActionData([
            'name' => 'Plano Torre Boreal',
            'construction_id' => $scenario['otherConstruction']->id,
            'initial_incurred_amount' => '300.000,00',
        ])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors()
        ->assertActionNotMounted();

    expect(planSetsVersioningUiSentNotifications())->toBe([['title' => 'Salvo', 'body' => null, 'status' => 'success']])
        ->and($planSet->fresh()->only(['name', 'construction_id', 'initial_incurred_amount']))->toBe([
            'name' => 'Plano Torre Boreal',
            'construction_id' => $scenario['otherConstruction']->id,
            'initial_incurred_amount' => '300000.00',
        ])
        // A V1 continua em rascunho, com o cronograma de antes.
        ->and(Arr::only(planSetsVersioningUiDatabaseState(), ['measurement_plan_versions', 'measurement_plan_lines']))
        ->toBe(Arr::only($before, ['measurement_plan_versions', 'measurement_plan_lines']));
});

it('still saves the new name of a plan activated while the edit modal was open when its construction and incurred stay as they were', function () {
    $this->travelTo(CarbonImmutable::parse('2026-05-15 12:00:00'));
    $scenario = planSetsVersioningUiScenario();
    $planSet = planSetsVersioningUiPlan($scenario, $scenario['construction']);

    $component = planSetsVersioningUiTable($scenario['operation'])
        ->mountTableAction('edit', $planSet)
        ->setTableActionData(['name' => 'Plano Aurora — fase 1']);

    planSetsVersioningUiActivate($planSet, makeAdminUser());
    planSetsVersioningUiForgetNotifications();

    // A recusa é só da troca dos campos travados: obra e incorrido vão iguais
    // ao que o plano tem, e o nome do plano em vigor continua livre.
    $component->callMountedTableAction()
        ->assertHasNoTableActionErrors()
        ->assertActionNotMounted();

    expect(planSetsVersioningUiSentNotifications())->toBe([['title' => 'Salvo', 'body' => null, 'status' => 'success']])
        ->and($planSet->fresh()->only(['name', 'construction_id', 'initial_incurred_amount']))->toBe([
            'name' => 'Plano Aurora — fase 1',
            'construction_id' => $scenario['construction']->id,
            'initial_incurred_amount' => '0.00',
        ]);
});

// ── Colunas da versão ────────────────────────────────────────────────────────

it('shows the version that answers for the plan, its validity, the next step and the lines of that version only', function () {
    $this->travelTo(CarbonImmutable::parse('2026-05-15 12:00:00'));
    $scenario = planSetsVersioningUiScenario();
    $actor = $scenario['actor'];
    $service = app(MeasurementPlanVersionService::class);
    $planSet = planSetsVersioningUiPlan($scenario, $scenario['construction']);

    // Antes da primeira ativação o plano responde pelo rascunho da V1.
    planSetsVersioningUiTable($scenario['operation'])
        ->assertTableColumnStateSet('version', 'V1 · Rascunho', $planSet)
        ->assertTableColumnHasDescription('version', 'Ative a V1 na aba Versões dos Planos para receber medições', $planSet)
        ->assertTableColumnFormattedStateSet('lines_count', '3 linhas', $planSet);

    planSetsVersioningUiActivate($planSet, $actor);

    planSetsVersioningUiTable($scenario['operation'])
        ->assertTableColumnStateSet('version', 'V1 · Vigente', $planSet)
        ->assertTableColumnHasDescription('version', 'Vigente desde 01/05/2026', $planSet)
        ->assertTableColumnFormattedStateSet('lines_count', '3 linhas', $planSet);

    // A V2 em rascunho ganha uma medição prevista a mais: enquanto não é
    // ativada, a estrutura mostrada continua a da V1 vigente.
    $v2 = planSetsVersioningUiRevise($planSet, $actor);
    $rows = $v2->lines()->orderBy('sequence_number')->get()->map(fn (MeasurementPlanLine $line): array => [
        'id' => $line->id,
        'sequence_number' => $line->sequence_number,
        'planned_monthly_percent' => $line->planned_monthly_percent,
        'planned_cumulative_percent' => $line->planned_cumulative_percent,
        'measurement_date' => $line->measurement_date->format('Y-m'),
    ])->all();
    $v2 = $service->updateDraft($v2, $actor, [], [
        ...$rows,
        ['sequence_number' => 4, 'planned_monthly_percent' => '10.00', 'planned_cumulative_percent' => '40.00', 'measurement_date' => '2026-08'],
    ], (int) $v2->revision);

    planSetsVersioningUiTable($scenario['operation'])
        ->assertTableColumnStateSet('version', 'V1 · Vigente', $planSet)
        ->assertTableColumnHasDescription('version', 'Vigente desde 01/05/2026 · V2 em rascunho', $planSet)
        ->assertTableColumnFormattedStateSet('lines_count', '3 linhas', $planSet);

    $this->travelTo(CarbonImmutable::parse('2026-06-10 12:00:00'));
    planSetsVersioningUiActivate($planSet, $actor);
    planSetsVersioningUiRevise($planSet, $actor);

    // V1 substituída, V2 vigente com quatro medições previstas e V3 em
    // rascunho: o plano tem 3 + 4 + 4 linhas, e a estrutura conta as da V2.
    expect(MeasurementPlanLine::query()->where('plan_set_id', $planSet->id)->count())->toBe(11);

    planSetsVersioningUiTable($scenario['operation'])
        ->assertTableColumnStateSet('version', 'V2 · Vigente', $planSet)
        ->assertTableColumnHasDescription('version', 'Vigente desde 01/06/2026 · V3 em rascunho', $planSet)
        ->assertTableColumnFormattedStateSet('lines_count', '4 linhas', $planSet)
        ->assertSeeInOrder(['Plano Torre Aurora', 'Torre Aurora', 'V2 · Vigente', 'Vigente desde 01/06/2026 · V3 em rascunho', '4 linhas']);
});

// ── Criar revisão ────────────────────────────────────────────────────────────

it('offers the plan revision only for a plan in force without a draft, in an operation that still plans, to who can update it', function (string $case, bool $isOffered) {
    $scenario = planSetsVersioningUiActivePlan();
    $planSet = $scenario['planSet'];

    match ($case) {
        'V1 vigente' => null,
        'V1 em rascunho' => $planSet = planSetsVersioningUiPlan($scenario, $scenario['otherConstruction']),
        'V2 em rascunho' => planSetsVersioningUiRevise($planSet, $scenario['actor']),
        'operação concluída' => app(OperationLifecycleService::class)->complete($scenario['operation'], $scenario['actor']),
        'operação cancelada' => app(OperationLifecycleService::class)->cancel($scenario['operation'], $scenario['actor'], 'Operação encerrada pelo comitê.'),
        'sem permissão de edição' => $this->actingAs(planSetsVersioningUiReader($scenario['operation'])),
    };

    $component = planSetsVersioningUiTable($scenario['operation']->fresh());

    $isOffered
        ? $component->assertTableActionVisible('createPlanRevision', $planSet)
        : $component->assertTableActionHidden('createPlanRevision', $planSet);
})->with([
    'V1 vigente' => ['V1 vigente', true],
    // Sem versão vigente, o caminho é ativar a V1, não revisá-la.
    'V1 em rascunho' => ['V1 em rascunho', false],
    // Um rascunho por vez: conclua, ative ou cancele o que já existe.
    'V2 em rascunho' => ['V2 em rascunho', false],
    'operação concluída' => ['operação concluída', false],
    'operação cancelada' => ['operação cancelada', false],
    'sem permissão de edição' => ['sem permissão de edição', false],
]);

it('opens the revision as a draft copied from the version in force, with the category and the reason', function () {
    ['actor' => $actor, 'operation' => $operation, 'planSet' => $planSet, 'v1' => $v1] = planSetsVersioningUiActivePlan();
    $v1Before = (array) DB::table('measurement_plan_versions')->where('id', $v1->id)->sole();

    planSetsVersioningUiTable($operation)
        ->mountTableAction('createPlanRevision', $planSet)
        // A revisão confere, ao gravar, a vigente que a pessoa viu ao abrir.
        ->assertTableActionDataSet(['expected_active_version_id' => $v1->id])
        ->setTableActionData([
            'revision_category' => MeasurementPlanRevisionCategory::Cost->value,
            'revision_reason' => 'Reajuste do contrato da construtora.',
        ])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors()
        ->assertActionNotMounted();

    $v2 = MeasurementPlanVersion::query()->where('plan_set_id', $planSet->id)->where('version_number', 2)->sole();

    expect($v2->status)->toBe(MeasurementPlanVersionStatus::Draft)
        ->and($v2->previous_version_id)->toBe($v1->id)
        ->and($v2->construction_fund_amount)->toBe('1000000.00')
        ->and($v2->revision_category)->toBe(MeasurementPlanRevisionCategory::Cost)
        ->and($v2->revision_reason)->toBe('Reajuste do contrato da construtora.')
        ->and($v2->created_by)->toBe($actor->id)
        ->and($v2->revision)->toBe(0)
        ->and($v2->effective_from)->toBeNull()
        // O cronograma vem igual, com a mesma linhagem em cada medição prevista.
        ->and(planSetsVersioningUiPlannedLines($v2))->toBe(planSetsVersioningUiPlannedLines($v1))
        ->and($v2->lines()->whereNotNull('measurement_id')->exists())->toBeFalse()
        // A vigente não muda: a revisão só vale depois de ativada.
        ->and((array) DB::table('measurement_plan_versions')->where('id', $v1->id)->sole())->toBe($v1Before)
        ->and(planSetsVersioningUiNotification('V2 criada em rascunho.'))->toBe([
            'title' => 'V2 criada em rascunho.',
            'body' => 'Edite o rascunho e ative-o na aba Versões dos Planos.',
            'status' => 'success',
        ]);

    $created = planSetsVersioningUiEvent('plan_version_created', $v2);

    expect($created->causer_id)->toBe($actor->id)
        ->and(Arr::only($created->properties->all(), ['version_number', 'previous_version_id', 'revision_category', 'revision_reason', 'line_count']))->toBe([
            'version_number' => 2,
            'previous_version_id' => $v1->id,
            'revision_category' => 'cost',
            'revision_reason' => 'Reajuste do contrato da construtora.',
            'line_count' => 3,
        ]);
});

it('requires the reason of the plan revision', function () {
    ['operation' => $operation, 'planSet' => $planSet] = planSetsVersioningUiActivePlan();
    $before = planSetsVersioningUiDatabaseState();

    planSetsVersioningUiTable($operation)
        ->callTableAction('createPlanRevision', $planSet, data: [
            'revision_category' => MeasurementPlanRevisionCategory::Schedule->value,
            'revision_reason' => '',
        ])
        ->assertHasTableActionErrors(['revision_reason' => 'O campo justificativa da revisão é obrigatório.'])
        ->assertActionMounted(TestAction::make('createPlanRevision')->table($planSet));

    expect(planSetsVersioningUiDatabaseState())->toBe($before);
});

it('refuses a revision opened on a version that another person has since replaced', function () {
    ['actor' => $actor, 'operation' => $operation, 'planSet' => $planSet] = planSetsVersioningUiActivePlan();

    // A pessoa abre a revisão sobre a V1...
    $component = planSetsVersioningUiTable($operation)->mountTableAction('createPlanRevision', $planSet);

    // ...e, nesse meio-tempo, outra pessoa revisa e ativa a V2.
    planSetsVersioningUiRevise($planSet, $actor);
    planSetsVersioningUiActivate($planSet->fresh(), $actor);
    $before = planSetsVersioningUiDatabaseState();

    $component->setTableActionData([
        'revision_category' => MeasurementPlanRevisionCategory::Cost->value,
        'revision_reason' => 'Motivo escrito para a V1, que já não vale.',
    ])->callMountedTableAction();

    expect(planSetsVersioningUiNotification('Revisão não criada.'))->toBe([
        'title' => 'Revisão não criada.',
        'body' => sprintf(MeasurementPlanVersionService::STALE_BASE_MESSAGE, 'V2'),
        'status' => 'danger',
    ])
        ->and(planSetsVersioningUiDatabaseState())->toBe($before)
        ->and(MeasurementPlanVersion::query()->where('plan_set_id', $planSet->id)->pluck('status', 'version_number')->all())->toBe([
            1 => MeasurementPlanVersionStatus::Superseded,
            2 => MeasurementPlanVersionStatus::Active,
        ]);
});

it('closes the revision modal with the reason when the action stopped applying after it was opened', function (string $change, string $message) {
    ['actor' => $actor, 'operation' => $operation, 'planSet' => $planSet] = planSetsVersioningUiActivePlan();

    $component = planSetsVersioningUiTable($operation)->mountTableAction('createPlanRevision', $planSet);

    match ($change) {
        'rascunho aberto por outra pessoa' => planSetsVersioningUiRevise($planSet, $actor),
        'operação concluída' => app(OperationLifecycleService::class)->complete($operation, $actor),
        'permissão retirada' => $actor->syncRoles([]),
    };

    $before = planSetsVersioningUiDatabaseState();

    // O Filament ignoraria o envio calado; a tela responde com o motivo.
    $component->setTableActionData([
        'revision_category' => MeasurementPlanRevisionCategory::Scope->value,
        'revision_reason' => 'Inclusão do bloco B.',
    ])->callMountedTableAction()
        ->assertActionNotMounted();

    expect(planSetsVersioningUiNotification('Revisão não criada.'))->toBe([
        'title' => 'Revisão não criada.',
        'body' => $message,
        'status' => 'danger',
    ])
        ->and(planSetsVersioningUiDatabaseState())->toBe($before);
})->with([
    'rascunho aberto por outra pessoa' => ['rascunho aberto por outra pessoa', 'A situação do plano mudou desde que você abriu esta ação. Recarregue a página.'],
    'operação concluída' => ['operação concluída', 'A situação do plano mudou desde que você abriu esta ação. Recarregue a página.'],
    'permissão retirada' => ['permissão retirada', 'Você não pode mais alterar os planos de medição desta operação.'],
]);

// ── Exclusão do plano ────────────────────────────────────────────────────────

it('deletes a plan that only has the V1 draft together with its schedule, leaving the trail of the versions', function () {
    $scenario = planSetsVersioningUiScenario();
    $planSet = planSetsVersioningUiPlan($scenario, $scenario['construction']);
    $v1 = $planSet->draftVersion()->firstOrFail();

    planSetsVersioningUiTable($scenario['operation'])
        ->callTableAction('delete', $planSet)
        ->assertHasNoTableActionErrors()
        ->assertActionNotMounted();

    expect(MeasurementPlanSet::query()->whereKey($planSet->id)->exists())->toBeFalse()
        ->and(MeasurementPlanVersion::query()->where('plan_set_id', $planSet->id)->exists())->toBeFalse()
        ->and(MeasurementPlanLine::query()->where('plan_set_id', $planSet->id)->exists())->toBeFalse();

    // As versões descem pela FK, sem os ganchos delas: a trilha do plano
    // guarda o que foi junto.
    $deletion = planSetsVersioningUiEvent('plan_versions_deleted_with_plan', $planSet);

    expect($deletion->causer_id)->toBe($scenario['actor']->id)
        ->and(Arr::only($deletion->properties->all(), ['operation_id', 'plan_set_id', 'construction_id', 'versions', 'actor_user_id']))->toBe([
            'operation_id' => $scenario['operation']->id,
            'plan_set_id' => $planSet->id,
            'construction_id' => $scenario['construction']->id,
            'versions' => [
                ['plan_version_id' => $v1->id, 'version_number' => 1, 'status' => 'draft', 'effective_from' => null, 'construction_fund_amount' => '1000000.00', 'line_count' => 3],
            ],
            'actor_user_id' => $scenario['actor']->id,
        ]);
});

it('refuses with the reason of the domain to delete a plan that already received a measurement file', function () {
    ['actor' => $actor, 'operation' => $operation, 'planSet' => $planSet, 'v1' => $v1] = planSetsVersioningUiActivePlan();

    // Medição de maio enviada pelo fluxo real e aguardando a Engenharia.
    MeasurementPhysicalProgressScenario::measurement([
        'actor' => $actor,
        'operation' => $operation,
        'planSet' => $planSet,
        'lines' => ['2026-05' => $v1->lines()->orderBy('sequence_number')->firstOrFail()],
    ], '2026-05');
    $before = planSetsVersioningUiDatabaseState();

    planSetsVersioningUiTable($operation)
        ->callTableAction('delete', $planSet)
        ->assertActionMounted(TestAction::make('delete')->table($planSet));

    expect(planSetsVersioningUiNotification('Plano não excluído.'))->toBe([
        'title' => 'Plano não excluído.',
        'body' => MeasurementPlanSet::MEASUREMENT_HISTORY_DELETION_REFUSAL,
        'status' => 'danger',
    ])
        ->and(planSetsVersioningUiDatabaseState())->toBe($before);
});

it('offers the plan deletion only while the operation still plans', function (string $situation, bool $isOffered) {
    ['actor' => $actor, 'operation' => $operation, 'planSet' => $planSet] = planSetsVersioningUiActivePlan();

    match ($situation) {
        'em andamento' => null,
        'concluída' => app(OperationLifecycleService::class)->complete($operation, $actor),
        'cancelada' => app(OperationLifecycleService::class)->cancel($operation, $actor, 'Operação encerrada pelo comitê.'),
    };

    $component = planSetsVersioningUiTable($operation->fresh());

    $isOffered
        ? $component->assertTableActionVisible('delete', $planSet)->assertTableBulkActionVisible('delete')
        : $component->assertTableActionHidden('delete', $planSet)->assertTableBulkActionHidden('delete');
})->with([
    'em andamento' => ['em andamento', true],
    // Concluída ou cancelada não replaneja: o plano e o histórico das versões
    // ficam como estão até a reabertura, como o "Novo Plano" e a revisão.
    'concluída' => ['concluída', false],
    'cancelada' => ['cancelada', false],
]);

it('refuses with the reason a plan deletion confirmed after the operation was completed', function () {
    ['actor' => $actor, 'operation' => $operation, 'planSet' => $planSet] = planSetsVersioningUiActivePlan();

    // A pessoa abre a confirmação com a operação em andamento...
    $component = planSetsVersioningUiTable($operation)->mountTableAction('delete', $planSet);

    // ...e, nesse meio-tempo, a operação é concluída.
    app(OperationLifecycleService::class)->complete($operation, $actor);
    $before = planSetsVersioningUiDatabaseState();
    planSetsVersioningUiForgetNotifications();

    $component->callMountedTableAction()->assertActionNotMounted();

    expect(planSetsVersioningUiSentNotifications())->toBe([[
        'title' => 'Plano não excluído.',
        'body' => 'A situação do plano mudou desde que você abriu esta ação. Recarregue a página.',
        'status' => 'danger',
    ]])
        ->and(planSetsVersioningUiDatabaseState())->toBe($before)
        ->and(MeasurementPlanSet::query()->whereKey($planSet->id)->exists())->toBeTrue();
});

// ── Formulário da operação ───────────────────────────────────────────────────

it('locks on the operation form the fund of a plan in force, showing the fund of the version in force', function () {
    ['actor' => $actor, 'operation' => $operation, 'planSet' => $planSet] = planSetsVersioningUiActivePlan();

    // O rascunho da V2 já tem outro fundo, mas quem responde pelo plano é a
    // V1 vigente.
    $v2 = planSetsVersioningUiRevise($planSet, $actor);
    app(MeasurementPlanVersionService::class)->updateDraft($v2, $actor, ['construction_fund_amount' => '1150000.00'], null, (int) $v2->revision);
    $before = planSetsVersioningUiDatabaseState();

    $page = Livewire::test(EditOperation::class, ['record' => $operation->getRouteKey()]);
    $row = 'developments.'.array_key_first($page->get('data.developments'));

    expect($page->get("data.{$row}.construction_fund_amount"))->toBe('1.000.000,00')
        ->and($page->get("data.{$row}.has_active_version"))->toBeTrue();

    $page->assertFormFieldDisabled("{$row}.construction_fund_amount")
        ->assertFormFieldExists("{$row}.construction_fund_amount", fn (Field $field): bool => planSetsVersioningUiHelperText($field) === 'Plano em vigor: o Fundo de Obra muda por revisão do plano, na aba Versões dos Planos.')
        ->assertSee('Plano em vigor: o Fundo de Obra muda por revisão do plano, na aba Versões dos Planos.')
        ->call('save')
        ->assertHasNoFormErrors();

    expect(planSetsVersioningUiDatabaseState()['measurement_plan_versions'])->toBe($before['measurement_plan_versions'])
        ->and($planSet->fresh()->currentConstructionFundAmount())->toBe('1000000.00');
});

it('saves on the operation form a new fund for the V1 draft, counting the save of the draft', function () {
    $scenario = planSetsVersioningUiScenario();
    $planSet = planSetsVersioningUiPlan($scenario, $scenario['construction']);

    $page = Livewire::test(EditOperation::class, ['record' => $scenario['operation']->getRouteKey()]);
    $row = 'developments.'.array_key_first($page->get('data.developments'));

    expect($page->get("data.{$row}.construction_fund_amount"))->toBe('1.000.000,00')
        ->and($page->get("data.{$row}.construction_fund_revision"))->toBe(0);

    $page->assertFormFieldEnabled("{$row}.construction_fund_amount")
        ->assertFormFieldExists("{$row}.construction_fund_amount", fn (Field $field): bool => planSetsVersioningUiHelperText($field) === null)
        ->set("data.{$row}.construction_fund_amount", '1.250.000,00')
        ->call('save')
        ->assertHasNoFormErrors();

    $v1 = MeasurementPlanVersion::query()->where('plan_set_id', $planSet->id)->sole();

    expect($v1->version_number)->toBe(1)
        ->and($v1->status)->toBe(MeasurementPlanVersionStatus::Draft)
        ->and($v1->construction_fund_amount)->toBe('1250000.00')
        ->and($v1->revision)->toBe(1)
        ->and(planSetsVersioningUiNotification('Operação de obra atualizada com sucesso.')['status'] ?? null)->toBe('success');
});

it('keeps saving the V1 draft fund on the same operation page after a save, without blaming another person', function () {
    $scenario = planSetsVersioningUiScenario();
    $planSet = planSetsVersioningUiPlan($scenario, $scenario['construction']);
    $v1 = $planSet->draftVersion()->firstOrFail();
    $saved = [['title' => 'Operação de obra atualizada com sucesso.', 'body' => null, 'status' => 'success']];

    $page = Livewire::test(EditOperation::class, ['record' => $scenario['operation']->getRouteKey()]);

    expect(planSetsVersioningUiFundTokens($page))->toBe([
        'construction_fund_amount' => '1.000.000,00',
        'construction_fund_original' => '1000000.00',
        'construction_fund_revision' => 0,
    ]);

    $page->set('data.developments.'.array_key_first($page->get('data.developments')).'.construction_fund_amount', '1.250.000,00')
        ->call('save')
        ->assertHasNoFormErrors();

    // A página continua aberta depois de salvar: o fundo mostrado e o
    // contador lido voltam do banco, já com a gravação desta tentativa.
    expect($v1->fresh()->only(['construction_fund_amount', 'revision']))->toBe(['construction_fund_amount' => '1250000.00', 'revision' => 1])
        ->and(planSetsVersioningUiFundTokens($page))->toBe([
            'construction_fund_amount' => '1.250.000,00',
            'construction_fund_original' => '1250000.00',
            'construction_fund_revision' => 1,
        ]);

    // Salvar de novo sem mudar nada não acusa outra pessoa de ter mexido no
    // rascunho, nem grava nada nele.
    $versions = planSetsVersioningUiDatabaseState()['measurement_plan_versions'];
    planSetsVersioningUiForgetNotifications();

    $page->call('save')->assertHasNoFormErrors();

    expect(planSetsVersioningUiSentNotifications())->toBe($saved)
        ->and(planSetsVersioningUiDatabaseState()['measurement_plan_versions'])->toBe($versions);

    // Voltar ao fundo que a página mostrou ao abrir é mudança de verdade: com
    // o fundo mostrado de antes da primeira gravação, ele passaria por "sem
    // mudança" e o rascunho ficaria com 1.250.000,00 sob o aviso de sucesso.
    planSetsVersioningUiForgetNotifications();

    $page->set('data.developments.'.array_key_first($page->get('data.developments')).'.construction_fund_amount', '1.000.000,00')
        ->call('save')
        ->assertHasNoFormErrors();

    expect(planSetsVersioningUiSentNotifications())->toBe($saved)
        ->and($v1->fresh()->only(['construction_fund_amount', 'revision']))->toBe(['construction_fund_amount' => '1000000.00', 'revision' => 2])
        ->and($v1->fresh()->status)->toBe(MeasurementPlanVersionStatus::Draft)
        ->and(planSetsVersioningUiFundTokens($page))->toBe([
            'construction_fund_amount' => '1.000.000,00',
            'construction_fund_original' => '1000000.00',
            'construction_fund_revision' => 2,
        ]);
});

it('refuses on the operation form a fund based on a V1 draft another person saved after the form was opened', function () {
    $scenario = planSetsVersioningUiScenario();
    $planSet = planSetsVersioningUiPlan($scenario, $scenario['construction']);

    $page = Livewire::test(EditOperation::class, ['record' => $scenario['operation']->getRouteKey()]);
    $row = 'developments.'.array_key_first($page->get('data.developments'));

    // Outra pessoa grava o fundo do rascunho primeiro, pela aba de versões.
    $v1 = $planSet->draftVersion()->firstOrFail();
    app(MeasurementPlanVersionService::class)->updateDraft($v1, $scenario['actor'], ['construction_fund_amount' => '1100000.00'], null, 0);

    $page->set("data.{$row}.construction_fund_amount", '1.250.000,00')
        ->call('save');

    expect(planSetsVersioningUiNotification('Empreendimentos não atualizados.'))->toBe([
        'title' => 'Empreendimentos não atualizados.',
        'body' => sprintf(MeasurementPlanVersionService::STALE_DRAFT_MESSAGE, 'V1').' Os demais dados da operação foram salvos.',
        'status' => 'danger',
    ])
        ->and($v1->fresh()->construction_fund_amount)->toBe('1100000.00')
        ->and($v1->fresh()->revision)->toBe(1);
});

it('shows in the financial column the fund of the version that answers for the plan', function () {
    $scenario = planSetsVersioningUiActivePlan();
    ['actor' => $actor, 'operation' => $operation, 'planSet' => $planSet] = $scenario;
    $boreal = planSetsVersioningUiPlan($scenario, $scenario['otherConstruction'], '500000.00');
    $v2 = planSetsVersioningUiRevise($planSet, $actor);
    app(MeasurementPlanVersionService::class)->updateDraft($v2, $actor, ['construction_fund_amount' => '1150000.00'], null, (int) $v2->revision);

    // A Torre Aurora responde pela V1 vigente (o fundo da V2 em rascunho ainda
    // não vale); a Torre Boreal, sem ativação, pelo rascunho da V1.
    planSetsVersioningUiTable($operation)
        ->assertSeeInOrder(['Plano Torre Aurora', 'Fundo de Obra', '1.000.000,00', 'Saldo Disponível', '1.000.000,00'])
        ->assertSeeInOrder(['Plano Torre Boreal', 'Fundo de Obra', '500.000,00', 'Saldo Disponível', '500.000,00'])
        ->assertDontSee('1.150.000,00');

    $this->travelTo(CarbonImmutable::parse('2026-06-10 12:00:00'));
    planSetsVersioningUiActivate($planSet, $actor);

    planSetsVersioningUiTable($operation)
        ->assertSeeInOrder(['Plano Torre Aurora', 'Fundo de Obra', '1.150.000,00', 'Saldo Disponível', '1.150.000,00'])
        ->assertDontSee('1.000.000,00');

    expect($boreal->fresh()->currentConstructionFundAmount())->toBe('500000.00');
});
