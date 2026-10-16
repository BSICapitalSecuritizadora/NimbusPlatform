<?php

use App\Support\Bradesco\BradescoMoney;

it('parses Brazilian wire values into exact signed cents', function (string $value, string $sign, int $cents) {
    expect(BradescoMoney::toSignedCents($value, $sign))->toBe($cents);
})->with([
    'positive units' => ['250,00', '+', 25000],
    'negative units' => ['250,00', '-', -25000],
    'thousands with dot' => ['1.000,00', '+', 100000],
    'millions with dots' => ['10.500,25', '-', -1050025],
    'zero stays zero' => ['0,00', '+', 0],
    'single cent' => ['0,01', '+', 1],
    'max 13+2 fits decimal(15,2)' => ['9.999.999.999.999,99', '+', 999999999999999],
]);

it('renders cents back in canonical decimal form', function () {
    expect(BradescoMoney::fromCents(25000))->toBe('250.00')
        ->and(BradescoMoney::fromCents(-1050025))->toBe('-10500.25')
        ->and(BradescoMoney::fromCents(0))->toBe('0.00');
});

it('rejects values and signs outside the documented form', function (string $value, string $sign) {
    expect(fn () => BradescoMoney::toSignedCents($value, $sign))->toThrow(InvalidArgumentException::class);
})->with([
    'blank sign is unknown, not zero' => ['250,00', ''],
    'en dash is not a minus' => ['250,00', '–'],
    'letter sign' => ['250,00', 'D'],
    'dot decimals' => ['250.00', '+'],
    'missing decimals' => ['250', '+'],
    'single decimal' => ['250,0', '+'],
    'blank value' => ['', '+'],
    'letters in value' => ['abc', '+'],
    'above 13 integer digits' => ['99.999.999.999.999,99', '+'],
]);
