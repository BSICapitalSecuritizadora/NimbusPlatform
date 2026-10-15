<?php

use App\Support\BankIdentifierMasker;

it('masks canonical numbers keeping length and tail visible', function (string $number, string $expected) {
    expect(BankIdentifierMasker::maskNumber($number))->toBe($expected);
})->with([
    'zero-padded account' => ['0012345', '*****45'],
    'plain account' => ['12345', '***45'],
    'agency' => ['1234', '**34'],
    'two chars' => ['12', '**'],
    'single char' => ['1', '*'],
]);

it('masks agency and account pairs keeping the check digit visible', function () {
    expect(BankIdentifierMasker::maskAgency('0123', '4'))->toBe('**23-4')
        ->and(BankIdentifierMasker::maskAccount('0012345', '6'))->toBe('*****45-6')
        ->and(BankIdentifierMasker::maskAccount('12345', 'x'))->toBe('***45-X');
});

it('builds masked canonical keys with a visible bank id', function () {
    expect(BankIdentifierMasker::maskedCanonicalKey(7, '0123', '4', '0012345', '6'))
        ->toBe('7:**23-4:*****45-6');
});

it('masks raw values without trusting their content', function () {
    expect(BankIdentifierMasker::maskRaw(null))->toBe('(blank)')
        ->and(BankIdentifierMasker::maskRaw('   '))->toBe('(blank)')
        ->and(BankIdentifierMasker::maskRaw('12A4-XX'))->toBe('*****XX (7 chars)');
});

it('masks conta azul identifiers keeping head and tail visible', function () {
    expect(BankIdentifierMasker::maskContaAzulId(null))->toBe('(none)')
        ->and(BankIdentifierMasker::maskContaAzulId(''))->toBe('(none)')
        ->and(BankIdentifierMasker::maskContaAzulId('abc'))->toBe('***')
        ->and(BankIdentifierMasker::maskContaAzulId('ca-account-01'))->toBe('ca*********01');
});

it('never leaks a full identifier through the mask', function (string $full) {
    $masked = BankIdentifierMasker::maskNumber($full);

    expect($masked)->not->toContain($full)
        ->and(strlen($masked))->toBe(strlen($full));
})->with([
    'short' => ['1234'],
    'padded' => ['0012345'],
    'long' => ['123456789012'],
]);
