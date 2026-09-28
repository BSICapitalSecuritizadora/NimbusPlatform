<?php

use App\Models\SalesBoard;
use App\Models\SalesBoardHistory;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SalesBoards\RolloutFixture;

uses(RefreshDatabase::class);

/**
 * O quadro manual é gravado numa transação própria.
 *
 * O guard de escrita trava a Emissão em modo compartilhado para serializar o
 * registro manual com a ativação da automação -- a corrida em si está em
 * SalesBoardRolloutManualWriteMysqlConcurrencyTest, que só roda em MySQL. Aqui
 * fica o que o SQLite consegue provar: a leitura do guard acontece dentro de
 * uma transação aberta pela própria gravação, e não depende de quem chama ter
 * aberto uma, e o quadro não sobrevive sem a sua primeira versão.
 */
it('reads the claiming emission inside a transaction opened by the save itself', function () {
    $scenario = RolloutFixture::emission(1);
    $construction = $scenario['constructions'][0];

    $callerLevel = DB::transactionLevel();
    $guardLevels = [];

    DB::listen(function (QueryExecuted $query) use (&$guardLevels): void {
        if (str_contains(str_replace(['"', '`'], '', $query->sql), 'from emissions where emissions.id in')) {
            $guardLevels[] = $query->connection->transactionLevel();
        }
    });

    RolloutFixture::legacyBoard($construction, '2026-08-01');

    expect($guardLevels)->not->toBeEmpty()
        ->and(min($guardLevels))->toBeGreaterThan($callerLevel);
});

it('does not keep a board whose first history version fails to be recorded', function () {
    $scenario = RolloutFixture::emission(1);
    $construction = $scenario['constructions'][0];

    SalesBoardHistory::creating(function (): never {
        throw new RuntimeException('Falha simulada ao gravar a versão.');
    });

    expect(fn () => RolloutFixture::legacyBoard($construction, '2026-08-01'))
        ->toThrow(RuntimeException::class, 'Falha simulada ao gravar a versão.');

    expect(SalesBoard::query()->where('construction_id', $construction->getKey())->count())->toBe(0);
});
