<?php

use App\Rules\Cnpj;

function cnpjIsValid(mixed $value): bool
{
    $failed = false;
    (new Cnpj)->validate('cnpj', $value, function () use (&$failed): void {
        $failed = true;
    });

    return ! $failed;
}

it('accepts formatted and unformatted valid cnpjs', function (string $cnpj) {
    expect(cnpjIsValid($cnpj))->toBeTrue();
})->with(['11.257.352/0001-43', '11257352000143']);

it('rejects malformed, repeated, wrong-sized, and invalid-check-digit cnpjs', function (mixed $cnpj) {
    expect(cnpjIsValid($cnpj))->toBeFalse();
})->with(['11.257.352/0001-44', '00.000.000/0000-00', '123', 'cnpj']);
