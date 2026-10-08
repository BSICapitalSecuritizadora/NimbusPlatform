<?php

use App\Enums\MeasurementPlanRevisionCategory;
use App\Enums\MeasurementPlanVersionStatus;
use App\Filament\Resources\Measurements\Pages\CreateMeasurement;
use App\Filament\Resources\Measurements\Pages\ListMeasurements;
use App\Filament\Resources\Measurements\Pages\ViewMeasurement;
use App\Filament\Resources\Operations\Pages\CreateOperation;
use App\Filament\Resources\Operations\Pages\EditOperation;
use App\Filament\Resources\Operations\Pages\ListOperations;
use App\Filament\Resources\Operations\Pages\ViewOperation;
use App\Filament\Resources\Operations\RelationManagers\PlanLinesRelationManager;
use App\Filament\Resources\Operations\RelationManagers\PlanSetsRelationManager;
use App\Filament\Resources\Operations\RelationManagers\PlanVersionsRelationManager;
use App\Models\Construction;
use App\Models\Emission;
use App\Models\Measurement;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Models\MeasurementPlanVersion;
use App\Models\Operation;
use App\Models\User;
use App\Services\MeasurementWorkflow;
use App\Services\Security\ClamAvFileScanner;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\MeasurementPlanVersionFixture;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

function makeMeasurementAdminUser(): User
{
    $user = User::factory()->withTwoFactor()->create([
        'email' => fake()->unique()->safeEmail(),
    ]);
    $user->assignRole('admin');

    return $user;
}

it('renders the operations list and create pages', function () {
    $this->actingAs(makeMeasurementAdminUser());

    Livewire::test(ListOperations::class)->assertSuccessful();
    Livewire::test(CreateOperation::class)->assertSuccessful();
});

it('creates an operation and derives its title from the development', function () {
    $this->actingAs(makeMeasurementAdminUser());
    $emission = Emission::factory()->create();
    $construction = Construction::factory()->create([
        'emission_id' => $emission->id,
        'development_name' => 'Residencial Teste',
    ]);

    Livewire::test(CreateOperation::class)
        ->fillForm(['emission_id' => $emission->id])
        ->fillForm([
            'developments' => [
                ['construction_id' => $construction->id, 'construction_fund_amount' => '1.000.000,00'],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $operation = Operation::query()->first();
    // O Fundo de Obra é da versão do plano: o do formulário vai para a V1, que
    // nasce em rascunho -- o plano só passa a valer quando ela for ativada.
    $version = $operation?->planSets()->where('construction_id', $construction->id)->sole()->currentVersion();

    expect($operation)->not->toBeNull()
        ->and($operation->emission_id)->toBe($emission->id)
        ->and($operation->title)->toBe('Residencial Teste')
        ->and($operation->code)->toStartWith('OP-')
        ->and($version?->construction_fund_amount)->toBe('1000000.00')
        ->and($version?->version_number)->toBe(1)
        ->and($version?->status)->toBe(MeasurementPlanVersionStatus::Draft);
});

it('allows selecting more than one rejection-notify user', function () {
    $this->actingAs(makeMeasurementAdminUser());
    $emission = Emission::factory()->create();
    $construction = Construction::factory()->create(['emission_id' => $emission->id]);
    $watcherA = User::factory()->create();
    $watcherB = User::factory()->create();

    Livewire::test(CreateOperation::class)
        ->fillForm([
            'emission_id' => $emission->id,
            'status' => 'active',
            'rejectionNotifyUsers' => [$watcherA->id, $watcherB->id],
        ])
        ->fillForm([
            'developments' => [
                ['construction_id' => $construction->id, 'construction_fund_amount' => '100.000,00'],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $operation = Operation::query()->latest('id')->first();

    expect($operation->rejectionNotifyUsers()->pluck('users.id')->sort()->values()->all())
        ->toBe(collect([$watcherA->id, $watcherB->id])->sort()->values()->all());
});

it('requires at least one development', function () {
    $this->actingAs(makeMeasurementAdminUser());
    $emission = Emission::factory()->create();

    Livewire::test(CreateOperation::class)
        ->fillForm([
            'emission_id' => $emission->id,
            'status' => 'active',
        ])
        ->call('create')
        ->assertHasFormErrors(['developments']);
});

it('auto-fills the due date from the selected emission', function () {
    $this->actingAs(makeMeasurementAdminUser());
    $emission = Emission::factory()->create(['maturity_date' => '2030-12-31']);

    Livewire::test(CreateOperation::class)
        ->fillForm(['emission_id' => $emission->id])
        ->assertFormSet(['due_date' => '2030-12-31']);
});

it('creates a plan set per selected development when the emission has many', function () {
    $this->actingAs(makeMeasurementAdminUser());
    $emission = Emission::factory()->create();
    $conviva1 = Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => 'Conviva I']);
    $conviva2 = Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => 'Conviva II']);

    Livewire::test(CreateOperation::class)
        ->fillForm(['emission_id' => $emission->id])
        ->fillForm([
            'developments' => [
                ['construction_id' => $conviva1->id, 'construction_fund_amount' => '500.000,00'],
                ['construction_id' => $conviva2->id, 'construction_fund_amount' => '750.000,00'],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $operation = Operation::query()->latest('id')->first();

    // Cada empreendimento leva o próprio Fundo de Obra para a V1 do seu plano.
    expect($operation->planSets()->count())->toBe(2)
        ->and($operation->planSets()->pluck('construction_id')->sort()->values()->all())
        ->toBe(collect([$conviva1->id, $conviva2->id])->sort()->values()->all())
        ->and($operation->planSets()->where('is_default', true)->count())->toBe(1)
        ->and($operation->title)->toBe('Conviva I, Conviva II')
        ->and($operation->planSets()->where('construction_id', $conviva1->id)->sole()->currentConstructionFundAmount())->toBe('500000.00')
        ->and($operation->planSets()->where('construction_id', $conviva2->id)->sole()->currentConstructionFundAmount())->toBe('750000.00')
        ->and(MeasurementPlanVersion::query()->whereIn('plan_set_id', $operation->planSets()->pluck('id'))->pluck('status')->all())
        ->toBe([MeasurementPlanVersionStatus::Draft, MeasurementPlanVersionStatus::Draft]);
});

it('renders the measurement plan editor (schedule) relation manager', function () {
    $this->actingAs(makeMeasurementAdminUser());
    $operation = Operation::factory()->create();

    Livewire::test(PlanSetsRelationManager::class, [
        'ownerRecord' => $operation,
        'pageClass' => EditOperation::class,
    ])->assertSuccessful();
});

it('renders the read-only schedule monitoring with the plan lines', function () {
    $this->actingAs(makeMeasurementAdminUser());
    $operation = Operation::factory()->create();
    $planSet = MeasurementPlanSet::factory()->create(['operation_id' => $operation->id]);
    $line = MeasurementPlanLine::factory()->create([
        'plan_set_id' => $planSet->id,
        'operation_id' => $operation->id,
        'planned_cumulative_percent' => 40,
        'realized_cumulative_percent' => 55,
    ]);
    // O acompanhamento mostra o cronograma da versão vigente: a linha entra no
    // rascunho da V1, que a fixture põe em vigor.
    MeasurementPlanVersionFixture::activate($planSet);

    Livewire::test(PlanLinesRelationManager::class, [
        'ownerRecord' => $operation,
        'pageClass' => ViewOperation::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$line]);

    expect($line->fresh()->evolution_trend)->toBe(MeasurementPlanLine::TREND_AHEAD);
});

it('edits the planned monthly and cumulative progress in a plan revision instead of the monitoring grid', function () {
    // A vigência é o mês da ativação: com o relógio em agosto, a revisão vale
    // a partir de 08/2026 e ainda pode replanejar a medição prevista do mês.
    $this->travelTo(now()->setDate(2026, 8, 10)->setTime(15, 0));
    $this->actingAs(makeMeasurementAdminUser());
    $operation = Operation::factory()->create();
    $planSet = MeasurementPlanSet::factory()->create(['operation_id' => $operation->id]);
    $line = MeasurementPlanLine::factory()->create([
        'plan_set_id' => $planSet->id,
        'operation_id' => $operation->id,
        'sequence_number' => 1,
        'planned_monthly_percent' => 0,
        'planned_cumulative_percent' => 0,
        'measurement_date' => '2026-08-01',
    ]);
    $firstVersion = MeasurementPlanVersionFixture::activeVersion($planSet);
    $monitoring = fn () => Livewire::test(PlanLinesRelationManager::class, [
        'ownerRecord' => $operation,
        'pageClass' => ViewOperation::class,
    ]);
    $versions = Livewire::test(PlanVersionsRelationManager::class, [
        'ownerRecord' => $operation,
        'pageClass' => ViewOperation::class,
    ]);

    // O previsto da versão vigente é histórico: o acompanhamento não o edita,
    // e a mudança nasce num rascunho de revisão, na aba Versões dos Planos.
    $monitoring()->assertTableActionDoesNotExist('editPlanned');

    $versions->callTableAction('createRevision', $firstVersion, data: [
        'revision_category' => MeasurementPlanRevisionCategory::PhysicalPlanning->value,
        'revision_reason' => 'Replanejamento físico do mês de agosto.',
    ])->assertHasNoTableActionErrors();

    $draft = MeasurementPlanVersion::query()->where('plan_set_id', $planSet->id)->draft()->sole();
    $draftLine = $draft->lines()->sole();

    $versions->callTableAction('editDraft', $draft, data: [
        'lines' => [[
            'id' => $draftLine->id,
            'sequence_number' => 1,
            'planned_monthly_percent' => 15,
            'planned_cumulative_percent' => 15,
            'measurement_date' => '2026-08',
        ]],
    ])->assertHasNoTableActionErrors();

    // Enquanto é rascunho, o acompanhamento continua com o previsto da V1.
    $monitoring()
        ->assertCanSeeTableRecords([$line])
        ->assertCanNotSeeTableRecords([$draftLine]);

    $versions->callTableAction('activateVersion', $draft->fresh())->assertHasNoTableActionErrors();

    $monitoring()
        ->assertCanSeeTableRecords([$draftLine])
        ->assertCanNotSeeTableRecords([$line]);

    expect($draftLine->fresh()->planned_monthly_percent)->toBe('15.00')
        ->and($draftLine->fresh()->planned_cumulative_percent)->toBe('15.00')
        ->and($draftLine->fresh()->lineage_key)->toBe($line->lineage_key)
        ->and($line->fresh()->planned_monthly_percent)->toBe('0.00')
        ->and($line->fresh()->planned_cumulative_percent)->toBe('0.00')
        ->and($firstVersion->fresh()->status)->toBe(MeasurementPlanVersionStatus::Superseded);
});

it('no longer exposes manual realized entry in the monitoring grid', function () {
    $this->actingAs(makeMeasurementAdminUser());
    $operation = Operation::factory()->create();
    $planSet = MeasurementPlanSet::factory()->create(['operation_id' => $operation->id]);
    MeasurementPlanLine::factory()->create([
        'plan_set_id' => $planSet->id,
        'operation_id' => $operation->id,
    ]);
    MeasurementPlanVersionFixture::activate($planSet);

    // A grade é só leitura: o realizado vem da Engenharia e o previsto muda por
    // revisão do plano. Resta o atalho para o arquivo da medição da linha.
    Livewire::test(PlanLinesRelationManager::class, [
        'ownerRecord' => $operation,
        'pageClass' => ViewOperation::class,
    ])
        ->assertTableActionExists('openMeasurement')
        ->assertTableActionDoesNotExist('registerActual')
        ->assertTableActionDoesNotExist('editPlanned');
});

it('renders the measurements list page', function () {
    $this->actingAs(makeMeasurementAdminUser());

    Livewire::test(ListMeasurements::class)->assertSuccessful();
});

it('shows a contextual empty state when no measurement exists', function () {
    $this->actingAs(makeMeasurementAdminUser());

    Livewire::test(ListMeasurements::class)
        ->assertSee('Nenhuma medição cadastrada')
        ->assertSee('Criar primeira medição')
        ->assertDontSee('Nenhuma medição corresponde aos filtros selecionados');
});

it('distinguishes the filtered empty state from the empty base', function () {
    $this->actingAs(makeMeasurementAdminUser());
    Measurement::factory()->create();

    Livewire::test(ListMeasurements::class)
        ->set('tableSearch', 'termo-inexistente')
        ->assertSee('Nenhuma medição corresponde aos filtros selecionados')
        ->assertSee('Limpar filtros')
        ->assertDontSee('Nenhuma medição cadastrada');
});

it('offers a per-development measurement slot when the operation is selected', function () {
    $this->actingAs(makeMeasurementAdminUser());

    $emission = Emission::factory()->create();
    $construction = Construction::factory()->create(['emission_id' => $emission->id]);
    $operation = Operation::factory()->forEmission($emission)->create();
    $planSet = MeasurementPlanSet::factory()->create([
        'operation_id' => $operation->id,
        'construction_id' => $construction->id,
        'is_default' => true,
    ]);
    MeasurementPlanLine::factory()->create([
        'plan_set_id' => $planSet->id,
        'operation_id' => $operation->id,
        'sequence_number' => 3,
        'measurement_date' => '2026-07-01',
    ]);
    // Só plano em vigor recebe medição: a linha entra no rascunho da V1, que a
    // fixture ativa.
    MeasurementPlanVersionFixture::activate($planSet);

    $component = Livewire::test(CreateMeasurement::class)
        ->fillForm(['operation_id' => $operation->id]);

    $assets = collect($component->get('data.assets'))->values();

    expect($assets)->toHaveCount(1)
        ->and($assets->first())->toHaveKey('plan_line_id')
        ->and((int) $assets->first()['plan_set_id'])->toBe($planSet->id);
});

it('pre-fills one file slot per development when the operation is selected', function () {
    $this->actingAs(makeMeasurementAdminUser());

    $emission = Emission::factory()->create();
    $constructionA = Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => 'Obra A']);
    $constructionB = Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => 'Obra B']);
    $operation = Operation::factory()->forEmission($emission)->create();
    $planA = MeasurementPlanSet::factory()->create(['operation_id' => $operation->id, 'construction_id' => $constructionA->id]);
    $planB = MeasurementPlanSet::factory()->create(['operation_id' => $operation->id, 'construction_id' => $constructionB->id]);
    MeasurementPlanVersionFixture::activate($planA, $planB);

    $component = Livewire::test(CreateMeasurement::class)
        ->fillForm(['operation_id' => $operation->id]);

    $assets = collect($component->get('data.assets'))->values();

    expect($assets)->toHaveCount(2)
        ->and($assets->pluck('plan_set_id')->map(fn ($id): int => (int) $id)->sort()->values()->all())
        ->toBe(collect([$planA->id, $planB->id])->sort()->values()->all());
});

it('offers no file slot to a development whose plan is still a draft', function () {
    $this->actingAs(makeMeasurementAdminUser());

    $emission = Emission::factory()->create();
    $constructionA = Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => 'Obra A']);
    $constructionB = Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => 'Obra B']);
    $operation = Operation::factory()->forEmission($emission)->create();
    $effective = MeasurementPlanSet::factory()->create(['operation_id' => $operation->id, 'construction_id' => $constructionA->id]);
    MeasurementPlanSet::factory()->create(['operation_id' => $operation->id, 'construction_id' => $constructionB->id]);
    MeasurementPlanVersionFixture::activate($effective);

    // O plano que nunca foi ativado tem o cronograma em rascunho: não recebe
    // medição, e a Engenharia também não exige o arquivo dele.
    $component = Livewire::test(CreateMeasurement::class)
        ->fillForm(['operation_id' => $operation->id]);

    expect(collect($component->get('data.assets'))->pluck('plan_set_id')->map(fn ($id): int => (int) $id)->values()->all())
        ->toBe([$effective->id]);
});

it('persists one asset per development with its file and starts the review', function () {
    $this->actingAs($admin = makeMeasurementAdminUser());

    $emission = Emission::factory()->create();
    $constructionA = Construction::factory()->create(['emission_id' => $emission->id]);
    $constructionB = Construction::factory()->create(['emission_id' => $emission->id]);
    $operation = Operation::factory()->forEmission($emission)->create(['responsible_user_id' => $admin->id]);
    $planA = MeasurementPlanSet::factory()->create(['operation_id' => $operation->id, 'construction_id' => $constructionA->id]);
    $planB = MeasurementPlanSet::factory()->create(['operation_id' => $operation->id, 'construction_id' => $constructionB->id]);
    MeasurementPlanVersionFixture::activate($planA, $planB);

    $measurement = Measurement::create([
        'operation_id' => $operation->id,
        'status' => 'pending',
        'current_stage' => 1,
        'uploaded_by' => $admin->id,
        'uploaded_at' => now(),
    ]);
    Storage::fake('local');
    Storage::disk('local')->put('measurements/a.pdf', "%PDF-1.4\n%%EOF");
    Storage::disk('local')->put('measurements/b.pdf', "%PDF-1.4\n%%EOF");
    $measurement->assets()->createMany([
        ['plan_set_id' => $planA->id, 'storage_path' => 'measurements/a.pdf', 'filename' => 'a.pdf'],
        ['plan_set_id' => $planB->id, 'storage_path' => 'measurements/b.pdf', 'filename' => 'b.pdf'],
    ]);
    app(MeasurementWorkflow::class)->startReview($measurement, $admin);

    // Cada arquivo guarda a versão vigente do plano do seu empreendimento.
    expect($measurement->fresh()->assets()->count())->toBe(2)
        ->and($measurement->fresh()->assets()->pluck('plan_set_id')->sort()->values()->all())
        ->toBe(collect([$planA->id, $planB->id])->sort()->values()->all())
        ->and($measurement->fresh()->assets()->orderBy('plan_set_id')->pluck('plan_version_id')->map(fn ($id): int => (int) $id)->all())
        ->toBe(collect([$planA, $planB])->sortBy('id')->map(fn (MeasurementPlanSet $plan): int => (int) $plan->activeVersion()->value('id'))->values()->all())
        ->and($measurement->fresh()->status)->toBe('in_review');
});

it('requires an operation to send a measurement', function () {
    $this->actingAs(makeMeasurementAdminUser());

    Livewire::test(CreateMeasurement::class)
        ->call('create')
        ->assertHasFormErrors(['operation_id']);
});

it('exposes the review actions to the stage reviewer and approves a stage', function () {
    $reviewer = makeMeasurementAdminUser();
    $this->actingAs($reviewer);

    $operation = Operation::factory()->create(['responsible_user_id' => $reviewer->id]);
    $planSet = MeasurementPlanSet::factory()->create(['operation_id' => $operation->id]);
    $line = MeasurementPlanLine::factory()->create([
        'operation_id' => $operation->id,
        'plan_set_id' => $planSet->id,
        'measurement_date' => '2026-07-01',
        'realized_monthly_percent' => 0,
        'realized_cumulative_percent' => 0,
    ]);
    MeasurementPlanVersionFixture::activate($planSet);
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->id,
        'reference_month' => '2026-07-01',
        'status' => 'pending',
        'current_stage' => 1,
    ]);
    Storage::fake('local');
    Storage::disk('local')->put('measurements/test.pdf', '%PDF-1.7 measurement');
    $measurement->assets()->create([
        'plan_set_id' => $planSet->id,
        'plan_line_id' => $line->id,
        'storage_path' => 'measurements/test.pdf',
    ]);
    app(MeasurementWorkflow::class)->startReview($measurement, $reviewer);

    Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->assertSuccessful()
        ->assertActionVisible('approve')
        ->assertActionVisible('reject')
        ->assertActionVisible('pause')
        ->assertActionHidden('resume')
        ->callAction('approve', data: ['notes' => 'Ok', 'realized' => [$planSet->id => 10]]);

    expect($measurement->fresh()->status)->toBe('in_review')
        ->and($measurement->fresh()->current_stage)->toBe(2);
});

it('hides the validation from users who are not responsible for the stage', function () {
    $reviewer = makeMeasurementAdminUser();
    $bystander = User::factory()->withTwoFactor()->create();
    $bystander->assignRole('editor');
    $this->actingAs($bystander);

    $operation = Operation::factory()->create([
        'assigned_user_id' => $bystander->id,
        'responsible_user_id' => $reviewer->id,
    ]);
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->id,
        'status' => 'pending',
        'current_stage' => 1,
    ]);
    app(MeasurementWorkflow::class)->startReview($measurement, $reviewer);

    Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->assertSuccessful()
        ->assertActionHidden('approve')
        ->assertActionHidden('reject')
        ->assertActionHidden('pause');
});

it('lets a super admin validate any stage', function () {
    $superAdmin = User::factory()->withTwoFactor()->create();
    $superAdmin->assignRole('super-admin');
    $this->actingAs($superAdmin);

    $reviewer = makeMeasurementAdminUser();
    $operation = Operation::factory()->create(['responsible_user_id' => $reviewer->id]);
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->id,
        'status' => 'pending',
        'current_stage' => 1,
    ]);
    app(MeasurementWorkflow::class)->startReview($measurement, $superAdmin);

    Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->assertSuccessful()
        ->assertActionVisible('approve');
});

it('shows payment to the payment manager and finalize to the finalizer', function () {
    $manager = makeMeasurementAdminUser();
    $this->actingAs($manager);

    $operation = Operation::factory()->create([
        'payment_manager_user_id' => $manager->id,
        'payment_finalizer_user_id' => $manager->id,
    ]);
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->id,
        'status' => 'awaiting_payment',
        'current_stage' => MeasurementWorkflow::STAGE_PAYMENT,
    ]);
    $measurement->reviews()->create([
        'stage' => MeasurementWorkflow::STAGE_PAYMENT,
        'reviewer_user_id' => $manager->id,
        'status' => 'pending',
    ]);

    Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->assertActionVisible('registerPayment')
        ->assertActionHidden('attachReceipt')
        ->assertActionHidden('finalize');
});

it('renders the create measurement page with custom subheading and stacked sections', function () {
    $this->actingAs(makeMeasurementAdminUser());

    Livewire::test(CreateMeasurement::class)
        ->assertOk()
        ->assertSee('Envie os arquivos da competência para cada empreendimento vinculado à operação.')
        ->assertSee('Dados da Medição')
        ->assertSee('Identifique a operação e confirme a competência do envio.')
        ->assertSee('Arquivo por Empreendimento')
        ->assertSee('Associe a medição prevista e envie o arquivo correspondente para cada empreendimento.')
        ->assertSee('Nenhuma operação selecionada')
        ->assertSee('Selecione uma operação acima para carregar os empreendimentos vinculados')
        ->assertFormFieldExists('operation_id')
        ->assertFormFieldExists('reference_month')
        ->assertFormFieldExists('notes');
});

it('persists clean engineering uploads and rolls back rejected uploads through the measurement form', function (string $disk, string $scanResult) {
    $this->actingAs($admin = makeMeasurementAdminUser());
    Notification::fake();
    Storage::fake($disk);
    config()->set('filesystems.private_disk', $disk);
    if ($disk === 'private') {
        config()->set('filesystems.disks.private.driver', 'azure');
    }
    $emission = Emission::factory()->create();
    $construction = Construction::factory()->create(['emission_id' => $emission->id]);
    $operation = Operation::factory()->forEmission($emission)->create(['status' => 'active', 'responsible_user_id' => $admin->id]);
    $plan = MeasurementPlanSet::factory()->create(['operation_id' => $operation->id, 'construction_id' => $construction->id]);
    $line = MeasurementPlanLine::factory()->create([
        'plan_set_id' => $plan->id, 'operation_id' => $operation->id, 'measurement_date' => '2026-09-01',
    ]);
    MeasurementPlanVersionFixture::activate($plan);
    $this->mock(ClamAvFileScanner::class, function ($mock) use ($scanResult): void {
        $mock->shouldReceive('isEnabled')->andReturnTrue();
        $mock->shouldReceive('scanStream')->once()->andReturn($scanResult);
    });

    $component = Livewire::test(CreateMeasurement::class)
        ->fillForm(['operation_id' => $operation->id]);
    $assetKey = array_key_first($component->get('data.assets'));
    $component->fillForm([
        'reference_month' => '2026-09-01',
        'assets' => [$assetKey => [
            'plan_set_id' => $plan->id,
            'plan_line_id' => $line->id,
            'storage_path' => [UploadedFile::fake()->createWithContent('medicao.pdf', '%PDF-1.7 engenharia')],
        ]],
    ])->call('create');

    if ($scanResult === ClamAvFileScanner::RESULT_CLEAN) {
        $component->assertHasNoFormErrors();
        $measurement = $operation->measurements()->firstOrFail();
        $asset = $measurement->assets()->firstOrFail();
        expect($asset->storage_disk)->toBe($disk)
            ->and($asset->sha256)->toBe(hash('sha256', '%PDF-1.7 engenharia'));
        Storage::disk($disk)->assertExists($asset->storage_path);

        return;
    }

    // A chave `asset` não é campo do formulário: no bag ela não aparecia em
    // lugar nenhum. A recusa chega como notificação, e o bag fica vazio.
    $refusal = collect(session()->get('filament.claimed_notifications') ?? session()->get('filament.notifications') ?? [])
        ->firstWhere('title', 'Medição não enviada.');

    expect($component->errors()->keys())->toBe([])
        ->and((string) ($refusal['body'] ?? ''))->toBe($scanResult === ClamAvFileScanner::RESULT_INFECTED
            ? 'O arquivo foi bloqueado pelo antivírus. Envie um arquivo seguro.'
            : 'Não foi possível verificar a segurança do arquivo. Tente novamente quando o antivírus estiver disponível.')
        ->and($operation->measurements()->count())->toBe(0)
        ->and(Storage::disk($disk)->allFiles('nimbus_docs/measurements/assets'))->toBeEmpty();
})->with(['local', 'private'])->with([ClamAvFileScanner::RESULT_CLEAN, ClamAvFileScanner::RESULT_INFECTED, ClamAvFileScanner::RESULT_UNAVAILABLE]);
