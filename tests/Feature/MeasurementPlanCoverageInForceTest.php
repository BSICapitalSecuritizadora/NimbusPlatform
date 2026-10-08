<?php

use App\Enums\MeasurementPlanRevisionCategory;
use App\Filament\Resources\Measurements\Pages\CreateMeasurement;
use App\Filament\Resources\Measurements\Pages\EditMeasurement;
use App\Filament\Resources\Measurements\Pages\ViewMeasurement;
use App\Models\Construction;
use App\Models\Measurement;
use App\Models\MeasurementAsset;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Models\MeasurementPlanVersion;
use App\Models\Operation;
use App\Models\User;
use App\Services\MeasurementPlanVersionService;
use App\Services\MeasurementWorkflow;
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
 * A medição cobre os planos que estavam em vigor quando foi enviada.
 *
 * Todo plano nasce com a V1 em rascunho e só recebe medição depois de ativado,
 * e o Editar não acrescenta o arquivo de um empreendimento. Por isso a
 * Engenharia exige de cada medição sem pagamento só os planos que já valiam no
 * envio -- a ativação guarda a última medição da operação até ali --, também
 * quando a medição volta à Engenharia de uma etapa posterior. O plano que passa
 * a valer depois é medido pelas medições seguintes.
 *
 * Nas telas: o envio confere, sob o lock da Operation, que os planos em vigor
 * não mudaram desde a escolha da operação; o Enviar Medição diz quais planos
 * ainda não valem; e o Editar não deixa a troca de linha sumir calada quando a
 * versão da medição é substituída com a página aberta.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    Notification::fake();
});

/**
 * Leva o relógio às 10h de Brasília (13h UTC) do dia: a vigência de uma
 * versão ativada é o mês desse dia no calendário de negócio.
 */
function planCoverageInForceTravelTo(string $date): void
{
    test()->travelTo(CarbonImmutable::parse("{$date} 13:00:00", 'UTC'));
}

/**
 * Operação em andamento em que quem envia a medição responde por todas as
 * etapas; quem planeja as obras é um administrador.
 *
 * @return array{actor: User, planner: User, operation: Operation}
 */
function planCoverageInForceOperation(): array
{
    config()->set('filesystems.private_disk', 'local');

    $actor = User::factory()->withTwoFactor()->create();
    $actor->givePermissionTo(['measurements.view', 'measurements.create', 'measurements.update', 'measurements.review']);

    return [
        'actor' => $actor,
        'planner' => makeAdminUser(),
        'operation' => Operation::factory()->create(array_merge(
            ['status' => 'active'],
            array_fill_keys(Operation::RESPONSIBILITY_FIELDS, $actor->id),
        )),
    ];
}

/**
 * Plano de uma obra da operação, criado pelo serviço com a V1 em rascunho:
 * Fundo de Obra de R$ 10.000,00 e 10% previstos no mês informado ('Y-m'; 08/2026
 * se nenhum) e no seguinte.
 *
 * @param  array{planner: User, operation: Operation}  $scenario
 */
function planCoverageInForcePlan(array $scenario, string $development, bool $isDefault = false, string $firstMonth = '2026-08'): MeasurementPlanSet
{
    $construction = Construction::factory()->create([
        'emission_id' => $scenario['operation']->emission_id,
        'development_name' => $development,
    ]);
    $first = CarbonImmutable::parse("{$firstMonth}-01");

    return app(MeasurementPlanVersionService::class)->createPlan($scenario['operation'], $scenario['planner'], [
        'name' => "Plano {$development}",
        'construction_id' => $construction->id,
        'is_default' => $isDefault,
        'initial_incurred_amount' => '0.00',
    ], ['construction_fund_amount' => '10000.00'], [
        ['sequence_number' => 1, 'planned_monthly_percent' => '10.00', 'planned_cumulative_percent' => '10.00', 'measurement_date' => $first->format('Y-m')],
        ['sequence_number' => 2, 'planned_monthly_percent' => '10.00', 'planned_cumulative_percent' => '20.00', 'measurement_date' => $first->addMonthNoOverflow()->format('Y-m')],
    ]);
}

/**
 * Põe em vigor, pelo serviço, o rascunho da V1 do plano.
 *
 * @param  array{planner: User}  $scenario
 */
function planCoverageInForceActivate(array $scenario, MeasurementPlanSet $planSet): MeasurementPlanVersion
{
    $draft = MeasurementPlanVersion::query()->where('plan_set_id', $planSet->id)->draft()->sole();

    return app(MeasurementPlanVersionService::class)->activate($draft, $scenario['planner'], (int) $draft->revision);
}

/**
 * Revisão de custo pelo serviço, do rascunho à ativação: a versão vigente
 * passa a substituída.
 *
 * @param  array{planner: User}  $scenario
 */
function planCoverageInForceRevise(array $scenario, MeasurementPlanSet $planSet): MeasurementPlanVersion
{
    $service = app(MeasurementPlanVersionService::class);
    $active = MeasurementPlanVersion::query()->where('plan_set_id', $planSet->id)->active()->sole();
    $draft = $service->createRevision($planSet, $scenario['planner'], [
        'revision_category' => MeasurementPlanRevisionCategory::Cost->value,
        'revision_reason' => 'Reajuste do orçamento da obra aprovado pelo comitê de crédito.',
    ], (int) $active->id);
    $draft = $service->updateDraft($draft, $scenario['planner'], ['construction_fund_amount' => '12000.00'], null, (int) $draft->revision);

    return $service->activate($draft, $scenario['planner'], (int) $draft->revision);
}

/**
 * A medição prevista do mês ('Y-m') na versão vigente do plano.
 */
function planCoverageInForceLine(MeasurementPlanSet $planSet, string $month): MeasurementPlanLine
{
    return MeasurementPlanLine::query()
        ->where('plan_version_id', MeasurementPlanVersion::query()->where('plan_set_id', $planSet->id)->active()->value('id'))
        ->get()
        ->sole(fn (MeasurementPlanLine $line): bool => $line->measurement_date->format('Y-m') === $month);
}

/**
 * Medição do mês com o arquivo de cada plano informado, na medição prevista
 * da versão vigente, aguardando a Engenharia -- como o envio a grava.
 *
 * @param  array{actor: User, operation: Operation}  $scenario
 * @param  list<MeasurementPlanSet>  $planSets
 */
function planCoverageInForceMeasurement(array $scenario, string $month, array $planSets): Measurement
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
        $path = "nimbus_docs/measurements/assets/coverage-in-force-{$measurement->id}-{$planSet->id}.pdf";
        Storage::disk('local')->put($path, "%PDF-1.7 medição {$month} de {$planSet->name}");
        $measurement->assets()->create([
            'plan_set_id' => $planSet->id,
            'plan_line_id' => planCoverageInForceLine($planSet, $month)->id,
            'storage_path' => $path,
            'storage_disk' => 'local',
        ]);
    }

    app(MeasurementWorkflow::class)->startReview($measurement->fresh(), $scenario['actor']);

    return $measurement->fresh();
}

/**
 * "Enviar Medição" com a operação escolhida.
 */
function planCoverageInForceSendPage(Operation $operation): Testable
{
    return Livewire::test(CreateMeasurement::class)->fillForm(['operation_id' => $operation->id]);
}

/**
 * O plano de cada arquivo que o envio oferece, na ordem do formulário.
 *
 * @return list<int>
 */
function planCoverageInForceOfferedPlans(Testable $page): array
{
    return collect($page->get('data.assets'))
        ->map(fn (array $row): int => (int) $row['plan_set_id'])
        ->values()
        ->all();
}

/**
 * Preenche cada arquivo oferecido com a medição prevista do mês e um PDF, e
 * envia.
 */
function planCoverageInForceSubmit(Testable $page, string $month): Testable
{
    $assets = collect($page->get('data.assets'))
        ->map(function (array $row) use ($month): array {
            $planSet = MeasurementPlanSet::query()->findOrFail($row['plan_set_id']);

            return [
                'plan_set_id' => $planSet->id,
                'plan_line_id' => planCoverageInForceLine($planSet, $month)->id,
                'storage_path' => [UploadedFile::fake()->createWithContent("medicao-{$planSet->id}.pdf", "%PDF-1.7 medição {$month} de {$planSet->name}")],
            ];
        })
        ->all();

    return $page->fillForm(['reference_month' => "{$month}-01", 'assets' => $assets])->call('create');
}

/**
 * Decide a etapa pendente pela tela da medição, como quem responde por ela.
 *
 * @param  array<string, mixed>  $data
 */
function planCoverageInForceDecide(Measurement $measurement, string $action, array $data): Testable
{
    return Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->callAction($action, data: $data);
}

/**
 * Notificações do Filament na sessão, lidas sem consumir: o
 * `assertNotified()` compara só o título e esvazia a sessão.
 *
 * @return list<array<string, mixed>>
 */
function planCoverageInForceNotifications(): array
{
    return array_values(session()->get('filament.claimed_notifications') ?? session()->get('filament.notifications') ?? []);
}

function planCoverageInForceNotificationBody(string $title): ?string
{
    $body = collect(planCoverageInForceNotifications())->firstWhere('title', $title)['body'] ?? null;

    return $body === null ? null : (string) $body;
}

/**
 * @return list<string>
 */
function planCoverageInForceNotificationTitles(): array
{
    return array_map(fn (array $notification): string => (string) $notification['title'], planCoverageInForceNotifications());
}

// ── Engenharia: os planos em vigor no envio ──────────────────────────────────

it('approves again at Engineering without the plan activated after the sending, when Compliance and Gestão send the measurement back', function () {
    planCoverageInForceTravelTo('2026-08-10');
    $scenario = planCoverageInForceOperation();
    $alfa = planCoverageInForcePlan($scenario, 'Torre Alfa', isDefault: true);
    // O cronograma da Torre Beta começa em setembro: agosto é medido antes de
    // ela valer, e uma medição prevista dela em agosto nunca seria medida.
    $beta = planCoverageInForcePlan($scenario, 'Torre Beta', firstMonth: '2026-09');
    planCoverageInForceActivate($scenario, $alfa);
    $this->actingAs($scenario['actor']);

    // Enviada com a V1 da Torre Beta ainda em rascunho: o formulário só
    // oferece o arquivo da Torre Alfa.
    $page = planCoverageInForceSendPage($scenario['operation']);

    expect(planCoverageInForceOfferedPlans($page))->toBe([$alfa->id]);

    planCoverageInForceSubmit($page, '2026-08')->assertHasNoFormErrors();
    $measurement = $scenario['operation']->measurements()->sole();

    // A Torre Beta entra em vigor depois do envio.
    planCoverageInForceTravelTo('2026-08-12');
    $betaV1 = planCoverageInForceActivate($scenario, $beta);

    // A Engenharia aprova com o arquivo que a medição tem e a Gestão aprova;
    // a Compliance a devolve à Gestão, que a devolve à Engenharia.
    planCoverageInForceTravelTo('2026-08-14');
    planCoverageInForceDecide($measurement, 'approve', ['realized' => [$alfa->id => 10]]);

    expect($measurement->fresh()->only(['status', 'current_stage']))->toBe(['status' => 'in_review', 'current_stage' => 2]);

    planCoverageInForceDecide($measurement, 'approve', ['notes' => 'Conferida pela Gestão.']);
    planCoverageInForceDecide($measurement, 'reject', ['notes' => 'Falta a assinatura do fiscal no relatório da Torre Alfa.']);
    planCoverageInForceDecide($measurement, 'reject', ['notes' => 'Rever o percentual medido da Torre Alfa.']);

    expect($measurement->fresh()->only(['status', 'current_stage', 'engineering_snapshot']))->toBe([
        'status' => 'in_review',
        'current_stage' => MeasurementWorkflow::STAGE_ENGINEERING,
        'engineering_snapshot' => null,
    ]);

    // De volta à Engenharia, a Torre Beta -- que passou a valer depois do
    // envio, e cujo arquivo o Editar não acrescenta -- continua fora: a
    // medição não fica presa.
    planCoverageInForceDecide($measurement, 'approve', ['realized' => [$alfa->id => 8]])->assertHasNoActionErrors();

    $approved = $measurement->fresh();

    expect(planCoverageInForceNotificationTitles())->toBe(['Etapa aprovada.'])
        ->and($approved->only(['status', 'current_stage']))->toBe(['status' => 'in_review', 'current_stage' => 2])
        ->and(array_map(
            fn (array $entry): array => [$entry['plan_set_id'], $entry['realized_monthly_percent']],
            $approved->engineering_snapshot['plan_sets'],
        ))->toBe([[$alfa->id, '8.00']])
        ->and($approved->assets()->pluck('plan_set_id')->all())->toBe([$alfa->id])
        ->and($betaV1->last_measurement_id_at_activation)->toBe($measurement->id);
});

it('requires at Engineering the file of a plan that was already in force when the measurement was sent', function () {
    planCoverageInForceTravelTo('2026-08-10');
    $scenario = planCoverageInForceOperation();
    $alfa = planCoverageInForcePlan($scenario, 'Torre Alfa', isDefault: true);
    // A Torre Beta começa em setembro: agosto é medido antes de ela valer.
    $beta = planCoverageInForcePlan($scenario, 'Torre Beta', firstMonth: '2026-09');
    planCoverageInForceActivate($scenario, $alfa);
    $august = planCoverageInForceMeasurement($scenario, '2026-08', [$alfa]);
    planCoverageInForceTravelTo('2026-08-12');
    $betaV1 = planCoverageInForceActivate($scenario, $beta);
    // Setembro é enviada com a Torre Beta já em vigor, mas sem o arquivo dela.
    planCoverageInForceTravelTo('2026-09-10');
    $september = planCoverageInForceMeasurement($scenario, '2026-09', [$alfa]);
    $this->actingAs($scenario['actor']);

    // A mesma ativação separa as duas: agosto, enviada antes dela, é aprovada
    // só com a Torre Alfa; setembro, enviada depois, precisa da Torre Beta.
    planCoverageInForceDecide($august, 'approve', ['realized' => [$alfa->id => 10]])->assertHasNoActionErrors();
    $page = planCoverageInForceDecide($september, 'approve', ['realized' => [$alfa->id => 10]]);

    expect(planCoverageInForceNotificationTitles())->toBe(['Ação não concluída.'])
        ->and(planCoverageInForceNotificationBody('Ação não concluída.'))
        ->toBe('Envie exatamente um arquivo para cada empreendimento da operação. Envie o arquivo da medição para Torre Beta.');

    $page->assertActionMounted('approve');

    expect($september->fresh()->only(['status', 'current_stage', 'engineering_snapshot']))->toBe([
        'status' => 'in_review',
        'current_stage' => MeasurementWorkflow::STAGE_ENGINEERING,
        'engineering_snapshot' => null,
    ])
        ->and($august->fresh()->current_stage)->toBe(2)
        ->and($betaV1->last_measurement_id_at_activation)->toBe($august->id);
});

it('approves a competence without the file of a plan in force that has no planned measurement in it, in the ordinary monthly cycle', function () {
    // A Torre Beta entra na operação com o cronograma a partir de setembro e
    // passa a valer em 02/09, antes do envio da medição de agosto (05/09).
    planCoverageInForceTravelTo('2026-08-10');
    $scenario = planCoverageInForceOperation();
    $alfa = planCoverageInForcePlan($scenario, 'Torre Alfa', isDefault: true);
    $beta = planCoverageInForcePlan($scenario, 'Torre Beta', firstMonth: '2026-09');
    planCoverageInForceActivate($scenario, $alfa);
    planCoverageInForceTravelTo('2026-09-02');
    planCoverageInForceActivate($scenario, $beta);
    planCoverageInForceTravelTo('2026-09-05');
    $august = planCoverageInForceMeasurement($scenario, '2026-08', [$alfa]);
    $this->actingAs($scenario['actor']);

    // A Torre Beta vale para agosto, mas não prevê medição em agosto: não há
    // linha dela para o arquivo, e a competência não pode ficar sem como ser
    // medida. A Engenharia aprova agosto só com a Torre Alfa.
    planCoverageInForceDecide($august, 'approve', ['realized' => [$alfa->id => 10]])->assertHasNoActionErrors();

    expect($august->fresh()->current_stage)->toBe(2)
        ->and(array_column($august->fresh()->engineering_snapshot['plan_sets'], 'plan_set_id'))->toBe([$alfa->id]);
});

it('approves the competence sent again after the refusal of the measurement that preceded a plan without a planned measurement in it', function () {
    planCoverageInForceTravelTo('2026-08-10');
    $scenario = planCoverageInForceOperation();
    $alfa = planCoverageInForcePlan($scenario, 'Torre Alfa', isDefault: true);
    $beta = planCoverageInForcePlan($scenario, 'Torre Beta', firstMonth: '2026-09');
    planCoverageInForceActivate($scenario, $alfa);

    // Agosto é enviada só com a Torre Alfa; a Torre Beta (de setembro em
    // diante) passa a valer; agosto é recusada na Engenharia e reenviada.
    planCoverageInForceTravelTo('2026-09-05');
    $august = planCoverageInForceMeasurement($scenario, '2026-08', [$alfa]);
    planCoverageInForceTravelTo('2026-09-06');
    $betaV1 = planCoverageInForceActivate($scenario, $beta);
    app(MeasurementWorkflow::class)->reject($august->fresh(), $scenario['actor'], 'Planilha de outro mês.');
    planCoverageInForceTravelTo('2026-09-07');
    $resent = planCoverageInForceMeasurement($scenario, '2026-08', [$alfa]);
    $this->actingAs($scenario['actor']);

    // O reenvio sai depois de a Torre Beta valer, mas ela não prevê agosto: a
    // competência continua medível só com a Torre Alfa.
    planCoverageInForceDecide($resent, 'approve', ['realized' => [$alfa->id => 10]])->assertHasNoActionErrors();

    expect($august->fresh()->status)->toBe('rejected')
        ->and($betaV1->last_measurement_id_at_activation)->toBe($august->id)
        ->and($resent->fresh()->current_stage)->toBe(2)
        ->and(array_column($resent->fresh()->engineering_snapshot['plan_sets'], 'plan_set_id'))->toBe([$alfa->id]);
});

// ── Enviar Medição: os planos em vigor na escolha da operação ────────────────

it('refuses a sending whose plans in force changed after the operation was selected, and sends once the operation is selected again', function () {
    planCoverageInForceTravelTo('2026-08-10');
    $scenario = planCoverageInForceOperation();
    $alfa = planCoverageInForcePlan($scenario, 'Torre Alfa', isDefault: true);
    $beta = planCoverageInForcePlan($scenario, 'Torre Beta');
    planCoverageInForceActivate($scenario, $alfa);
    $this->actingAs($scenario['actor']);
    $page = planCoverageInForceSendPage($scenario['operation']);

    expect(planCoverageInForceOfferedPlans($page))->toBe([$alfa->id])
        ->and($page->get('data.offered_plan_set_ids'))->toBe([$alfa->id]);

    // Com o formulário aberto, outra pessoa ativa a V1 da Torre Beta: enviada
    // só com a Torre Alfa, a medição ficaria sem o arquivo de um plano em
    // vigor, que o Editar não acrescenta.
    planCoverageInForceActivate($scenario, $beta);
    planCoverageInForceSubmit($page, '2026-08');

    expect($page->errors()->toArray())->toBe(['data.operation_id' => [CreateMeasurement::PLANS_IN_FORCE_CHANGED_MESSAGE]])
        ->and(planCoverageInForceNotifications())->toBe([])
        ->and(Measurement::query()->count())->toBe(0)
        ->and(MeasurementAsset::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles('nimbus_docs/measurements/assets'))->toBe([]);

    Notification::assertNothingSent();

    // Escolhida de novo, a operação oferece os arquivos dos dois planos, e o
    // envio passa.
    $page->fillForm(['operation_id' => null])->fillForm(['operation_id' => $scenario['operation']->id]);

    expect(planCoverageInForceOfferedPlans($page))->toBe([$alfa->id, $beta->id]);

    planCoverageInForceSubmit($page, '2026-08')->assertHasNoFormErrors();
    $measurement = $scenario['operation']->measurements()->sole();

    expect($measurement->only(['status', 'current_stage']))->toBe(['status' => 'in_review', 'current_stage' => MeasurementWorkflow::STAGE_ENGINEERING])
        ->and($measurement->assets()->orderBy('plan_set_id')->pluck('plan_set_id')->all())->toBe([$alfa->id, $beta->id]);
});

it('explains on Enviar Medição that no development has a plan in force and refuses the empty sending', function () {
    planCoverageInForceTravelTo('2026-08-10');
    $scenario = planCoverageInForceOperation();
    planCoverageInForcePlan($scenario, 'Torre Alfa', isDefault: true);
    planCoverageInForcePlan($scenario, 'Torre Beta');
    $this->actingAs($scenario['actor']);

    // As duas V1 ainda são rascunho: a seção de arquivos fica vazia, e o aviso
    // diz por quê e onde ativar.
    $page = planCoverageInForceSendPage($scenario['operation'])
        ->assertSee('Ainda sem plano de medição em vigor, não recebem medição neste envio: Torre Alfa, Torre Beta. Ative a versão do plano na aba Versões dos Planos da operação.');

    expect($page->get('data.assets'))->toBe([]);

    $page->call('create');

    expect($page->errors()->toArray())->toBe([
        'data.assets' => ['Nenhum empreendimento desta operação tem plano de medição em vigor: ative a versão do plano (aba Versões dos Planos da operação) antes de enviar medição.'],
    ])
        ->and(planCoverageInForceNotifications())->toBe([])
        ->and(Measurement::query()->count())->toBe(0);
});

it('lists on Enviar Medição only the developments whose plan is not in force yet', function () {
    planCoverageInForceTravelTo('2026-08-10');
    $scenario = planCoverageInForceOperation();
    $alfa = planCoverageInForcePlan($scenario, 'Torre Alfa', isDefault: true);
    $beta = planCoverageInForcePlan($scenario, 'Torre Beta');
    planCoverageInForceActivate($scenario, $alfa);
    $this->actingAs($scenario['actor']);

    $page = planCoverageInForceSendPage($scenario['operation'])
        ->assertSee('Ainda sem plano de medição em vigor, não recebe medição neste envio: Torre Beta. Ative a versão do plano na aba Versões dos Planos da operação.');

    expect(planCoverageInForceOfferedPlans($page))->toBe([$alfa->id]);

    // Com os dois planos em vigor, o aviso some.
    planCoverageInForceActivate($scenario, $beta);

    planCoverageInForceSendPage($scenario['operation'])->assertDontSee('Ainda sem plano de medição em vigor');
});

// ── Editar Medição: a versão substituída com a página aberta ─────────────────

it('refuses the schedule line picked on an open edit page when a revision supersedes the version of the measurement before saving', function () {
    planCoverageInForceTravelTo('2026-08-20');
    $scenario = planCoverageInForceOperation();
    $alfa = planCoverageInForcePlan($scenario, 'Torre Alfa', isDefault: true);
    $v1 = planCoverageInForceActivate($scenario, $alfa);
    $august = planCoverageInForceLine($alfa, '2026-08');
    $september = planCoverageInForceLine($alfa, '2026-09');
    $measurement = planCoverageInForceMeasurement($scenario, '2026-08', [$alfa]);
    $asset = $measurement->assets()->sole();
    $this->actingAs($scenario['actor']);

    // Em 31/08 a pessoa abre o Editar e troca a medição prevista de agosto
    // pela de setembro, da mesma V1: a competência acompanha a linha.
    planCoverageInForceTravelTo('2026-08-31');
    $page = Livewire::test(EditMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->set("data.assets.record-{$asset->id}.plan_line_id", $september->id);

    // Em 01/09, com a página aberta, outra pessoa ativa a V2 do plano.
    planCoverageInForceTravelTo('2026-09-01');
    planCoverageInForceRevise($scenario, $alfa);

    $page->call('save');

    // A troca chega à gravação e é recusada com o motivo, em vez de sumir
    // calada enquanto a competência ia para setembro.
    expect(planCoverageInForceNotificationTitles())->toBe(['Medição não atualizada.'])
        ->and(planCoverageInForceNotificationBody('Medição não atualizada.'))
        ->toBe(sprintf(MeasurementAsset::SUPERSEDED_VERSION_LINE_CHANGE_REFUSAL, 'V1', 'Torre Alfa'))
        ->and($asset->fresh()->only(['plan_version_id', 'plan_line_id', 'line_claim_key']))->toBe([
            'plan_version_id' => $v1->id,
            'plan_line_id' => $august->id,
            'line_claim_key' => $august->lineage_key,
        ])
        ->and($measurement->fresh()->reference_month->toDateString())->toBe('2026-08-01');
});

it('saves the schedule line picked on the edit page while the version of the measurement is still in force', function () {
    planCoverageInForceTravelTo('2026-08-20');
    $scenario = planCoverageInForceOperation();
    $alfa = planCoverageInForcePlan($scenario, 'Torre Alfa', isDefault: true);
    $v1 = planCoverageInForceActivate($scenario, $alfa);
    $september = planCoverageInForceLine($alfa, '2026-09');
    $measurement = planCoverageInForceMeasurement($scenario, '2026-08', [$alfa]);
    $asset = $measurement->assets()->sole();
    $this->actingAs($scenario['actor']);
    planCoverageInForceTravelTo('2026-08-31');

    Livewire::test(EditMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->set("data.assets.record-{$asset->id}.plan_line_id", $september->id)
        ->call('save')
        ->assertHasNoFormErrors();

    expect(planCoverageInForceNotificationTitles())->toBe(['Medição atualizada com sucesso.'])
        ->and($asset->fresh()->only(['plan_version_id', 'plan_line_id', 'line_claim_key']))->toBe([
            'plan_version_id' => $v1->id,
            'plan_line_id' => $september->id,
            'line_claim_key' => $september->lineage_key,
        ])
        ->and($measurement->fresh()->reference_month->toDateString())->toBe('2026-09-01');
});

it('locks the schedule line on an edit page opened after the version of the measurement was superseded', function () {
    planCoverageInForceTravelTo('2026-08-20');
    $scenario = planCoverageInForceOperation();
    $alfa = planCoverageInForcePlan($scenario, 'Torre Alfa', isDefault: true);
    planCoverageInForceActivate($scenario, $alfa);
    $measurement = planCoverageInForceMeasurement($scenario, '2026-08', [$alfa]);
    $asset = $measurement->assets()->sole();
    planCoverageInForceTravelTo('2026-09-01');
    planCoverageInForceRevise($scenario, $alfa);
    $this->actingAs($scenario['actor']);

    $page = Livewire::test(EditMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->assertSee('Travada: a medição foi enviada sob uma versão do plano que já foi substituída. Para medir outra competência, recuse-a e envie uma nova sob a versão vigente.');

    expect($page->instance()->getSchema('form')->getComponentByStatePath("assets.record-{$asset->id}.plan_line_id")?->isDisabled())->toBeTrue();
});
