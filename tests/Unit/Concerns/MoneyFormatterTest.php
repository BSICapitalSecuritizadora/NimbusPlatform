<?php

use App\Concerns\MoneyFormatter;

it('normalizes brazilian and decimal monetary inputs', function (mixed $input, float $expected) {
    expect(MoneyFormatter::normalizeDecimalValue($input))->toBe($expected);
})->with([
    'null becomes zero' => [null, 0.0],
    'empty string becomes zero' => ['', 0.0],
    'integer is rounded to two decimals' => [1250, 1250.0],
    'float is rounded to two decimals' => [1250.556, 1250.56],
    'brazilian currency string' => ['R$ 1.234,56', 1234.56],
    'thousands separator with dot only' => ['1.234', 1234.0],
    'decimal with dot' => ['1234.56', 1234.56],
    'large brazilian amount' => ['9.876.543,21', 9876543.21],
    'invalid value becomes zero' => ['abc', 0.0],
]);

it('normalizes integer-like inputs using the monetary parser', function (mixed $input, int $expected) {
    expect(MoneyFormatter::normalizeIntegerValue($input))->toBe($expected);
})->with([
    'string rounds down' => ['10,4', 10],
    'string rounds up' => ['10,6', 11],
    'currency string rounds' => ['R$ 1.999,50', 2000],
    'invalid value becomes zero' => ['foo', 0],
]);

it('formats normalized values for display using brazilian currency conventions', function () {
    expect(MoneyFormatter::formatCurrencyForDisplay('R$ 1234,5'))->toBe('1.234,50')
        ->and(MoneyFormatter::formatCurrencyForDisplay(9876543.219))->toBe('9.876.543,22');
});

it('rounds complex fractional cent values safely in brazilian monetary inputs', function (mixed $input, float $expected) {
    expect(MoneyFormatter::normalizeDecimalValue($input))->toBe($expected);
})->with([
    'repeating decimal with dot' => ['33.3333', 33.33],
    'repeating decimal with comma' => ['33,3333', 33.33],
    'half-up brazilian rounding' => ['R$ 33,335', 33.34],
    'high precision integer string' => ['1000.9999', 1001.0],
]);

it('formats null or blank monetary values as zero for display', function (mixed $input) {
    expect(MoneyFormatter::formatCurrencyForDisplay($input))->toBe('0,00');
})->with([
    'null' => null,
    'empty string' => '',
    'whitespace' => '   ',
    'currency prefix only' => 'R$ ',
]);

it('treats null-like values as zero when normalizing integers', function (mixed $input) {
    expect(MoneyFormatter::normalizeIntegerValue($input))->toBe(0);
})->with([
    'null' => null,
    'empty string' => '',
    'whitespace' => '   ',
    'invalid alpha' => 'sem valor',
]);
