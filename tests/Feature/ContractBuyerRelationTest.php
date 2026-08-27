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
        // A coluna do modelo singular não existe mais.
        ->and(Schema::hasColumn('contracts', 'client_id'))->toBeFalse();
});

it('refuses the same buyer twice on the same contract', function () {
    $contract = buyerContract();
    $clientId = $contract->clients->first()->getKey();

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

    $contract = Contract::factory()
        ->forUnit(ConstructionUnit::factory()->create())
        ->withBuyers($joao, $maria)
        ->create();

    expect($contract->clients->pluck('name')->all())->toBe(['João da Silva', 'Maria da Silva'])
        ->and($contract->client_id)->toBeNull();
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

// ── Fonte única de verdade ────────────────────────────────────────────────

it('records the buyer in the buyer table, the only place it can live', function () {
    $contract = buyerContract();

    expect(DB::table('contract_clients')->where('contract_id', $contract->getKey())->pluck('client_id')->all())
        ->toBe([$contract->clients->first()->getKey()])
        ->and($contract->clients)->toHaveCount(1);
});

it('replaces the buyer set and says whether anything moved', function () {
    $joao = Client::factory()->create(['name' => 'João da Silva']);
    $maria = Client::factory()->create(['name' => 'Maria da Silva']);

    $contract = buyerContract($joao);

    expect($contract->syncBuyers([$joao->id, $maria->id]))->toBeTrue()
        ->and($contract->fresh()->clients->pluck('name')->all())->toBe(['João da Silva', 'Maria da Silva']);

    // Mesmo conjunto em outra ordem não é alteração.
    expect($contract->fresh()->syncBuyers([$maria->id, $joao->id]))->toBeFalse();
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

/**
 * O objetivo da remoção: nenhum código novo consegue escrever
 * `$contract->client_id`, não por convenção, mas porque não há onde escrever.
 * Nem coluna, nem chave estrangeira, nem índice órfão.
 */
it('leaves no trace of the single-buyer column on the contracts table', function () {
    $columns = collect(Schema::getColumns('contracts'))->pluck('name');

    $foreignKeysToClients = collect(Schema::getForeignKeys('contracts'))
        ->filter(fn (array $key): bool => $key['foreign_table'] === 'clients');

    $indexesOnClient = collect(Schema::getIndexes('contracts'))
        ->filter(fn (array $index): bool => in_array('client_id', $index['columns'], true));

    expect($columns)->not->toContain('client_id')
        ->and($foreignKeysToClients)->toBeEmpty()
        ->and($indexesOnClient)->toBeEmpty()
        // O resto da tabela sobreviveu intacto, inclusive a coluna gerada.
        ->and($columns)->toContain('construction_unit_id', 'code_normalized', 'occupied_unit_lock')
        ->and(collect(Schema::getIndexes('contracts'))
            ->filter(fn (array $index): bool => $index['columns'] === ['occupied_unit_lock']))
        ->not->toBeEmpty();
});

/**
 * A relação com o cliente passa a existir por um único caminho.
 */
it('keeps the only path from a contract to a client on the buyer table', function () {
    $keys = collect(Schema::getForeignKeys('contract_clients'))
        ->pluck('foreign_table')
        ->sort()
        ->values();

    expect($keys->all())->toBe(['clients', 'contracts'])
        ->and(Schema::hasColumns('contract_clients', ['id', 'contract_id', 'client_id']))->toBeTrue();
});

// ── Invariante da fase de expansão ────────────────────────────────────────

it('leaves no contract without a buyer', function () {
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

// ── A própria migration da Fase C ─────────────────────────────────────────

/**
 * O DROP acontece com contratos já na base, não numa tabela vazia: é o caso do
 * ambiente local, e é onde um erro custaria vínculos.
 */
it('leaves existing contracts and their buyers untouched by the drop', function () {
    $joao = Client::factory()->create(['name' => 'João da Silva']);
    $maria = Client::factory()->create(['name' => 'Maria da Silva']);

    $single = buyerContract($joao);
    $couple = Contract::factory()
        ->forUnit(ConstructionUnit::factory()->create())
        ->withBuyers($joao, $maria)
        ->create();

    $before = DB::table('contract_clients')->orderBy('contract_id')->orderBy('client_id')->get()->toArray();

    // Reaplica a remoção sobre a base já povoada.
    $migration = require database_path('migrations/2026_08_26_400001_drop_client_id_from_contracts_table.php');
    $migration->down();
    $migration->up();

    expect(Schema::hasColumn('contracts', 'client_id'))->toBeFalse()
        ->and(DB::table('contract_clients')->orderBy('contract_id')->orderBy('client_id')->get()->toArray())->toEqual($before)
        ->and($single->fresh()->buyerIds())->toBe([$joao->id])
        ->and($couple->fresh()->clients->pluck('name')->all())->toBe(['João da Silva', 'Maria da Silva']);
});

/**
 * A coluna some enquanto for a única referência de comprador de alguém.
 */
it('refuses to drop the column while some contract has no buyer', function () {
    $contract = buyerContract();

    $migration = require database_path('migrations/2026_08_26_400001_drop_client_id_from_contracts_table.php');
    $migration->down();

    // Um contrato órfão na tabela de compradores.
    DB::table('contract_clients')->where('contract_id', $contract->getKey())->delete();

    expect(fn () => $migration->up())
        ->toThrow(RuntimeException::class, 'não possuem comprador');

    // Aborta antes de qualquer DDL: o schema continua como estava.
    expect(Schema::hasColumn('contracts', 'client_id'))->toBeTrue();
});

/**
 * Rollback devolve a forma da tabela, nunca um comprador inventado.
 */
it('restores the column empty, with its key and index, and no buyer', function () {
    $joao = Client::factory()->create();
    $maria = Client::factory()->create();

    $contract = Contract::factory()
        ->forUnit(ConstructionUnit::factory()->create())
        ->withBuyers($joao, $maria)
        ->create();

    $migration = require database_path('migrations/2026_08_26_400001_drop_client_id_from_contracts_table.php');
    $migration->down();

    $column = collect(Schema::getColumns('contracts'))->firstWhere('name', 'client_id');

    $foreignKeys = collect(Schema::getForeignKeys('contracts'))
        ->filter(fn (array $key): bool => in_array('client_id', $key['columns'], true));

    $indexes = collect(Schema::getIndexes('contracts'))
        ->filter(fn (array $index): bool => in_array('client_id', $index['columns'], true));

    expect($column)->not->toBeNull()
        ->and($column['nullable'])->toBeTrue()
        ->and($foreignKeys)->not->toBeEmpty()
        ->and($indexes)->not->toBeEmpty()
        // Nenhum comprador foi escolhido para preencher a coluna.
        ->and(DB::table('contracts')->whereNotNull('client_id')->count())->toBe(0)
        // E os compradores de verdade continuam onde sempre estiveram.
        ->and($contract->fresh()->clients)->toHaveCount(2);
});
