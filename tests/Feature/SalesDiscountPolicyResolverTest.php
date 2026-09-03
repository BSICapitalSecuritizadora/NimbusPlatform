<?php

use App\Models\Construction;
use App\Models\SalesDiscountPolicy;
use App\Services\SalesBoards\SalesDiscountPolicyResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function discountPolicyResolver(): SalesDiscountPolicyResolver
{
    return app(SalesDiscountPolicyResolver::class);
}

it('reports absence when the construction has no policy at all', function () {
    $construction = Construction::factory()->create();

    $resolved = discountPolicyResolver()->policyAt($construction, CarbonImmutable::parse('2026-07-01'));

    expect($resolved->isAbsent())->toBeTrue()
        ->and($resolved->maximumDiscountBasisPoints)->toBeNull()
        ->and($resolved->effectiveFrom)->toBeNull()
        ->and($resolved->policy)->toBeNull();
});

it('applies a policy effective exactly on the queried date', function () {
    $construction = Construction::factory()->create();

    SalesDiscountPolicy::factory()->forConstruction($construction)->effectiveFrom('2026-07-01')->allowing('5.00')->create();

    $onTheDay = discountPolicyResolver()->policyAt($construction, CarbonImmutable::parse('2026-07-01'));
    $theDayBefore = discountPolicyResolver()->policyAt($construction, CarbonImmutable::parse('2026-06-30'));

    expect($onTheDay->isPresent())->toBeTrue()
        ->and($onTheDay->maximumDiscountBasisPoints)->toBe(500)
        ->and($onTheDay->effectiveFromDate())->toBe('2026-07-01')
        ->and($theDayBefore->isAbsent())->toBeTrue();
});

it('never applies a policy before its effective date', function () {
    $construction = Construction::factory()->create();

    SalesDiscountPolicy::factory()->forConstruction($construction)->effectiveFrom('2026-07-01')->allowing('5.00')->create();
    SalesDiscountPolicy::factory()->forConstruction($construction)->effectiveFrom('2026-10-01')->allowing('4.00')->create();

    $september = discountPolicyResolver()->policyAt($construction, CarbonImmutable::parse('2026-09-20'));
    $october = discountPolicyResolver()->policyAt($construction, CarbonImmutable::parse('2026-10-01'));

    expect($september->maximumDiscountBasisPoints)->toBe(500)
        ->and($september->effectiveFromDate())->toBe('2026-07-01')
        ->and($october->maximumDiscountBasisPoints)->toBe(400)
        ->and($october->effectiveFromDate())->toBe('2026-10-01');
});

it('lets a correction recorded for the same effective date win by the highest id', function () {
    $construction = Construction::factory()->create();

    $wrong = SalesDiscountPolicy::factory()->forConstruction($construction)->effectiveFrom('2026-07-01')->allowing('5.00')->create();
    $correction = SalesDiscountPolicy::factory()->forConstruction($construction)->effectiveFrom('2026-07-01')->allowing('3.50')->create();

    $resolved = discountPolicyResolver()->policyAt($construction, CarbonImmutable::parse('2026-07-15'));

    expect($correction->id)->toBeGreaterThan($wrong->id)
        ->and($resolved->maximumDiscountBasisPoints)->toBe(350)
        ->and(SalesDiscountPolicy::count())->toBe(2);
});

it('resolves a zero percent policy as an actual limit, not as absence', function () {
    $construction = Construction::factory()->create();

    SalesDiscountPolicy::factory()->forConstruction($construction)->effectiveFrom('2026-01-01')->allowing('0.00')->create();

    $resolved = discountPolicyResolver()->policyAt($construction, CarbonImmutable::parse('2026-07-01'));

    expect($resolved->isAbsent())->toBeFalse()
        ->and($resolved->maximumDiscountBasisPoints)->toBe(0)
        ->and($resolved->formattedMaximumDiscount())->toBe('0,00%');
});

it('resolves a hundred percent policy', function () {
    $construction = Construction::factory()->create();

    SalesDiscountPolicy::factory()->forConstruction($construction)->effectiveFrom('2026-01-01')->allowing('100.00')->create();

    $resolved = discountPolicyResolver()->policyAt($construction, CarbonImmutable::parse('2026-07-01'));

    expect($resolved->maximumDiscountBasisPoints)->toBe(10_000)
        ->and($resolved->formattedMaximumDiscount())->toBe('100,00%');
});

it('is scoped per construction and never borrows another development policy', function () {
    $withPolicy = Construction::factory()->create();
    $withoutPolicy = Construction::factory()->create();

    SalesDiscountPolicy::factory()->forConstruction($withPolicy)->effectiveFrom('2026-01-01')->allowing('5.00')->create();

    expect(discountPolicyResolver()->policyAt($withPolicy, CarbonImmutable::parse('2026-07-01'))->maximumDiscountBasisPoints)->toBe(500)
        ->and(discountPolicyResolver()->policyAt($withoutPolicy, CarbonImmutable::parse('2026-07-01'))->isAbsent())->toBeTrue();
});

it('resolves many constructions in a constant number of queries', function () {
    $constructions = Construction::factory()->count(20)->create();

    foreach ($constructions->take(12) as $construction) {
        SalesDiscountPolicy::factory()->forConstruction($construction)->effectiveFrom('2026-01-01')->allowing('5.00')->create();
    }

    $ids = $constructions->pluck('id')->map(fn (int $id): int => $id)->all();

    DB::enableQueryLog();
    DB::flushQueryLog();

    $resolved = discountPolicyResolver()->forConstructionIds($ids, CarbonImmutable::parse('2026-07-01'));

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($queries)->toHaveCount(1)
        ->and($resolved)->toHaveCount(20)
        ->and(collect($resolved)->filter(fn ($policy): bool => $policy->isPresent()))->toHaveCount(12)
        ->and(collect($resolved)->filter(fn ($policy): bool => $policy->isAbsent()))->toHaveCount(8);
});
