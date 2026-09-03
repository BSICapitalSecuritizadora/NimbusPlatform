<?php

use App\Support\Money\IntegerMoney;

it('parses the decimal strings the domain actually stores', function (mixed $input, ?int $expected) {
    expect(IntegerMoney::cents($input))->toBe($expected);
})->with([
    'decimal:2 cast' => ['1000000.00', 100_000_000],
    'brazilian grouped' => ['1.000.000,00', 100_000_000],
    'brazilian with symbol' => ['R$ 1.000,00', 100_000],
    'american grouped' => ['1,000,000.00', 100_000_000],
    'thousands only' => ['1.000', 100_000],
    'plain integer string' => ['1000', 100_000],
    'single decimal digit' => ['1.5', 150],
    'one cent' => ['0,01', 1],
    'half a cent rounds up' => ['0.005', 1],
    'sub-cent rounds down' => ['0.004', 0],
    'four leading digits are not grouping' => ['1234.567', 123_457],
    'negative' => ['-500.25', -50_025],
    'integer' => [1000, 100_000],
    'null' => [null, null],
    'empty string' => ['', null],
    'not a number' => ['abc', null],
]);

it('parses percentages into basis points', function (mixed $input, ?int $expected) {
    expect(IntegerMoney::basisPoints($input))->toBe($expected);
})->with([
    'five percent' => ['5.00', 500],
    'five percent brazilian' => ['5,00', 500],
    'fraction' => ['4.25', 425],
    'with symbol' => ['4,25%', 425],
    'zero' => ['0.00', 0],
    'hundred' => ['100.00', 10_000],
    'bare integer string' => ['5', 500],
    'null' => [null, null],
]);

it('never loses a cent to floating point', function () {
    // The classic 0.1 + 0.2 case, and the values that a naive
    // `(int) ($value * 100)` truncates one cent below the real amount.
    expect(IntegerMoney::cents('0.30'))->toBe(30)
        ->and(IntegerMoney::cents('1.15'))->toBe(115)
        ->and(IntegerMoney::cents('8.20'))->toBe(820)
        ->and(IntegerMoney::cents('129.95'))->toBe(12_995)
        ->and(IntegerMoney::cents('1.005'))->toBe(100_500);
});

it('rounds the minimum price up, never granting more discount than authorised', function () {
    expect(IntegerMoney::minimumAfterDiscount(100_000_000, 500))->toBe(95_000_000)
        ->and(IntegerMoney::minimumAfterDiscount(100_000_001, 500))->toBe(95_000_001)
        ->and(IntegerMoney::minimumAfterDiscount(100_000_010, 500))->toBe(95_000_010)
        ->and(IntegerMoney::minimumAfterDiscount(1, 500))->toBe(1)
        ->and(IntegerMoney::minimumAfterDiscount(0, 500))->toBe(0);
});

it('treats the discount as a ceiling at both ends of the range', function () {
    expect(IntegerMoney::minimumAfterDiscount(100_000_000, 0))->toBe(100_000_000)
        ->and(IntegerMoney::minimumAfterDiscount(100_000_000, 10_000))->toBe(0);
});

it('refuses a discount outside the representable range', function (int $basisPoints) {
    expect(fn () => IntegerMoney::minimumAfterDiscount(100_000_000, $basisPoints))
        ->toThrow(InvalidArgumentException::class);
})->with([[-1], [10_001]]);

it('refuses a negative reference value', function () {
    expect(fn () => IntegerMoney::minimumAfterDiscount(-1, 500))->toThrow(InvalidArgumentException::class);
});

it('agrees with arbitrary precision arithmetic across the whole decimal(15,2) range', function () {
    $references = [1, 99, 100_000_000, 123_456_789_012_345, 999_999_999_999_999];
    $discounts = [0, 1, 250, 500, 1_234, 9_999, 10_000];

    foreach ($references as $referenceCents) {
        foreach ($discounts as $basisPoints) {
            $minimum = IntegerMoney::minimumAfterDiscount($referenceCents, $basisPoints);

            $exact = bcdiv(
                bcmul((string) $referenceCents, (string) (10_000 - $basisPoints), 0),
                '10000',
                10,
            );

            expect(bccomp((string) $minimum, $exact, 10))->toBeGreaterThanOrEqual(0)
                ->and(bccomp((string) ($minimum - 1), $exact, 10))->toBeLessThan(0);
        }
    }
});

it('measures the effective discount without overflowing', function () {
    expect(IntegerMoney::effectiveDiscountBasisPoints(100_000_000, 95_000_000))->toBe(500)
        ->and(IntegerMoney::effectiveDiscountBasisPoints(100_000_000, 100_000_000))->toBe(0)
        ->and(IntegerMoney::effectiveDiscountBasisPoints(100_000_000, 101_000_000))->toBe(-100)
        ->and(IntegerMoney::effectiveDiscountBasisPoints(999_999_999_999_999, 949_999_999_999_999))->toBe(500)
        ->and(IntegerMoney::effectiveDiscountBasisPoints(0, 100))->toBeNull()
        ->and(IntegerMoney::effectiveDiscountBasisPoints(-1, 100))->toBeNull();
});

it('formats cents and basis points in brazilian notation', function () {
    expect(IntegerMoney::format(100_000_000))->toBe('1.000.000,00')
        ->and(IntegerMoney::format(95_000_000))->toBe('950.000,00')
        ->and(IntegerMoney::format(1))->toBe('0,01')
        ->and(IntegerMoney::format(0))->toBe('0,00')
        ->and(IntegerMoney::format(-10_000))->toBe('-100,00')
        ->and(IntegerMoney::formatBasisPoints(500))->toBe('5,00')
        ->and(IntegerMoney::formatBasisPoints(10_000))->toBe('100,00')
        ->and(IntegerMoney::formatBasisPoints(0))->toBe('0,00');
});
