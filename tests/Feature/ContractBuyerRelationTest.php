<?php

use App\Models\Client;
use App\Models\ConstructionUnit;
use App\Models\Contract;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * Part of the `parity` group: the buyer table is a unique index, a pair of
 * foreign keys and a backfill, and all three behave differently enough between
 * SQLite and MySQL to be worth proving on both. The normal suite runs it on
 * SQLite; `composer test:parity` runs the same tests on MySQL.
 */
pest()->group('parity');

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

function buyerContract(?Client $client = null): Contract
{
    return Contract::factory()
        ->forUnit(ConstructionUnit::factory()->create())
        ->forClient($client ?? Client::factory()->create())
        ->create();
}

// ── Schema ────────────────────────────────────────────────────────────────

it('creates the buyer table without touching the columns the contract already had', function () {
    expect(Schema::hasTable('contract_clients'))->toBeTrue()
        ->and(Schema::hasColumns('contract_clients', ['id', 'contract_id', 'client_id']))->toBeTrue()
        // Sem timestamps: a data técnica do vínculo não é data comercial.
        ->and(Schema::hasColumn('contract_clients', 'created_at'))->toBeFalse()
        ->and(Schema::hasColumn('contract_clients', 'updated_at'))->toBeFalse()
        // A coluna antiga continua no lugar durante a fase de expansão.
        ->and(Schema::hasColumn('contracts', 'client_id'))->toBeTrue();
});

it('refuses the same buyer twice on the same contract', function () {
    $contract = buyerContract();
    $clientId = $contract->client_id;

    expect(fn () => DB::table('contract_clients')->insert([
        'contract_id' => $contract->getKey(),
        'client_id' => $clientId,
    ]))->toThrow(QueryException::class);

    expect(DB::table('contract_clients')->where('contract_id', $contract->getKey())->count())->toBe(1);
});

// ── Relações ──────────────────────────────────────────────────────────────

it('reads one buyer through the plural relation', function () {
    $client = Client::factory()->create(['name' => 'João da Silva']);
    $contract = buyerContract($client);

    expect($contract->clients()->pluck('clients.id')->all())->toBe([$client->id]);
});

it('reads several buyers of the same contract', function () {
    $joao = Client::factory()->create(['name' => 'João da Silva']);
    $maria = Client::factory()->create(['name' => 'Maria da Silva']);

    $contract = buyerContract($joao);

    DB::table('contract_clients')->insert([
        'contract_id' => $contract->getKey(),
        'client_id' => $maria->id,
    ]);

    expect($contract->fresh()->clients->pluck('name')->all())->toBe(['João da Silva', 'Maria da Silva']);
});

it('reads every contract of one buyer', function () {
    $client = Client::factory()->create();

    $first = buyerContract($client);
    $second = buyerContract($client);
    buyerContract();

    expect($client->contracts()->pluck('contracts.id')->sort()->values()->all())
        ->toBe(collect([$first->id, $second->id])->sort()->values()->all());
});

it('keeps an archived buyer visible on the contract that hired them', function () {
    $client = Client::factory()->create(['name' => 'João da Silva']);
    $contract = buyerContract($client);

    $client->delete();

    expect($client->fresh()->trashed())->toBeTrue()
        ->and($contract->fresh()->clients->pluck('name')->all())->toBe(['João da Silva'])
        ->and(DB::table('contract_clients')->where('contract_id', $contract->getKey())->count())->toBe(1);
});

it('refuses to erase a client that appears in a contract', function () {
    $client = Client::factory()->create();
    buyerContract($client);

    expect(fn () => $client->forceDelete())->toThrow(QueryException::class);
});

// ── Espelho transitório client_id → pivot ─────────────────────────────────

it('records the buyer of a contract created through the model', function () {
    $contract = buyerContract();

    expect(DB::table('contract_clients')->where('contract_id', $contract->getKey())->pluck('client_id')->all())
        ->toBe([$contract->client_id]);
});

it('follows the buyer when the legacy column is changed', function () {
    $contract = buyerContract();
    $other = Client::factory()->create();

    $contract->update(['client_id' => $other->id]);

    expect(DB::table('contract_clients')->where('contract_id', $contract->getKey())->pluck('client_id')->all())
        ->toBe([$other->id])
        ->and($contract->fresh()->clients()->count())->toBe(1);
});

it('leaves the buyer alone when the contract is saved without changing them', function () {
    $contract = buyerContract();

    $contract->update(['sale_value' => 999000.00]);
    $contract->update(['sale_value' => 999000.00]);

    expect(DB::table('contract_clients')->where('contract_id', $contract->getKey())->count())->toBe(1);
});

it('drops the contract links when the contract is erased', function () {
    $contract = buyerContract();

    $contract->forceDelete();

    expect(DB::table('contract_clients')->where('contract_id', $contract->getKey())->count())->toBe(0);
});

it('keeps the links of a contract that was only archived', function () {
    $contract = buyerContract();

    $contract->delete();

    expect(DB::table('contract_clients')->where('contract_id', $contract->getKey())->count())->toBe(1);
});

// ── Invariante da fase de expansão ────────────────────────────────────────

it('leaves every contract with exactly one buyer while the domain is still singular', function () {
    collect(range(1, 5))->each(fn () => buyerContract());

    $contracts = Contract::query()->count();

    $withBuyer = DB::table('contract_clients')->distinct()->count('contract_id');

    $withMoreThanOne = DB::table('contract_clients')
        ->select('contract_id')
        ->groupBy('contract_id')
        ->havingRaw('COUNT(*) > 1')
        ->get()
        ->count();

    expect($contracts)->toBe(5)
        ->and($withBuyer)->toBe(5)
        ->and($withMoreThanOne)->toBe(0);
});
