<?php

use App\Enums\SalesBoardPositionStatus;
use App\Models\Construction;
use App\Models\Emission;
use App\Models\SalesBoard;
use App\Services\SalesBoards\SalesBoardPositionReader;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function salesBoardReader(): SalesBoardPositionReader
{
    return app(SalesBoardPositionReader::class);
}

/**
 * @param  array<string, int|float|string>  $attributes
 */
function salesBoardFor(Emission $emission, Construction $construction, string $referenceMonth, array $attributes = []): SalesBoard
{
    return SalesBoard::factory()
        ->forEmissionAndConstruction($emission, $construction)
        ->create(['reference_month' => $referenceMonth, ...$attributes]);
}

/**
 * @return array<string, int>
 */
function unitsBreakdown(int $stock, int $financed, int $paid, int $exchanged): array
{
    return [
        'stock_units' => $stock,
        'financed_units' => $financed,
        'paid_units' => $paid,
        'exchanged_units' => $exchanged,
    ];
}

it('reads the board of the queried competence for a construction', function () {
    $emission = Emission::factory()->create();
    $construction = Construction::factory()->create(['emission_id' => $emission->id]);

    salesBoardFor($emission, $construction, '2026-07-01', unitsBreakdown(10, 5, 3, 2));

    $position = salesBoardReader()->forConstruction($construction, CarbonImmutable::parse('2026-07-01'));

    expect($position->status)->toBe(SalesBoardPositionStatus::Current)
        ->and($position->wasCarriedForward())->toBeFalse()
        ->and($position->referenceMonthUsedDate())->toBe('2026-07-01')
        ->and($position->totalUnits)->toBe(20)
        ->and($position->stockUnits)->toBe(10);
});

it('carries the last known position forward when the construction has no board in the competence', function () {
    $emission = Emission::factory()->create();
    $construction = Construction::factory()->create(['emission_id' => $emission->id]);

    salesBoardFor($emission, $construction, '2026-05-01', unitsBreakdown(40, 30, 20, 10));

    $position = salesBoardReader()->forConstruction($construction, CarbonImmutable::parse('2026-07-01'));

    expect($position->status)->toBe(SalesBoardPositionStatus::CarriedForward)
        ->and($position->wasCarriedForward())->toBeTrue()
        ->and($position->positionDate->toDateString())->toBe('2026-07-01')
        ->and($position->referenceMonthUsedDate())->toBe('2026-05-01')
        ->and($position->totalUnits)->toBe(100);
});

it('reports an explicit absence instead of zero when the construction never had a board', function () {
    $emission = Emission::factory()->create();
    $construction = Construction::factory()->create(['emission_id' => $emission->id]);

    $position = salesBoardReader()->forConstruction($construction, CarbonImmutable::parse('2026-07-01'));

    expect($position->status)->toBe(SalesBoardPositionStatus::Unpositioned)
        ->and($position->isResolved())->toBeFalse()
        ->and($position->salesBoard)->toBeNull()
        ->and($position->referenceMonthUsed)->toBeNull();
});

it('separates a construction positioned only after the competence from one never positioned', function () {
    $emission = Emission::factory()->create();
    $positionedLater = Construction::factory()->create(['emission_id' => $emission->id]);
    $neverPositioned = Construction::factory()->create(['emission_id' => $emission->id]);

    salesBoardFor($emission, $positionedLater, '2026-09-01');

    $later = salesBoardReader()->forConstruction($positionedLater, CarbonImmutable::parse('2026-07-01'));
    $never = salesBoardReader()->forConstruction($neverPositioned, CarbonImmutable::parse('2026-07-01'));

    expect($later->status)->toBe(SalesBoardPositionStatus::NotYetPositioned)
        ->and($later->status->isExpected())->toBeFalse()
        ->and($never->status)->toBe(SalesBoardPositionStatus::Unpositioned)
        ->and($never->status->isExpected())->toBeTrue();
});

it('sums every construction of the emission in the same competence', function () {
    $emission = Emission::factory()->create();
    $first = Construction::factory()->create(['emission_id' => $emission->id]);
    $second = Construction::factory()->create(['emission_id' => $emission->id]);
    $third = Construction::factory()->create(['emission_id' => $emission->id]);

    salesBoardFor($emission, $first, '2026-07-01', unitsBreakdown(5, 4, 3, 2));
    salesBoardFor($emission, $second, '2026-07-01', unitsBreakdown(10, 0, 0, 0));
    salesBoardFor($emission, $third, '2026-07-01', unitsBreakdown(1, 1, 1, 1));

    $position = salesBoardReader()->forEmission($emission, CarbonImmutable::parse('2026-07-01'));

    expect($position->totalUnits)->toBe(28)
        ->and($position->stockUnits)->toBe(16)
        ->and($position->constructionsExpected)->toBe(3)
        ->and($position->constructionsCovered)->toBe(3)
        ->and($position->isFullyCovered())->toBeTrue()
        ->and($position->hasCarryForward())->toBeFalse();
});

it('mixes the current competence with the last known position of the others', function () {
    $emission = Emission::factory()->create();
    $updated = Construction::factory()->create(['emission_id' => $emission->id]);
    $stale = Construction::factory()->create(['emission_id' => $emission->id]);

    salesBoardFor($emission, $updated, '2026-07-01', unitsBreakdown(20, 0, 0, 0));
    salesBoardFor($emission, $stale, '2026-05-01', unitsBreakdown(100, 0, 0, 0));

    $position = salesBoardReader()->forEmission($emission, CarbonImmutable::parse('2026-07-01'));

    expect($position->totalUnits)->toBe(120)
        ->and($position->constructionsCovered)->toBe(2)
        ->and($position->hasCarryForward())->toBeTrue()
        ->and($position->referenceMonthByConstruction()[$updated->id])->toBe('2026-07-01')
        ->and($position->referenceMonthByConstruction()[$stale->id])->toBe('2026-05-01');
});

it('exposes the competence actually used by each construction', function () {
    $emission = Emission::factory()->create();
    $first = Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => 'Alfa']);
    $second = Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => 'Beta']);

    salesBoardFor($emission, $first, '2026-06-01');
    salesBoardFor($emission, $second, '2026-04-01');
    salesBoardFor($emission, $second, '2026-02-01');

    $position = salesBoardReader()->forEmission($emission, CarbonImmutable::parse('2026-06-01'));

    expect($position->referenceMonthByConstruction())->toBe([
        $first->id => '2026-06-01',
        $second->id => '2026-04-01',
    ])->and(array_keys($position->referenceMonthByConstruction()))->toBe([$first->id, $second->id]);
});

it('reports partial coverage without hiding the missing construction', function () {
    $emission = Emission::factory()->create();
    $first = Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => 'Alfa']);
    $second = Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => 'Beta']);
    $missing = Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => 'Gama']);

    salesBoardFor($emission, $first, '2026-07-01', unitsBreakdown(3, 0, 0, 0));
    salesBoardFor($emission, $second, '2026-06-01', unitsBreakdown(4, 0, 0, 0));

    $position = salesBoardReader()->forEmission($emission, CarbonImmutable::parse('2026-07-01'));

    expect($position->constructionsExpected)->toBe(3)
        ->and($position->constructionsCovered)->toBe(2)
        ->and($position->isFullyCovered())->toBeFalse()
        ->and($position->totalUnits)->toBe(7)
        ->and($position->referenceMonthByConstruction()[$missing->id])->toBeNull()
        ->and($position->coverage()['missing_construction_ids'])->toBe([$missing->id]);
});

it('never counts a future position', function () {
    $emission = Emission::factory()->create();
    $construction = Construction::factory()->create(['emission_id' => $emission->id]);

    salesBoardFor($emission, $construction, '2026-05-01', unitsBreakdown(10, 0, 0, 0));
    salesBoardFor($emission, $construction, '2026-08-01', unitsBreakdown(999, 0, 0, 0));

    $position = salesBoardReader()->forEmission($emission, CarbonImmutable::parse('2026-06-01'));

    expect($position->totalUnits)->toBe(10)
        ->and($position->referenceMonthByConstruction()[$construction->id])->toBe('2026-05-01');
});

it('resolves a tie on the reference month deterministically by the latest row', function () {
    // The uniqueness of (emission, construction, competence) rules out a tie
    // inside one emission, so the tie is built the only way the schema allows
    // it: the same construction carrying a board of another emission in the
    // same competence.
    $emission = Emission::factory()->create();
    $otherEmission = Emission::factory()->create();
    $construction = Construction::factory()->create(['emission_id' => $emission->id]);

    $older = salesBoardFor($emission, $construction, '2026-07-01', unitsBreakdown(10, 0, 0, 0));
    $newer = salesBoardFor($otherEmission, $construction, '2026-07-01', unitsBreakdown(77, 0, 0, 0));

    $first = salesBoardReader()->forConstruction($construction, CarbonImmutable::parse('2026-07-01'));
    $second = salesBoardReader()->forConstruction($construction, CarbonImmutable::parse('2026-07-01'));

    expect($newer->id)->toBeGreaterThan($older->id)
        ->and($first->salesBoard?->id)->toBe($newer->id)
        ->and($first->totalUnits)->toBe(77)
        ->and($second->salesBoard?->id)->toBe($first->salesBoard?->id);
});

it('reads a position without creating any sales board', function () {
    $emission = Emission::factory()->create();
    $construction = Construction::factory()->create(['emission_id' => $emission->id]);

    salesBoardFor($emission, $construction, '2026-05-01');

    $before = SalesBoard::count();

    salesBoardReader()->forEmission($emission, CarbonImmutable::parse('2026-09-01'));
    salesBoardReader()->forConstruction($construction, CarbonImmutable::parse('2026-09-01'));
    salesBoardReader()->forEmissionMonths($emission, [
        CarbonImmutable::parse('2026-06-01'),
        CarbonImmutable::parse('2026-07-01'),
    ]);

    expect(SalesBoard::count())->toBe($before);
});

it('resolves an emission with many constructions in a constant number of queries', function () {
    $emission = Emission::factory()->create();

    $constructions = Construction::factory()->count(20)->create(['emission_id' => $emission->id]);

    foreach ($constructions as $construction) {
        salesBoardFor($emission, $construction, '2026-05-01', unitsBreakdown(1, 0, 0, 0));
    }

    $emission = $emission->fresh();

    DB::enableQueryLog();
    DB::flushQueryLog();

    $position = salesBoardReader()->forEmission($emission, CarbonImmutable::parse('2026-07-01'));

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($position->totalUnits)->toBe(20)
        ->and($position->constructionsCovered)->toBe(20)
        ->and($queries)->toHaveCount(2);
});

it('reads several competences of an emission with a single load', function () {
    $emission = Emission::factory()->create();
    $first = Construction::factory()->create(['emission_id' => $emission->id]);
    $second = Construction::factory()->create(['emission_id' => $emission->id]);

    salesBoardFor($emission, $first, '2026-05-01', unitsBreakdown(10, 0, 0, 0));
    salesBoardFor($emission, $first, '2026-07-01', unitsBreakdown(20, 0, 0, 0));
    salesBoardFor($emission, $second, '2026-05-01', unitsBreakdown(100, 0, 0, 0));

    $emission = $emission->fresh();

    DB::enableQueryLog();
    DB::flushQueryLog();

    $positions = salesBoardReader()->forEmissionMonths($emission, [
        CarbonImmutable::parse('2026-05-01'),
        CarbonImmutable::parse('2026-06-01'),
        CarbonImmutable::parse('2026-07-01'),
    ]);

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($queries)->toHaveCount(2)
        ->and($positions->get('2026-05')->totalUnits)->toBe(110)
        ->and($positions->get('2026-06')->totalUnits)->toBe(110)
        ->and($positions->get('2026-07')->totalUnits)->toBe(120);
});

it('lists the competences with a board up to the queried month', function () {
    $emission = Emission::factory()->create();
    $first = Construction::factory()->create(['emission_id' => $emission->id]);
    $second = Construction::factory()->create(['emission_id' => $emission->id]);

    salesBoardFor($emission, $first, '2026-05-01');
    salesBoardFor($emission, $second, '2026-05-01');
    salesBoardFor($emission, $first, '2026-07-01');
    salesBoardFor($emission, $first, '2026-09-01');

    $competences = salesBoardReader()->competencesUntil($emission, CarbonImmutable::parse('2026-07-31'));

    expect(array_map(fn (CarbonImmutable $month): string => $month->toDateString(), $competences))
        ->toBe(['2026-05-01', '2026-07-01']);
});

it('keeps a board attached to the emission even when the construction is not listed under it', function () {
    $emission = Emission::factory()->create();
    $listed = Construction::factory()->create(['emission_id' => $emission->id]);
    $unlisted = Construction::factory()->create();

    salesBoardFor($emission, $listed, '2026-07-01', unitsBreakdown(5, 0, 0, 0));
    salesBoardFor($emission, $unlisted, '2026-07-01', unitsBreakdown(9, 0, 0, 0));

    $position = salesBoardReader()->forEmission($emission, CarbonImmutable::parse('2026-07-01'));

    expect($position->totalUnits)->toBe(14)
        ->and($position->constructionsCovered)->toBe(2);
});
