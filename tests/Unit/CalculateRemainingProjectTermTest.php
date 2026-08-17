<?php

use App\Actions\Proposals\CalculateRemainingProjectTerm;

it('calculates a future construction from construction start to delivery', function () {
    expect((new CalculateRemainingProjectTerm)->handle('2027-01', '2028-07', '2026-08-15'))->toBe(18);
});

it('calculates a construction in progress from the current month', function () {
    expect((new CalculateRemainingProjectTerm)->handle('2025-01', '2027-02', '2026-08-15'))->toBe(6);
});

it('returns zero for delivery in the current month or already expired', function (string $delivery) {
    expect((new CalculateRemainingProjectTerm)->handle('2025-01', $delivery, '2026-08-15'))->toBe(0);
})->with(['2026-08', '2026-07']);

it('returns null for invalid ordering, malformed dates, or missing dates', function (mixed $start, mixed $delivery) {
    expect((new CalculateRemainingProjectTerm)->handle($start, $delivery, '2026-08-15'))->toBeNull();
})->with([
    ['2028-01', '2027-12'],
    ['invalid', '2028-01'],
    ['2027-02-30', '2028-01'],
    [null, '2028-01'],
    ['2027-01', null],
]);
