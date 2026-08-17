<?php

use App\Actions\Proposals\ResolveStateRegistration;

it('prioritizes an active state registration even when it is not first', function () {
    $registration = (new ResolveStateRegistration)->handle([
        ['inscricao_estadual' => 'INATIVA-1', 'situacao' => 'Inativa'],
        ['inscricao_estadual' => 'ATIVA-2', 'situacao' => 'Ativa'],
        ['inscricao_estadual' => 'ATIVA-3', 'ativo' => true],
    ]);

    expect($registration)->toBe('ATIVA-2');
});

it('falls back to the first valid registration when all are inactive', function () {
    expect((new ResolveStateRegistration)->handle([
        ['inscricao_estadual' => 'IE-1', 'status' => 'baixada'],
        ['inscricao_estadual' => 'IE-2', 'ativo' => false],
    ]))->toBe('IE-1');
});

it('returns null when registrations are absent or incomplete', function (array $registrations) {
    expect((new ResolveStateRegistration)->handle($registrations))->toBeNull();
})->with([
    'empty list' => [[]],
    'missing number' => [[['ativo' => true]]],
    'malformed items' => [[null, 'invalid']],
]);
