<?php

use App\Enums\MeasurementPlanRevisionCategory;
use App\Enums\MeasurementPlanVersionStatus;
use App\Exceptions\MeasurementWorkflowException;
use App\Filament\Resources\Measurements\Pages\CreateMeasurement;
use App\Filament\Resources\Measurements\Pages\EditMeasurement;
use App\Filament\Resources\Operations\Pages\EditOperation;
use App\Filament\Resources\Operations\Pages\ViewOperation;
use App\Filament\Resources\Operations\RelationManagers\PlanLinesRelationManager;
use App\Filament\Resources\Operations\RelationManagers\PlanSetsRelationManager;
use App\Filament\Resources\Operations\RelationManagers\PlanVersionsRelationManager;
use App\Models\Construction;
use App\Models\Measurement;
use App\Models\MeasurementAsset;
use App\Models\MeasurementPayment;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Models\MeasurementPlanVersion;
use App\Models\Operation;
use App\Models\ResponsibilityDelegation;
use App\Models\User;
use App\Services\MeasurementPlanVersionService;
use App\Services\MeasurementWorkflow;
use App\Services\Security\ClamAvFileScanner;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\MeasurementPhysicalProgressScenario as PhysicalScenario;
use Tests\Support\MeasurementPlanVersionFixture;

/**
 * O que a medição já usou não muda por fora do fluxo.
 *
 * Desde que a recusa terminal deixou de existir para a medição com pagamento
 * (P1-01), a saída dela é a Engenharia corrigir e aprovar de novo. Isso só
 * funciona se nada do que o pagamento usou -- os arquivos, a obra e a linha de
 * cada arquivo, a competência -- puder mudar por baixo dele, e se a Engenharia
 * conferir a mesma cobertura que o pagamento já usou, e não a das obras que
 * entraram na operação depois.
 *
 * Também aqui: as recusas do domínio nos planos e nas linhas da operação
 * chegam à pessoa como aviso, e as recusas de arquivo dizem de qual
 * empreendimento são e deixam rastro no log.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    Notification::fake();
});

function integrityGuardsUser(string $role = 'editor'): User
{
    $user = User::factory()->withTwoFactor()->create(['is_active' => true, 'approved_at' => now()]);
    $user->assignRole($role);

    return $user;
}

/**
 * Notificações do Filament da última requisição, lidas sem consumir: o
 * `assertNotified()` compara só o título e esvazia a sessão.
 *
 * @return list<array<string, mixed>>
 */
function integrityGuardsNotifications(): array
{
    return array_values(session()->get('filament.claimed_notifications') ?? session()->get('filament.notifications') ?? []);
}

function integrityGuardsNotificationBody(string $title): ?string
{
    $body = collect(integrityGuardsNotifications())->firstWhere('title', $title)['body'] ?? null;

    return $body === null ? null : (string) $body;
}

/**
 * Registros de log do nível pedido emitidos a partir daqui.
 *
 * @return ArrayObject<int, array{message: string, context: array<string, mixed>}>
 */
function integrityGuardsLogs(string $level): ArrayObject
{
    $logs = new ArrayObject;

    Log::listen(function (MessageLogged $logged) use ($logs, $level): void {
        if ($logged->level === $level) {
            $logs->append(['message' => $logged->message, 'context' => $logged->context]);
        }
    });

    return $logs;
}

/**
 * Operação com Torre Alfa e Torre Beta, duas competências no cronograma de
 * cada obra (08 e 09/2026) e a medição de agosto enviada com um arquivo por
 * obra, aguardando a Engenharia. Quem envia é um editor que ocupa os sete
 * papéis da operação. O cronograma e o Fundo de Obra estão na V1 de cada
 * plano, vigente desde 08/2026: é a versão que a medição leva.
 *
 * @return array{actor: User, operation: Operation, measurement: Measurement, planSets: list<MeasurementPlanSet>, august: list<MeasurementPlanLine>, september: list<MeasurementPlanLine>, assets: list<MeasurementAsset>}
 */
function integrityGuardsTwoDevelopments(): array
{
    config()->set('filesystems.private_disk', 'local');

    $actor = integrityGuardsUser();
    $operation = Operation::factory()->create(array_merge(
        ['status' => 'active'],
        array_fill_keys(Operation::RESPONSIBILITY_FIELDS, $actor->id),
    ));
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->id,
        'reference_month' => '2026-08-01',
        'status' => 'pending',
        'current_stage' => MeasurementWorkflow::STAGE_ENGINEERING,
        'storage_path' => null,
        'filename' => null,
        'uploaded_by' => $actor->id,
    ]);
    $planSets = [];
    $august = [];
    $september = [];
    $assets = [];

    foreach (['Torre Alfa' => '10000.00', 'Torre Beta' => '20000.00'] as $name => $fund) {
        $planSet = MeasurementPlanSet::factory()->withConstructionFund($fund)->create([
            'operation_id' => $operation->id,
            'construction_id' => Construction::factory()->create(['emission_id' => $operation->emission_id, 'development_name' => $name])->id,
            'name' => "Plano {$name}",
            'is_default' => $planSets === [],
            'initial_incurred_amount' => '0.00',
        ]);

        foreach (['2026-08-01', '2026-09-01'] as $index => $date) {
            $line = MeasurementPlanLine::factory()->create([
                'operation_id' => $operation->id,
                'plan_set_id' => $planSet->id,
                'sequence_number' => $index + 1,
                'measurement_date' => $date,
                'planned_monthly_percent' => 10,
                'planned_cumulative_percent' => 10 * ($index + 1),
                'initial_realized_cumulative_percent' => 0,
                'realized_monthly_percent' => 0,
                'realized_cumulative_percent' => 0,
            ]);

            if ($index === 0) {
                $august[] = $line;
            } else {
                $september[] = $line;
            }
        }

        // As linhas entram no rascunho da V1, e o plano só recebe arquivo de
        // medição depois que ela fica vigente.
        MeasurementPlanVersionFixture::activate($planSet);

        $path = "nimbus_docs/measurements/assets/integrity-{$measurement->id}-{$planSet->id}.pdf";
        Storage::disk('local')->put($path, "%PDF-1.7 medição de agosto da {$name}");
        $assets[] = $measurement->assets()->create([
            'plan_set_id' => $planSet->id,
            'plan_line_id' => end($august)->id,
            'storage_path' => $path,
            'storage_disk' => 'local',
        ]);
        $planSets[] = $planSet;
    }

    app(MeasurementWorkflow::class)->startReview($measurement->fresh(), $actor);

    return [
        'actor' => $actor,
        'operation' => $operation,
        'measurement' => $measurement->fresh(),
        'planSets' => $planSets,
        'august' => $august,
        'september' => $september,
        'assets' => $assets,
    ];
}

/**
 * @param  array{actor: User, measurement: Measurement, planSets: list<MeasurementPlanSet>}  $scenario
 */
function integrityGuardsApproveEngineering(array $scenario, int $percent = 10): void
{
    app(MeasurementWorkflow::class)->approve(
        $scenario['measurement']->fresh(),
        $scenario['actor'],
        engineeringProgress: collect($scenario['planSets'])
            ->mapWithKeys(fn (MeasurementPlanSet $planSet): array => [$planSet->id => $percent])
            ->all(),
    );
}

/**
 * A medição de agosto paga nas duas obras -- R$ 1.000,00 e R$ 2.000,00, o
 * esperado pelo avanço de 10% -- e devolvida da Finalização à Engenharia, sem
 * comprovante ainda. É o estado em que a recusa terminal deixou de existir.
 *
 * @return array{actor: User, operation: Operation, measurement: Measurement, planSets: list<MeasurementPlanSet>, august: list<MeasurementPlanLine>, september: list<MeasurementPlanLine>, assets: list<MeasurementAsset>, payments: list<MeasurementPayment>}
 */
function integrityGuardsPaidReturned(): array
{
    $scenario = integrityGuardsTwoDevelopments();
    $workflow = app(MeasurementWorkflow::class);
    [$alfa, $beta] = $scenario['planSets'];

    integrityGuardsApproveEngineering($scenario);
    $workflow->approve($scenario['measurement']->fresh(), $scenario['actor']);
    $workflow->approve($scenario['measurement']->fresh(), $scenario['actor']);
    $scenario['payments'] = $workflow->registerPayments($scenario['measurement']->fresh(), $scenario['actor'], [
        ['plan_set_id' => $alfa->id, 'pay_date' => '2026-08-31', 'amount' => '1000.00'],
        ['plan_set_id' => $beta->id, 'pay_date' => '2026-08-31', 'amount' => '2000.00'],
    ])->all();
    $workflow->approve($scenario['measurement']->fresh(), $scenario['actor']);
    $workflow->returnToStage($scenario['measurement']->fresh(), $scenario['actor'], MeasurementWorkflow::STAGE_ENGINEERING, 'Rever o percentual medido.');
    $scenario['measurement'] = $scenario['measurement']->fresh();

    return $scenario;
}

/**
 * Replanejamento do plano em vigor: a revisão nasce em rascunho, copiada da
 * vigente, e o rascunho recebe o Fundo de Obra novo ou o previsto mensal novo
 * da linha de agosto. É o único caminho para mudar o que a versão vigente
 * guarda; a ativação é que decide se a mudança vale.
 *
 * @param  array{actor: User, august: list<MeasurementPlanLine>}  $scenario
 */
function integrityGuardsReplanningDraft(array $scenario, MeasurementPlanSet $planSet, string $change): MeasurementPlanVersion
{
    $service = app(MeasurementPlanVersionService::class);
    $august = collect($scenario['august'])->firstWhere('plan_set_id', $planSet->id);
    $draft = $service->createRevision($planSet, $scenario['actor'], [
        'revision_category' => $change === 'fundo de obra' ? MeasurementPlanRevisionCategory::Cost->value : MeasurementPlanRevisionCategory::Schedule->value,
        'revision_reason' => 'Replanejamento pedido pela construtora.',
    ]);

    return match ($change) {
        'fundo de obra' => $service->updateDraft($draft, $scenario['actor'], ['construction_fund_amount' => '99999.00'], null, (int) $draft->revision),
        'linha do cronograma' => $service->updateDraft(
            $draft,
            $scenario['actor'],
            [],
            $draft->lines()->orderBy('sequence_number')->get()
                ->map(fn (MeasurementPlanLine $line): array => [
                    'id' => $line->id,
                    'sequence_number' => $line->sequence_number,
                    'planned_monthly_percent' => $line->lineage_key === $august->lineage_key ? 15 : $line->planned_monthly_percent,
                    'planned_cumulative_percent' => $line->planned_cumulative_percent,
                    'measurement_date' => $line->measurement_date->toDateString(),
                ])
                ->all(),
            (int) $draft->revision,
        ),
    };
}

// ── Arquivos, obra, linha e competência da medição paga ──────────────────────

it('refuses to remove a file of a paid measurement, by the model and by a forged edit', function () {
    $scenario = integrityGuardsPaidReturned();
    [, $beta] = $scenario['assets'];
    $refusal = 'O arquivo de uma medição com pagamento registrado não pode ser removido: o pagamento depende dele.';

    expect($scenario['measurement']->status)->toBe('in_review')
        ->and($scenario['measurement']->payments()->count())->toBe(2)
        ->and(fn () => $beta->fresh()->delete())->toThrow(MeasurementWorkflowException::class, $refusal);

    $this->actingAs($scenario['actor']);
    $page = Livewire::test(EditMeasurement::class, ['record' => $scenario['measurement']->getRouteKey()]);
    $page->set('data.assets', collect($page->get('data.assets'))
        ->reject(fn (array $item): bool => (int) $item['plan_set_id'] === (int) $beta->plan_set_id)
        ->all())
        ->call('save');

    expect(integrityGuardsNotificationBody('Medição não atualizada.'))->toBe($refusal)
        ->and($scenario['measurement']->assets()->orderBy('id')->pluck('id')->all())->toBe(array_map(fn (MeasurementAsset $asset): int => $asset->id, $scenario['assets']))
        ->and(Activity::query()
            ->where('log_name', 'measurement_assets')
            ->where('description', 'measurement_asset_removed')
            ->exists())->toBeFalse();
});

it('refuses to move the file of a paid measurement to another development or schedule line', function (string $change) {
    $scenario = integrityGuardsPaidReturned();
    [$alfa] = $scenario['assets'];
    $before = $alfa->fresh()->only(['plan_set_id', 'plan_line_id']);
    $attributes = match ($change) {
        'linha do cronograma' => ['plan_line_id' => $scenario['september'][0]->id],
        'empreendimento' => ['plan_set_id' => $scenario['planSets'][1]->id, 'plan_line_id' => $scenario['september'][1]->id],
    };

    expect(fn () => $alfa->fresh()->update($attributes))->toThrow(
        MeasurementWorkflowException::class,
        'A obra e a linha do cronograma de uma medição com pagamento registrado não podem ser alteradas: o pagamento continua vinculado a elas.',
    );

    expect($alfa->fresh()->only(['plan_set_id', 'plan_line_id']))->toBe($before);
})->with(['linha do cronograma', 'empreendimento']);

it('still replaces the file of a paid measurement on its own schedule line', function () {
    $scenario = integrityGuardsPaidReturned();
    [$alfa] = $scenario['assets'];
    $this->actingAs($scenario['actor']);

    Livewire::test(EditMeasurement::class, ['record' => $scenario['measurement']->getRouteKey()])
        ->fillForm(['assets' => ["record-{$alfa->id}" => [
            'plan_set_id' => $alfa->plan_set_id,
            'plan_line_id' => $alfa->plan_line_id,
            'storage_path' => [UploadedFile::fake()->createWithContent('alfa.pdf', '%PDF-1.7 versão corrigida da Torre Alfa')],
        ]]])
        ->call('save')
        ->assertHasNoFormErrors();

    $alfa->refresh();

    expect($alfa->sha256)->toBe(hash('sha256', '%PDF-1.7 versão corrigida da Torre Alfa'))
        ->and($alfa->plan_set_id)->toBe($scenario['planSets'][0]->id)
        ->and($alfa->plan_line_id)->toBe($scenario['august'][0]->id);
});

it('refuses to change the competence of a paid measurement and keeps its other data editable', function () {
    $scenario = integrityGuardsPaidReturned();

    expect(fn () => $scenario['measurement']->fresh()->update(['reference_month' => '2026-09-01']))->toThrow(
        MeasurementWorkflowException::class,
        'A competência de uma medição com pagamento registrado não pode ser alterada: o pagamento continua vinculado a ela.',
    );

    $scenario['measurement']->fresh()->update(['notes' => 'Planilha corrigida pela construtora.']);

    expect($scenario['measurement']->fresh()->reference_month->toDateString())->toBe('2026-08-01')
        ->and($scenario['measurement']->fresh()->notes)->toBe('Planilha corrigida pela construtora.');
});

it('keeps a paid measurement on its competence and schedule lines when the edit form is forged', function () {
    $scenario = integrityGuardsPaidReturned();
    [$alfa, $beta] = $scenario['assets'];
    $this->actingAs($scenario['actor']);

    Livewire::test(EditMeasurement::class, ['record' => $scenario['measurement']->getRouteKey()])
        ->set('data.reference_month', '2026-09-01')
        ->set("data.assets.record-{$alfa->id}.plan_line_id", $scenario['september'][0]->id)
        ->set("data.assets.record-{$beta->id}.plan_line_id", $scenario['september'][1]->id)
        ->call('save');

    expect($scenario['measurement']->fresh()->reference_month->toDateString())->toBe('2026-08-01')
        ->and($alfa->fresh()->plan_line_id)->toBe($scenario['august'][0]->id)
        ->and($beta->fresh()->plan_line_id)->toBe($scenario['august'][1]->id)
        ->and(MeasurementPlanLine::query()->whereKey($scenario['august'][0]->id)->availableForMeasurement()->exists())->toBeFalse();
});

it('locks the competence and the schedule lines and keeps every file of a paid measurement in the edit form', function () {
    $scenario = integrityGuardsPaidReturned();
    $this->actingAs($scenario['actor']);

    $page = Livewire::test(EditMeasurement::class, ['record' => $scenario['measurement']->getRouteKey()])
        ->assertFormFieldDisabled('reference_month');
    $schema = $page->instance()->getSchema('form');
    $repeater = $schema->getComponentByStatePath('assets');

    expect($repeater)->toBeInstanceOf(Repeater::class)
        ->and($repeater->isDeletable())->toBeFalse()
        ->and(array_map(
            fn (string $key): ?bool => $schema->getComponentByStatePath("assets.{$key}.plan_line_id")?->isDisabled(),
            array_keys($page->get('data.assets')),
        ))->toBe([true, true]);
});

it('keeps the competence, the schedule lines and the removal open while the measurement has no payment', function () {
    $scenario = integrityGuardsTwoDevelopments();
    $this->actingAs($scenario['actor']);

    $page = Livewire::test(EditMeasurement::class, ['record' => $scenario['measurement']->getRouteKey()])
        ->assertFormFieldEnabled('reference_month');
    $schema = $page->instance()->getSchema('form');

    expect($schema->getComponentByStatePath('assets')->isDeletable())->toBeTrue()
        ->and(array_map(
            fn (string $key): ?bool => $schema->getComponentByStatePath("assets.{$key}.plan_line_id")?->isDisabled(),
            array_keys($page->get('data.assets')),
        ))->toBe([false, false]);
});

// ── Cobertura da Engenharia ──────────────────────────────────────────────────

it('reapproves a paid measurement returned to Engineering on the developments of its own files after a new development joins the operation', function () {
    $scenario = integrityGuardsPaidReturned();
    $workflow = app(MeasurementWorkflow::class);
    [$alfa, $beta] = $scenario['planSets'];

    // A obra nova entra na operação depois do pagamento, e a medição de
    // setembro já a aprova: o plano dela não pode mais ser excluído.
    $gamma = MeasurementPlanSet::factory()->withConstructionFund('30000.00')->create([
        'operation_id' => $scenario['operation']->id,
        'construction_id' => Construction::factory()->create(['emission_id' => $scenario['operation']->emission_id, 'development_name' => 'Torre Gama'])->id,
        'name' => 'Plano Torre Gama',
        'initial_incurred_amount' => '0.00',
    ]);
    $gammaLine = MeasurementPlanLine::factory()->create([
        'operation_id' => $scenario['operation']->id,
        'plan_set_id' => $gamma->id,
        'sequence_number' => 1,
        'measurement_date' => '2026-09-01',
        'initial_realized_cumulative_percent' => 0,
        'realized_monthly_percent' => 0,
        'realized_cumulative_percent' => 0,
    ]);
    MeasurementPlanVersionFixture::activate($gamma);
    $september = Measurement::factory()->create([
        'operation_id' => $scenario['operation']->id,
        'reference_month' => '2026-09-01',
        'status' => 'pending',
        'current_stage' => MeasurementWorkflow::STAGE_ENGINEERING,
        'storage_path' => null,
        'filename' => null,
        'uploaded_by' => $scenario['actor']->id,
    ]);

    foreach ([[$alfa, $scenario['september'][0]], [$beta, $scenario['september'][1]], [$gamma, $gammaLine]] as [$planSet, $line]) {
        $path = "nimbus_docs/measurements/assets/integrity-september-{$planSet->id}.pdf";
        Storage::disk('local')->put($path, "%PDF-1.7 medição de setembro de {$planSet->name}");
        $september->assets()->create(['plan_set_id' => $planSet->id, 'plan_line_id' => $line->id, 'storage_path' => $path, 'storage_disk' => 'local']);
    }

    $workflow->startReview($september->fresh(), $scenario['actor']);
    $workflow->approve($september->fresh(), $scenario['actor'], engineeringProgress: [$alfa->id => 5, $beta->id => 5, $gamma->id => 5]);

    expect(fn () => $gamma->fresh()->delete())->toThrow(MeasurementWorkflowException::class);

    // A medição paga volta a ser aprovada com os empreendimentos dos próprios
    // arquivos, e a etapa Pagamento aprova com os pagamentos que já existiam.
    integrityGuardsApproveEngineering($scenario, 10);
    $workflow->approve($scenario['measurement']->fresh(), $scenario['actor']);
    $workflow->approve($scenario['measurement']->fresh(), $scenario['actor']);
    $workflow->approve($scenario['measurement']->fresh(), $scenario['actor']);

    $measurement = $scenario['measurement']->fresh();

    expect($measurement->status)->toBe('awaiting_receipt')
        ->and($measurement->current_stage)->toBe(MeasurementWorkflow::STAGE_FINALIZATION)
        ->and(collect($measurement->engineering_snapshot['plan_sets'])->pluck('plan_set_id')->all())->toBe([$alfa->id, $beta->id])
        ->and(MeasurementPayment::query()->where('measurement_id', $measurement->id)->orderBy('id')->pluck('plan_set_id')->all())->toBe([$alfa->id, $beta->id])
        ->and($measurement->reviewForStage(MeasurementWorkflow::STAGE_PAYMENT)?->status)->toBe('approved');
});

it('still requires a file for every development in force when a measurement without payment was sent', function () {
    // Agosto: a V1 da obra nova, ativada agora, vale a partir de 08/2026. O
    // cronograma dela começa em setembro: agosto já tem medição de pé sem o
    // arquivo dela, e uma medição prevista em agosto nunca seria medida.
    $this->travelTo(CarbonImmutable::parse('2026-08-20 10:00:00', 'America/Sao_Paulo'));
    $scenario = integrityGuardsTwoDevelopments();
    $workflow = app(MeasurementWorkflow::class);
    [$alfa, $beta] = $scenario['planSets'];
    $gamma = MeasurementPlanSet::factory()->withConstructionFund('30000.00')->create([
        'operation_id' => $scenario['operation']->id,
        'construction_id' => Construction::factory()->create(['emission_id' => $scenario['operation']->emission_id, 'development_name' => 'Torre Gama'])->id,
        'name' => 'Plano Torre Gama',
        'initial_incurred_amount' => '0.00',
    ]);
    MeasurementPlanLine::factory()->create([
        'operation_id' => $scenario['operation']->id,
        'plan_set_id' => $gamma->id,
        'sequence_number' => 1,
        'measurement_date' => '2026-09-01',
        'planned_monthly_percent' => 10,
        'planned_cumulative_percent' => 10,
        'initial_realized_cumulative_percent' => 0,
        'realized_monthly_percent' => 0,
        'realized_cumulative_percent' => 0,
    ]);
    $gammaDraft = $gamma->draftVersion()->firstOrFail();
    $errors = [];

    // A medição de agosto aguarda a Engenharia sem o arquivo da Torre Gama, e
    // o plano dela entra em vigor mesmo assim: a medição foi enviada antes, e
    // a Engenharia não exige dela o arquivo que o Editar não acrescenta.
    $gammaV1 = app(MeasurementPlanVersionService::class)->activate($gammaDraft, $scenario['actor'], (int) $gammaDraft->revision);
    integrityGuardsApproveEngineering($scenario, 10);

    expect($gammaV1->status)->toBe(MeasurementPlanVersionStatus::Active)
        ->and(collect($scenario['measurement']->fresh()->engineering_snapshot['plan_sets'])->pluck('plan_set_id')->all())->toBe([$alfa->id, $beta->id]);

    // A medição de setembro foi enviada com a Torre Gama já em vigor: a
    // Engenharia continua exigindo dela o arquivo de toda obra vigente.
    $september = Measurement::factory()->create([
        'operation_id' => $scenario['operation']->id,
        'reference_month' => '2026-09-01',
        'status' => 'pending',
        'current_stage' => MeasurementWorkflow::STAGE_ENGINEERING,
        'storage_path' => null,
        'filename' => null,
        'uploaded_by' => $scenario['actor']->id,
    ]);

    foreach ([[$alfa, $scenario['september'][0]], [$beta, $scenario['september'][1]]] as [$planSet, $line]) {
        $path = "nimbus_docs/measurements/assets/integrity-september-{$planSet->id}.pdf";
        Storage::disk('local')->put($path, "%PDF-1.7 medição de setembro de {$planSet->name}");
        $september->assets()->create(['plan_set_id' => $planSet->id, 'plan_line_id' => $line->id, 'storage_path' => $path, 'storage_disk' => 'local']);
    }

    $workflow->startReview($september->fresh(), $scenario['actor']);

    try {
        $workflow->approve($september->fresh(), $scenario['actor'], engineeringProgress: [$alfa->id => 10, $beta->id => 10]);
    } catch (ValidationException $exception) {
        $errors = $exception->errors();
    }

    expect($errors)->toBe([
        'assets.coverage' => ['Envie exatamente um arquivo para cada empreendimento da operação.'],
        "assets.{$gamma->id}" => ['Envie o arquivo da medição para Torre Gama.'],
    ])
        ->and($september->fresh()->hasApprovedEngineering())->toBeFalse();
});

// ── Recusas visíveis nos planos e nas linhas da operação ─────────────────────

it('explains why a schedule line used by an approved Engineering keeps its planned values', function () {
    // Setembro: a competência de agosto, que a Engenharia aprovou, já passou, e
    // uma revisão ativada agora valeria a partir de setembro.
    $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00:00', 'America/Sao_Paulo'));
    $scenario = integrityGuardsTwoDevelopments();
    integrityGuardsApproveEngineering($scenario);
    [$alfa] = $scenario['planSets'];
    $line = $scenario['august'][0];
    $this->actingAs($scenario['actor']);

    // A aba do cronograma só acompanha: o previsto não se edita na linha da
    // versão vigente, e o modelo recusa a escrita direta com o motivo.
    Livewire::test(PlanLinesRelationManager::class, ['ownerRecord' => $scenario['operation'], 'pageClass' => ViewOperation::class])
        ->assertTableActionDoesNotExist('editPlanned', record: $line);

    expect(fn () => $line->fresh()->update(['planned_monthly_percent' => 12]))
        ->toThrow(MeasurementWorkflowException::class, 'A linha de cronograma usada por uma Engenharia aprovada está bloqueada.');

    // O previsto muda só numa revisão -- e a revisão que reescreve agosto é
    // recusada na ativação, com o motivo, mesmo com a competência já passada.
    $draft = integrityGuardsReplanningDraft($scenario, $alfa, 'linha do cronograma');

    Livewire::test(PlanVersionsRelationManager::class, ['ownerRecord' => $scenario['operation'], 'pageClass' => ViewOperation::class])
        ->callTableAction('activateVersion', $draft)
        ->assertActionHalted(TestAction::make('activateVersion')->table($draft));

    expect(integrityGuardsNotificationBody('Versão do plano não alterada.'))
        ->toBe('A medição prevista 01 (08/2026) é anterior à vigência 09/2026 e precisa continuar igual à da V1: a revisão não reescreve competências passadas.')
        ->and($draft->fresh()->status)->toBe(MeasurementPlanVersionStatus::Draft)
        ->and($alfa->activeVersion()->value('id'))->toBe($line->plan_version_id)
        ->and($line->fresh()->planned_monthly_percent)->toBe('10.00');
});

it('explains why a plan covered by an approved Engineering keeps its context', function (string $change) {
    // Agosto: uma revisão ativada agora valeria a partir de agosto, a mesma
    // competência que a Engenharia aprovou.
    $this->travelTo(CarbonImmutable::parse('2026-08-20 10:00:00', 'America/Sao_Paulo'));
    $scenario = integrityGuardsTwoDevelopments();
    integrityGuardsApproveEngineering($scenario);
    [$alfa] = $scenario['planSets'];
    $line = $scenario['august'][0];
    $captured = MeasurementPlanVersion::query()->findOrFail($scenario['assets'][0]->fresh()->plan_version_id);
    $this->actingAs($scenario['actor']);

    // O Fundo de Obra e o cronograma são da versão: a edição do plano não os
    // leva mais, e o payload forjado nela não muda nada.
    $manager = Livewire::test(PlanSetsRelationManager::class, ['ownerRecord' => $scenario['operation'], 'pageClass' => ViewOperation::class])
        ->mountTableAction('edit', $alfa);

    match ($change) {
        'fundo de obra' => $manager->set('mountedActions.0.data.construction_fund_amount', '99.999,00'),
        'linha do cronograma' => $manager->set("mountedActions.0.data.lines.record-{$line->id}.planned_monthly_percent", 15),
    };

    $manager->callMountedTableAction()->assertHasNoTableActionErrors();

    expect(integrityGuardsNotificationBody('Plano não atualizado.'))->toBeNull()
        ->and($alfa->fresh()->currentConstructionFundAmount())->toBe('10000.00')
        ->and($line->fresh()->planned_monthly_percent)->toBe('10.00')
        ->and($alfa->versions()->count())->toBe(1);

    // O replanejamento nasce num rascunho de revisão, e a ativação dele é que
    // recusa, com o motivo.
    $draft = integrityGuardsReplanningDraft($scenario, $alfa, $change);

    Livewire::test(PlanVersionsRelationManager::class, ['ownerRecord' => $scenario['operation'], 'pageClass' => ViewOperation::class])
        ->callTableAction('activateVersion', $draft)
        ->assertActionHalted(TestAction::make('activateVersion')->table($draft));

    expect(integrityGuardsNotificationBody('Versão do plano não alterada.'))
        ->toBe("A competência 08/2026 já tem medição de pé (#{$scenario['measurement']->id}) e não pode ser replanejada. Ativada agora, a revisão valeria a partir de 08/2026; ative-a a partir de 01/09/2026.")
        ->and($draft->fresh()->status)->toBe(MeasurementPlanVersionStatus::Draft)
        ->and($captured->fresh()->status)->toBe(MeasurementPlanVersionStatus::Active)
        ->and($alfa->fresh()->currentConstructionFundAmount())->toBe('10000.00')
        ->and($line->fresh()->planned_monthly_percent)->toBe('10.00');
})->with(['fundo de obra', 'linha do cronograma']);

it('explains why a plan covered by an approved Engineering is not deleted, alone or in bulk', function () {
    $scenario = integrityGuardsTwoDevelopments();
    integrityGuardsApproveEngineering($scenario);
    [$alfa, $beta] = $scenario['planSets'];
    $refusal = 'Um empreendimento coberto por Engenharia aprovada não pode ser removido.';
    $this->actingAs($scenario['actor']);

    Livewire::test(PlanSetsRelationManager::class, ['ownerRecord' => $scenario['operation'], 'pageClass' => ViewOperation::class])
        ->callTableAction('delete', $alfa)
        ->assertActionHalted(TestAction::make('delete')->table($alfa));

    $rowRefusal = integrityGuardsNotificationBody('Plano não excluído.');

    Livewire::test(PlanSetsRelationManager::class, ['ownerRecord' => $scenario['operation'], 'pageClass' => ViewOperation::class])
        ->callTableBulkAction('delete', [$alfa, $beta]);

    expect($rowRefusal)->toBe($refusal)
        ->and(integrityGuardsNotificationBody('Falha ao excluir'))->toBe("<p>{$refusal}</p>")
        ->and(MeasurementPlanSet::query()->whereKey([$alfa->id, $beta->id])->count())->toBe(2);
});

it('explains on the operation edit page why a development fund covered by an approved Engineering keeps its value', function () {
    $scenario = integrityGuardsTwoDevelopments();
    integrityGuardsApproveEngineering($scenario);
    [$alfa] = $scenario['planSets'];
    $this->actingAs($scenario['actor']);

    $page = Livewire::test(EditOperation::class, ['record' => $scenario['operation']->getRouteKey()]);
    $key = collect($page->get('data.developments'))
        ->search(fn (array $row): bool => (int) $row['construction_id'] === (int) $alfa->construction_id);

    expect($key)->not->toBeFalse()
        ->and($page->instance()->getSchema('form')->getComponentByStatePath("developments.{$key}.construction_fund_amount")?->isDisabled())->toBeTrue();

    // O Fundo de Obra é da versão vigente: o campo do plano em vigor vem
    // travado, com o caminho da revisão.
    $page->assertSee('Plano em vigor: o Fundo de Obra muda por revisão do plano, na aba Versões dos Planos.');

    // O payload forjado, que destrava o campo e manda outro fundo, é recusado
    // pelo serviço de planos; a operação em si é gravada.
    $page->set("data.developments.{$key}.has_active_version", false)
        ->set("data.developments.{$key}.construction_fund_amount", '99.999,00')
        ->call('save');

    expect(integrityGuardsNotificationBody('Empreendimentos não atualizados.'))
        ->toBe(MeasurementPlanSet::FUND_BELONGS_TO_VERSION_REFUSAL.' Os demais dados da operação foram salvos.')
        ->and($alfa->fresh()->currentConstructionFundAmount())->toBe('10000.00')
        ->and($alfa->versions()->count())->toBe(1);
});

// ── Arquivos recusados: de qual empreendimento, e com rastro ─────────────────

it('names the development whose file the antivirus refused and logs its plan', function () {
    $scenario = integrityGuardsTwoDevelopments();
    [$alfa, $beta] = $scenario['planSets'];
    $scanner = Mockery::mock(ClamAvFileScanner::class);
    $scanner->shouldReceive('isEnabled')->andReturnTrue();
    $scanner->shouldReceive('scan')->andReturn(ClamAvFileScanner::RESULT_CLEAN);
    $scanner->shouldReceive('scanStream')->andReturnUsing(fn ($stream): string => str_contains((string) stream_get_contents($stream), 'EICAR')
        ? ClamAvFileScanner::RESULT_INFECTED
        : ClamAvFileScanner::RESULT_CLEAN);
    app()->instance(ClamAvFileScanner::class, $scanner);
    $logs = integrityGuardsLogs('critical');
    $this->actingAs($scenario['actor']);

    $page = Livewire::test(CreateMeasurement::class)->fillForm(['operation_id' => $scenario['operation']->id]);
    $keys = collect($page->get('data.assets'))
        ->mapWithKeys(fn (array $item, string $key): array => [(int) $item['plan_set_id'] => $key]);
    $page->fillForm([
        'reference_month' => '2026-09-01',
        'assets' => [
            $keys[$alfa->id] => ['plan_set_id' => $alfa->id, 'plan_line_id' => $scenario['september'][0]->id, 'storage_path' => [UploadedFile::fake()->createWithContent('alfa.pdf', '%PDF-1.7 Torre Alfa limpa')]],
            $keys[$beta->id] => ['plan_set_id' => $beta->id, 'plan_line_id' => $scenario['september'][1]->id, 'storage_path' => [UploadedFile::fake()->createWithContent('beta.pdf', '%PDF-1.7 EICAR Torre Beta')]],
        ],
    ])->call('create');

    expect(integrityGuardsNotificationBody('Medição não enviada.'))->toBe('Torre Beta: O arquivo foi bloqueado pelo antivírus. Envie um arquivo seguro.')
        ->and($scenario['operation']->measurements()->count())->toBe(1)
        ->and($logs->getArrayCopy())->toHaveCount(1)
        ->and($logs[0]['message'])->toBe('Upload bloqueado pela varredura antivírus.')
        ->and($logs[0]['context'])->toMatchArray(['reason' => 'malware_detectado', 'field' => 'asset', 'plan_set_id' => $beta->id]);
});

it('logs the integrity refusal of an Engineering file', function (string $defect) {
    $scenario = PhysicalScenario::plan();
    $measurement = PhysicalScenario::measurement($scenario, '2026-05');
    $asset = $measurement->assets()->sole();

    match ($defect) {
        'arquivo ausente' => Storage::disk('local')->delete($asset->storage_path),
        'SHA-256 inválido' => DB::table('measurement_assets')->where('id', $asset->id)->update(['sha256' => 'nao-e-um-sha256']),
        'metadados inválidos' => DB::table('measurement_assets')->where('id', $asset->id)->update(['mime_type' => '']),
    };

    $logs = integrityGuardsLogs('warning');

    expect(fn () => PhysicalScenario::approveEngineering($scenario, $measurement, 10))->toThrow(ValidationException::class);

    $integrity = collect($logs->getArrayCopy())
        ->where('message', 'Arquivo de medição recusado na conferência de integridade da Engenharia.')
        ->values();

    expect($integrity)->toHaveCount(1)
        ->and($integrity[0]['context'])->toMatchArray([
            'measurement_id' => $measurement->id,
            'asset_id' => $asset->id,
            'disk' => 'local',
            'relative_path' => $asset->storage_path,
            'reason' => match ($defect) {
                'arquivo ausente' => 'arquivo_ausente_ou_invalido',
                'SHA-256 inválido' => 'sha256_invalido',
                'metadados inválidos' => 'metadados_invalidos',
            },
        ]);
})->with(['arquivo ausente', 'SHA-256 inválido', 'metadados inválidos']);

// ── Seletor de operação do envio ─────────────────────────────────────────────

it('offers on the send form only the operations where the person can send a measurement', function (string $profile, bool $offered) {
    $scenario = PhysicalScenario::plan();
    $operation = $scenario['operation'];
    $scenario['actor']->forceFill(['is_active' => true, 'approved_at' => now()])->save();
    $person = integrityGuardsUser($profile === 'administrador' ? 'admin' : 'editor');

    match ($profile) {
        'participante direto' => $operation->forceFill(['stage3_reviewer_user_id' => $person->id])->saveQuietly(),
        'delegado da Engenharia' => ResponsibilityDelegation::factory()->active()->forOperation($operation)->create([
            'delegator_user_id' => $scenario['actor']->id,
            'delegate_user_id' => $person->id,
        ]),
        'administrador' => null,
    };

    $this->actingAs($person);

    expect(Operation::query()->visibleTo($person)->whereKey($operation->id)->exists())->toBeTrue();

    $select = Livewire::test(CreateMeasurement::class)->instance()->getSchema('form')->getComponentByStatePath('operation_id');

    expect($select)->toBeInstanceOf(Select::class)
        ->and(array_key_exists($operation->id, $select->getOptions()))->toBe($offered);
})->with([
    'participante direto' => ['participante direto', true],
    'delegado da Engenharia' => ['delegado da Engenharia', false],
    'administrador' => ['administrador', true],
]);

it('keeps the saved operation listed when editing a measurement of a closed operation', function () {
    $scenario = PhysicalScenario::plan();
    $measurement = PhysicalScenario::measurement($scenario, '2026-05');
    app(MeasurementWorkflow::class)->reject($measurement->fresh(), $scenario['actor'], 'Arquivo de outra competência.');
    $scenario['operation']->forceFill(['status' => 'completed'])->saveQuietly();
    $this->actingAs($scenario['actor']);

    $editSelect = Livewire::test(EditMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->instance()->getSchema('form')->getComponentByStatePath('operation_id');
    $sendSelect = Livewire::test(CreateMeasurement::class)
        ->instance()->getSchema('form')->getComponentByStatePath('operation_id');

    expect(array_keys($editSelect->getOptions()))->toBe([$scenario['operation']->id])
        ->and($sendSelect->getOptions())->toBe([]);
});
