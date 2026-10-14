<?php

use App\DTOs\Measurements\MeasurementPhysicalProgressContribution;
use App\Enums\MeasurementRevisionStatus;
use App\Filament\Resources\Measurements\Pages\EditMeasurement;
use App\Filament\Resources\Measurements\Pages\ViewMeasurement;
use App\Filament\Resources\Operations\Pages\ViewOperation;
use App\Filament\Resources\Operations\RelationManagers\PlanLinesRelationManager;
use App\Filament\Resources\Operations\RelationManagers\PlanSetsRelationManager;
use App\Models\Measurement;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Models\Operation;
use App\Models\User;
use App\Services\MeasurementPhysicalProgressService;
use App\Services\MeasurementWorkflow;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\MeasurementPhysicalProgressScenario;
use Tests\Support\MeasurementRevisionScenario as Scenario;

/*
 * A medição vigente que volta à Engenharia (reanálise): a contribuição física
 * aprovada antes sai do avanço reconhecido até a nova decisão, e a tela explica
 * a queda -- na página da medição, nos modais de aprovação da Engenharia do
 * empreendimento, na aba Planos e no Cronograma da operação -- sem tratá-la
 * como regressão da obra. A trilha protegida registra a saída e a volta.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    Notification::fake();
});

/**
 * Hora de Brasília no fuso da aplicação (UTC): a tela mostra o dia do
 * calendário de negócio; o banco grava em UTC.
 */
function reanalysisPresentationBusinessTime(string $dateTime): CarbonImmutable
{
    return BusinessTime::toApplication(CarbonImmutable::parse($dateTime, BusinessTime::timezone()));
}

/**
 * Maio medido a 10% na R0, paga e finalizada, e corrigido para 12% na R1, que
 * passou a valer sem pagamento próprio -- a família pagou na R0 -- e está na
 * etapa Pagamento. Tudo ao meio-dia de 07/10/2026 em Brasília.
 *
 * @return array{scenario: array{actor: User, operation: Operation, planSet: MeasurementPlanSet, lines: array<string, MeasurementPlanLine>}, may: Measurement, revision: Measurement}
 */
function reanalysisPresentationEffectiveRevision(string $initialPercent = '30.00'): array
{
    test()->travelTo(reanalysisPresentationBusinessTime('2026-10-07 12:00'));
    $scenario = Scenario::plan(initialPercent: $initialPercent);
    $may = Scenario::finalized($scenario, '2026-05', 10);
    $revision = Scenario::effective($scenario, $may, 12);

    return ['scenario' => $scenario, 'may' => $may->fresh(), 'revision' => $revision];
}

/**
 * A revisão vigente volta à Engenharia pela cadeia de recusas Pagamento →
 * Compliance → Gestão. As recusas correm às 22h30 de 07/10/2026 em Brasília,
 * já 08/10 01h30 em UTC: a tela tem de mostrar o dia de Brasília.
 *
 * @param  array{actor: User, operation: Operation, planSet: MeasurementPlanSet, lines: array<string, MeasurementPlanLine>}  $scenario
 */
function reanalysisPresentationReturnToEngineering(array $scenario, Measurement $revision): Measurement
{
    $workflow = app(MeasurementWorkflow::class);

    test()->travelTo(reanalysisPresentationBusinessTime('2026-10-07 22:30'));
    $workflow->reject($revision->fresh(), $scenario['actor'], 'Pagamento devolve: conferir o percentual.');
    $workflow->reject($revision->fresh(), $scenario['actor'], 'Compliance devolve: conferir o percentual.');
    $workflow->reject($revision->fresh(), $scenario['actor'], 'Percentual de maio a reanalisar.');

    return $revision->fresh();
}

/**
 * @return array{scenario: array{actor: User, operation: Operation, planSet: MeasurementPlanSet, lines: array<string, MeasurementPlanLine>}, may: Measurement, revision: Measurement}
 */
function reanalysisPresentationReturnedRevision(string $initialPercent = '30.00'): array
{
    ['scenario' => $scenario, 'may' => $may, 'revision' => $revision] = reanalysisPresentationEffectiveRevision($initialPercent);

    return [
        'scenario' => $scenario,
        'may' => $may->fresh(),
        'revision' => reanalysisPresentationReturnToEngineering($scenario, $revision),
    ];
}

/**
 * Os eventos da trilha protegida gravados na medição, do mais antigo ao mais
 * novo.
 *
 * @return Collection<int, Activity>
 */
function reanalysisPresentationEvents(Measurement $measurement, string $event): Collection
{
    return Activity::query()
        ->where('log_name', 'measurement_workflow')
        ->where('subject_type', $measurement->getMorphClass())
        ->where('subject_id', $measurement->getKey())
        ->where('description', $event)
        ->orderBy('id')
        ->get();
}

/**
 * O instante da invalidação em ISO-8601 (UTC), para comparar a entrada
 * inteira por igualdade.
 *
 * @param  array<string, mixed>  $entry
 * @return array<string, mixed>
 */
function reanalysisPresentationWithInstant(array $entry): array
{
    return [...$entry, 'invalidated_at' => $entry['invalidated_at']?->utc()->toIso8601String()];
}

/**
 * @return array<int, list<array<string, mixed>>>
 */
function reanalysisPresentationContributions(Operation $operation): array
{
    return array_map(
        fn (array $entries): array => array_map(reanalysisPresentationWithInstant(...), $entries),
        app(MeasurementPhysicalProgressService::class)->reanalysisContributions((int) $operation->getKey()),
    );
}

/**
 * @return list<array<string, mixed>>
 */
function reanalysisPresentationNotice(Measurement $measurement): array
{
    return array_map(
        reanalysisPresentationWithInstant(...),
        app(MeasurementPhysicalProgressService::class)->reanalysisNoticeFor($measurement->fresh()),
    );
}

/**
 * @param  array{actor: User, operation: Operation, planSet: MeasurementPlanSet, lines: array<string, MeasurementPlanLine>}  $scenario
 */
function reanalysisPresentationRecognized(array $scenario): string
{
    return MeasurementPhysicalProgressScenario::progress($scenario)->currentPercent();
}

/**
 * As medições que contam no avanço reconhecido do cenário.
 *
 * @param  array{actor: User, operation: Operation, planSet: MeasurementPlanSet, lines: array<string, MeasurementPlanLine>}  $scenario
 * @return list<int>
 */
function reanalysisPresentationContributors(array $scenario): array
{
    return collect(MeasurementPhysicalProgressScenario::progress($scenario)->contributions)
        ->map(fn (MeasurementPhysicalProgressContribution $contribution): int => $contribution->measurementId)
        ->sort()
        ->values()
        ->all();
}

/**
 * A contribuição de maio como a trilha a grava, a partir do registro da
 * Engenharia.
 *
 * @return array<string, mixed>
 */
function reanalysisPresentationMayContribution(MeasurementPlanLine $line, string $monthly, string $cumulative): array
{
    return [
        'plan_set_id' => (int) $line->plan_set_id,
        'plan_line_id' => (int) $line->id,
        'plan_line_lineage_key' => (string) $line->lineage_key,
        'measurement_date' => '2026-05-01',
        'realized_monthly_percent' => $monthly,
        'realized_cumulative_percent' => $cumulative,
    ];
}

/**
 * O bloco de contexto físico do modal de aprovação da Engenharia, em texto. O
 * modal vai na resposta como JSON escapado: o texto sai do componente.
 */
function reanalysisPresentationApprovalContext(Testable $component, int $planSetId): string
{
    $name = $component->instance()->getMountedActionSchemaName();

    foreach ($component->instance()->{$name}->getFlatComponents(withHidden: true) as $key => $item) {
        if (str_ends_with((string) $key, "physical_progress_context.{$planSetId}") && $item instanceof Placeholder) {
            return strip_tags((string) $item->getContent());
        }
    }

    return '';
}

function reanalysisPresentationPlanSets(Operation $operation): Testable
{
    return Livewire::test(PlanSetsRelationManager::class, [
        'ownerRecord' => $operation,
        'pageClass' => ViewOperation::class,
    ]);
}

function reanalysisPresentationSchedule(Operation $operation): Testable
{
    return Livewire::test(PlanLinesRelationManager::class, [
        'ownerRecord' => $operation,
        'pageClass' => ViewOperation::class,
    ]);
}

/**
 * O que a célula da tabela mostra para o registro: estado, cor, ícone,
 * descrição e tooltip.
 *
 * @return array{state: mixed, color: mixed, icon: mixed, description: string|null, tooltip: string|null}
 */
function reanalysisPresentationCell(Testable $component, string $name, Model $record): array
{
    $column = $component->instance()->getTable()->getColumn($name);

    expect($column)->toBeInstanceOf(TextColumn::class);

    $column->record($component->instance()->getTableRecord((string) $record->getKey()));
    $column->clearCachedState();
    $state = $column->getState();
    $description = $column->getDescriptionBelow();
    $tooltip = $column->getTooltip($state);

    return [
        'state' => $state,
        'color' => $column->getColor($state),
        'icon' => $column->getIcon($state),
        'description' => $description === null ? null : (string) $description,
        'tooltip' => $tooltip === null ? null : (string) $tooltip,
    ];
}

function reanalysisPresentationValidationMessage(callable $attempt): string
{
    try {
        $attempt();
    } catch (ValidationException $exception) {
        return (string) collect($exception->errors())->flatten()->first();
    }

    throw new RuntimeException('A tentativa não foi recusada.');
}

it('takes the contribution of an effective revision returned to Engineering out of the recognized progress without bringing back the one it replaced (30 + 12 = 42 → 30)', function () {
    ['scenario' => $scenario, 'may' => $may, 'revision' => $revision] = reanalysisPresentationEffectiveRevision();
    $planSetId = (int) $scenario['planSet']->id;

    expect(reanalysisPresentationRecognized($scenario))->toBe('42.00')
        ->and(reanalysisPresentationContributors($scenario))->toBe([$revision->id])
        ->and(reanalysisPresentationContributions($scenario['operation']))->toBe([]);

    $revision = reanalysisPresentationReturnToEngineering($scenario, $revision);

    // A R1 não tem pagamento próprio -- a família pagou na R0 --, e a Gestão
    // pôde devolvê-la à Engenharia: o registro aprovado dela deixou de valer.
    expect($revision->payments()->exists())->toBeFalse()
        ->and($revision->isEffectiveRevision())->toBeTrue()
        ->and($revision->current_stage)->toBe(MeasurementWorkflow::STAGE_ENGINEERING)
        ->and($revision->engineering_snapshot)->toBeNull()
        ->and($may->fresh()->revisionStatus())->toBe(MeasurementRevisionStatus::Superseded)
        ->and(reanalysisPresentationRecognized($scenario))->toBe('30.00')
        ->and(reanalysisPresentationContributors($scenario))->toBe([])
        ->and(reanalysisPresentationContributions($scenario['operation']))->toBe([
            $planSetId => [[
                'measurement_id' => $revision->id,
                'revision_label' => 'R1',
                'reference_month' => '05/2026',
                'basis_points' => 1200,
                'plan_line_lineage_key' => $scenario['lines']['2026-05']->fresh()->lineage_key,
                'invalidated_at' => '2026-10-08T01:30:00+00:00',
            ]],
        ])
        ->and(reanalysisPresentationNotice($revision))->toBe([[
            'plan_set_id' => $planSetId,
            'label' => 'Plano padrão',
            'excluded_basis_points' => 1200,
            'recognized_basis_points' => 3000,
            'with_reanalysis_basis_points' => 4200,
            'exceeds_limit' => false,
            'invalidated_at' => '2026-10-08T01:30:00+00:00',
        ]])
        // A substituída não está em reanálise: o aviso é só da vigente devolvida.
        ->and(reanalysisPresentationNotice($may))->toBe([]);
});

it('records which contribution left the recognized progress, attributed to the Management reviewer who returned the revision', function () {
    ['scenario' => $scenario, 'revision' => $revision] = reanalysisPresentationReturnedRevision();
    $actor = $scenario['actor'];
    $audits = reanalysisPresentationEvents($revision, 'measurement_physical_contribution_invalidated');

    // Uma só: as recusas do Pagamento e da Compliance devolvem a uma etapa
    // intermediária; só a recusa da Gestão reabre a Engenharia.
    expect($audits)->toHaveCount(1);

    $audit = $audits->first();
    $properties = $audit->properties->all();

    expect($audit->causer_id)->toBe($actor->id)
        ->and($audit->created_at->toIso8601String())->toBe('2026-10-08T01:30:00+00:00')
        ->and($properties['reopened_stage'])->toBe(MeasurementWorkflow::STAGE_ENGINEERING)
        ->and($properties)->not->toHaveKey('stage')
        ->and($properties['responsibility'])->toBe('stage2_reviewer_user_id')
        ->and($properties['reason'])->toBe('Percentual de maio a reanalisar.')
        ->and($properties['contributions'])->toBe([
            reanalysisPresentationMayContribution($scenario['lines']['2026-05']->fresh(), '12.00', '42.00'),
        ])
        // Quem recusou é o revisor da Gestão direto da operação: nem
        // delegação, nem bypass administrativo.
        ->and($properties['admin_override'])->toBeFalse()
        ->and($properties['delegated'])->toBeFalse()
        ->and($properties['delegation_id'])->toBeNull()
        ->and($properties['delegator_user_id'])->toBeNull()
        ->and($properties['actual_actor_user_id'])->toBe($actor->id)
        ->and($properties['measurement_id'])->toBe($revision->id)
        ->and($properties['revision_number'])->toBe(1)
        ->and($properties['revision_family_id'])->toBe((int) $revision->revision_family_id);
});

it('explains on the page of the revision that its earlier contribution is outside the recognized progress, dated in Brasília', function () {
    ['scenario' => $scenario, 'may' => $may, 'revision' => $revision] = reanalysisPresentationReturnedRevision();
    $this->actingAs($scenario['actor']);

    // As 22h30 de 07/10 em Brasília já são 08/10 em UTC: vale o dia de Brasília.
    Livewire::test(ViewMeasurement::class, ['record' => $revision->getRouteKey()])
        ->assertSee('Avanço físico reconhecido: 30,00%. Reanálise da Engenharia em andamento: a contribuição de 12,00 p.p. aprovada antes (registro invalidado em 07/10/2026) está fora do avanço reconhecido enquanto a Engenharia reanalisa a medição. Somando a contribuição em reanálise: 42,00%. Isso não indica, por si só, regressão física da obra.')
        ->assertDontSee('não cabe mais no limite de 100%');

    // A substituída não está em reanálise e não repete o aviso.
    Livewire::test(ViewMeasurement::class, ['record' => $may->getRouteKey()])
        ->assertSee('Substituída pela revisão')
        ->assertDontSee('Reanálise da Engenharia em andamento');
});

it('shows the contribution under reanalysis in every Engineering approval of the development, the revision itself included', function () {
    ['scenario' => $scenario, 'revision' => $revision] = reanalysisPresentationReturnedRevision();
    $june = MeasurementPhysicalProgressScenario::measurement($scenario, '2026-06');
    $planSetId = (int) $scenario['planSet']->id;
    $reanalysis = 'Medição R1 · 05/2026 em reanálise da Engenharia: 12,00 p.p. aprovados antes estão fora do avanço reconhecido (registro invalidado em 07/10/2026). Isso não indica, por si só, regressão física da obra.';
    $this->actingAs($scenario['actor']);

    // O avanço atual (30%) já exclui a contribuição em reanálise; o bloco diz
    // quanto ficou de fora e de qual medição, para a queda não parecer
    // regressão da obra.
    $revisionApproval = Livewire::test(ViewMeasurement::class, ['record' => $revision->getRouteKey()])
        ->mountAction('approve');

    expect(reanalysisPresentationApprovalContext($revisionApproval, $planSetId))
        ->toBe('Plano padrãoAvanço físico inicial30,00% em 30/04/2026Medido no sistema0,00%Avanço físico atual30,00%Máximo restante70,00%Fora do avanço reconhecido (reanálise)12,00 p.p.Versão do planoV1 · Fundo de Obra R$ 1.000.000,00Realizado aprovado na R010,00%'.$reanalysis);

    // Outra medição do mesmo empreendimento vê a mesma conta.
    $juneApproval = Livewire::test(ViewMeasurement::class, ['record' => $june->getRouteKey()])
        ->mountAction('approve');

    expect(reanalysisPresentationApprovalContext($juneApproval, $planSetId))
        ->toBe('Plano padrãoAvanço físico inicial30,00% em 30/04/2026Medido no sistema0,00%Avanço físico atual30,00%Máximo restante70,00%Fora do avanço reconhecido (reanálise)12,00 p.p.Versão do planoV1 · Fundo de Obra R$ 1.000.000,00'.$reanalysis);
});

it('flags the development and the schedule line under reanalysis on the operation with points, competence and date only', function () {
    ['scenario' => $scenario] = reanalysisPresentationReturnedRevision();
    $lines = $scenario['lines'];
    $this->actingAs(makeAdminUser());

    // Quem vê a operação nem sempre vê a medição: pontos percentuais,
    // competência e data -- nunca o motivo da devolução.
    expect(reanalysisPresentationCell(reanalysisPresentationPlanSets($scenario['operation']), 'physical_progress', $scenario['planSet']))->toBe([
        'state' => '30,00%',
        'color' => null,
        'icon' => 'heroicon-o-exclamation-triangle',
        'description' => 'Inicial 30,00% em 30/04/2026 · Medido 0,00% · Em reanálise: 12,00 p.p. fora do avanço reconhecido',
        'tooltip' => 'Reanálise da Engenharia em andamento (R1 · 05/2026, registro invalidado em 07/10/2026): 12,00 p.p. aprovados antes estão fora do avanço reconhecido. Isso não indica, por si só, regressão física da obra.',
    ]);

    $schedule = reanalysisPresentationSchedule($scenario['operation'])
        ->assertTableColumnStateSet('realized_monthly_percent', null, $lines['2026-05']);

    expect(reanalysisPresentationCell($schedule, 'status', $lines['2026-05']))->toBe([
        'state' => 'Em reanálise',
        'color' => 'warning',
        'icon' => null,
        'description' => null,
        'tooltip' => '12,00 p.p. aprovados antes ficam fora do avanço reconhecido até a nova aprovação da Engenharia (registro invalidado em 07/10/2026).',
    ])
        // A linha sem medição continua só pendente.
        ->and(reanalysisPresentationCell($schedule, 'status', $lines['2026-06']))->toBe([
            'state' => 'Pendente',
            'color' => 'gray',
            'icon' => null,
            'description' => null,
            'tooltip' => null,
        ]);
});

it('counts the revision once again when the Engineering approves it anew and records the end of the reanalysis (30 + 11 = 41)', function () {
    ['scenario' => $scenario, 'may' => $may, 'revision' => $revision] = reanalysisPresentationReturnedRevision();
    $planSetId = (int) $scenario['planSet']->id;
    $line = $scenario['lines']['2026-05']->fresh();
    $invalidations = reanalysisPresentationEvents($revision, 'measurement_engineering_snapshot_invalidated');

    expect($invalidations)->toHaveCount(1);

    $this->travelTo(reanalysisPresentationBusinessTime('2026-10-08 12:00'));
    $this->actingAs($scenario['actor']);

    Livewire::test(ViewMeasurement::class, ['record' => $revision->getRouteKey()])
        ->callAction('approve', data: ['realized' => [$planSetId => 11]])
        ->assertHasNoActionErrors();

    // A R1 volta a contar uma vez, com o registro novo; a R0 substituída não
    // volta, nem antes nem depois.
    expect($revision->fresh()->current_stage)->toBe(2)
        ->and(reanalysisPresentationRecognized($scenario))->toBe('41.00')
        ->and(reanalysisPresentationContributors($scenario))->toBe([$revision->id])
        ->and($may->fresh()->revisionStatus())->toBe(MeasurementRevisionStatus::Superseded)
        ->and(reanalysisPresentationContributions($scenario['operation']))->toBe([])
        ->and(reanalysisPresentationNotice($revision))->toBe([]);

    $completions = reanalysisPresentationEvents($revision, 'measurement_engineering_reanalysis_completed');

    expect($completions)->toHaveCount(1);

    $properties = $completions->first()->properties->all();

    expect($completions->first()->causer_id)->toBe($scenario['actor']->id)
        ->and($properties['responsibility'])->toBe('responsible_user_id')
        ->and($properties['invalidation_activity_id'])->toBe($invalidations->first()->id)
        ->and($properties['invalidated_at'])->toBe('2026-10-08T01:30:00+00:00')
        ->and($properties['previous_contributions'])->toBe([reanalysisPresentationMayContribution($line, '12.00', '42.00')])
        ->and($properties['new_contributions'])->toBe([reanalysisPresentationMayContribution($line, '11.00', '41.00')])
        ->and($properties['admin_override'])->toBeFalse()
        ->and($properties['delegated'])->toBeFalse();

    // Fim da reanálise: a tela volta ao normal.
    Livewire::test(ViewMeasurement::class, ['record' => $revision->getRouteKey()])
        ->assertSee('Revisões da medição')
        ->assertDontSee('Reanálise da Engenharia em andamento');

    $this->actingAs(makeAdminUser());

    expect(reanalysisPresentationCell(reanalysisPresentationPlanSets($scenario['operation']), 'physical_progress', $scenario['planSet']))->toBe([
        'state' => '41,00%',
        'color' => null,
        'icon' => null,
        'description' => 'Inicial 30,00% em 30/04/2026 · Medido 11,00%',
        'tooltip' => null,
    ])
        ->and(reanalysisPresentationCell(reanalysisPresentationSchedule($scenario['operation']), 'status', $scenario['lines']['2026-05']))->toBe([
            'state' => 'Realizado informado',
            'color' => 'success',
            'icon' => null,
            'description' => null,
            'tooltip' => null,
        ]);
});

it('does not report a reanalysis for a legacy measurement back at Engineering without the invalidated record', function () {
    $this->travelTo(reanalysisPresentationBusinessTime('2026-10-07 12:00'));
    $scenario = Scenario::plan(initialPercent: '30.00');
    $may = Scenario::legacyPaidAtEngineering(Scenario::finalized($scenario, '2026-05', 10));
    $this->actingAs(makeAdminUser());

    // A linha legada não deixou na trilha o registro invalidado: ela sai do
    // avanço, mas não há contribuição a mostrar como reanálise.
    expect(reanalysisPresentationEvents($may, 'measurement_engineering_snapshot_invalidated'))->toHaveCount(0)
        ->and(reanalysisPresentationRecognized($scenario))->toBe('30.00')
        ->and(reanalysisPresentationContributions($scenario['operation']))->toBe([])
        ->and(reanalysisPresentationNotice($may))->toBe([])
        ->and(reanalysisPresentationCell(reanalysisPresentationSchedule($scenario['operation']), 'status', $scenario['lines']['2026-05'])['state'])->toBe('Pendente');

    MeasurementPhysicalProgressScenario::approveEngineering($scenario, $may, 10);

    expect($may->fresh()->hasApprovedEngineering())->toBeTrue()
        ->and(reanalysisPresentationEvents($may, 'measurement_engineering_snapshot_created'))->toHaveCount(2)
        ->and(reanalysisPresentationEvents($may, 'measurement_engineering_reanalysis_completed'))->toHaveCount(0)
        ->and(reanalysisPresentationRecognized($scenario))->toBe('40.00');
});

it('warns on the page of the revision that the previous value no longer fits once another measurement used the freed headroom (95 + 12 > 100)', function () {
    ['scenario' => $scenario, 'revision' => $revision] = reanalysisPresentationReturnedRevision('80.00');
    $planSetId = (int) $scenario['planSet']->id;

    // Sem a R1, o empreendimento está em 80%: junho cabe com 15%.
    MeasurementPhysicalProgressScenario::measured($scenario, '2026-06', 15);

    expect(reanalysisPresentationRecognized($scenario))->toBe('95.00')
        ->and(reanalysisPresentationNotice($revision))->toBe([[
            'plan_set_id' => $planSetId,
            'label' => 'Plano padrão',
            'excluded_basis_points' => 1200,
            'recognized_basis_points' => 9500,
            'with_reanalysis_basis_points' => 10700,
            'exceeds_limit' => true,
            'invalidated_at' => '2026-10-08T01:30:00+00:00',
        ]]);

    $this->actingAs($scenario['actor']);

    Livewire::test(ViewMeasurement::class, ['record' => $revision->getRouteKey()])
        ->assertSee('Avanço físico reconhecido: 95,00%. Reanálise da Engenharia em andamento: a contribuição de 12,00 p.p. aprovada antes (registro invalidado em 07/10/2026) está fora do avanço reconhecido enquanto a Engenharia reanalisa a medição. Somando a contribuição em reanálise: 107,00% — a reaprovação no valor anterior não cabe mais no limite de 100%. Isso não indica, por si só, regressão física da obra.');

    // O aviso diz a verdade: a Engenharia não reaprova mais os 12% de antes.
    expect(reanalysisPresentationValidationMessage(fn () => MeasurementPhysicalProgressScenario::approveEngineering($scenario, $revision, 12)))
        ->toBe('O percentual físico informado para Plano padrão ultrapassaria o limite de 100% do empreendimento. Progresso atual: 95,00%. Percentual informado: 12,00%. Máximo restante: 5,00%.')
        ->and($revision->fresh()->current_stage)->toBe(MeasurementWorkflow::STAGE_ENGINEERING)
        ->and($revision->fresh()->engineering_snapshot)->toBeNull()
        ->and(reanalysisPresentationNotice($revision)[0]['exceeds_limit'] ?? null)->toBeTrue();
});

it('explains the reanalysis on the page of an unpaid original measurement returned by the Management', function () {
    $this->travelTo(reanalysisPresentationBusinessTime('2026-10-07 12:00'));
    $scenario = Scenario::plan(initialPercent: '30.00');
    $may = MeasurementPhysicalProgressScenario::measured($scenario, '2026-05', 10);

    $this->travelTo(reanalysisPresentationBusinessTime('2026-10-07 22:30'));
    MeasurementPhysicalProgressScenario::returnToEngineering($scenario, $may);
    $audits = reanalysisPresentationEvents($may, 'measurement_physical_contribution_invalidated');

    expect($may->fresh()->payments()->exists())->toBeFalse()
        ->and($may->fresh()->current_stage)->toBe(MeasurementWorkflow::STAGE_ENGINEERING)
        ->and(reanalysisPresentationRecognized($scenario))->toBe('30.00')
        ->and(reanalysisPresentationNotice($may))->toBe([[
            'plan_set_id' => (int) $scenario['planSet']->id,
            'label' => 'Plano padrão',
            'excluded_basis_points' => 1000,
            'recognized_basis_points' => 3000,
            'with_reanalysis_basis_points' => 4000,
            'exceeds_limit' => false,
            'invalidated_at' => '2026-10-08T01:30:00+00:00',
        ]])
        ->and($audits)->toHaveCount(1)
        ->and($audits->first()->properties['responsibility'])->toBe('stage2_reviewer_user_id')
        ->and($audits->first()->properties['contributions'])->toBe([
            reanalysisPresentationMayContribution($scenario['lines']['2026-05']->fresh(), '10.00', '40.00'),
        ]);

    $this->actingAs($scenario['actor']);

    Livewire::test(ViewMeasurement::class, ['record' => $may->getRouteKey()])
        ->assertSee('Avanço físico reconhecido: 30,00%. Reanálise da Engenharia em andamento: a contribuição de 10,00 p.p. aprovada antes (registro invalidado em 07/10/2026) está fora do avanço reconhecido enquanto a Engenharia reanalisa a medição. Somando a contribuição em reanálise: 40,00%. Isso não indica, por si só, regressão física da obra.');
});

it('warns in the Management rejection of an effective measurement that its contribution will leave the recognized progress, and only then', function () {
    ['scenario' => $scenario, 'revision' => $revision] = reanalysisPresentationEffectiveRevision();
    $workflow = app(MeasurementWorkflow::class);
    $workflow->reject($revision->fresh(), $scenario['actor'], 'Pagamento devolve: conferir o percentual.');
    $workflow->reject($revision->fresh(), $scenario['actor'], 'Compliance devolve: conferir o percentual.');
    // Junho revisado e ainda em análise: a contribuição dele nunca contou.
    $june = Scenario::finalized($scenario, '2026-06', 10);
    $pendingRevision = Scenario::engineeringApproved($scenario, $june, 8);
    $this->actingAs($scenario['actor']);

    expect($revision->fresh()->current_stage)->toBe(2)
        ->and($pendingRevision->fresh()->current_stage)->toBe(2)
        ->and($pendingRevision->fresh()->isPendingRevision())->toBeTrue();

    // A recusa da Gestão avisa, antes, o que a reanálise faz com o avanço.
    Livewire::test(ViewMeasurement::class, ['record' => $revision->getRouteKey()])
        ->assertActionVisible('reject')
        ->assertActionEnabled('reject')
        ->assertActionExists('reject', fn (Action $action): bool => $action->getModalDescription() === 'A medição voltará para a Engenharia, e o registro aprovado por ela será invalidado. Até a nova aprovação da Engenharia, a contribuição física desta medição fica fora do avanço reconhecido.');

    Livewire::test(ViewMeasurement::class, ['record' => $pendingRevision->getRouteKey()])
        ->assertActionVisible('reject')
        ->assertActionExists('reject', fn (Action $action): bool => $action->getModalDescription() === 'A medição voltará para a Engenharia, e o registro aprovado por ela será invalidado.');
});

it('attributes the invalidation to the Finalizer responsibility, as an administrative override, when an admin returns an unpaid effective revision from Finalization to Engineering', function () {
    $this->travelTo(reanalysisPresentationBusinessTime('2026-10-07 12:00'));
    $scenario = Scenario::plan(initialPercent: '30.00');
    $may = Scenario::finalized($scenario, '2026-05', 10);
    $revision = Scenario::effective($scenario, $may, 10);
    $workflow = app(MeasurementWorkflow::class);
    // A família já pagou maio inteiro: a R1 aprova a etapa Pagamento sem
    // pagamento próprio e chega à Finalização.
    $workflow->approve($revision->fresh(), $scenario['actor']);
    $admin = makeAdminUser();

    expect($revision->fresh()->status)->toBe('approved')
        ->and($revision->fresh()->current_stage)->toBe(MeasurementWorkflow::STAGE_FINALIZATION)
        ->and($revision->fresh()->payments()->exists())->toBeFalse();

    // O administrador não é o Finalizador da operação: devolve pelo bypass.
    $workflow->returnToStage($revision->fresh(), $admin, MeasurementWorkflow::STAGE_ENGINEERING, 'Rever o percentual de maio.');
    $audits = reanalysisPresentationEvents($revision, 'measurement_physical_contribution_invalidated');

    expect($audits)->toHaveCount(1);

    $properties = $audits->first()->properties->all();

    expect($audits->first()->causer_id)->toBe($admin->id)
        ->and($properties['responsibility'])->toBe('payment_finalizer_user_id')
        ->and($properties['admin_override'])->toBeTrue()
        ->and($properties['delegated'])->toBeFalse()
        ->and($properties['actual_actor_user_id'])->toBe($admin->id)
        ->and($properties['reopened_stage'])->toBe(MeasurementWorkflow::STAGE_ENGINEERING)
        ->and($properties['reason'])->toBe('Rever o percentual de maio.')
        ->and($properties['contributions'])->toBe([
            reanalysisPresentationMayContribution($scenario['lines']['2026-05']->fresh(), '10.00', '40.00'),
        ])
        ->and(reanalysisPresentationRecognized($scenario))->toBe('30.00')
        ->and(collect(reanalysisPresentationContributions($scenario['operation'])[(int) $scenario['planSet']->id] ?? [])->pluck('basis_points', 'measurement_id')->all())
        ->toBe([$revision->id => 1000]);
});

it('never treats a pending revision returned to Engineering as a reanalysis: it never counted in the recognized progress', function () {
    $this->travelTo(reanalysisPresentationBusinessTime('2026-10-07 12:00'));
    $scenario = Scenario::plan(initialPercent: '30.00');
    $may = Scenario::finalized($scenario, '2026-05', 10);
    $revision = Scenario::engineeringApproved($scenario, $may, 12);

    app(MeasurementWorkflow::class)->reject($revision->fresh(), $scenario['actor'], 'Gestão devolve: conferir o percentual.');

    // O registro da revisão em análise é invalidado, mas a contribuição dela
    // nunca esteve no avanço reconhecido: a R0 continua valendo.
    expect($revision->fresh()->current_stage)->toBe(MeasurementWorkflow::STAGE_ENGINEERING)
        ->and($revision->fresh()->isPendingRevision())->toBeTrue()
        ->and(reanalysisPresentationEvents($revision, 'measurement_engineering_snapshot_invalidated'))->toHaveCount(1)
        ->and(reanalysisPresentationEvents($revision, 'measurement_physical_contribution_invalidated'))->toHaveCount(0)
        ->and(reanalysisPresentationRecognized($scenario))->toBe('40.00')
        ->and(reanalysisPresentationContributors($scenario))->toBe([$may->id])
        ->and(reanalysisPresentationContributions($scenario['operation']))->toBe([])
        ->and(reanalysisPresentationNotice($revision))->toBe([]);

    $this->actingAs($scenario['actor']);

    Livewire::test(ViewMeasurement::class, ['record' => $revision->getRouteKey()])
        ->assertSee('Revisões da medição')
        ->assertDontSee('Reanálise da Engenharia em andamento');

    MeasurementPhysicalProgressScenario::approveEngineering($scenario, $revision, 11);

    expect(reanalysisPresentationEvents($revision, 'measurement_engineering_reanalysis_completed'))->toHaveCount(0)
        ->and(reanalysisPresentationRecognized($scenario))->toBe('40.00');
});

it('shows on the schedule line the contribution another measurement got recognized there after the measurement in reanalysis moved to another line', function () {
    $scenario = MeasurementPhysicalProgressScenario::plan();
    $may = $scenario['lines']['2026-05'];
    $june = $scenario['lines']['2026-06'];
    $moved = MeasurementPhysicalProgressScenario::measured($scenario, '2026-05', '5.00');
    MeasurementPhysicalProgressScenario::returnToEngineering($scenario, $moved);
    $this->actingAs($scenario['actor']);

    // Em reanálise e sem Engenharia aprovada, a medição ainda se edita: o
    // arquivo vai para a linha de junho, e a competência com ele.
    Livewire::test(EditMeasurement::class, ['record' => $moved->getRouteKey()])
        ->set('data.assets.record-'.$moved->assets()->sole()->id.'.plan_line_id', $june->id)
        ->call('save')
        ->assertHasNoFormErrors();

    // Outra medição mede maio e é aprovada: a linha de maio tem contribuição
    // reconhecida (4,00 p.p.) e não é mais a da medição em reanálise.
    MeasurementPhysicalProgressScenario::measured($scenario, '2026-05', '4.00');
    $this->actingAs(makeAdminUser());
    $lines = Livewire::test(PlanLinesRelationManager::class, ['ownerRecord' => $scenario['operation'], 'pageClass' => ViewOperation::class]);

    expect($moved->fresh()->reference_month->toDateString())->toBe('2026-06-01');

    $lines->assertTableColumnStateSet('realized_monthly_percent', '4.00', $may)
        ->assertTableColumnStateSet('status', 'Realizado informado', $may)
        ->assertTableColumnStateSet('status', 'Pendente', $june);

    expect((new ReflectionMethod($lines->instance(), 'reanalysisTooltip'))->invoke($lines->instance(), MeasurementPlanLine::query()->find($may->id)))->toBeNull();
});
