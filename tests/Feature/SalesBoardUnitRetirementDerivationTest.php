<?php

use App\DTOs\SalesBoards\SalesBoardDerivedPosition;
use App\Enums\ContractStatus;
use App\Enums\SalesBoardDiffCode;
use App\Enums\SalesBoardIssueCode;
use App\Enums\SalesBoardIssueSeverity;
use App\Enums\SalesBoardRecalculationOutcome;
use App\Enums\SalesBoardStaleImpact;
use App\Enums\SalesBoardUnitClassification;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\ConstructionUnitExchange;
use App\Models\ConstructionUnitRetirement;
use App\Models\SalesBoardCycleBaseline;
use App\Services\SalesBoards\ConstructionUnitRetirementService;
use App\Services\SalesBoards\SalesBoardCompetenceBridgeBuilder;
use App\Services\SalesBoards\SalesBoardCycleCancellationService;
use App\Services\SalesBoards\SalesBoardDerivationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SalesBoards\CycleFixture;
use Tests\Support\SalesBoards\DerivationFixture;
use Tests\Support\SalesBoards\ExtemporaneousFixture;
use Tests\Support\SalesBoards\GovernanceFixture;

/**
 * A baixa na derivação: a unidade sai das linhas a partir da competência que
 * contém a data, a competência em que a presença muda recebe o aviso, e a
 * baixada que continua ocupada vira linha indeterminada -- nunca some.
 */
uses(RefreshDatabase::class);

pest()->group('parity');

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00:00'));
});

function retirementDerivation(Construction $construction, string $month): SalesBoardDerivedPosition
{
    return DerivationFixture::derive($construction->fresh(), $month);
}

function retireThroughService(ConstructionUnit $unit, string $date): ConstructionUnitRetirement
{
    return app(ConstructionUnitRetirementService::class)->retire(
        $unit,
        GovernanceFixture::approver(),
        CarbonImmutable::parse($date),
        'Unidade cadastrada em duplicidade na carga inicial.',
    );
}

/**
 * @return list<string>
 */
function issueCodesFor(SalesBoardDerivedPosition $position, ConstructionUnit $unit): array
{
    return collect($position->issues)
        ->filter(fn ($issue): bool => $issue->constructionUnitId === $unit->id)
        ->map(fn ($issue): string => $issue->code->value)
        ->values()
        ->all();
}

/**
 * A ocupação gravada por fora do serviço, por nome: a lista mostra o contrato
 * e a permuta lado a lado.
 */
function occupyRetiredUnit(string $occupant, ConstructionUnit $unit): void
{
    match ($occupant) {
        'contrato' => DerivationFixture::contract($unit, '2026-07-15', '480000.00'),
        'permuta' => ConstructionUnitExchange::factory()->forUnit($unit)->effectiveFrom('2026-07-05')->worth('480000.00')->create(),
    };
}

it('takes the retired unit out from the competence that contains the date, keeping the frozen history', function () {
    [$construction, $units] = CycleFixture::readyConstruction(3);
    $cycle = CycleFixture::generate($construction)->cycle;
    $frozen = CycleFixture::currentBaseline($cycle);

    retireThroughService($units[2], '2026-08-10');

    $july = retirementDerivation($construction, '2026-07-01');
    $august = retirementDerivation($construction, '2026-08-01');
    $september = retirementDerivation($construction, '2026-09-01');

    expect($july->unitsTotal)->toBe(3)
        ->and($july->stockUnits)->toBe(3)
        ->and($july->stockValueCents)->toBe(150_000_000)
        ->and($august->unitsTotal)->toBe(2)
        ->and($august->stockUnits)->toBe(2)
        ->and($august->stockValueCents)->toBe(100_000_000)
        ->and(DerivationFixture::lineFor($august, $units[2]))->toBeNull()
        ->and($september->unitsTotal)->toBe(2)
        ->and($august->bucketsBalance())->toBeTrue();

    // O que foi congelado não muda: a versão de 07/2026 continua com a unidade.
    expect(SalesBoardCycleBaseline::query()->findOrFail($frozen->id)->lines()->count())->toBe(3)
        ->and((int) SalesBoardCycleBaseline::query()->findOrFail($frozen->id)->units_total)->toBe(3);
});

it('warns of the retirement only in the competence where the unit leaves, without the internal reason', function () {
    [$construction, $units] = CycleFixture::readyConstruction(3);
    retireThroughService($units[2], '2026-08-10');

    $july = retirementDerivation($construction, '2026-07-01');
    $august = retirementDerivation($construction, '2026-08-01');
    $september = retirementDerivation($construction, '2026-09-01');

    $warning = collect($august->issues)->firstWhere('code', SalesBoardIssueCode::UnitRetired);

    expect(issueCodesFor($july, $units[2]))->toBe([])
        ->and(issueCodesFor($august, $units[2]))->toBe([SalesBoardIssueCode::UnitRetired->value])
        ->and($warning->constructionUnitId)->toBe($units[2]->id)
        ->and($warning->severity())->toBe(SalesBoardIssueSeverity::Warning)
        ->and($warning->message)->toContain($units[2]->display_name)
        ->and($warning->message)->toContain('10/08/2026')
        ->and($warning->message)->not->toContain('duplicidade')
        ->and($august->isComplete())->toBeTrue()
        ->and($august->blockingIssues())->toBe([])
        ->and(issueCodesFor($september, $units[2]))->toBe([]);
});

it('brings the unit back in the competence of the reactivation, and an annulled retirement changes nothing', function () {
    [$construction, $units] = CycleFixture::readyConstruction(3);

    ConstructionUnitRetirement::factory()->forUnit($units[2])->retiredOn('2026-07-10')->reactivatedOn('2026-08-20')->create();
    ConstructionUnitRetirement::factory()->forUnit($units[1])->retiredOn('2026-07-10')->reactivatedOn('2026-07-10')->create();

    $july = retirementDerivation($construction, '2026-07-01');
    $august = retirementDerivation($construction, '2026-08-01');

    expect($july->unitsTotal)->toBe(2)
        ->and(issueCodesFor($july, $units[2]))->toBe([SalesBoardIssueCode::UnitRetired->value])
        ->and($august->unitsTotal)->toBe(3)
        ->and(DerivationFixture::lineFor($august, $units[2])?->classification)->toBe(SalesBoardUnitClassification::Stock)
        ->and(issueCodesFor($august, $units[2]))->toBe([SalesBoardIssueCode::UnitReactivated->value])
        ->and(collect($august->issues)->firstWhere('code', SalesBoardIssueCode::UnitReactivated)->message)->toContain('20/08/2026')
        // A baixa anulada não vale em data nenhuma.
        ->and(DerivationFixture::lineFor($july, $units[1]))->not->toBeNull()
        ->and(issueCodesFor($july, $units[1]))->toBe([])
        ->and(issueCodesFor($august, $units[1]))->toBe([]);
});

/**
 * Com julho cancelado, agosto parte de junho e absorve julho: a presença da
 * unidade é comparada com o fim de junho, e não com o fim de julho. Sem isso a
 * baixa (ou a reativação) datada em julho já valeria nas duas pontas, nenhuma
 * competência receberia o aviso, e a ponte acusaria a saída (ou a entrada) sem
 * explicação.
 */
it('warns of a retirement dated in a cancelled month in the competence that absorbs it, and the bridge explains it', function () {
    [$construction, $units] = CycleFixture::readyConstruction(3);
    ExtemporaneousFixture::publish(CycleFixture::generate($construction, '2026-06-01')->cycle);
    app(SalesBoardCycleCancellationService::class)->cancel(
        CycleFixture::generate($construction, '2026-07-01')->cycle,
        GovernanceFixture::approver(),
        'Competência cancelada no piloto.',
    );

    retireThroughService($units[2], '2026-07-10');

    $august = CycleFixture::generate($construction, '2026-08-01')->cycle;
    $baseline = CycleFixture::currentBaseline($august);
    $bridge = app(SalesBoardCompetenceBridgeBuilder::class)->forBaseline($baseline);

    expect($baseline->units_total)->toBe(2)
        ->and(collect($baseline->frozenWarnings())->where('construction_unit_id', $units[2]->id)->pluck('code')->all())
        ->toBe([SalesBoardIssueCode::UnitRetired->value])
        ->and($bridge->explanations)->toBe(['Baixas' => 1])
        ->and($bridge->unexplainedUnits)->toBe([]);
});

it('warns of a reactivation dated in a cancelled month in the competence that absorbs it, and the bridge explains it', function () {
    [$construction, $units] = CycleFixture::readyConstruction(3);
    ConstructionUnitRetirement::factory()->forUnit($units[2])->retiredOn('2026-05-10')->reactivatedOn('2026-07-15')->create();

    ExtemporaneousFixture::publish(CycleFixture::generate($construction, '2026-06-01')->cycle);
    app(SalesBoardCycleCancellationService::class)->cancel(
        CycleFixture::generate($construction, '2026-07-01')->cycle,
        GovernanceFixture::approver(),
        'Competência cancelada no piloto.',
    );

    $august = CycleFixture::generate($construction, '2026-08-01')->cycle;
    $baseline = CycleFixture::currentBaseline($august);
    $bridge = app(SalesBoardCompetenceBridgeBuilder::class)->forBaseline($baseline);

    expect($baseline->units_total)->toBe(3)
        ->and(collect($baseline->frozenWarnings())->where('construction_unit_id', $units[2]->id)->pluck('code')->all())
        ->toBe([SalesBoardIssueCode::UnitReactivated->value])
        ->and($bridge->explanations)->toBe(['Reativações' => 1])
        ->and($bridge->unexplainedUnits)->toBe([]);
});

it('keeps a retired unit that is still occupied as an undetermined line that blocks', function (string $occupant) {
    [$construction, $units] = CycleFixture::readyConstruction(3);

    ConstructionUnitRetirement::factory()->forUnit($units[2])->retiredOn('2026-07-01')->create();
    occupyRetiredUnit($occupant, $units[2]);

    $position = retirementDerivation($construction, '2026-07-01');
    $line = DerivationFixture::lineFor($position, $units[2]);

    expect($position->unitsTotal)->toBe(3)
        ->and($line?->classification)->toBe(SalesBoardUnitClassification::Undetermined)
        ->and(issueCodesFor($position, $units[2]))->toContain(SalesBoardIssueCode::RetiredUnitInUse->value)
        ->and(SalesBoardIssueCode::RetiredUnitInUse->severity())->toBe(SalesBoardIssueSeverity::Blocker)
        ->and($position->isComplete())->toBeFalse()
        ->and(issueCodesFor($position, $units[2]))->not->toContain(SalesBoardIssueCode::UnitRetired->value)
        ->and(collect($position->issues)->firstWhere('code', SalesBoardIssueCode::RetiredUnitInUse)->message)
        ->toContain('baixada desde 01/07/2026');
})->with(['contrato', 'permuta']);

it('keeps block and unit on the movements of a unit retired later in the same month', function () {
    [$construction, $units] = CycleFixture::readyConstruction(3);
    $contract = DerivationFixture::contract($units[2], '2026-08-03', '480000.00', cancellationDate: '2026-08-05', status: ContractStatus::Cancelled);

    retireThroughService($units[2], '2026-08-10');

    $august = retirementDerivation($construction, '2026-08-01');

    $sale = collect($august->movements->sales)->firstWhere('contractId', $contract->id);
    $cancellation = collect($august->movements->cancellations)->firstWhere('contractId', $contract->id);
    $codes = DerivationFixture::issueCodes($august);

    expect(DerivationFixture::lineFor($august, $units[2]))->toBeNull()
        ->and($sale?->block)->toBe('01')
        ->and($sale?->unit)->toBe('103')
        ->and($cancellation?->block)->toBe('01')
        ->and($cancellation?->unit)->toBe('103')
        ->and($codes)->not->toContain(SalesBoardIssueCode::UnitConstructionMismatch->value)
        ->and($codes)->not->toContain(SalesBoardIssueCode::SaleUnitValueMissing->value)
        ->and($codes)->toContain(SalesBoardIssueCode::UnitRetired->value)
        ->and($august->blockingIssues())->toBe([]);
});

it('derives a complete, empty position when every unit is retired', function () {
    [$construction, $units] = CycleFixture::readyConstruction(3);

    foreach ($units as $unit) {
        ConstructionUnitRetirement::factory()->forUnit($unit)->retiredOn('2026-07-01')->create();
    }

    $position = retirementDerivation($construction, '2026-07-01');

    expect($position->lines)->toBe([])
        ->and($position->unitsTotal)->toBe(0)
        ->and($position->stockValueCents)->toBe(0)
        ->and($position->isComplete())->toBeTrue()
        ->and(DerivationFixture::issueCodes($position))->not->toContain(SalesBoardIssueCode::NoConstructionUnits->value)
        ->and(collect($position->warnings())->filter(fn ($issue): bool => $issue->code === SalesBoardIssueCode::UnitRetired))->toHaveCount(3);
});

it('derives the same position through the batch API as one by one when there are retirements', function () {
    [$first, $firstUnits] = CycleFixture::readyConstruction(3);
    [$second, $secondUnits] = CycleFixture::readyConstruction(2);

    ConstructionUnitRetirement::factory()->forUnit($firstUnits[0])->retiredOn('2026-07-10')->create();
    ConstructionUnitRetirement::factory()->forUnit($secondUnits[1])->retiredOn('2026-06-10')->reactivatedOn('2026-07-15')->create();
    ConstructionUnitRetirement::factory()->forUnit($secondUnits[0])->retiredOn('2026-07-01')->create();
    DerivationFixture::contract($secondUnits[0], '2026-07-20', '480000.00');

    $service = app(SalesBoardDerivationService::class);
    $month = CarbonImmutable::parse('2026-07-01');
    $constructions = Construction::query()->whereKey([$first->id, $second->id])->orderBy('id')->get();

    $batch = $service->deriveForConstructions($constructions, $month);

    foreach ($constructions as $construction) {
        expect($batch[$construction->id]->toArray(withLines: true))
            ->toBe($service->deriveForConstruction($construction, $month)->toArray(withLines: true));
    }

    expect($batch[$first->id]->unitsTotal)->toBe(2)
        ->and($batch[$second->id]->unitsTotal)->toBe(2)
        ->and(DerivationFixture::issueCodes($batch[$second->id]))->toContain(SalesBoardIssueCode::RetiredUnitInUse->value, SalesBoardIssueCode::UnitReactivated->value);
});

it('marks the open competence as materially changed and recalculates it without the unit', function () {
    [$construction, $units] = CycleFixture::readyConstruction(3);
    $cycle = CycleFixture::generate($construction)->cycle;

    retireThroughService($units[2], '2026-07-01');

    $assessment = CycleFixture::check($cycle);
    $result = CycleFixture::recalculate($cycle, 'Baixa da unidade inexistente registrada pela Gestão.');

    $removed = collect($result->diff?->lines ?? [])->firstWhere('constructionUnitId', $units[2]->id);

    expect($assessment->impact)->toBe(SalesBoardStaleImpact::Material)
        ->and($result->outcome)->toBe(SalesBoardRecalculationOutcome::Recalculated)
        ->and($result->baseline?->lines()->count())->toBe(2)
        ->and((int) $result->baseline?->units_total)->toBe(2)
        ->and($result->previousBaseline->lines()->count())->toBe(3)
        ->and(collect($removed?->changes ?? [])->pluck('code')->all())->toBe([SalesBoardDiffCode::UnitRemoved])
        ->and(collect($result->baseline?->frozenWarnings() ?? [])->pluck('code')->all())->toContain(SalesBoardIssueCode::UnitRetired->value);
});
