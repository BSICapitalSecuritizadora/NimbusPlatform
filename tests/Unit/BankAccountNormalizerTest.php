<?php

use App\Support\BankAccountNormalizer;

it('parses agency and account values into canonical number and digit parts', function (string $method, string $input, string $number, string $digit) {
    expect(BankAccountNormalizer::{$method}($input))->toBe(['number' => $number, 'digit' => $digit]);
})->with([
    'agency with dash' => ['parseAgency', '1234-5', '1234', '5'],
    'agency with slash' => ['parseAgency', '1234/5', '1234', '5'],
    'agency with dot' => ['parseAgency', '1234.5', '1234', '5'],
    'agency with surrounding whitespace' => ['parseAgency', '  1234-5  ', '1234', '5'],
    'agency with spaced separator' => ['parseAgency', '1234 - 5', '1234', '5'],
    'agency with leading zeros' => ['parseAgency', '0123-4', '0123', '4'],
    'account with dash' => ['parseAccount', '12345-6', '12345', '6'],
    'account with slash' => ['parseAccount', '12345/6', '12345', '6'],
    'account with leading zeros' => ['parseAccount', '0012345-6', '0012345', '6'],
    'account with grouped digits' => ['parseAccount', '12 345-6', '12345', '6'],
    'nine digit account' => ['parseAccount', '123456789-0', '123456789', '0'],
    'lowercase x digit is uppercased' => ['parseAccount', '12345-x', '12345', 'X'],
]);

it('rejects malformed or ambiguous agency and account values', function (string $method, string $input) {
    expect(fn () => BankAccountNormalizer::{$method}($input))->toThrow(InvalidArgumentException::class);
})->with([
    'blank agency' => ['parseAgency', '   '],
    'blank account' => ['parseAccount', ''],
    'agency without separator is ambiguous' => ['parseAgency', '12345'],
    'account without separator is ambiguous' => ['parseAccount', '123456'],
    'agency with two separators' => ['parseAgency', '1.234-5'],
    'account with two separators' => ['parseAccount', '12-345-6'],
    'letters in the number' => ['parseAccount', '12A45-6'],
    'missing digit' => ['parseAccount', '12345-'],
    'missing number' => ['parseAccount', '-6'],
    'two character digit' => ['parseAccount', '12345-67'],
    'agency number too long' => ['parseAgency', '1234567-8'],
    'account number too short' => ['parseAccount', '123-4'],
    'account number too long' => ['parseAccount', '1234567890123-4'],
]);

it('formats canonical parts for display preserving leading zeros', function () {
    expect(BankAccountNormalizer::formatAgency('0123', '4'))->toBe('0123-4')
        ->and(BankAccountNormalizer::formatAccount('0012345', '6'))->toBe('0012345-6')
        ->and(BankAccountNormalizer::formatAccount('12345', 'x'))->toBe('12345-X');
});

it('rejects non canonical parts when formatting for display', function () {
    expect(fn () => BankAccountNormalizer::formatAgency('12A4', '5'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => BankAccountNormalizer::formatAccount('123', '6'))->toThrow(InvalidArgumentException::class);
});

it('normalizes product codes trimming whitespace while preserving zeros and casing', function () {
    expect(BankAccountNormalizer::normalizeCodigoProduto('  00123  '))->toBe('00123')
        ->and(BankAccountNormalizer::normalizeCodigoProduto('CDB DI'))->toBe('CDB DI');
});

it('rejects blank product codes', function () {
    expect(fn () => BankAccountNormalizer::normalizeCodigoProduto('   '))->toThrow(InvalidArgumentException::class);
});

it('builds a deterministic canonical key from the physical identity', function () {
    expect(BankAccountNormalizer::canonicalKey(7, '0123', '4', '0012345', '6'))
        ->toBe('7:0123:4:0012345:6')
        ->and(BankAccountNormalizer::canonicalKey(7, '0123', '4', '0012345', '6'))
        ->toBe(BankAccountNormalizer::canonicalKey(7, '0123', '4', '0012345', '6'))
        ->and(BankAccountNormalizer::canonicalKey(8, '0123', '4', '0012345', '6'))
        ->not->toBe(BankAccountNormalizer::canonicalKey(7, '0123', '4', '0012345', '6'));
});
