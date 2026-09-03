<?php

use App\Enums\ResolvedUnitValueSource;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\ConstructionUnitValue;
use App\Services\SalesBoards\UnitValueResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function unitValueResolver(): UnitValueResolver
{
    return app(UnitValueResolver::class);
}

function unitWithBase(?string $baseValue = null, ?string $referenceDate = null): ConstructionUnit
{
    $construction = Construction::factory()->create();

    return ConstructionUnit::factory()->create([
        'construction_id' => $construction->id,
        'base_value' => $baseValue,
        'base_value_reference_date' => $referenceDate,
    ]);
}

it('uses the base value when the unit has no history', function () {
    $unit = unitWithBase('900000.00', '2026-01-01');

    $resolved = unitValueResolver()->forUnit($unit, CarbonImmutable::parse('2026-07-31'));

    expect($resolved->source)->toBe(ResolvedUnitValueSource::Base)
        ->and($resolved->valueCents)->toBe(90_000_000)
        ->and($resolved->effectiveFromDate())->toBe('2026-01-01')
        ->and($resolved->isAbsent())->toBeFalse();
});

it('reports absence when the unit has neither base value nor history', function () {
    $unit = unitWithBase();

    $resolved = unitValueResolver()->forUnit($unit, CarbonImmutable::parse('2026-07-31'));

    expect($resolved->source)->toBe(ResolvedUnitValueSource::Absent)
        ->and($resolved->isAbsent())->toBeTrue()
        ->and($resolved->valueCents)->toBeNull()
        ->and($resolved->effectiveFrom)->toBeNull();
});

it('does not apply a base value whose reference date has not arrived yet', function () {
    $unit = unitWithBase('900000.00', '2026-08-01');

    $before = unitValueResolver()->forUnit($unit, CarbonImmutable::parse('2026-07-31'));
    $on = unitValueResolver()->forUnit($unit, CarbonImmutable::parse('2026-08-01'));

    expect($before->isAbsent())->toBeTrue()
        ->and($before->valueCents)->toBeNull()
        ->and($on->source)->toBe(ResolvedUnitValueSource::Base)
        ->and($on->valueCents)->toBe(90_000_000);
});

it('prefers a history entry effective before the queried date over the base value', function () {
    $unit = unitWithBase('900000.00', '2026-01-01');

    ConstructionUnitValue::factory()->forUnit($unit)->effectiveFrom('2026-07-01')->worth('1000000.00')->create();

    $resolved = unitValueResolver()->forUnit($unit, CarbonImmutable::parse('2026-07-15'));

    expect($resolved->source)->toBe(ResolvedUnitValueSource::History)
        ->and($resolved->valueCents)->toBe(100_000_000)
        ->and($resolved->effectiveFromDate())->toBe('2026-07-01');
});

it('applies a history entry effective exactly on the queried date', function () {
    $unit = unitWithBase('900000.00', '2026-01-01');

    ConstructionUnitValue::factory()->forUnit($unit)->effectiveFrom('2026-07-01')->worth('1000000.00')->create();

    $onTheDay = unitValueResolver()->forUnit($unit, CarbonImmutable::parse('2026-07-01'));
    $theDayBefore = unitValueResolver()->forUnit($unit, CarbonImmutable::parse('2026-06-30'));

    expect($onTheDay->source)->toBe(ResolvedUnitValueSource::History)
        ->and($onTheDay->valueCents)->toBe(100_000_000)
        ->and($theDayBefore->source)->toBe(ResolvedUnitValueSource::Base)
        ->and($theDayBefore->valueCents)->toBe(90_000_000);
});

it('never lets a future value leak into an earlier query', function () {
    $unit = unitWithBase('900000.00', '2026-01-01');

    ConstructionUnitValue::factory()->forUnit($unit)->effectiveFrom('2026-07-01')->worth('1000000.00')->create();
    ConstructionUnitValue::factory()->forUnit($unit)->effectiveFrom('2026-10-01')->worth('1100000.00')->create();

    $september = unitValueResolver()->forUnit($unit, CarbonImmutable::parse('2026-09-30'));
    $october = unitValueResolver()->forUnit($unit, CarbonImmutable::parse('2026-10-01'));

    expect($september->valueCents)->toBe(100_000_000)
        ->and($september->effectiveFromDate())->toBe('2026-07-01')
        ->and($october->valueCents)->toBe(110_000_000)
        ->and($october->effectiveFromDate())->toBe('2026-10-01');
});

it('takes the most recent history entry when several precede the queried date', function () {
    $unit = unitWithBase('900000.00', '2026-01-01');

    ConstructionUnitValue::factory()->forUnit($unit)->effectiveFrom('2026-03-01')->worth('950000.00')->create();
    ConstructionUnitValue::factory()->forUnit($unit)->effectiveFrom('2026-07-01')->worth('1000000.00')->create();
    ConstructionUnitValue::factory()->forUnit($unit)->effectiveFrom('2026-05-01')->worth('970000.00')->create();

    $resolved = unitValueResolver()->forUnit($unit, CarbonImmutable::parse('2026-12-31'));

    expect($resolved->valueCents)->toBe(100_000_000)
        ->and($resolved->effectiveFromDate())->toBe('2026-07-01');
});

it('lets a correction recorded for the same date win by the highest id', function () {
    $unit = unitWithBase('900000.00', '2026-01-01');

    $wrong = ConstructionUnitValue::factory()->forUnit($unit)->effectiveFrom('2026-07-01')->worth('1000000.00')->create();
    $correction = ConstructionUnitValue::factory()->forUnit($unit)->effectiveFrom('2026-07-01')->worth('1010000.00')->create();

    $resolved = unitValueResolver()->forUnit($unit, CarbonImmutable::parse('2026-07-31'));

    expect($correction->id)->toBeGreaterThan($wrong->id)
        ->and($resolved->valueCents)->toBe(101_000_000)
        ->and(ConstructionUnitValue::count())->toBe(2)
        ->and($wrong->fresh())->not->toBeNull();
});

it('resolves every unit of a construction in a constant number of queries', function () {
    $construction = Construction::factory()->create();

    $units = ConstructionUnit::factory()->count(50)->create([
        'construction_id' => $construction->id,
        'base_value' => '500000.00',
        'base_value_reference_date' => '2026-01-01',
    ]);

    foreach ($units->take(20) as $unit) {
        ConstructionUnitValue::factory()->forUnit($unit)->effectiveFrom('2026-06-01')->worth('600000.00')->create();
    }

    DB::enableQueryLog();
    DB::flushQueryLog();

    $resolved = unitValueResolver()->forConstruction($construction, CarbonImmutable::parse('2026-07-01'));

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($queries)->toHaveCount(2)
        ->and($resolved)->toHaveCount(50)
        ->and(collect($resolved)->where('source', ResolvedUnitValueSource::History))->toHaveCount(20)
        ->and(collect($resolved)->where('source', ResolvedUnitValueSource::Base))->toHaveCount(30);
});

it('keeps zero apart from absence', function () {
    $zeroUnit = unitWithBase('0.00', '2026-01-01');
    $unknownUnit = unitWithBase();

    $zero = unitValueResolver()->forUnit($zeroUnit, CarbonImmutable::parse('2026-07-01'));
    $unknown = unitValueResolver()->forUnit($unknownUnit, CarbonImmutable::parse('2026-07-01'));

    expect($zero->isAbsent())->toBeFalse()
        ->and($zero->valueCents)->toBe(0)
        ->and($unknown->isAbsent())->toBeTrue()
        ->and($unknown->valueCents)->toBeNull();
});

it('resolves a mixed set of units in one pass', function () {
    $construction = Construction::factory()->create();

    $withHistory = ConstructionUnit::factory()->create([
        'construction_id' => $construction->id,
        'base_value' => '900000.00',
        'base_value_reference_date' => '2026-01-01',
    ]);
    $baseOnly = ConstructionUnit::factory()->create([
        'construction_id' => $construction->id,
        'base_value' => '800000.00',
        'base_value_reference_date' => '2026-01-01',
    ]);
    $unknown = ConstructionUnit::factory()->create([
        'construction_id' => $construction->id,
        'base_value' => null,
        'base_value_reference_date' => null,
    ]);

    ConstructionUnitValue::factory()->forUnit($withHistory)->effectiveFrom('2026-07-01')->worth('1000000.00')->create();

    $resolved = unitValueResolver()->forConstruction($construction, CarbonImmutable::parse('2026-07-31'));

    expect($resolved[$withHistory->id]->source)->toBe(ResolvedUnitValueSource::History)
        ->and($resolved[$withHistory->id]->valueCents)->toBe(100_000_000)
        ->and($resolved[$baseOnly->id]->source)->toBe(ResolvedUnitValueSource::Base)
        ->and($resolved[$baseOnly->id]->valueCents)->toBe(80_000_000)
        ->and($resolved[$unknown->id]->isAbsent())->toBeTrue();
});

it('refuses to delete a unit that already carries value history', function () {
    $unit = unitWithBase('900000.00', '2026-01-01');

    ConstructionUnitValue::factory()->forUnit($unit)->effectiveFrom('2026-07-01')->worth('1000000.00')->create();

    expect(fn () => $unit->delete())->toThrow(QueryException::class)
        ->and(ConstructionUnitValue::count())->toBe(1);
});
