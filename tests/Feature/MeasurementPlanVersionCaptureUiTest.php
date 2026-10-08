<?php

use App\DTOs\Measurements\MeasurementPhysicalProgress;
use App\Enums\MeasurementPlanRevisionCategory;
use App\Enums\MeasurementPlanVersionStatus;
use App\Filament\Resources\Measurements\Pages\CreateMeasurement;
use App\Filament\Resources\Measurements\Pages\ViewMeasurement;
use App\Models\Construction;
use App\Models\Measurement;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Models\MeasurementPlanVersion;
use App\Models\Operation;
use App\Models\User;
use App\Services\MeasurementPlanVersionService;
use App\Services\MeasurementWorkflow;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Forms\Components\Placeholder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/**
 * A versão do plano que cada arquivo de medição captura, e onde a tela a mostra.
 *
 * Regra escolhida: a captura no envio. O arquivo de cada empreendimento fica
 * ligado, para sempre, à versão do plano vigente quando a medição é enviada: é
 * nela que a Engenharia confere a medição prevista, e é o Fundo de Obra dela
 * que o snapshot congela para o pagamento. Uma revisão ativada depois do envio
 * não alcança a medição; e a medição de uma competência anterior à vigência,
 * enviada depois da ativação, vai para a versão nova -- a aba de versões avisa
 * isso antes da ativação, e o modal de aprovação da Engenharia mostra a versão
 * e o fundo de cada arquivo.
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
});

/**
 * Leva o relógio às 10h de Brasília (13h UTC) do dia: a versão ativada vale a
 * partir do mês desse dia no calendário de negócio.
 */
function planVersionCaptureTravelTo(string $date): void
{
    test()->travelTo(CarbonImmutable::parse("{$date} 13:00:00", 'UTC'));
}

/**
 * Operação em andamento em que quem envia a medição responde por todas as
 * etapas; quem planeja as obras é um administrador.
 *
 * @return array{actor: User, planner: User, operation: Operation}
 */
function planVersionCaptureOperation(): array
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
 * Plano de uma obra da operação criado pelo serviço, com a V1 posta em vigor
 * pelo serviço no mês corrente: o Fundo de Obra informado e 10% previstos por
 * mês nas competências dadas ('Y-m'), com o acumulado somado sobre o avanço
 * inicial.
 *
 * @param  array{planner: User, operation: Operation}  $scenario
 * @param  list<string>  $months
 */
function planVersionCaptureActivePlan(array $scenario, string $development, string $fund, array $months, string $initialPercent = '0.00', ?string $referenceDate = null): MeasurementPlanSet
{
    $construction = Construction::factory()->create([
        'emission_id' => $scenario['operation']->emission_id,
        'development_name' => $development,
    ]);
    $running = (int) MeasurementPhysicalProgress::basisPoints($initialPercent);
    $lines = [];

    foreach (array_values($months) as $index => $month) {
        $running += 1000;
        $lines[] = [
            'sequence_number' => $index + 1,
            'planned_monthly_percent' => '10.00',
            'planned_cumulative_percent' => MeasurementPhysicalProgress::decimal($running),
            'measurement_date' => $month,
        ];
    }

    $service = app(MeasurementPlanVersionService::class);
    $planSet = $service->createPlan($scenario['operation'], $scenario['planner'], [
        'name' => "Plano {$development}",
        'construction_id' => $construction->id,
        'is_default' => ! $scenario['operation']->planSets()->exists(),
        'initial_incurred_amount' => '0.00',
        'initial_physical_progress_percent' => $initialPercent,
        'initial_physical_progress_reference_date' => $referenceDate,
    ], ['construction_fund_amount' => $fund], $lines);
    $draft = planVersionCaptureVersion($planSet, 1);
    $service->activate($draft, $scenario['planner'], (int) $draft->revision);

    return $planSet->fresh();
}

function planVersionCaptureVersion(MeasurementPlanSet $planSet, int $number): MeasurementPlanVersion
{
    return MeasurementPlanVersion::query()
        ->where('plan_set_id', $planSet->id)
        ->where('version_number', $number)
        ->sole();
}

/**
 * Revisão de custo pelo serviço, do rascunho à ativação: o novo Fundo de Obra
 * vale a partir do mês corrente, e a versão vigente passa a substituída.
 *
 * @param  array{planner: User}  $scenario
 */
function planVersionCaptureReviseFund(array $scenario, MeasurementPlanSet $planSet, string $fund): MeasurementPlanVersion
{
    $service = app(MeasurementPlanVersionService::class);
    $draft = $service->createRevision($planSet->fresh(), $scenario['planner'], [
        'revision_category' => MeasurementPlanRevisionCategory::Cost->value,
        'revision_reason' => 'Reajuste do orçamento da obra aprovado pelo comitê.',
    ]);
    $draft = $service->updateDraft($draft, $scenario['planner'], ['construction_fund_amount' => $fund], null, (int) $draft->revision);

    return $service->activate($draft, $scenario['planner'], (int) $draft->revision);
}

/**
 * A medição prevista do mês ('Y-m') numa versão do plano.
 */
function planVersionCaptureLine(MeasurementPlanVersion $version, string $month): MeasurementPlanLine
{
    return MeasurementPlanLine::query()
        ->where('plan_version_id', $version->id)
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
function planVersionCaptureMeasurement(array $scenario, string $month, array $planSets): Measurement
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
        $active = MeasurementPlanVersion::query()->where('plan_set_id', $planSet->id)->active()->sole();
        $path = "nimbus_docs/measurements/assets/plan-version-capture-{$measurement->id}-{$planSet->id}.pdf";
        Storage::disk('local')->put($path, "%PDF-1.7 medição {$month} de {$planSet->name}");
        $measurement->assets()->create([
            'plan_set_id' => $planSet->id,
            'plan_line_id' => planVersionCaptureLine($active, $month)->id,
            'storage_path' => $path,
            'storage_disk' => 'local',
        ]);
    }

    app(MeasurementWorkflow::class)->startReview($measurement->fresh(), $scenario['actor']);

    return $measurement->fresh();
}

/**
 * O bloco de contexto de um empreendimento no modal de aprovação da Engenharia
 * montado: o nome dele e o valor de cada termo, na ordem da tela. O modal vai
 * na resposta como JSON escapado; o conteúdo do bloco, não.
 *
 * @return array{development: string, cells: array<string, string>}
 */
function planVersionCaptureApprovalContext(Testable $component, int $planSetId): array
{
    $name = $component->instance()->getMountedActionSchemaName();
    $content = null;

    foreach ($component->instance()->{$name}->getFlatComponents(withHidden: true) as $key => $item) {
        if (str_ends_with($key, "physical_progress_context.{$planSetId}") && $item instanceof Placeholder) {
            $content = (string) $item->getContent();
        }
    }

    expect($content)->not->toBeNull();

    $document = new DOMDocument;
    $document->loadHTML('<?xml encoding="utf-8" ?><div>'.$content.'</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($document);
    $cells = [];

    foreach ($xpath->query('//dl/div') as $pair) {
        $cells[trim($xpath->query('dt', $pair)->item(0)->textContent)] = trim($xpath->query('dd', $pair)->item(0)->textContent);
    }

    return [
        'development' => trim($xpath->query('//p')->item(0)->textContent),
        'cells' => $cells,
    ];
}

/**
 * O que o snapshot da Engenharia congelou de cada empreendimento: a versão, o
 * Fundo de Obra dela e a medição prevista aprovada, na ordem dos planos.
 *
 * @return list<array{0: int, 1: int, 2: int, 3: string|null, 4: string, 5: string}>
 */
function planVersionCaptureSnapshot(Measurement $measurement): array
{
    return collect($measurement->fresh()->engineering_snapshot['plan_sets'])
        ->sortBy('plan_set_id')
        ->map(fn (array $entry): array => [
            $entry['plan_set_id'],
            $entry['plan_version_id'],
            $entry['plan_version_number'],
            $entry['construction_fund_amount'],
            $entry['measurement_date'],
            $entry['plan_line_lineage_key'],
        ])
        ->values()
        ->all();
}

// ── Modal de aprovação da Engenharia ─────────────────────────────────────────

it('shows in the Engineering approval modal the plan version each file was sent under, with its fund, even after a later revision', function () {
    planVersionCaptureTravelTo('2026-06-10');
    $scenario = planVersionCaptureOperation();
    $aurora = planVersionCaptureActivePlan($scenario, 'Torre Aurora', '20000000.00', ['2026-07', '2026-08', '2026-09', '2026-10']);
    $boreal = planVersionCaptureActivePlan($scenario, 'Torre Boreal', '8000000.00', ['2026-07', '2026-08', '2026-09', '2026-10']);
    $borealV1 = planVersionCaptureVersion($boreal, 1);

    // A Torre Aurora passa de R$ 20 milhões para R$ 23 milhões na V2, em vigor
    // desde 07/2026; a Torre Boreal segue na V1.
    planVersionCaptureTravelTo('2026-07-02');
    $auroraV2 = planVersionCaptureReviseFund($scenario, $aurora, '23000000.00');

    // A medição de julho, enviada em 05/08 com os dois arquivos, captura a
    // versão vigente de cada plano no envio: a V2 da Aurora e a V1 da Boreal.
    planVersionCaptureTravelTo('2026-08-05');
    $july = planVersionCaptureMeasurement($scenario, '2026-07', [$aurora, $boreal]);

    // Com a medição ainda na Engenharia, a Aurora ganha a V3 (R$ 25 milhões),
    // em vigor desde 08/2026: o arquivo de julho continua na V2.
    $auroraV3 = planVersionCaptureReviseFund($scenario, $aurora, '25000000.00');
    $this->actingAs($scenario['actor']);

    $component = Livewire::test(ViewMeasurement::class, ['record' => $july->getRouteKey()])->mountAction('approve');

    expect($auroraV3->status)->toBe(MeasurementPlanVersionStatus::Active)
        ->and($july->assets()->orderBy('plan_set_id')->pluck('plan_version_id')->all())->toBe([$auroraV2->id, $borealV1->id])
        ->and(planVersionCaptureApprovalContext($component, $aurora->id))->toBe([
            'development' => 'Torre Aurora',
            'cells' => [
                'Avanço físico inicial' => '0,00%',
                'Medido no sistema' => '0,00%',
                'Avanço físico atual' => '0,00%',
                'Máximo restante' => '100,00%',
                'Versão do plano' => 'V2 · Fundo de Obra R$ 23.000.000,00',
            ],
        ])
        ->and(planVersionCaptureApprovalContext($component, $boreal->id))->toBe([
            'development' => 'Torre Boreal',
            'cells' => [
                'Avanço físico inicial' => '0,00%',
                'Medido no sistema' => '0,00%',
                'Avanço físico atual' => '0,00%',
                'Máximo restante' => '100,00%',
                'Versão do plano' => 'V1 · Fundo de Obra R$ 8.000.000,00',
            ],
        ]);

    // O que o modal mostra é o que a aprovação congela no snapshot.
    $component->setActionData(['realized' => [$aurora->id => 10, $boreal->id => 10]])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect($july->fresh()->current_stage)->toBe(2)
        ->and(planVersionCaptureSnapshot($july))->toBe([
            [$aurora->id, $auroraV2->id, 2, '23000000.00', '2026-07-01', planVersionCaptureLine($auroraV2, '2026-07')->lineage_key],
            [$boreal->id, $borealV1->id, 1, '8000000.00', '2026-07-01', planVersionCaptureLine($borealV1, '2026-07')->lineage_key],
        ]);
});

// ── Competência anterior à vigência enviada depois da ativação ───────────────

it('binds the June measurement sent after the July cost revision took effect to the revision and its fund', function () {
    // Torre Aurora com 30% executados até 31/05/2026 e a V1 (R$ 20 milhões)
    // vigente desde 06/2026: 10% previstos por mês de 06/2026 a 08/2026.
    planVersionCaptureTravelTo('2026-06-01');
    $scenario = planVersionCaptureOperation();
    $aurora = planVersionCaptureActivePlan($scenario, 'Torre Aurora', '20000000.00', ['2026-06', '2026-07', '2026-08'], '30.00', '2026-05-31');
    $juneV1 = planVersionCaptureLine(planVersionCaptureVersion($aurora, 1), '2026-06');

    // 02/07: a revisão de custo (R$ 23 milhões) passa a valer desde 07/2026,
    // com junho ainda por medir.
    planVersionCaptureTravelTo('2026-07-02');
    $auroraV2 = planVersionCaptureReviseFund($scenario, $aurora, '23000000.00');
    $juneV2 = planVersionCaptureLine($auroraV2, '2026-06');

    // Regra escolhida -- captura no envio: o arquivo fica na versão vigente
    // quando a medição é enviada, não na que planejou a competência. Junho é
    // anterior à vigência da V2, mas a medição dele só sai em 05/07, depois da
    // ativação: o Enviar Medição oferece a junho da V2 -- a mesma medição
    // prevista da V1, pela linhagem --, e a medição leva a V2 e o Fundo de
    // Obra de R$ 23 milhões. A aba de versões avisou isso antes da ativação.
    planVersionCaptureTravelTo('2026-07-05');
    $this->actingAs($scenario['actor']);

    Livewire::test(CreateMeasurement::class)
        ->fillForm(['operation_id' => $scenario['operation']->id])
        ->fillForm(['reference_month' => '2026-06-01', 'assets' => [[
            'plan_set_id' => $aurora->id,
            'plan_line_id' => $juneV2->id,
            'storage_path' => [UploadedFile::fake()->createWithContent('medicao-junho.pdf', '%PDF-1.7 medição de junho da Torre Aurora')],
        ]]])
        ->call('create')
        ->assertHasNoFormErrors();

    $june = $scenario['operation']->measurements()->sole();
    $asset = $june->assets()->sole();

    expect($june->reference_month->toDateString())->toBe('2026-06-01')
        ->and($asset->plan_version_id)->toBe($auroraV2->id)
        ->and($asset->plan_line_id)->toBe($juneV2->id)
        ->and($asset->line_claim_key)->toBe($juneV1->lineage_key);

    // A Engenharia vê a V2 e o fundo dela no modal, e aprova junho sob ela.
    $component = Livewire::test(ViewMeasurement::class, ['record' => $june->getRouteKey()])->mountAction('approve');

    expect(planVersionCaptureApprovalContext($component, $aurora->id))->toBe([
        'development' => 'Torre Aurora',
        'cells' => [
            'Avanço físico inicial' => '30,00% em 31/05/2026',
            'Medido no sistema' => '0,00%',
            'Avanço físico atual' => '30,00%',
            'Máximo restante' => '70,00%',
            'Versão do plano' => 'V2 · Fundo de Obra R$ 23.000.000,00',
        ],
    ]);

    $component->setActionData(['realized' => [$aurora->id => 10]])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    // O snapshot congela a V2 e o Fundo de Obra dela; a medição ocupa a junho
    // da V2, e a da V1 fica sem medição.
    expect(planVersionCaptureSnapshot($june))->toBe([
        [$aurora->id, $auroraV2->id, 2, '23000000.00', '2026-06-01', $juneV1->lineage_key],
    ])
        ->and($juneV2->fresh()->measurement_id)->toBe($june->id)
        ->and($juneV1->fresh()->measurement_id)->toBeNull();

    // Fora do modal, para sempre: o arquivo de junho mostra, na própria
    // medição, a versão em que foi enviado e o Fundo de Obra dela.
    Livewire::test(ViewMeasurement::class, ['record' => $june->getRouteKey()])
        ->assertSee('Versão do plano')
        ->assertSee('V2 · Fundo de Obra R$ 23.000.000,00');
});
