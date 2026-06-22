<?php

use App\Domain\PuCalculator\Support\PuVersionNumber;

it('extracts ordinals from recognizable version strings', function (?string $input, ?int $expected) {
    expect(PuVersionNumber::ordinal($input))->toBe($expected);
})->with([
    'canonical' => ['v1', 1],
    'uppercase with space' => ['V 2', 2],
    'plain number' => ['3', 3],
    'zero padded' => ['v01', 1],
    'prefixed word' => ['versao-4', 4],
    'underscored' => ['rev_10', 10],
    'trailing number wins' => ['2024-v7', 7],
    'non numeric' => ['final', null],
    'empty' => ['', null],
    'null' => [null, null],
]);

it('computes the highest ordinal and the next canonical version', function () {
    expect(PuVersionNumber::highestOrdinal(['v1', 'rev_5', 'final', null]))->toBe(5)
        ->and(PuVersionNumber::formatNext(['v1', 'rev_5']))->toBe('v6')
        ->and(PuVersionNumber::formatNext([]))->toBe('v1')
        ->and(PuVersionNumber::isRecognizable('v9'))->toBeTrue()
        ->and(PuVersionNumber::isRecognizable('final'))->toBeFalse();
});
