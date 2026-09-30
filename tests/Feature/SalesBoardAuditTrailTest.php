<?php

use App\Enums\SalesBoardManagementReviewStatus;
use App\Enums\SalesBoardNonconformityDecision;
use App\Enums\SalesBoardNonconformityOrigin;
use App\Enums\SalesBoardRolloutRecipientRole;
use App\Models\Client;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\ConstructionUnitExchange;
use App\Models\Contract;
use App\Models\ContractInstallment;
use App\Models\Emission;
use App\Models\SalesBoard;
use App\Models\SalesBoardBuilderReview;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardHistory;
use App\Models\SalesBoardManagementNonconformity;
use App\Models\SalesBoardManagementReview;
use App\Models\SalesBoardPublication;
use App\Models\SalesBoardRolloutHomologation;
use App\Models\SalesBoardRolloutHomologationConstruction;
use App\Models\SalesBoardRolloutRecipient;
use App\Models\User;
use App\Services\SalesBoards\SalesBoardRolloutHomologationService;
use App\Services\SalesBoards\SalesBoardRolloutRecipientDirectory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Permission\Models\Role;
use Tests\Support\SalesBoards\ManagementReviewFixture;
use Tests\Support\SalesBoards\RolloutFixture;

uses(RefreshDatabase::class);

/**
 * Os models do módulo que gravam trilha, descobertos na pasta e não listados à
 * mão: um model novo do Quadro que ganhe `LogsActivity` entra na checagem de
 * política sem que alguém precise lembrar de acrescentá-lo aqui.
 *
 * @return list<class-string>
 */
function salesBoardAuditedModels(): array
{
    $models = [];

    foreach (glob(dirname(__DIR__, 2).'/app/Models/Sales*.php') ?: [] as $file) {
        $model = 'App\\Models\\'.basename($file, '.php');

        if (class_exists($model) && in_array(LogsActivity::class, class_uses_recursive($model), true)) {
            $models[] = $model;
        }
    }

    sort($models);

    return $models;
}

/**
 * Quem age nos cenários: super-admin, para que a trilha registre um autor sem
 * depender das regras de permissão e de segregação que outros testes cobrem.
 */
function salesBoardAuditActor(): User
{
    $user = User::factory()->create(['approved_at' => now()]);
    $user->assignRole(Role::findOrCreate('super-admin'));

    return $user;
}

/**
 * Envelhece toda a trilha para além da janela descartável e roda o expurgo
 * diário, exatamente como o agendador faria.
 */
function salesBoardAuditPurgeDisposableWindow(): void
{
    DB::table('activity_log')->update([
        'created_at' => now()->subDays((int) config('audit.retention_disposable_days', 365) + 35),
    ]);

    test()->artisan('audit:clean-filtered')->assertExitCode(0);
}

/**
 * @return Collection<int, Activity>
 */
function salesBoardAuditTrailOf(object $subject): Collection
{
    return Activity::query()
        ->where('subject_type', $subject::class)
        ->where('subject_id', $subject->getKey())
        ->orderBy('id')
        ->get();
}

// ── Política ──────────────────────────────────────────────────────────────────

it('files every audited Sales Board model under the protected sales_board log', function (string $model) {
    expect((new $model)->getActivitylogOptions()->logName)->toBe('sales_board')
        ->and(config('audit.protected_logs'))->toContain('sales_board');
})->with(fn (): array => salesBoardAuditedModels());

it('finds every audited Sales Board model by scanning the models folder', function () {
    // Sem esta âncora, uma varredura que voltasse vazia deixaria a checagem de
    // política verde sem conferir model nenhum.
    expect(salesBoardAuditedModels())->toContain(
        SalesBoard::class,
        SalesBoardHistory::class,
        SalesBoardCycle::class,
        SalesBoardBuilderReview::class,
        SalesBoardManagementReview::class,
        SalesBoardManagementNonconformity::class,
        SalesBoardPublication::class,
        SalesBoardRolloutHomologation::class,
        SalesBoardRolloutHomologationConstruction::class,
        SalesBoardRolloutRecipient::class,
    );
});

it('files every Sales Board source under its own protected log', function (string $model, string $logName) {
    expect((new $model)->getActivitylogOptions()->logName)->toBe($logName)
        ->and(config('audit.protected_logs'))->toContain($logName);
})->with([
    'Contract' => [Contract::class, 'contracts'],
    'ContractInstallment' => [ContractInstallment::class, 'contract_installments'],
    'ConstructionUnit' => [ConstructionUnit::class, 'construction_units'],
    'ConstructionUnitExchange' => [ConstructionUnitExchange::class, 'construction_unit_exchanges'],
    'Construction' => [Construction::class, 'constructions'],
    'Emission' => [Emission::class, 'emissions'],
]);

// ── Governança do Quadro ──────────────────────────────────────────────────────

it('leaves no trail of a published competence in the disposable bucket, and keeps all of it past a year', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $review = ManagementReviewFixture::open($scenario['cycle']);
    $manager = salesBoardAuditActor();
    $this->actingAs($manager);

    ManagementReviewFixture::decideAll($review, $manager);
    ManagementReviewFixture::approve($review, $manager);

    $moduleTrail = fn (): Builder => Activity::query()->whereIn('subject_type', salesBoardAuditedModels());

    expect(Activity::query()->where('subject_type', SalesBoardPublication::class)->exists())->toBeTrue()
        ->and(Activity::query()->where('subject_type', SalesBoard::class)->exists())->toBeTrue()
        ->and($moduleTrail()->pluck('log_name')->unique()->values()->all())->toBe(['sales_board']);

    $approval = salesBoardAuditTrailOf($review)
        ->first(fn (Activity $activity): bool => data_get($activity->attribute_changes, 'attributes.status') === SalesBoardManagementReviewStatus::Approved->value);

    expect($approval)->not->toBeNull()
        ->and(data_get($approval->attribute_changes, 'attributes.approved_by_user_id'))->toBe($manager->id)
        ->and($approval->causer_id)->toBe($manager->id);

    $recorded = $moduleTrail()->count();

    salesBoardAuditPurgeDisposableWindow();

    expect($moduleTrail()->count())->toBe($recorded);
});

it('keeps an undone management decision, with its author and reason, past a year', function () {
    $scenario = ManagementReviewFixture::submittedCycleWithNonConformSale();
    $review = ManagementReviewFixture::open($scenario['cycle']);
    $item = ManagementReviewFixture::nonconformityOf($review, SalesBoardNonconformityOrigin::SystemSaleNonConform);
    $manager = salesBoardAuditActor();
    $this->actingAs($manager);

    ManagementReviewFixture::decide(
        $item,
        SalesBoardNonconformityDecision::AcceptedException,
        'Exceção aceita pela diretoria comercial em reunião.',
        $manager,
    );
    ManagementReviewFixture::decide($item->fresh(), SalesBoardNonconformityDecision::Pending, null, $manager);

    // A linha guarda só a decisão vigente: o motivo desfeito some dela.
    expect($item->fresh()->decision_reason)->toBeNull()
        ->and($item->fresh()->decided_by_user_id)->toBeNull();

    salesBoardAuditPurgeDisposableWindow();

    $undo = salesBoardAuditTrailOf($item)->last();

    expect($undo)->not->toBeNull()
        ->and($undo->log_name)->toBe('sales_board')
        ->and($undo->causer_id)->toBe($manager->id)
        ->and(data_get($undo->attribute_changes, 'old.decision'))->toBe(SalesBoardNonconformityDecision::AcceptedException->value)
        ->and(data_get($undo->attribute_changes, 'old.decision_reason'))->toBe('Exceção aceita pela diretoria comercial em reunião.')
        ->and(data_get($undo->attribute_changes, 'old.decided_by_user_id'))->toBe($manager->id)
        ->and(data_get($undo->attribute_changes, 'attributes.decision'))->toBe(SalesBoardNonconformityDecision::Pending->value);
});

it('keeps who returned a management review to the builder, and why', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $review = ManagementReviewFixture::open($scenario['cycle']);
    $manager = salesBoardAuditActor();
    $this->actingAs($manager);

    ManagementReviewFixture::returnToBuilder($review, $manager, 'Precisamos da confirmação do contrato da unidade 102.');

    salesBoardAuditPurgeDisposableWindow();

    $returned = salesBoardAuditTrailOf($review)
        ->first(fn (Activity $activity): bool => data_get($activity->attribute_changes, 'attributes.status') === SalesBoardManagementReviewStatus::Returned->value);

    expect($returned)->not->toBeNull()
        ->and($returned->log_name)->toBe('sales_board')
        ->and(data_get($returned->attribute_changes, 'attributes.returned_by_user_id'))->toBe($manager->id)
        ->and(data_get($returned->attribute_changes, 'attributes.return_reason'))->toBe('Precisamos da confirmação do contrato da unidade 102.');
});

it('keeps who deleted a legacy board, and the versions the cascade took along, past a year', function () {
    $scenario = RolloutFixture::emission(1);
    $operator = salesBoardAuditActor();
    $this->actingAs($operator);

    $board = RolloutFixture::legacyBoard($scenario['constructions'][0], stockUnits: 2, stockValue: '1000000.00');
    $boardId = $board->getKey();

    expect(SalesBoardHistory::query()->where('sales_board_id', $boardId)->exists())->toBeTrue();

    $board->delete();

    // A cascata do banco apaga as versões sem disparar evento nenhum.
    expect(SalesBoardHistory::query()->where('sales_board_id', $boardId)->exists())->toBeFalse();

    salesBoardAuditPurgeDisposableWindow();

    $deletion = Activity::query()
        ->where('subject_type', SalesBoard::class)
        ->where('subject_id', $boardId)
        ->where('event', 'deleted')
        ->sole();

    $versions = Activity::query()
        ->where('subject_type', SalesBoardHistory::class)
        ->where('event', 'created')
        ->get()
        ->filter(fn (Activity $activity): bool => (int) data_get($activity->attribute_changes, 'attributes.sales_board_id') === $boardId);

    expect($deletion->log_name)->toBe('sales_board')
        ->and($deletion->causer_id)->toBe($operator->id)
        ->and((float) data_get($deletion->attribute_changes, 'old.stock_value'))->toBe(1000000.0)
        ->and($versions)->toHaveCount(1)
        ->and($versions->first()->log_name)->toBe('sales_board')
        ->and((int) data_get($versions->first()->attribute_changes, 'attributes.stock_units'))->toBe(2)
        ->and((float) data_get($versions->first()->attribute_changes, 'attributes.stock_value'))->toBe(1000000.0);
});

// ── Rollout ───────────────────────────────────────────────────────────────────

it('keeps who attested the impacts and who rejected a rollout homologation, and why', function () {
    $scenario = RolloutFixture::emission(1);
    $operator = salesBoardAuditActor();
    $this->actingAs($operator);

    $homologation = RolloutFixture::open($scenario['emission'], $operator);
    RolloutFixture::reviewImpacts($homologation, $operator);

    app(SalesBoardRolloutHomologationService::class)->reject(
        $homologation->fresh(),
        $operator,
        'A competência de comparação precisa ser revista com a Gestão.',
    );

    salesBoardAuditPurgeDisposableWindow();

    $trail = salesBoardAuditTrailOf($homologation);
    $attestation = $trail->first(fn (Activity $activity): bool => data_get($activity->attribute_changes, 'attributes.guarantees_reviewed_by_user_id') !== null);
    $rejection = $trail->first(fn (Activity $activity): bool => data_get($activity->attribute_changes, 'attributes.rejection_reason') !== null);

    expect($trail->pluck('log_name')->unique()->values()->all())->toBe(['sales_board'])
        ->and($attestation)->not->toBeNull()
        ->and(data_get($attestation->attribute_changes, 'attributes.guarantees_reviewed_by_user_id'))->toBe($operator->id)
        ->and($rejection)->not->toBeNull()
        ->and(data_get($rejection->attribute_changes, 'attributes.rejected_by_user_id'))->toBe($operator->id)
        ->and(data_get($rejection->attribute_changes, 'attributes.rejection_reason'))->toBe('A competência de comparação precisa ser revista com a Gestão.');
});

it('keeps an accepted difference that a reassessment discarded', function () {
    $scenario = RolloutFixture::emission();
    RolloutFixture::legacyBoard($scenario['constructions'][0], stockUnits: 9);
    RolloutFixture::legacyBoard($scenario['constructions'][1]);
    $operator = salesBoardAuditActor();
    $this->actingAs($operator);

    $homologation = RolloutFixture::open($scenario['emission'], $operator);
    $service = app(SalesBoardRolloutHomologationService::class);
    $row = $homologation->constructions->firstWhere('construction_id', $scenario['constructions'][0]->id);

    $service->acceptDifference($row, 'O quadro legado contava um bloco que foi desmembrado.', $operator);

    // A fonte muda, e a reavaliação zera o aceite na própria linha.
    ConstructionUnit::factory()->create([
        'construction_id' => $scenario['constructions'][0]->id,
        'block' => '01', 'unit' => '888',
        'base_value' => '400000.00', 'base_value_reference_date' => '2026-01-01',
    ]);
    $service->reassess($homologation);

    expect($row->fresh()->accepted_difference)->toBeFalse()
        ->and($row->fresh()->difference_reason)->toBeNull();

    salesBoardAuditPurgeDisposableWindow();

    $trail = salesBoardAuditTrailOf($row);
    $discarded = $trail->last();

    // Só as duas mudanças de aceite: o retrato derivado não vira trilha.
    expect($trail)->toHaveCount(2)
        ->and($trail->pluck('event')->unique()->values()->all())->toBe(['updated'])
        ->and($discarded->log_name)->toBe('sales_board')
        ->and(data_get($discarded->attribute_changes, 'old.accepted_difference'))->toBeTrue()
        ->and(data_get($discarded->attribute_changes, 'old.difference_reason'))->toBe('O quadro legado contava um bloco que foi desmembrado.')
        ->and(data_get($discarded->attribute_changes, 'old.accepted_by_user_id'))->toBe($operator->id)
        ->and(data_get($discarded->attribute_changes, 'attributes.accepted_difference'))->toBeFalse();
});

it('keeps an accepted difference whose construction left the Emission', function () {
    $scenario = RolloutFixture::emission();
    RolloutFixture::legacyBoard($scenario['constructions'][0], stockUnits: 9);
    RolloutFixture::legacyBoard($scenario['constructions'][1]);
    $operator = salesBoardAuditActor();
    $this->actingAs($operator);

    $homologation = RolloutFixture::open($scenario['emission'], $operator);
    $service = app(SalesBoardRolloutHomologationService::class);
    $row = $homologation->constructions->firstWhere('construction_id', $scenario['constructions'][0]->id);

    $service->acceptDifference($row, 'O quadro legado contava um bloco que foi desmembrado.', $operator);

    // A saída é montada por baixo dos eventos: pelo model, a guarda das fontes
    // do Quadro recusaria trocar de Emissão uma obra com quadro registrado.
    DB::table('constructions')
        ->where('id', $scenario['constructions'][0]->id)
        ->update(['emission_id' => Emission::factory()->create(['status' => 'active'])->id]);

    $service->reassess($homologation);

    expect($row->fresh())->toBeNull();

    salesBoardAuditPurgeDisposableWindow();

    $removal = salesBoardAuditTrailOf($row)->last();

    expect($removal->event)->toBe('deleted')
        ->and($removal->log_name)->toBe('sales_board')
        ->and($removal->causer_id)->toBe($operator->id)
        ->and(data_get($removal->attribute_changes, 'old.sales_board_rollout_homologation_id'))->toBe($homologation->id)
        ->and(data_get($removal->attribute_changes, 'old.construction_id'))->toBe($scenario['constructions'][0]->id)
        ->and(data_get($removal->attribute_changes, 'old.accepted_difference'))->toBeTrue()
        ->and(data_get($removal->attribute_changes, 'old.difference_reason'))->toBe('O quadro legado contava um bloco que foi desmembrado.')
        ->and(data_get($removal->attribute_changes, 'old.accepted_by_user_id'))->toBe($operator->id);
});

it('records who added and who removed a rollout recipient', function () {
    $scenario = RolloutFixture::emission(1);
    $operator = salesBoardAuditActor();
    $this->actingAs($operator);

    $recipientUser = RolloutFixture::operationalUser();
    $directory = app(SalesBoardRolloutRecipientDirectory::class);

    $recipient = $directory->add($scenario['emission'], SalesBoardRolloutRecipientRole::Management, $recipientUser, $operator);
    $directory->remove($recipient);

    salesBoardAuditPurgeDisposableWindow();

    $trail = salesBoardAuditTrailOf($recipient);

    expect($trail->pluck('event')->all())->toBe(['created', 'deleted'])
        ->and($trail->pluck('log_name')->unique()->values()->all())->toBe(['sales_board'])
        ->and($trail->pluck('causer_id')->unique()->values()->all())->toBe([$operator->id])
        ->and(data_get($trail->last()->attribute_changes, 'old.user_id'))->toBe($recipientUser->id)
        ->and(data_get($trail->last()->attribute_changes, 'old.role'))->toBe(SalesBoardRolloutRecipientRole::Management->value);
});

// ── Fontes ────────────────────────────────────────────────────────────────────

it('keeps who deleted and restored a contract, and who changed its buyers, past a year', function () {
    $operator = salesBoardAuditActor();
    $this->actingAs($operator);

    $contract = Contract::factory()->create();
    $installment = ContractInstallment::factory()->for($contract)->create();

    $contract->syncBuyers([Client::factory()->create()->getKey()]);
    $installment->delete();
    $contract->delete();
    $contract->restore();

    salesBoardAuditPurgeDisposableWindow();

    $contractTrail = salesBoardAuditTrailOf($contract);
    $installmentTrail = salesBoardAuditTrailOf($installment);

    expect($contractTrail->pluck('log_name')->unique()->values()->all())->toBe(['contracts'])
        ->and($contractTrail->pluck('event')->all())->toContain('deleted', 'restored')
        ->and($contractTrail->first(fn (Activity $activity): bool => data_get($activity->attribute_changes, 'attributes.client_ids') !== null))->not->toBeNull()
        ->and($contractTrail->firstWhere('event', 'deleted')->causer_id)->toBe($operator->id)
        ->and($installmentTrail->pluck('log_name')->unique()->values()->all())->toBe(['contract_installments'])
        ->and($installmentTrail->pluck('event')->all())->toContain('deleted');
});

it('leaves no Sales Board source trail in the disposable bucket', function () {
    $this->actingAs(salesBoardAuditActor());

    $emission = Emission::factory()->create(['status' => 'active']);
    $construction = Construction::factory()->create(['emission_id' => $emission->getKey()]);
    $unit = ConstructionUnit::factory()->create(['construction_id' => $construction->getKey()]);
    $contract = Contract::factory()->forUnit($unit)->create();
    ContractInstallment::factory()->for($contract)->create();
    ConstructionUnitExchange::factory()->forUnit(ConstructionUnit::factory()->create(['construction_id' => $construction->getKey()]))->create();

    $sources = [
        Emission::class => 'emissions',
        Construction::class => 'constructions',
        ConstructionUnit::class => 'construction_units',
        ConstructionUnitExchange::class => 'construction_unit_exchanges',
        Contract::class => 'contracts',
        ContractInstallment::class => 'contract_installments',
    ];

    foreach ($sources as $model => $logName) {
        expect(Activity::query()->where('subject_type', $model)->pluck('log_name')->unique()->values()->all())
            ->toBe([$logName]);
    }

    $recorded = Activity::query()->whereIn('subject_type', array_keys($sources))->count();

    salesBoardAuditPurgeDisposableWindow();

    expect(Activity::query()->whereIn('subject_type', array_keys($sources))->count())->toBe($recorded);
});
