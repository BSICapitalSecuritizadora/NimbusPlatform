<?php

use App\DTOs\Measurements\MeasurementPhysicalProgress;
use App\Enums\MeasurementPlanRevisionCategory;
use App\Enums\MeasurementPlanVersionStatus;
use App\Filament\Resources\Measurements\Pages\CreateMeasurement;
use App\Filament\Resources\Measurements\Pages\EditMeasurement;
use App\Filament\Resources\Measurements\Pages\ViewMeasurement;
use App\Filament\Resources\Operations\Pages\ViewOperation;
use App\Filament\Resources\Operations\RelationManagers\PlanLinesRelationManager;
use App\Filament\Resources\Operations\RelationManagers\PlanVersionsRelationManager;
use App\Models\Construction;
use App\Models\Measurement;
use App\Models\MeasurementAsset;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Models\MeasurementPlanVersion;
use App\Models\Operation;
use App\Models\User;
use App\Services\MeasurementPlanVersionResolver;
use App\Services\MeasurementPlanVersionService;
use App\Services\MeasurementWorkflow;
use App\Services\OperationNextMeasurementResolver;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/**
 * A competência decide a versão do plano -- nas telas.
 *
 * Regra: a versão que vale para uma competência é a que a rege no histórico
 * gravado das versões (a de maior número com vigência até o mês; antes da
 * primeira vigência, a V1), e o envio a congela no arquivo para sempre. O
 * Enviar Medição oferece cada competência pela versão que a rege e monta os
 * arquivos pelos empreendimentos que preveem medição nela; o Editar só
 * oferece as competências da versão congelada; o acompanhamento, as versões e
 * a própria medição mostram a mesma versão que a medição levou.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    Notification::fake();
    // Os arquivos de medição vão para o disco privado falso.
    config()->set('filesystems.private_disk', 'local');
    // Vigência e competência são do calendário de negócio: o fuso fixo deixa
    // as fronteiras de mês independentes do ambiente.
    config()->set('measurements.business_timezone', 'America/Sao_Paulo');
    // O relógio destes cenários está em 2027, no futuro da máquina. A limpeza
    // de envios temporários do Livewire compara a data de gravação real do
    // arquivo com o relógio do teste e apagaria o PDF recém-enviado antes de o
    // formulário lê-lo.
    config()->set('livewire.temporary_file_upload.cleanup', false);
});

/**
 * Leva o relógio às 10h de Brasília (13h UTC) do dia: a versão ativada vale a
 * partir do mês desse dia no calendário de negócio.
 */
function periodUiTravelTo(string $date): void
{
    test()->travelTo(CarbonImmutable::parse("{$date} 13:00:00", 'UTC'));
}

/**
 * Operação em andamento em que quem envia a medição responde por todas as
 * etapas; quem planeja as obras é um administrador.
 *
 * @return array{actor: User, planner: User, operation: Operation}
 */
function periodUiOperation(): array
{
    $actor = User::factory()->withTwoFactor()->create();
    $actor->givePermissionTo(['measurements.view', 'measurements.create', 'measurements.update', 'measurements.review']);

    return [
        'actor' => $actor,
        'planner' => makeAdminUser(),
        'operation' => Operation::factory()->create([
            'status' => 'active',
            ...array_fill_keys(Operation::RESPONSIBILITY_FIELDS, $actor->id),
        ]),
    ];
}

/**
 * Uma medição prevista por mês, todas com o mesmo previsto mensal.
 *
 * @return array<string, string> previsto mensal por competência ('Y-m')
 */
function periodUiMonthly(string $firstMonth, int $count, string $percent): array
{
    $first = CarbonImmutable::parse("{$firstMonth}-01");
    $months = [];

    for ($index = 0; $index < $count; $index++) {
        $months[$first->addMonthsNoOverflow($index)->format('Y-m')] = $percent;
    }

    return $months;
}

/**
 * Plano de uma obra da operação criado pelo serviço, com a V1 em rascunho: o
 * Fundo de Obra informado e o previsto mensal de cada competência, com o
 * acumulado somado desde zero.
 *
 * @param  array{planner: User, operation: Operation}  $scenario
 * @param  array<string, string>  $monthlyByMonth  previsto mensal por competência ('Y-m')
 */
function periodUiPlan(array $scenario, string $development, string $fund, array $monthlyByMonth, ?bool $isDefault = null): MeasurementPlanSet
{
    $construction = Construction::factory()->create([
        'emission_id' => $scenario['operation']->emission_id,
        'development_name' => $development,
    ]);
    $lines = [];
    $running = 0;

    foreach (array_keys($monthlyByMonth) as $index => $month) {
        $running += (int) MeasurementPhysicalProgress::basisPoints($monthlyByMonth[$month]);
        $lines[] = [
            'sequence_number' => $index + 1,
            'planned_monthly_percent' => $monthlyByMonth[$month],
            'planned_cumulative_percent' => MeasurementPhysicalProgress::decimal($running),
            'measurement_date' => $month,
        ];
    }

    return app(MeasurementPlanVersionService::class)->createPlan($scenario['operation'], $scenario['planner'], [
        'name' => "Plano {$development}",
        'construction_id' => $construction->id,
        'is_default' => $isDefault ?? ! $scenario['operation']->planSets()->exists(),
        'initial_incurred_amount' => '0.00',
    ], ['construction_fund_amount' => $fund], $lines);
}

function periodUiVersion(MeasurementPlanSet $planSet, int $number): MeasurementPlanVersion
{
    return MeasurementPlanVersion::query()
        ->where('plan_set_id', $planSet->id)
        ->where('version_number', $number)
        ->sole();
}

/**
 * Ativa o rascunho pelo serviço, como quem acabou de abrir a tela: com o
 * contador atual. A versão vale a partir do mês corrente.
 *
 * @param  array{planner: User}  $scenario
 */
function periodUiActivate(array $scenario, MeasurementPlanVersion $draft): MeasurementPlanVersion
{
    $seen = $draft->fresh();

    return app(MeasurementPlanVersionService::class)->activate($seen, $scenario['planner'], (int) $seen->revision);
}

/**
 * Rascunho de uma revisão de custo pelo serviço: o cronograma copiado da
 * vigente e o novo Fundo de Obra, ainda sem ativar.
 *
 * @param  array{planner: User}  $scenario
 */
function periodUiCostRevision(array $scenario, MeasurementPlanSet $planSet, string $fund): MeasurementPlanVersion
{
    $service = app(MeasurementPlanVersionService::class);
    $draft = $service->createRevision($planSet->fresh(), $scenario['planner'], [
        'revision_category' => MeasurementPlanRevisionCategory::Cost->value,
        'revision_reason' => 'Reajuste do orçamento da obra aprovado pelo comitê de crédito.',
    ]);

    return $service->updateDraft($draft, $scenario['planner'], ['construction_fund_amount' => $fund], null, (int) $draft->revision);
}

/**
 * @return array<string, MeasurementPlanLine> linhas da versão por competência ('Y-m')
 */
function periodUiLines(MeasurementPlanVersion $version): array
{
    return MeasurementPlanLine::query()
        ->where('plan_version_id', $version->id)
        ->orderBy('measurement_date')
        ->orderBy('sequence_number')
        ->get()
        ->keyBy(fn (MeasurementPlanLine $line): string => $line->measurement_date->format('Y-m'))
        ->all();
}

/**
 * Medição do mês com o arquivo de cada plano informado, na medição prevista
 * da versão que rege o mês, aguardando a Engenharia -- como o envio a grava.
 *
 * @param  array{actor: User, operation: Operation}  $scenario
 * @param  list<MeasurementPlanSet>  $planSets
 */
function periodUiMeasurement(array $scenario, string $month, array $planSets): Measurement
{
    $measurement = Measurement::factory()->create([
        'operation_id' => $scenario['operation']->id,
        'reference_month' => "{$month}-01",
        'status' => 'pending',
        'current_stage' => MeasurementWorkflow::STAGE_ENGINEERING,
        'storage_path' => null,
        'filename' => null,
        'uploaded_by' => $scenario['actor']->id,
    ]);

    foreach ($planSets as $planSet) {
        $governing = app(MeasurementPlanVersionResolver::class)->forCompetence($planSet, "{$month}-01");
        $path = "nimbus_docs/measurements/assets/period-ui-{$measurement->id}-{$planSet->id}.pdf";
        Storage::disk('local')->put($path, "%PDF-1.7 medição {$month} de {$planSet->name}");
        $measurement->assets()->create([
            'plan_set_id' => $planSet->id,
            'plan_line_id' => periodUiLines($governing)[$month]->id,
            'storage_path' => $path,
            'storage_disk' => 'local',
        ]);
    }

    app(MeasurementWorkflow::class)->startReview($measurement->fresh(), $scenario['actor']);

    return $measurement->fresh();
}

/**
 * Torre Aurora com a V1 ativada em 10/01/2027 (vigente desde 01/2027): Fundo
 * de Obra de R$ 20.000.000,00 e 5% previstos por mês de 01/2027 a 12/2027. As
 * competências informadas são medidas no mês seguinte e aprovadas pela
 * Engenharia com 5%. Em 02/07/2027 a revisão de custo (R$ 23.000.000,00) entra
 * em vigor: a V2 vale desde 07/2027. O relógio fica em 02/07/2027.
 *
 * @param  list<string>  $measuredMonths  competências ('Y-m') medidas antes da revisão
 * @return array{actor: User, planner: User, operation: Operation, aurora: MeasurementPlanSet, v1: MeasurementPlanVersion, v2: MeasurementPlanVersion}
 */
function periodUiRevisedYearPlan(array $measuredMonths = []): array
{
    periodUiTravelTo('2027-01-10');
    $scenario = periodUiOperation();
    $aurora = periodUiPlan($scenario, 'Torre Aurora', '20000000.00', periodUiMonthly('2027-01', 12, '5.00'));
    $v1 = periodUiActivate($scenario, periodUiVersion($aurora, 1));

    foreach ($measuredMonths as $month) {
        periodUiTravelTo(CarbonImmutable::parse("{$month}-05")->addMonthNoOverflow()->toDateString());
        $measurement = periodUiMeasurement($scenario, $month, [$aurora]);
        app(MeasurementWorkflow::class)->approve($measurement, $scenario['actor'], engineeringProgress: [$aurora->id => '5.00']);
    }

    periodUiTravelTo('2027-07-02');
    $v2 = periodUiActivate($scenario, periodUiCostRevision($scenario, $aurora, '23000000.00'));

    return [...$scenario, 'aurora' => $aurora->fresh(), 'v1' => $v1->fresh(), 'v2' => $v2];
}

/**
 * A Torre Aurora de {@see periodUiRevisedYearPlan()} com janeiro a abril
 * medidos, maio esquecido e junho enviado com atraso, em 10/08/2027, com a V2
 * já vigente: junho é da V1. O relógio fica em 10/08/2027.
 *
 * @return array{actor: User, planner: User, operation: Operation, aurora: MeasurementPlanSet, v1: MeasurementPlanVersion, v2: MeasurementPlanVersion, june: Measurement}
 */
function periodUiDelayedJune(): array
{
    $plan = periodUiRevisedYearPlan(['2027-01', '2027-02', '2027-03', '2027-04']);
    periodUiTravelTo('2027-08-10');

    return [...$plan, 'june' => periodUiMeasurement($plan, '2027-06', [$plan['aurora']])];
}

/**
 * Torre Alfa (5% por mês de 01/2027 a 12/2027) e Torre Beta (10% por mês de
 * 09/2027 a 12/2027), as duas com a V1 ativada em 10/01/2027.
 *
 * @return array{actor: User, planner: User, operation: Operation, alfa: MeasurementPlanSet, beta: MeasurementPlanSet}
 */
function periodUiTwoTowers(): array
{
    periodUiTravelTo('2027-01-10');
    $scenario = periodUiOperation();
    $alfa = periodUiPlan($scenario, 'Torre Alfa', '20000000.00', periodUiMonthly('2027-01', 12, '5.00'));
    $beta = periodUiPlan($scenario, 'Torre Beta', '8000000.00', periodUiMonthly('2027-09', 4, '10.00'));
    periodUiActivate($scenario, periodUiVersion($alfa, 1));
    periodUiActivate($scenario, periodUiVersion($beta, 1));

    return [...$scenario, 'alfa' => $alfa->fresh(), 'beta' => $beta->fresh()];
}

/**
 * "Enviar Medição" com a operação escolhida.
 */
function periodUiSendPage(Operation $operation): Testable
{
    return Livewire::test(CreateMeasurement::class)->fillForm(['operation_id' => $operation->id]);
}

/**
 * Os arquivos do envio na ordem do formulário: o plano e a medição prevista
 * escolhida em cada um.
 *
 * @return list<array{0: int, 1: int|null}>
 */
function periodUiRows(Testable $page): array
{
    return collect($page->get('data.assets'))
        ->map(fn (array $row): array => [
            (int) $row['plan_set_id'],
            filled($row['plan_line_id'] ?? null) ? (int) $row['plan_line_id'] : null,
        ])
        ->values()
        ->all();
}

/**
 * O mês ('Y-m') do campo Competência. O DatePicker guarda a data com a hora
 * corrente; a competência é o mês.
 */
function periodUiCompetence(Testable $page): ?string
{
    $state = $page->get('data.reference_month');

    return blank($state) ? null : CarbonImmutable::parse((string) $state)->format('Y-m');
}

/**
 * A chave do item do repeater que leva o arquivo do plano.
 */
function periodUiRowKey(Testable $page, MeasurementPlanSet $planSet): int|string
{
    $key = collect($page->get('data.assets'))
        ->search(fn (array $row): bool => (int) $row['plan_set_id'] === (int) $planSet->id);

    expect($key)->not->toBeFalse();

    return $key;
}

/**
 * Opções do seletor "Medição do cronograma" de um item, como o formulário as
 * monta para quem está na tela.
 *
 * @return array<int, string>
 */
function periodUiLineOptions(Testable $page, int|string $rowKey): array
{
    return $page->instance()->getSchema('form')->getComponentByStatePath("assets.{$rowKey}.plan_line_id")?->getOptions() ?? [];
}

function periodUiNextMonth(Operation $operation): ?string
{
    return app(OperationNextMeasurementResolver::class)
        ->addNextMeasurementDate(Operation::query())
        ->findOrFail($operation->id)
        ->next_pending_measurement_at?->format('m/Y');
}

function periodUiForgetNotifications(): void
{
    session()->forget(['filament.notifications', 'filament.claimed_notifications']);
}

/**
 * Notificações do Filament enviadas desde a última limpeza, lidas sem
 * consumir: o `assertNotified()` compara só o título e esvazia a sessão.
 *
 * @return list<array{status: string|null, title: string, body: string|null}>
 */
function periodUiNotifications(): array
{
    $notifications = session()->get('filament.claimed_notifications') ?? session()->get('filament.notifications') ?? [];

    return array_values(array_map(fn (array $notification): array => [
        'status' => $notification['status'] ?? null,
        'title' => (string) ($notification['title'] ?? ''),
        'body' => isset($notification['body']) ? (string) $notification['body'] : null,
    ], $notifications));
}

function periodUiScheduleTab(Operation $operation): Testable
{
    return Livewire::test(PlanLinesRelationManager::class, [
        'ownerRecord' => $operation->fresh(),
        'pageClass' => ViewOperation::class,
    ]);
}

function periodUiVersionsTab(Operation $operation): Testable
{
    return Livewire::test(PlanVersionsRelationManager::class, [
        'ownerRecord' => $operation->fresh(),
        'pageClass' => ViewOperation::class,
    ]);
}

/**
 * As linhas do acompanhamento na ordem da tabela: a competência, o que a
 * coluna "Versão" mostra e a linha.
 *
 * @return list<array{0: string, 1: string, 2: int}>
 */
function periodUiScheduleRows(Testable $schedule): array
{
    $column = $schedule->instance()->getTable()->getColumn('version.version_number');

    return collect($schedule->instance()->getTableRecords()->items())
        ->map(function (MeasurementPlanLine $line) use ($column): array {
            $column->record($line);
            $column->clearCachedState();

            return [$line->measurement_date->format('Y-m'), (string) $column->formatState($column->getState()), (int) $line->id];
        })
        ->all();
}

// ── Enviar Medição: a competência atrasada ───────────────────────────────────

it('offers on Enviar Medição each competence by the version that governs it: the delayed June by V1, July onwards by V2', function () {
    $plan = periodUiRevisedYearPlan();
    $v1Lines = periodUiLines($plan['v1']);
    $v2Lines = periodUiLines($plan['v2']);

    // 10/08/2027: nada foi medido ainda, e a V2 vale desde 07/2027. Junho,
    // atrasado, é da V1 -- não da cópia de junho que a V2 traz, com a mesma
    // linhagem --, e julho em diante é da V2 -- não das linhas da V1, que não
    // rege mais esses meses.
    periodUiTravelTo('2027-08-10');
    $this->actingAs($plan['actor']);
    $page = periodUiSendPage($plan['operation']);
    $options = periodUiLineOptions($page, periodUiRowKey($page, $plan['aurora']));

    expect($options)->toBe([
        $v1Lines['2027-01']->id => 'Medição 01 · 01/2027 · 5,0% planejado',
        $v1Lines['2027-02']->id => 'Medição 02 · 02/2027 · 10,0% planejado',
        $v1Lines['2027-03']->id => 'Medição 03 · 03/2027 · 15,0% planejado',
        $v1Lines['2027-04']->id => 'Medição 04 · 04/2027 · 20,0% planejado',
        $v1Lines['2027-05']->id => 'Medição 05 · 05/2027 · 25,0% planejado',
        $v1Lines['2027-06']->id => 'Medição 06 · 06/2027 · 30,0% planejado',
        $v2Lines['2027-07']->id => 'Medição 07 · 07/2027 · 35,0% planejado',
        $v2Lines['2027-08']->id => 'Medição 08 · 08/2027 · 40,0% planejado',
        $v2Lines['2027-09']->id => 'Medição 09 · 09/2027 · 45,0% planejado',
        $v2Lines['2027-10']->id => 'Medição 10 · 10/2027 · 50,0% planejado',
        $v2Lines['2027-11']->id => 'Medição 11 · 11/2027 · 55,0% planejado',
        $v2Lines['2027-12']->id => 'Medição 12 · 12/2027 · 60,0% planejado',
    ])
        ->and(array_intersect(array_keys($options), [
            $v2Lines['2027-06']->id,
            $v1Lines['2027-07']->id,
            $v1Lines['2027-08']->id,
            $v1Lines['2027-09']->id,
            $v1Lines['2027-10']->id,
            $v1Lines['2027-11']->id,
            $v1Lines['2027-12']->id,
        ]))->toBe([])
        ->and($v2Lines['2027-06']->lineage_key)->toBe($v1Lines['2027-06']->lineage_key);
});

// ── Enviar Medição: os arquivos acompanham a competência ─────────────────────

it('keeps on Enviar Medição one file per development that plans the competence of the picked planned measurement, and Engineering approves without the other', function () {
    $towers = periodUiTwoTowers();
    ['alfa' => $alfa, 'beta' => $beta] = $towers;
    $alfaV1 = periodUiVersion($alfa, 1);
    $alfaLines = periodUiLines($alfaV1);
    periodUiTravelTo('2027-07-05');
    $this->actingAs($towers['actor']);

    // Escolhida a operação, ainda sem competência: um arquivo por plano em
    // vigor.
    $page = periodUiSendPage($towers['operation']);

    expect(periodUiRows($page))->toBe([[$alfa->id, null], [$beta->id, null]])
        ->and(periodUiCompetence($page))->toBeNull();

    $alfaRow = periodUiRowKey($page, $alfa);

    // Junho da Torre Alfa: a medição prevista decide a competência, e a Torre
    // Beta -- que só prevê medição de setembro em diante -- sai sozinha, sem a
    // pessoa ter de removê-la.
    $page->set("data.assets.{$alfaRow}.plan_line_id", $alfaLines['2027-06']->id);

    expect(periodUiCompetence($page))->toBe('2027-06')
        ->and(periodUiRows($page))->toBe([[$alfa->id, $alfaLines['2027-06']->id]]);

    // Setembro da Torre Alfa: a Torre Beta prevê setembro e volta, com a
    // medição prevista dela por escolher.
    $page->set("data.assets.{$alfaRow}.plan_line_id", $alfaLines['2027-09']->id);

    expect(periodUiCompetence($page))->toBe('2027-09')
        ->and(periodUiRows($page))->toBe([[$alfa->id, $alfaLines['2027-09']->id], [$beta->id, null]]);

    // A competência trocada para junho no próprio campo: a Torre Beta sai de
    // novo, e a medição prevista de setembro da Torre Alfa é limpa -- a
    // medição tem uma competência só.
    $page->set('data.reference_month', '2027-06-01');

    expect(periodUiCompetence($page))->toBe('2027-06')
        ->and(periodUiRows($page))->toBe([[$alfa->id, null]]);

    // Junho vai só com o arquivo da Torre Alfa, sob a V1.
    $page->set("data.assets.{$alfaRow}.plan_line_id", $alfaLines['2027-06']->id)
        ->fillForm(["assets.{$alfaRow}.storage_path" => [UploadedFile::fake()->createWithContent('medicao-junho-alfa.pdf', '%PDF-1.7 medição de junho da Torre Alfa')]])
        ->call('create')
        ->assertHasNoFormErrors();

    $june = $towers['operation']->measurements()->sole();

    expect($june->reference_month->toDateString())->toBe('2027-06-01')
        ->and($june->assets()->get()->map(fn (MeasurementAsset $asset): array => [
            (int) $asset->plan_set_id,
            (int) $asset->plan_version_id,
            (int) $asset->plan_line_id,
        ])->all())->toBe([[$alfa->id, $alfaV1->id, $alfaLines['2027-06']->id]]);

    // A Engenharia aprova junho só com a Torre Alfa: a Torre Beta não prevê
    // medição em junho e não é exigida.
    periodUiForgetNotifications();
    Livewire::test(ViewMeasurement::class, ['record' => $june->getRouteKey()])
        ->callAction('approve', data: ['realized' => [$alfa->id => 5]])
        ->assertHasNoActionErrors();

    $approved = $june->fresh();

    expect(array_column(periodUiNotifications(), 'title'))->toBe(['Etapa aprovada.'])
        ->and($approved->only(['status', 'current_stage']))->toBe(['status' => 'in_review', 'current_stage' => 2])
        ->and(array_map(
            fn (array $entry): array => [$entry['plan_set_id'], $entry['plan_version_id'], $entry['realized_monthly_percent']],
            $approved->engineering_snapshot['plan_sets'],
        ))->toBe([[$alfa->id, $alfaV1->id, '5.00']]);
});

it('explains on Enviar Medição that no development plans the competence typed and refuses the empty sending', function () {
    $towers = periodUiTwoTowers();
    periodUiTravelTo('2027-07-05');
    $this->actingAs($towers['actor']);
    $page = periodUiSendPage($towers['operation']);

    expect(periodUiRows($page))->toBe([[$towers['alfa']->id, null], [$towers['beta']->id, null]]);

    // Nenhuma das duas obras prevê medição em 01/2028: a lista de arquivos
    // fica vazia, a tela já diz por quê, e o envio repete o motivo.
    $page->set('data.reference_month', '2028-01-01');

    expect($page->get('data.assets'))->toBe([]);

    $page->assertSee('Nenhum empreendimento desta operação prevê medição em 01/2028: escolha outra competência.');

    periodUiForgetNotifications();
    $page->call('create');

    expect($page->errors()->toArray())->toBe([
        'data.assets' => ['Nenhum empreendimento desta operação prevê medição em 01/2028: escolha outra competência.'],
    ])
        ->and(periodUiNotifications())->toBe([])
        ->and(Measurement::query()->count())->toBe(0)
        ->and(MeasurementAsset::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles('nimbus_docs/measurements/assets'))->toBe([]);
});

// ── A competência medida sem o plano ─────────────────────────────────────────

it('does not offer the planned measurement of a competence the operation measured without the plan while that measurement stands, and offers it again once it is refused', function () {
    periodUiTravelTo('2027-03-01');
    $scenario = periodUiOperation();
    $service = app(MeasurementPlanVersionService::class);
    // A torre vale desde 03/2027. O anexo -- o plano padrão da operação --
    // tem março no cronograma e fica com a V1 em rascunho.
    $tower = periodUiPlan($scenario, 'Torre Principal', '1000000.00', periodUiMonthly('2027-03', 3, '10.00'), isDefault: false);
    periodUiActivate($scenario, periodUiVersion($tower, 1));
    $annex = periodUiPlan($scenario, 'Anexo', '1000000.00', periodUiMonthly('2027-03', 3, '10.00'), isDefault: true);

    // 05/04: março é enviado só com a torre -- o anexo ainda não valia.
    periodUiTravelTo('2027-04-05');
    $march = periodUiMeasurement($scenario, '2027-03', [$tower]);

    // 06/04: a V1 do anexo passa a valer desde 04/2027, com março antes da
    // vigência. Enquanto a medição de março estiver de pé, uma segunda medição
    // de março teria de cobrir também a torre, cuja linha de março ela ocupa:
    // a medição prevista de março do anexo não tem como ser medida.
    periodUiTravelTo('2027-04-06');
    $annexV1 = periodUiActivate($scenario, periodUiVersion($annex, 1));
    $annexLines = periodUiLines($annexV1);
    $this->actingAs($scenario['actor']);
    $page = periodUiSendPage($scenario['operation']);

    expect($annexV1->effective_from->toDateString())->toBe('2027-04-01')
        ->and(periodUiRows($page))->toBe([[$tower->id, null], [$annex->id, null]])
        ->and(periodUiLineOptions($page, periodUiRowKey($page, $annex)))->toBe([
            $annexLines['2027-04']->id => 'Medição 02 · 04/2027 · 10,0% planejado',
            $annexLines['2027-05']->id => 'Medição 03 · 05/2027 · 20,0% planejado',
        ])
        ->and($service->monthsMeasuredWithoutThePlan($annex))->toBe(['2027-03' => $march->id])
        ->and(periodUiNextMonth($scenario['operation']))->toBe('04/2027');

    // A Engenharia recusa março: a competência volta a ser medida, agora com
    // os dois planos, e a medição prevista de março do anexo volta a valer.
    Livewire::test(ViewMeasurement::class, ['record' => $march->getRouteKey()])
        ->callAction('reject', data: ['notes' => 'Arquivo de outra obra: reenviar março com os dois empreendimentos.'])
        ->assertHasNoActionErrors();

    $page = periodUiSendPage($scenario['operation']);

    expect($march->fresh()->status)->toBe('rejected')
        ->and(periodUiLineOptions($page, periodUiRowKey($page, $annex)))->toBe([
            $annexLines['2027-03']->id => 'Medição 01 · 03/2027 · 10,0% planejado',
            $annexLines['2027-04']->id => 'Medição 02 · 04/2027 · 10,0% planejado',
            $annexLines['2027-05']->id => 'Medição 03 · 05/2027 · 20,0% planejado',
        ])
        ->and($service->monthsMeasuredWithoutThePlan($annex))->toBe([])
        ->and(periodUiNextMonth($scenario['operation']))->toBe('03/2027');
});

// ── Editar Medição: a versão congelada ───────────────────────────────────────

it('offers on the edit page of the delayed measurement only the competences its frozen version governs, and moves it to the forgotten May', function () {
    $plan = periodUiDelayedJune();
    $v1Lines = periodUiLines($plan['v1']);
    $june = $plan['june'];
    $asset = $june->assets()->sole();
    $this->actingAs($plan['actor']);

    // Junho foi enviado sob a V1, que rege de janeiro a junho; janeiro a abril
    // já têm medição. Ficam maio, ainda sem medição, e o próprio junho -- nem
    // julho em diante da V1, que a V2 rege, nem nenhuma linha da V2.
    $page = Livewire::test(EditMeasurement::class, ['record' => $june->getRouteKey()])
        ->assertSee('A medição foi enviada sob a V1 do plano: só as competências regidas por ela aparecem aqui.');

    expect($asset->plan_version_id)->toBe($plan['v1']->id)
        ->and(periodUiLineOptions($page, "record-{$asset->id}"))->toBe([
            $v1Lines['2027-05']->id => 'Medição 05 · 05/2027 · 25,0% planejado',
            $v1Lines['2027-06']->id => 'Medição 06 · 06/2027 · 30,0% planejado',
        ]);

    // O arquivo era de maio: a correção troca a medição prevista, e a
    // competência acompanha. A versão continua a V1.
    periodUiForgetNotifications();
    $page->set("data.assets.record-{$asset->id}.plan_line_id", $v1Lines['2027-05']->id)
        ->call('save')
        ->assertHasNoFormErrors();

    expect(array_column(periodUiNotifications(), 'title'))->toBe(['Medição atualizada com sucesso.'])
        ->and($asset->fresh()->only(['plan_version_id', 'plan_line_id', 'line_claim_key']))->toBe([
            'plan_version_id' => $plan['v1']->id,
            'plan_line_id' => $v1Lines['2027-05']->id,
            'line_claim_key' => $v1Lines['2027-05']->lineage_key,
        ])
        ->and($june->fresh()->reference_month->toDateString())->toBe('2027-05-01');
});

it('refuses on the edit page a planned measurement or a competence that the frozen version does not govern, and changes nothing', function () {
    $plan = periodUiDelayedJune();
    $v1Lines = periodUiLines($plan['v1']);
    $june = $plan['june'];
    $asset = $june->assets()->sole();
    $frozen = fn (): array => $asset->fresh()->only(['plan_version_id', 'plan_line_id', 'line_claim_key']);
    $sent = $frozen();
    $this->actingAs($plan['actor']);

    // Julho da V1 não está entre as opções -- a V2 rege julho --, e o valor
    // forjado no estado é recusado no próprio campo.
    periodUiForgetNotifications();
    $page = Livewire::test(EditMeasurement::class, ['record' => $june->getRouteKey()])
        ->set("data.assets.record-{$asset->id}.plan_line_id", $v1Lines['2027-07']->id)
        ->call('save');

    expect($page->errors()->toArray())->toBe([
        "data.assets.record-{$asset->id}.plan_line_id" => ['Esta medição prevista não está mais disponível: o plano foi revisado ou outra medição a ocupou. Recarregue a página e escolha de novo.'],
    ])
        ->and(periodUiNotifications())->toBe([])
        ->and($frozen())->toBe($sent)
        ->and($june->fresh()->reference_month->toDateString())->toBe('2027-06-01');

    // A competência trocada para julho, com a medição prevista de junho: a
    // gravação recusa com o motivo, e nada muda.
    periodUiForgetNotifications();
    Livewire::test(EditMeasurement::class, ['record' => $june->getRouteKey()])
        ->set('data.reference_month', '2027-07-01')
        ->call('save')
        ->assertHasNoFormErrors();

    expect(periodUiNotifications())->toBe([[
        'status' => 'danger',
        'title' => 'Medição não atualizada.',
        'body' => sprintf(Measurement::FROZEN_VERSION_COMPETENCE_CHANGE_REFUSAL, '07/2027', 'V1', 'Torre Aurora'),
    ]])
        ->and($frozen())->toBe($sent)
        ->and($sent['plan_version_id'])->toBe($plan['v1']->id)
        ->and($june->fresh()->reference_month->toDateString())->toBe('2027-06-01');
});

// ── Cronograma (Acompanhamento) ──────────────────────────────────────────────

it('tracks on Cronograma (Acompanhamento) each competence by the version that governs it, and says which one', function () {
    $plan = periodUiRevisedYearPlan();
    $v1Lines = periodUiLines($plan['v1']);
    $v2Lines = periodUiLines($plan['v2']);
    periodUiTravelTo('2027-08-10');
    $this->actingAs($plan['planner']);

    // Doze competências, cada uma uma vez: janeiro a junho pela V1, julho a
    // dezembro pela V2. As cópias de janeiro a junho que a V2 traz e as linhas
    // da V1 de julho em diante não aparecem.
    $schedule = periodUiScheduleTab($plan['operation'])
        ->set('tableRecordsPerPage', 25)
        ->assertOk()
        ->assertCountTableRecords(12)
        ->assertCanNotSeeTableRecords([
            ...array_values(array_filter($v2Lines, fn (MeasurementPlanLine $line): bool => $line->measurement_date->format('Y-m') < '2027-07')),
            ...array_values(array_filter($v1Lines, fn (MeasurementPlanLine $line): bool => $line->measurement_date->format('Y-m') >= '2027-07')),
        ]);

    expect(periodUiScheduleRows($schedule))->toBe([
        ['2027-01', 'V1', $v1Lines['2027-01']->id],
        ['2027-02', 'V1', $v1Lines['2027-02']->id],
        ['2027-03', 'V1', $v1Lines['2027-03']->id],
        ['2027-04', 'V1', $v1Lines['2027-04']->id],
        ['2027-05', 'V1', $v1Lines['2027-05']->id],
        ['2027-06', 'V1', $v1Lines['2027-06']->id],
        ['2027-07', 'V2', $v2Lines['2027-07']->id],
        ['2027-08', 'V2', $v2Lines['2027-08']->id],
        ['2027-09', 'V2', $v2Lines['2027-09']->id],
        ['2027-10', 'V2', $v2Lines['2027-10']->id],
        ['2027-11', 'V2', $v2Lines['2027-11']->id],
        ['2027-12', 'V2', $v2Lines['2027-12']->id],
    ]);
});

// ── Versões dos Planos: a vigência de cada versão ────────────────────────────

it('shows on Versões dos Planos the competences before the vigency that the first version governs, and the version superseded in its own month', function () {
    // 10/04/2027: as duas obras têm medição prevista desde 02/2027.
    periodUiTravelTo('2027-04-10');
    $scenario = periodUiOperation();
    $aurora = periodUiPlan($scenario, 'Torre Aurora', '20000000.00', periodUiMonthly('2027-02', 11, '5.00'));
    $boreal = periodUiPlan($scenario, 'Torre Boreal', '8000000.00', periodUiMonthly('2027-02', 11, '5.00'));
    $auroraV1 = periodUiVersion($aurora, 1);
    $this->actingAs($scenario['planner']);

    // A V1 da Aurora ativada pela aba: vale desde 04/2027 e rege também
    // fevereiro e março, que ficam a medir atrasados.
    periodUiForgetNotifications();
    periodUiVersionsTab($scenario['operation'])
        ->mountTableAction('activateVersion', $auroraV1)
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    expect(periodUiNotifications())->toBe([
        ['status' => 'success', 'title' => 'V1 ativada.', 'body' => 'As medições deste plano usam esta versão.'],
    ]);

    // A Boreal ativa a V1 e, ainda em abril, a V2: a V1 fica só com fevereiro
    // e março.
    periodUiActivate($scenario, periodUiVersion($boreal, 1));
    periodUiTravelTo('2027-04-20');
    $borealV2 = periodUiActivate($scenario, periodUiCostRevision($scenario, $boreal, '9000000.00'));
    $borealV1 = periodUiVersion($boreal, 1);

    periodUiVersionsTab($scenario['operation'])
        ->assertTableColumnStateSet('validity', 'Desde 01/04/2027 (e as competências anteriores do cronograma)', $auroraV1)
        ->assertTableColumnStateSet('validity', 'Só as competências anteriores a 04/2027: substituída no mês da própria ativação', $borealV1)
        ->assertTableColumnStateSet('validity', 'Desde 01/04/2027', $borealV2);

    // 02/07/2027: a revisão de custo da Aurora, ativada pela aba, vale desde
    // 07/2027; a V1 fica com abril a junho e com as competências anteriores.
    periodUiTravelTo('2027-07-02');
    $auroraV2 = periodUiCostRevision($scenario, $aurora, '23000000.00');
    periodUiForgetNotifications();
    periodUiVersionsTab($scenario['operation'])
        ->mountTableAction('activateVersion', $auroraV2)
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    expect(periodUiNotifications())->toBe([
        ['status' => 'success', 'title' => 'V2 ativada.', 'body' => 'As medições das competências a partir de 07/2027 usam esta versão; as anteriores continuam na versão que as rege.'],
    ]);

    periodUiVersionsTab($scenario['operation'])
        ->assertTableColumnStateSet('validity', '01/04/2027 a 30/06/2027 (e as competências anteriores do cronograma)', $auroraV1)
        ->assertTableColumnStateSet('validity', 'Desde 01/07/2027', $auroraV2)
        ->assertTableColumnStateSet('validity', 'Só as competências anteriores a 04/2027: substituída no mês da própria ativação', $borealV1);

    // A tela diz o que a regra decide.
    $resolver = app(MeasurementPlanVersionResolver::class);
    $governing = fn (MeasurementPlanSet $planSet, string $month): ?int => $resolver->forCompetence($planSet, "{$month}-01")?->id;

    expect([
        $governing($aurora, '2027-02'),
        $governing($aurora, '2027-06'),
        $governing($aurora, '2027-07'),
        $governing($boreal, '2027-03'),
        $governing($boreal, '2027-04'),
    ])->toBe([$auroraV1->id, $auroraV1->id, $auroraV2->id, $borealV1->id, $borealV2->id]);
});

// ── A medição atrasada na própria tela ───────────────────────────────────────

it('shows on the delayed June measurement its competence and the V1 fund it was sent under while V2 is in force', function () {
    $plan = periodUiDelayedJune();
    $june = $plan['june'];
    $this->actingAs($plan['actor']);

    expect($plan['v2']->fresh()->status)->toBe(MeasurementPlanVersionStatus::Active)
        ->and($plan['v2']->construction_fund_amount)->toBe('23000000.00')
        ->and($plan['aurora']->fresh()->currentConstructionFundAmount())->toBe('23000000.00')
        ->and($june->assets()->sole()->plan_version_id)->toBe($plan['v1']->id);

    Livewire::test(ViewMeasurement::class, ['record' => $june->getRouteKey()])
        ->assertOk()
        ->assertSeeHtml('<strong class="bsi-meta-label">Competência:</strong> 06/2027')
        ->assertSeeInOrder(['Competência', '06/2027', 'Versão do plano', 'V1 · Fundo de Obra R$ 20.000.000,00'])
        ->assertDontSee('V2 · Fundo de Obra');
});

// ── O formulário de envio pela competência ───────────────────────────────────

it('gives every file that enters Enviar Medição a fresh key and lets no development be removed from the sending', function () {
    $towers = periodUiTwoTowers();
    periodUiTravelTo('2027-07-05');
    $this->actingAs($towers['actor']);
    $alfaLines = periodUiLines(periodUiVersion($towers['alfa'], 1));
    $page = periodUiSendPage($towers['operation']);
    $firstKeys = array_keys($page->get('data.assets'));
    $alfaKey = periodUiRowKey($page, $towers['alfa']);

    // Os arquivos são exatamente os empreendimentos que a competência
    // envolve, todos exigidos pela Engenharia: nenhum sai do envio.
    expect($page->instance()->getSchema('form')->getComponentByStatePath('assets')?->isDeletable())->toBeFalse();

    // Junho: a Torre Beta sai. Setembro: ela volta -- com uma chave nova, e
    // não com a de uma linha que saiu, para que um upload ainda em andamento
    // não caia no empreendimento errado.
    $page->set("data.assets.{$alfaKey}.plan_line_id", $alfaLines['2027-06']->id);

    expect(periodUiRows($page))->toBe([[$towers['alfa']->id, $alfaLines['2027-06']->id]]);

    $page->set("data.assets.{$alfaKey}.plan_line_id", $alfaLines['2027-09']->id);
    $betaKey = periodUiRowKey($page, $towers['beta']);

    expect(periodUiRows($page))->toBe([[$towers['alfa']->id, $alfaLines['2027-09']->id], [$towers['beta']->id, null]])
        ->and($betaKey)->toBeString()
        ->and($firstKeys)->not->toContain($betaKey)
        ->and(periodUiRowKey($page, $towers['alfa']))->toBe($alfaKey);
});

it('requires the competence of the measurement on Enviar Medição', function () {
    $towers = periodUiTwoTowers();
    periodUiTravelTo('2027-07-05');
    $this->actingAs($towers['actor']);
    $alfaLines = periodUiLines(periodUiVersion($towers['alfa'], 1));
    $page = periodUiSendPage($towers['operation']);
    $alfaKey = periodUiRowKey($page, $towers['alfa']);

    // A competência decide a versão de cada arquivo: apagada depois de
    // escolhida a medição prevista, o envio não nasce sem ela.
    $page->set("data.assets.{$alfaKey}.plan_line_id", $alfaLines['2027-06']->id)
        ->set("data.assets.{$alfaKey}.storage_path", [UploadedFile::fake()->createWithContent('medicao-junho.pdf', '%PDF-1.7 medição de junho da Torre Alfa')])
        ->set('data.reference_month', null)
        ->call('create');

    expect($page->errors()->toArray())->toHaveKey('data.reference_month')
        ->and($page->errors()->toArray()['data.reference_month'])->toBe(['Informe a competência de referência.'])
        ->and(Measurement::query()->count())->toBe(0);
});

it('moves on the edit page the competence of a measurement with two files only together with the planned measurement of both, and says the version is frozen', function () {
    periodUiTravelTo('2027-01-10');
    $scenario = periodUiOperation();
    $alfa = periodUiPlan($scenario, 'Torre Alfa', '20000000.00', periodUiMonthly('2027-01', 12, '5.00'));
    $beta = periodUiPlan($scenario, 'Torre Beta', '8000000.00', periodUiMonthly('2027-01', 12, '5.00'));
    $alfaLines = periodUiLines(periodUiActivate($scenario, periodUiVersion($alfa, 1)));
    $betaLines = periodUiLines(periodUiActivate($scenario, periodUiVersion($beta, 1)));

    periodUiTravelTo('2027-07-05');
    $june = periodUiMeasurement($scenario, '2027-06', [$alfa, $beta]);
    $alfaAsset = $june->assets()->where('plan_set_id', $alfa->id)->sole();
    $betaAsset = $june->assets()->where('plan_set_id', $beta->id)->sole();
    $this->actingAs($scenario['actor']);

    // No Editar a versão está congelada nos arquivos: o campo diz isso.
    $page = Livewire::test(EditMeasurement::class, ['record' => $june->getRouteKey()])
        ->assertSee('A versão do plano de cada arquivo ficou congelada no envio: a competência só muda junto com a medição prevista de cada arquivo, para outra que essa versão rege.');

    // Só a Torre Alfa vai para maio: a medição ficaria com duas competências.
    // A gravação recusa com o motivo, e nada muda.
    periodUiForgetNotifications();
    $page->set("data.assets.record-{$alfaAsset->id}.plan_line_id", $alfaLines['2027-05']->id)
        ->call('save');

    expect(periodUiNotifications())->toBe([[
        'status' => 'danger',
        'title' => 'Medição não atualizada.',
        'body' => sprintf(Measurement::COMPETENCE_WITHOUT_ITS_LINES_REFUSAL, 'Torre Beta', '06/2027', '05/2027'),
    ]])
        ->and($alfaAsset->fresh()->plan_line_id)->toBe($alfaLines['2027-06']->id)
        ->and($june->fresh()->reference_month->toDateString())->toBe('2027-06-01');

    // Com as duas medições previstas em maio, a competência vai junto.
    periodUiForgetNotifications();
    Livewire::test(EditMeasurement::class, ['record' => $june->getRouteKey()])
        ->set("data.assets.record-{$alfaAsset->id}.plan_line_id", $alfaLines['2027-05']->id)
        ->set("data.assets.record-{$betaAsset->id}.plan_line_id", $betaLines['2027-05']->id)
        ->call('save')
        ->assertHasNoFormErrors();

    expect(array_column(periodUiNotifications(), 'title'))->toBe(['Medição atualizada com sucesso.'])
        ->and($june->fresh()->reference_month->toDateString())->toBe('2027-05-01')
        ->and($june->assets()->orderBy('plan_set_id')->pluck('plan_line_id')->all())->toBe([$alfaLines['2027-05']->id, $betaLines['2027-05']->id]);
});

// ── O período da medição depois do envio ─────────────────────────────────────

it('refuses on the edit page a planned measurement moved to another month when the competence is put back, and keeps the month claimed', function () {
    $plan = periodUiDelayedJune();
    $v1Lines = periodUiLines($plan['v1']);
    $june = $plan['june'];
    $asset = $june->assets()->sole();
    $this->actingAs($plan['actor']);

    // A pessoa escolhe maio (a competência vai junto para maio) e devolve a
    // Competência para junho: a medição ficaria com duas competências, e
    // junho, livre para uma segunda medição.
    periodUiForgetNotifications();
    Livewire::test(EditMeasurement::class, ['record' => $june->getRouteKey()])
        ->set("data.assets.record-{$asset->id}.plan_line_id", $v1Lines['2027-05']->id)
        ->set('data.reference_month', '2027-06-01')
        ->call('save');

    expect(periodUiNotifications())->toBe([[
        'status' => 'danger',
        'title' => 'Medição não atualizada.',
        'body' => sprintf(Measurement::COMPETENCE_WITHOUT_ITS_LINES_REFUSAL, 'Torre Aurora', '05/2027', '06/2027'),
    ]])
        ->and($asset->fresh()->only(['plan_line_id', 'line_claim_key']))->toBe([
            'plan_line_id' => $v1Lines['2027-06']->id,
            'line_claim_key' => $v1Lines['2027-06']->lineage_key,
        ])
        ->and($june->fresh()->reference_month->toDateString())->toBe('2027-06-01')
        ->and(MeasurementPlanLine::query()->whereKey($v1Lines['2027-06']->id)->availableForMeasurement()->exists())->toBeFalse();
});

it('refuses on the edit page a competence or a removed file that leaves out a development Engineering will require', function () {
    // A Torre Alfa prevê de 01 a 12/2027 e a Torre Beta só de 09 a 12/2027.
    $towers = periodUiTwoTowers();
    $alfaLines = periodUiLines(periodUiVersion($towers['alfa'], 1));
    $betaLines = periodUiLines(periodUiVersion($towers['beta'], 1));
    periodUiTravelTo('2027-07-05');
    $june = periodUiMeasurement($towers, '2027-06', [$towers['alfa']]);
    $juneAsset = $june->assets()->sole();
    $this->actingAs($towers['actor']);

    // Junho só com a Alfa está certo. Movida para setembro, a medição teria
    // de cobrir também a Beta, e o Editar não acrescenta arquivo.
    periodUiForgetNotifications();
    Livewire::test(EditMeasurement::class, ['record' => $june->getRouteKey()])
        ->set("data.assets.record-{$juneAsset->id}.plan_line_id", $alfaLines['2027-09']->id)
        ->call('save');

    expect(periodUiNotifications())->toBe([[
        'status' => 'danger',
        'title' => 'Medição não atualizada.',
        'body' => sprintf(Measurement::COMPETENCE_COVERAGE_REFUSAL, '09/2027', 'Torre Beta'),
    ]])
        ->and($juneAsset->fresh()->plan_line_id)->toBe($alfaLines['2027-06']->id)
        ->and($june->fresh()->reference_month->toDateString())->toBe('2027-06-01');

    // Setembro com as duas torres: remover o arquivo da Beta deixaria de fora
    // um empreendimento exigido.
    periodUiTravelTo('2027-10-05');
    $september = periodUiMeasurement($towers, '2027-09', [$towers['alfa'], $towers['beta']]);
    $betaAsset = $september->assets()->where('plan_set_id', $towers['beta']->id)->sole();

    periodUiForgetNotifications();
    $page = Livewire::test(EditMeasurement::class, ['record' => $september->getRouteKey()]);
    $assets = $page->get('data.assets');
    unset($assets["record-{$betaAsset->id}"]);
    $page->set('data.assets', $assets)->call('save');

    expect(periodUiNotifications())->toBe([[
        'status' => 'danger',
        'title' => 'Medição não atualizada.',
        'body' => sprintf(Measurement::COMPETENCE_COVERAGE_REFUSAL, '09/2027', 'Torre Beta'),
    ]])
        ->and($september->assets()->pluck('plan_set_id')->sort()->values()->all())->toBe([$towers['alfa']->id, $towers['beta']->id])
        ->and($betaLines['2027-09']->fresh()->id)->toBe($betaAsset->fresh()->plan_line_id);
});

it('rebuilds on Enviar Medição the files of a competence a plan started to plan with the form open, keeping the file already chosen, and stores nothing on the refusal', function () {
    $towers = periodUiTwoTowers();
    $alfaLines = periodUiLines(periodUiVersion($towers['alfa'], 1));
    // A Torre Gama prevê de 09 a 12/2027 e ainda está com a V1 em rascunho.
    $gama = periodUiPlan($towers, 'Torre Gama', '6000000.00', periodUiMonthly('2027-09', 4, '10.00'));
    periodUiTravelTo('2027-10-05');
    $this->actingAs($towers['actor']);
    $page = periodUiSendPage($towers['operation']);
    $alfaKey = periodUiRowKey($page, $towers['alfa']);
    $betaKey = periodUiRowKey($page, $towers['beta']);
    $betaLines = periodUiLines(periodUiVersion($towers['beta'], 1));

    // Setembro escolhido nas duas torres, com os arquivos.
    $page->set("data.assets.{$alfaKey}.plan_line_id", $alfaLines['2027-09']->id)
        ->set("data.assets.{$alfaKey}.storage_path", [UploadedFile::fake()->createWithContent('alfa-setembro.pdf', '%PDF-1.7 setembro da Torre Alfa')])
        ->set("data.assets.{$betaKey}.plan_line_id", $betaLines['2027-09']->id)
        ->set("data.assets.{$betaKey}.storage_path", [UploadedFile::fake()->createWithContent('beta-setembro.pdf', '%PDF-1.7 setembro da Torre Beta')]);

    // Com o formulário preenchido, a V1 da Gama passa a valer -- e ela prevê
    // setembro. O envio é recusado antes de gravar qualquer arquivo, e a lista
    // é refeita: Alfa e Beta continuam como estavam, e a Gama entra.
    periodUiActivate($towers, periodUiVersion($gama, 1));
    $page->call('create');

    expect($page->errors()->toArray())->toBe(['data.operation_id' => [CreateMeasurement::PLANS_IN_FORCE_CHANGED_MESSAGE]])
        ->and(Measurement::query()->count())->toBe(0)
        ->and(MeasurementAsset::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles('nimbus_docs/measurements/assets'))->toBe([])
        ->and(periodUiRows($page))->toBe([
            [$towers['alfa']->id, $alfaLines['2027-09']->id],
            [$towers['beta']->id, $betaLines['2027-09']->id],
            [$gama->id, null],
        ]);

    // A pessoa completa a Gama e envia: passa, com os três arquivos.
    $gamaKey = periodUiRowKey($page, $gama);
    $page->set("data.assets.{$gamaKey}.plan_line_id", periodUiLines(periodUiVersion($gama, 1))['2027-09']->id)
        ->set("data.assets.{$gamaKey}.storage_path", [UploadedFile::fake()->createWithContent('gama-setembro.pdf', '%PDF-1.7 setembro da Torre Gama')])
        ->call('create')
        ->assertHasNoFormErrors();

    expect($towers['operation']->measurements()->sole()->assets()->orderBy('plan_set_id')->pluck('plan_set_id')->all())
        ->toBe([$towers['alfa']->id, $towers['beta']->id, $gama->id]);
});
