<?php

use App\Models\User;
use App\Support\Users\UserIdentityMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** @return array{0: mixed, 1: int} o resultado e quantas consultas ele custou */
function countingQueries(callable $subject): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $result = $subject();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();
    DB::flushQueryLog();

    return [$result, $queries];
}

it('resolve o mesmo usuário uma vez e devolve sempre a mesma instância', function () {
    $user = User::factory()->create();
    $map = new UserIdentityMap;

    [$instances, $queries] = countingQueries(fn (): array => [
        $map->get($user->getKey()),
        $map->get($user->getKey()),
        $map->get($user->getKey()),
    ]);

    expect($queries)->toBe(1)
        ->and($instances[0])->toBe($instances[1])
        ->and($instances[1])->toBe($instances[2])
        ->and($instances[0]->getKey())->toBe($user->getKey());
});

it('carrega em uma consulta os ids que ainda faltam, e só eles', function () {
    $users = User::factory()->count(3)->create();
    $map = new UserIdentityMap;

    [, $first] = countingQueries(fn () => $map->get($users[0]->getKey()));
    [, $second] = countingQueries(fn () => $map->load($users->modelKeys()));
    [, $third] = countingQueries(fn () => $map->load($users->modelKeys()));

    expect($first)->toBe(1)
        ->and($second)->toBe(1)
        ->and($third)->toBe(0)
        ->and($map->size())->toBe(3);
});

it('lembra a ausência de um id órfão em vez de reconsultá-lo', function () {
    $map = new UserIdentityMap;

    [$results, $queries] = countingQueries(fn (): array => [$map->get(9_999), $map->get(9_999)]);

    expect($queries)->toBe(1)
        ->and($results[0])->toBeNull()
        ->and($results[1])->toBeNull();
});

it('carrega junto as relações declaradas e nenhuma outra', function () {
    $user = User::factory()->create();
    $map = new UserIdentityMap(['roles', 'permissions']);

    [$loaded] = countingQueries(fn (): ?User => $map->get($user->getKey()));

    expect($loaded?->relationLoaded('roles'))->toBeTrue()
        ->and($loaded?->relationLoaded('permissions'))->toBeTrue();

    // As relações vieram com o usuário: consultá-las de novo não custa nada.
    [, $queries] = countingQueries(function () use ($loaded): void {
        $loaded?->roles->count();
        $loaded?->permissions->count();
    });

    expect($queries)->toBe(0);
});

it('ignora ids nulos', function () {
    $map = new UserIdentityMap;

    [$result, $queries] = countingQueries(fn (): ?User => $map->get(null));

    expect($result)->toBeNull()
        ->and($queries)->toBe(0)
        ->and($map->size())->toBe(0);
});
