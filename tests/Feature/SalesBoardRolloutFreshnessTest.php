<?php

use App\Enums\SalesBoardRolloutEventType;
use App\Enums\SalesBoardRolloutHomologationStatus;
use App\Enums\SalesBoardRolloutRecipientRole;
use App\Enums\SalesBoardSource;
use App\Exceptions\SalesBoardRolloutException;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\ConstructionUnitExchange;
use App\Models\ConstructionUnitValue;
use App\Models\Contract;
use App\Models\ContractInstallment;
use App\Models\Emission;
use App\Models\SalesBoard;
use App\Models\SalesBoardRolloutEvent;
use App\Models\SalesBoardRolloutHomologation;
use App\Models\SalesBoardRolloutHomologationConstruction;
use App\Models\SalesBoardRolloutRecipient;
use App\Models\SalesDiscountPolicy;
use App\Models\User;
use App\Services\SalesBoards\SalesBoardRolloutAssessmentService;
use App\Services\SalesBoards\SalesBoardRolloutHomologationService;
use App\Services\SalesBoards\SalesBoardRolloutRecipientDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Tests\Support\SalesBoards\DerivationFixture;
use Tests\Support\SalesBoards\RolloutFixture;

uses(RefreshDatabase::class);

/**
 * A homologação aprovada precisa continuar verdadeira até o instante da ativação.
 *
 * Aprovar e ativar são atos separados, e entre eles a fonte pode mudar sem que
 * nenhum quadro manual apareça. A Gestão só pode ativar exatamente o que
 * revisou: se a posição derivada, a legada ou a fonte que as sustenta mudou, a
 * ativação é recusada e o caminho é uma nova homologação -- nunca reescrever a
 * aprovada.
 *
 * Roda também no MySQL: as posições atravessam coluna JSON, e um hash que só
 * coincidisse no SQLite recusaria toda ativação real.
 */
pest()->group('parity');

/**
 * Três empreendimentos, cada um num estado de comparação:
 *
 * - **A** coincide com o legado (2 unidades em estoque, 1.000.000);
 * - **B** diverge -- tem uma venda na competência de comparação, o que torna a
 *   política de desconto fonte do mês, e uma tabela de preço vigente;
 * - **C** não tem posição legada.
 *
 * @return array{emission: Emission, constructions: list<Construction>, stockUnitA: ConstructionUnit, contract: Contract, installment: ContractInstallment, unitValue: ConstructionUnitValue, policy: SalesDiscountPolicy, legacyBoard: SalesBoard}
 */
function freshnessScenario(): array
{
    $scenario = RolloutFixture::emission(3);
    [$matched, $diverging] = $scenario['constructions'];

    $legacyBoard = RolloutFixture::legacyBoard($matched);
    RolloutFixture::legacyBoard($diverging);

    $soldUnit = DerivationFixture::unit($diverging, 'B90');
    $contract = DerivationFixture::contract($soldUnit, '2026-07-15', '480000.00');
    $installment = DerivationFixture::installment($contract, '001', '2026-08-15', '480000.00');

    $unitValue = ConstructionUnitValue::factory()
        ->forUnit(ConstructionUnit::query()->where('construction_id', $diverging->id)->orderBy('id')->firstOrFail())
        ->effectiveFrom('2026-01-01')
        ->worth('500000.00')
        ->create();

    RolloutFixture::recipients($scenario['emission']);

    return [
        ...$scenario,
        'stockUnitA' => ConstructionUnit::query()->where('construction_id', $matched->id)->orderBy('id')->firstOrFail(),
        'contract' => $contract,
        'installment' => $installment,
        'unitValue' => $unitValue,
        'policy' => SalesDiscountPolicy::query()->where('construction_id', $diverging->id)->sole(),
        'legacyBoard' => $legacyBoard,
    ];
}

/**
 * As mudanças que os testes aplicam depois da aprovação.
 *
 * Por nome, e não por closure no dataset: o que cada uma toca fica num lugar só,
 * e a própria lista separa o que é fonte material do que não é.
 *
 * @param  array{emission: Emission, constructions: list<Construction>, stockUnitA: ConstructionUnit, contract: Contract, installment: ContractInstallment, unitValue: ConstructionUnitValue, policy: SalesDiscountPolicy, legacyBoard: SalesBoard}  $scenario
 */
function changeAfterApproval(string $change, array $scenario): void
{
    match ($change) {
        // Material: fonte que a derivação usa.
        'contract sale value' => $scenario['contract']->update(['sale_value' => '470000.00']),
        'installment payment' => $scenario['installment']->update(['payment_date' => '2026-07-20', 'paid_value' => '480000.00']),
        'unit value' => $scenario['unitValue']->update(['value' => '550000.00']),
        'discount policy' => $scenario['policy']->update(['maximum_discount_percent' => '1.00']),
        'exchange' => ConstructionUnitExchange::factory()->create([
            'construction_unit_id' => $scenario['stockUnitA']->id,
            'exchange_value' => '700000.00',
            'effective_from' => '2026-01-01',
        ]),
        'new unit' => DerivationFixture::unit($scenario['constructions'][0], 'A99'),

        // Escopo: o conjunto de empreendimentos da Emissão.
        'construction added' => RolloutFixture::construction($scenario['emission'], 'Z'),
        'construction removed' => $scenario['constructions'][2]->update([
            'emission_id' => Emission::factory()->create(['status' => 'active'])->id,
        ]),

        // Não material: o vencimento não participa de decisão nenhuma do Quadro.
        'installment due date' => $scenario['installment']->update(['due_date' => '2026-09-30']),
        // Não material: `updated_at` não é freshness.
        'records touched' => [
            $scenario['contract']->touch(),
            $scenario['unitValue']->touch(),
            $scenario['policy']->touch(),
            $scenario['legacyBoard']->fresh()->touch(),
        ],
        'construction renamed' => $scenario['constructions'][1]->update(['development_name' => 'Residencial Renomeado']),
        // Não material: destinatário é portão do momento, e não fato revisado.
        'operational recipient replaced' => replaceOperationalRecipient($scenario['emission']),
    };
}

function replaceOperationalRecipient(Emission $emission): void
{
    $directory = app(SalesBoardRolloutRecipientDirectory::class);

    $previous = SalesBoardRolloutRecipient::query()
        ->where('emission_id', $emission->id)
        ->forRole(SalesBoardRolloutRecipientRole::Operational)
        ->sole();

    $directory->add($emission, SalesBoardRolloutRecipientRole::Operational, RolloutFixture::operationalUser(), null);
    $directory->remove($previous);
}

/**
 * O caminho completo de uma tentativa: avaliar, analisar as diferenças, atestar
 * os impactos e aprovar. Os destinatários ficam de fora porque são da Emissão, e
 * não da tentativa.
 */
function homologateAndApprove(Emission $emission): SalesBoardRolloutHomologation
{
    return approveAttempt(RolloutFixture::open($emission));
}

function approveAttempt(SalesBoardRolloutHomologation $homologation): SalesBoardRolloutHomologation
{
    $actor = User::factory()->create();
    $service = app(SalesBoardRolloutHomologationService::class);

    foreach ($homologation->fresh()->constructions as $row) {
        if ($row->requiresAcknowledgement()) {
            $service->acceptDifference($row, 'Diferença entendida com a operação antes do rollout.', $actor);
        }
    }

    RolloutFixture::reviewImpacts($homologation->fresh(), $actor);

    return RolloutFixture::approve($homologation, $actor);
}

/**
 * Tudo o que uma ativação recusada não pode ter mexido.
 *
 * @return array{emission: array<string, mixed>, homologation: array<string, mixed>, rows: list<array<string, mixed>>, events: int}
 */
function rolloutState(Emission $emission, SalesBoardRolloutHomologation $homologation): array
{
    return [
        'emission' => Arr::only($emission->fresh()->getAttributes(), [
            'sales_board_source',
            'sales_board_automation_start_reference_month',
            'sales_board_auto_open_builder_review',
            'sales_board_active_homologation_id',
            'updated_at',
        ]),
        'homologation' => $homologation->fresh()->getAttributes(),
        'rows' => SalesBoardRolloutHomologationConstruction::query()
            ->where('sales_board_rollout_homologation_id', $homologation->id)
            ->orderBy('id')
            ->get()
            ->map(fn (SalesBoardRolloutHomologationConstruction $row): array => $row->getAttributes())
            ->all(),
        'events' => SalesBoardRolloutEvent::query()->where('emission_id', $emission->id)->count(),
    ];
}

function rolloutAssessment(): SalesBoardRolloutAssessmentService
{
    return app(SalesBoardRolloutAssessmentService::class);
}

it('activates an approved homologation whose source did not change', function () {
    $scenario = freshnessScenario();
    $homologation = homologateAndApprove($scenario['emission']);

    expect(rolloutAssessment()->observe($homologation)->assessmentHash)->toBe($homologation->assessment_hash);

    $emission = RolloutFixture::activate($scenario['emission'], $homologation);

    expect($emission->sales_board_source)->toBe(SalesBoardSource::Automated)
        ->and($emission->sales_board_automation_start_reference_month->format('Y-m'))->toBe('2026-08')
        ->and($emission->sales_board_active_homologation_id)->toBe($homologation->id)
        ->and(SalesBoardRolloutEvent::query()->where('event_type', SalesBoardRolloutEventType::Activated)->count())->toBe(1)
        // A única marca deixada na homologação aprovada é o registro de uso.
        ->and($homologation->fresh()->assessment_hash)->toBe($homologation->assessment_hash)
        ->and($homologation->fresh()->assessed_at->toDateTimeString())->toBe($homologation->assessed_at->toDateTimeString())
        ->and($homologation->fresh()->activated_at)->not->toBeNull();
});

it('refuses to activate when material source changed after approval', function (string $change) {
    $scenario = freshnessScenario();
    $homologation = homologateAndApprove($scenario['emission']);
    $before = rolloutState($scenario['emission'], $homologation);

    changeAfterApproval($change, $scenario);

    // Nenhum quadro manual novo: o que mudou foi a fonte, não o conflito.
    expect(rolloutAssessment()->legacyConflictsFor($scenario['emission']->fresh(), $homologation->startsAt()))->toBe([])
        ->and(rolloutAssessment()->observe($homologation->fresh())->assessmentHash)->not->toBe($homologation->assessment_hash);

    expect(fn () => RolloutFixture::activate($scenario['emission'], $homologation))
        ->toThrow(SalesBoardRolloutException::class, 'mudou desde a homologação aprovada. Faça uma nova homologação');

    $emission = $scenario['emission']->fresh();

    expect($emission->sales_board_source)->toBe(SalesBoardSource::Legacy)
        ->and($emission->sales_board_automation_start_reference_month)->toBeNull()
        ->and($emission->sales_board_active_homologation_id)->toBeNull()
        ->and(SalesBoardRolloutEvent::query()->where('event_type', SalesBoardRolloutEventType::Activated)->count())->toBe(0)
        // A homologação aprovada não foi reescrita, nem marcada como substituída.
        ->and($homologation->fresh()->status)->toBe(SalesBoardRolloutHomologationStatus::Approved)
        ->and($homologation->fresh()->activated_at)->toBeNull()
        ->and(rolloutState($scenario['emission'], $homologation))->toBe($before);
})->with([
    'contract sale value',
    'installment payment',
    'unit value',
    'discount policy',
    'exchange',
    'new unit',
]);

it('refuses to activate when the legacy board of the comparison month changed', function () {
    $scenario = freshnessScenario();
    $homologation = homologateAndApprove($scenario['emission']);
    $before = rolloutState($scenario['emission'], $homologation);

    // 07/2026 é a competência de comparação, anterior ao início: não há conflito.
    $board = $scenario['legacyBoard']->fresh();
    $board->changeReason = 'Correção do valor de estoque informada pela construtora.';
    $board->update(['stock_value' => '1100000.00']);

    expect(rolloutAssessment()->legacyConflictsFor($scenario['emission']->fresh(), $homologation->startsAt()))->toBe([]);

    expect(fn () => RolloutFixture::activate($scenario['emission'], $homologation))
        ->toThrow(SalesBoardRolloutException::class, 'mudou desde a homologação aprovada');

    expect(rolloutState($scenario['emission'], $homologation))->toBe($before);
});

it('refuses to activate when the emission scope changed', function (string $change) {
    $scenario = freshnessScenario();
    $homologation = homologateAndApprove($scenario['emission']);
    $before = rolloutState($scenario['emission'], $homologation);

    changeAfterApproval($change, $scenario);

    expect(fn () => RolloutFixture::activate($scenario['emission'], $homologation))
        ->toThrow(SalesBoardRolloutException::class, 'empreendimentos da Emissão mudaram');

    expect(rolloutState($scenario['emission'], $homologation))->toBe($before);
})->with([
    'construction added',
    'construction removed',
]);

it('still activates when only non-material data changed', function (string $change) {
    $scenario = freshnessScenario();
    $homologation = homologateAndApprove($scenario['emission']);

    $this->travel(5)->minutes();

    changeAfterApproval($change, $scenario);

    expect(rolloutAssessment()->observe($homologation->fresh())->assessmentHash)->toBe($homologation->assessment_hash);

    $emission = RolloutFixture::activate($scenario['emission'], $homologation);

    expect($emission->sales_board_source)->toBe(SalesBoardSource::Automated)
        ->and($emission->sales_board_active_homologation_id)->toBe($homologation->id);
})->with([
    'installment due date',
    'records touched',
    'construction renamed',
    'operational recipient replaced',
]);

it('refuses to activate when the only operational recipient is no longer operational', function () {
    $scenario = freshnessScenario();
    $homologation = homologateAndApprove($scenario['emission']);
    $before = rolloutState($scenario['emission'], $homologation);

    SalesBoardRolloutRecipient::query()
        ->where('emission_id', $scenario['emission']->id)
        ->forRole(SalesBoardRolloutRecipientRole::Operational)
        ->sole()
        ->user
        ->update(['is_active' => false]);

    // A recusa é de destinatário: a fonte revisada continua exatamente a mesma.
    expect(rolloutAssessment()->observe($homologation->fresh())->assessmentHash)->toBe($homologation->assessment_hash);

    expect(fn () => RolloutFixture::activate($scenario['emission'], $homologation))
        ->toThrow(SalesBoardRolloutException::class, 'precisa de pelo menos um responsável operacional ativo');

    expect(rolloutState($scenario['emission'], $homologation))->toBe($before);
});

it('requires a new homologation attempt once the approved one went stale', function () {
    $scenario = freshnessScenario();
    $stale = homologateAndApprove($scenario['emission']);

    changeAfterApproval('contract sale value', $scenario);

    expect(fn () => RolloutFixture::activate($scenario['emission'], $stale))
        ->toThrow(SalesBoardRolloutException::class, 'Faça uma nova homologação');

    $second = RolloutFixture::open($scenario['emission']);
    $divergingRow = $second->constructions->firstWhere('construction_id', $scenario['constructions'][1]->id);

    // O aceite da tentativa anterior não atravessa: a diferença nova precisa de
    // alguém que a tenha visto.
    expect($second->attempt)->toBe(2)
        ->and($second->assessment_hash)->not->toBe($stale->assessment_hash)
        ->and($divergingRow->requiresAcknowledgement())->toBeTrue()
        ->and($divergingRow->accepted_difference)->toBeFalse();

    $approved = approveAttempt($second);

    $emission = RolloutFixture::activate($scenario['emission'], $approved);

    expect($emission->sales_board_source)->toBe(SalesBoardSource::Automated)
        ->and($emission->sales_board_active_homologation_id)->toBe($approved->id)
        ->and(SalesBoardRolloutEvent::query()->sole()->sales_board_rollout_homologation_id)->toBe($approved->id)
        // A tentativa envelhecida fica como estava: aprovada, nunca usada.
        ->and($stale->fresh()->status)->toBe(SalesBoardRolloutHomologationStatus::Approved)
        ->and($stale->fresh()->activated_at)->toBeNull()
        ->and($stale->fresh()->assessment_hash)->toBe($stale->assessment_hash);
});

it('observes the assessment without writing anything', function () {
    $scenario = freshnessScenario();
    $homologation = homologateAndApprove($scenario['emission']);

    changeAfterApproval('contract sale value', $scenario);

    $before = rolloutState($scenario['emission'], $homologation);
    $writes = [];

    DB::listen(function ($query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql) === 1) {
            $writes[] = $query->sql;
        }
    });

    $observation = rolloutAssessment()->observe($homologation->fresh());

    expect($writes)->toBe([])
        ->and($observation->assessmentHash)->not->toBe($homologation->assessment_hash)
        ->and($observation->constructionScopeHash)->toBe($homologation->construction_scope_hash)
        ->and(array_keys($observation->constructionRows))->toBe(collect($scenario['constructions'])->pluck('id')->all())
        ->and(rolloutState($scenario['emission'], $homologation))->toBe($before);
});

it('persists exactly the rows its hash describes', function () {
    $scenario = freshnessScenario();
    $homologation = homologateAndApprove($scenario['emission']);

    // Coincidente, divergente e sem legado: as três formas de posição passam
    // pela coluna JSON e voltam produzindo o mesmo resumo.
    expect($homologation->constructions->pluck('comparison_status')->map->value->all())
        ->toBe(['coincide', 'divergente', 'sem_posicao_legada'])
        ->and(rolloutAssessment()->assessmentHash($homologation))->toBe($homologation->assessment_hash)
        ->and(rolloutAssessment()->observe($homologation)->assessmentHash)->toBe($homologation->assessment_hash);
});

it('keeps governance out of the assessment hash', function () {
    $scenario = freshnessScenario();
    $actor = User::factory()->create();
    $service = app(SalesBoardRolloutHomologationService::class);

    $homologation = RolloutFixture::open($scenario['emission'], $actor);
    $hash = $homologation->assessment_hash;

    foreach ($homologation->constructions as $row) {
        if ($row->requiresAcknowledgement()) {
            $service->acceptDifference($row, 'Diferença entendida com a operação antes do rollout.', $actor);
        }
    }

    RolloutFixture::reviewImpacts($homologation, $actor);

    // Aceites e atestações mudaram; os fatos não.
    expect(rolloutAssessment()->assessmentHash($homologation->fresh()))->toBe($hash)
        ->and(rolloutAssessment()->observe($homologation->fresh())->assessmentHash)->toBe($hash)
        ->and($service->reassess($homologation->fresh())->assessment_hash)->toBe($hash);
});

it('observes the source inside the activation transaction', function () {
    $scenario = freshnessScenario();
    $homologation = homologateAndApprove($scenario['emission']);

    $outerLevel = DB::transactionLevel();
    $levels = [];

    DB::listen(function ($query) use (&$levels): void {
        if (preg_match('/from\s+["`]?contracts["`]?\s/i', $query->sql) === 1) {
            $levels[] = DB::transactionLevel();
        }
    });

    RolloutFixture::activate($scenario['emission'], $homologation);

    // A fonte é lida depois do lock da Emissão, na mesma transação que grava o
    // modo: entre observar e ativar não há janela.
    expect($levels)->not->toBeEmpty()
        ->and(min($levels))->toBeGreaterThan($outerLevel);
});
