<?php

use App\Support\PhoneNormalizer;

it('normalizes Brazilian phones for wa me links', function (string $phone, string $expected) {
    expect(PhoneNormalizer::forWhatsApp($phone))->toBe($expected);
})->with([
    ['(11) 99999-0000', '5511999990000'],
    ['55 11 4000-0000', '551140000000'],
]);

it('rejects phones that cannot form a valid international number', function (mixed $phone) {
    expect(PhoneNormalizer::forWhatsApp($phone))->toBeNull();
})->with([
    'null' => [null],
    'empty' => [''],
    'too short' => ['12345'],
]);
