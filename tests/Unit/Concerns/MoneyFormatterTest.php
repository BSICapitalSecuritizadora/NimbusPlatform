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
