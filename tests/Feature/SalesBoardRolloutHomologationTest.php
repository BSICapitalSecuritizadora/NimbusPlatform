<?php

use App\Enums\SalesBoardRolloutComparisonStatus;
use App\Enums\SalesBoardRolloutHomologationStatus;
use App\Enums\SalesBoardRolloutRecipientRole;
use App\Enums\SalesBoardSource;
use App\Exceptions\SalesBoardRolloutException;
use App\Models\ConstructionUnit;
use App\Models\Emission;
use App\Models\SalesBoardRolloutHomologation;
use App\Models\User;
use App\Services\SalesBoards\SalesBoardRolloutHomologationService;
use App\Services\SalesBoards\SalesBoardRolloutRecipientDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SalesBoards\RolloutFixture;

uses(RefreshDatabase::class);

it('starts every emission in legacy mode', function () {
    $emission = Emission::factory()->create(['status' => 'active']);

    expect($emission->sales_board_source)->toBe(SalesBoardSource::Legacy)
        ->and($emission->sales_board_automation_start_reference_month)->toBeNull()
        ->and($emission->sales_board_auto_open_builder_review)->toBeFalse()
        ->and($emission->sales_board_active_homologation_id)->toBeNull()
        ->and($emission->usesAutomatedSalesBoard())->toBeFalse();
});

it('opens a homologation with the natural comparison month', function () {
    $scenario = RolloutFixture::emission();
    $actor = User::factory()->create();

    $homologation = RolloutFixture::open($scenario['emission'], $actor);

    expect($homologation->status)->toBe(SalesBoardRolloutHomologationStatus::Draft)
        ->and($homologation->attempt)->toBe(1)
        ->and($homologation->proposed_start_reference_month->format('Y-m'))->toBe('2026-08')
        // A fronteira natural: a última competência legada contra a primeira automatizada.
        ->and($homologation->comparison_reference_month->format('Y-m'))->toBe('2026-07')
        ->and($homologation->created_by_user_id)->toBe($actor->id)
        ->and($homologation->constructions)->toHaveCount(2)
        ->and($homologation->assessment_hash)->not->toBeNull()
        ->and($homologation->construction_scope_hash)->not->toBeNull()
        // Homologar não ativa nada.
        ->and($scenario['emission']->fresh()->sales_board_source)->toBe(SalesBoardSource::Legacy);
});

it('refuses a second open homologation for the same emission', function () {
    $scenario = RolloutFixture::emission();
    RolloutFixture::open($scenario['emission']);

    expect(fn () => RolloutFixture::open($scenario['emission']))
        ->toThrow(SalesBoardRolloutException::class, 'Já existe a homologação')
        ->and(SalesBoardRolloutHomologation::query()->count())->toBe(1);
});

it('reports a matched comparison when legacy and derived agree', function () {
    $scenario = RolloutFixture::emission();

    foreach ($scenario['constructions'] as $construction) {
        RolloutFixture::legacyBoard($construction);
    }

    $homologation = RolloutFixture::open($scenario['emission']);
    $row = $homologation->constructions->first();

    expect($homologation->constructions->pluck('comparison_status')->unique()->all())
        ->toBe([SalesBoardRolloutComparisonStatus::Matched])
        ->and($row->is_ready)->toBeTrue()
        ->and($row->requiresAcknowledgement())->toBeFalse()
        ->and($row->hasAnyDelta())->toBeFalse()
        ->and($row->legacy_position['buckets']['stock']['units'])->toBe(2)
        ->and($row->derived_position['buckets']['stock']['units'])->toBe(2);
});

it('reports the delta when legacy and derived disagree', function () {
    $scenario = RolloutFixture::emission();

    // O legado diz 5 unidades a 3.000.000; a derivação apura 2 a 1.000.000.
    RolloutFixture::legacyBoard($scenario['constructions'][0], stockUnits: 5, stockValue: '3000000.00');
    RolloutFixture::legacyBoard($scenario['constructions'][1]);

    $homologation = RolloutFixture::open($scenario['emission']);
    $row = $homologation->constructions
        ->firstWhere('construction_id', $scenario['constructions'][0]->id);

    expect($row->comparison_status)->toBe(SalesBoardRolloutComparisonStatus::Different)
        ->and($row->requiresAcknowledgement())->toBeTrue()
        ->and($row->deltaFor('stock'))->toBe(['units' => -3, 'valueCents' => -200_000_000])
        ->and($row->position_delta['total_units'])->toBe(-3);
});

it('never turns a missing legacy position into zero', function () {
    $scenario = RolloutFixture::emission();

    $homologation = RolloutFixture::open($scenario['emission']);
    $row = $homologation->constructions->first();

    expect($row->comparison_status)->toBe(SalesBoardRolloutComparisonStatus::NoLegacyPosition)
        ->and($row->legacy_position)->toBeNull()
        ->and($row->requiresAcknowledgement())->toBeTrue()
        // Sem legado não há delta calculável -- e um delta contra zero seria inventado.
        ->and($row->position_delta['comparable'])->toBeFalse();
});

it('blocks approval while a construction is not ready', function () {
    $scenario = RolloutFixture::emission();

    // Uma unidade em estoque sem valor vigente: a prontidão bloqueia.
    ConstructionUnit::factory()->create([
        'construction_id' => $scenario['constructions'][0]->id,
        'block' => '01', 'unit' => '999',
        'base_value' => null, 'base_value_reference_date' => null,
    ]);

    $homologation = RolloutFixture::open($scenario['emission']);
    RolloutFixture::recipients($scenario['emission']);
    RolloutFixture::reviewImpacts($homologation);

    expect($homologation->constructions->firstWhere('construction_id', $scenario['constructions'][0]->id)->is_ready)
        ->toBeFalse();

    expect(fn () => RolloutFixture::approve($homologation))
        ->toThrow(SalesBoardRolloutException::class, 'fonte de 1 empreendimento');

    expect($homologation->fresh()->status)->toBe(SalesBoardRolloutHomologationStatus::Draft);
});

it('blocks approval while a difference has not been analysed', function () {
    $scenario = RolloutFixture::emission();
    RolloutFixture::legacyBoard($scenario['constructions'][0], stockUnits: 9);
    RolloutFixture::legacyBoard($scenario['constructions'][1]);

    $homologation = RolloutFixture::open($scenario['emission']);
    RolloutFixture::recipients($scenario['emission']);
    RolloutFixture::reviewImpacts($homologation);

    expect(fn () => RolloutFixture::approve($homologation))
        ->toThrow(SalesBoardRolloutException::class, 'comparação com o legado ainda não foi analisada');
});

it('accepts a difference with a reason, and then allows approval', function () {
    $scenario = RolloutFixture::emission();
    RolloutFixture::legacyBoard($scenario['constructions'][0], stockUnits: 9);
    RolloutFixture::legacyBoard($scenario['constructions'][1]);

    $homologation = RolloutFixture::open($scenario['emission']);
    $actor = User::factory()->create();
    $service = app(SalesBoardRolloutHomologationService::class);

    $row = $homologation->constructions->firstWhere('construction_id', $scenario['constructions'][0]->id);

    $accepted = $service->acceptDifference(
        $row,
        'O quadro legado incluía unidades de um bloco que foi desmembrado.',
        $actor,
    );

    expect($accepted->accepted_difference)->toBeTrue()
        ->and($accepted->difference_reason)->toBe('O quadro legado incluía unidades de um bloco que foi desmembrado.')
        ->and($accepted->accepted_by_user_id)->toBe($actor->id)
        ->and($accepted->accepted_at)->not->toBeNull()
        ->and($accepted->requiresAcknowledgement())->toBeFalse();

    RolloutFixture::recipients($scenario['emission']);
    RolloutFixture::reviewImpacts($homologation);

    expect(RolloutFixture::approve($homologation)->status)
        ->toBe(SalesBoardRolloutHomologationStatus::Approved);
});

it('refuses to accept a difference without a usable reason', function (string $reason) {
    $scenario = RolloutFixture::emission();
    RolloutFixture::legacyBoard($scenario['constructions'][0], stockUnits: 9);

    $homologation = RolloutFixture::open($scenario['emission']);
    $row = $homologation->constructions->firstWhere('construction_id', $scenario['constructions'][0]->id);

    expect(fn () => app(SalesBoardRolloutHomologationService::class)
        ->acceptDifference($row, $reason, User::factory()->create()))
        ->toThrow(SalesBoardRolloutException::class, 'pelo menos 10 caracteres');
})->with(['', ' ', 'ok', '.', '-']);

it('blocks approval until both impact reviews are recorded', function () {
    $scenario = RolloutFixture::emission();

    foreach ($scenario['constructions'] as $construction) {
        RolloutFixture::legacyBoard($construction);
    }

    $homologation = RolloutFixture::open($scenario['emission']);
    RolloutFixture::recipients($scenario['emission']);
    $actor = User::factory()->create();
    $service = app(SalesBoardRolloutHomologationService::class);

    expect(fn () => RolloutFixture::approve($homologation))
        ->toThrow(SalesBoardRolloutException::class, 'Garantias ainda não foi revisado');

    $service->markGuaranteesReviewed($homologation, $actor);

    expect(fn () => RolloutFixture::approve($homologation))
        ->toThrow(SalesBoardRolloutException::class, 'Relatório Mensal ainda não foi revisado');

    $service->markMonthlyReportReviewed($homologation->fresh(), $actor);

    $approved = RolloutFixture::approve($homologation);

    expect($approved->status)->toBe(SalesBoardRolloutHomologationStatus::Approved)
        ->and($approved->guaranteesReviewed())->toBeTrue()
        ->and($approved->monthlyReportReviewed())->toBeTrue()
        ->and($approved->guarantees_reviewed_by_user_id)->toBe($actor->id);
});

it('blocks approval until both recipient roles are covered', function () {
    $scenario = RolloutFixture::emission();

    foreach ($scenario['constructions'] as $construction) {
        RolloutFixture::legacyBoard($construction);
    }

    $homologation = RolloutFixture::open($scenario['emission']);
    RolloutFixture::reviewImpacts($homologation);

    expect(fn () => RolloutFixture::approve($homologation))
        ->toThrow(SalesBoardRolloutException::class, 'responsável operacional');

    app(SalesBoardRolloutRecipientDirectory::class)->add(
        $scenario['emission'],
        SalesBoardRolloutRecipientRole::Operational,
        RolloutFixture::operationalUser(),
        null,
    );

    expect(fn () => RolloutFixture::approve($homologation))
        ->toThrow(SalesBoardRolloutException::class, 'responsável da gestão');
});

it('blocks approval when a legacy board already exists at or after the start month', function () {
    $scenario = RolloutFixture::emission();

    foreach ($scenario['constructions'] as $construction) {
        RolloutFixture::legacyBoard($construction);
    }

    // Um quadro manual em 09/2026, com ativação proposta para 08/2026.
    $manual = RolloutFixture::legacyBoard($scenario['constructions'][0], '2026-09-01');

    $homologation = RolloutFixture::open($scenario['emission']);
    RolloutFixture::recipients($scenario['emission']);
    RolloutFixture::reviewImpacts($homologation);

    expect(fn () => RolloutFixture::approve($homologation))
        ->toThrow(SalesBoardRolloutException::class, 'Já existe Quadro de Vendas registrado a partir da competência inicial');

    // O registro manual continua intacto: mover a competência inicial é o caminho.
    expect($manual->fresh()->stock_units)->toBe(2)
        ->and($homologation->fresh()->status)->toBe(SalesBoardRolloutHomologationStatus::Draft);
});

it('blocks approval when the source changed after the review', function () {
    $scenario = RolloutFixture::emission();

    foreach ($scenario['constructions'] as $construction) {
        RolloutFixture::legacyBoard($construction);
    }

    $homologation = RolloutFixture::open($scenario['emission']);
    RolloutFixture::recipients($scenario['emission']);
    RolloutFixture::reviewImpacts($homologation);

    // A fonte muda depois de a pessoa ter revisado.
    ConstructionUnit::factory()->create([
        'construction_id' => $scenario['constructions'][0]->id,
        'block' => '01', 'unit' => '777',
        'base_value' => '400000.00', 'base_value_reference_date' => '2026-01-01',
    ]);

    expect(fn () => RolloutFixture::approve($homologation))
        ->toThrow(SalesBoardRolloutException::class, 'mudou desde a última revisão');

    expect($homologation->fresh()->status)->toBe(SalesBoardRolloutHomologationStatus::Draft);
});

it('invalidates an acceptance when the underlying difference changes', function () {
    $scenario = RolloutFixture::emission();
    RolloutFixture::legacyBoard($scenario['constructions'][0], stockUnits: 9);
    RolloutFixture::legacyBoard($scenario['constructions'][1]);

    $homologation = RolloutFixture::open($scenario['emission']);
    $service = app(SalesBoardRolloutHomologationService::class);
    $row = $homologation->constructions->firstWhere('construction_id', $scenario['constructions'][0]->id);

    $service->acceptDifference($row, 'Diferença entendida na primeira revisão.', User::factory()->create());

    expect($row->fresh()->accepted_difference)->toBeTrue();

    // A fonte muda: o delta aceito não é mais o delta que existe.
    ConstructionUnit::factory()->create([
        'construction_id' => $scenario['constructions'][0]->id,
        'block' => '01', 'unit' => '888',
        'base_value' => '400000.00', 'base_value_reference_date' => '2026-01-01',
    ]);

    $service->reassess($homologation);

    expect($row->fresh()->accepted_difference)->toBeFalse()
        ->and($row->fresh()->difference_reason)->toBeNull()
        ->and($row->fresh()->accepted_by_user_id)->toBeNull();
});

it('keeps an acceptance when nothing material changed', function () {
    $scenario = RolloutFixture::emission();
    RolloutFixture::legacyBoard($scenario['constructions'][0], stockUnits: 9);
    RolloutFixture::legacyBoard($scenario['constructions'][1]);

    $homologation = RolloutFixture::open($scenario['emission']);
    $service = app(SalesBoardRolloutHomologationService::class);
    $row = $homologation->constructions->firstWhere('construction_id', $scenario['constructions'][0]->id);

    $service->acceptDifference($row, 'Diferença entendida e registrada.', User::factory()->create());
    $hashBefore = $homologation->fresh()->assessment_hash;

    $service->reassess($homologation);

    expect($row->fresh()->accepted_difference)->toBeTrue()
        ->and($row->fresh()->difference_reason)->toBe('Diferença entendida e registrada.')
        // Reavaliar sobre a mesma fonte é idempotente.
        ->and($homologation->fresh()->assessment_hash)->toBe($hashBefore);
});

it('freezes an approved homologation', function () {
    $scenario = RolloutFixture::emission();

    foreach ($scenario['constructions'] as $construction) {
        RolloutFixture::legacyBoard($construction);
    }

    $homologation = RolloutFixture::approvedHomologation($scenario['emission']);

    expect(fn () => $homologation->forceFill(['proposed_start_reference_month' => '2026-09-01'])->save())
        ->toThrow(LogicException::class, 'immutable')
        ->and(fn () => $homologation->delete())
        ->toThrow(LogicException::class, 'cannot be deleted')
        // E aprovar não ativa.
        ->and($scenario['emission']->fresh()->sales_board_source)->toBe(SalesBoardSource::Legacy);
});

it('records a rejection with its reason', function () {
    $scenario = RolloutFixture::emission();
    $homologation = RolloutFixture::open($scenario['emission']);
    $actor = User::factory()->create();

    $rejected = app(SalesBoardRolloutHomologationService::class)
        ->reject($homologation, $actor, 'Cadastro de unidades ainda incompleto na carteira.');

    expect($rejected->status)->toBe(SalesBoardRolloutHomologationStatus::Rejected)
        ->and($rejected->rejection_reason)->toBe('Cadastro de unidades ainda incompleto na carteira.')
        ->and($rejected->rejected_by_user_id)->toBe($actor->id)
        ->and($rejected->isEditable())->toBeFalse();
});
