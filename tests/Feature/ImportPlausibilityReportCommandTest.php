<?php

use App\Models\Client;
use App\Models\ConstructionUnit;
use App\Models\ConstructionUnitExchange;
use App\Models\ConstructionUnitValue;
use App\Models\Contract;
use App\Models\ContractInstallment;
use App\Models\Emission;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * O relatório somente leitura das regras de plausibilidade sobre o dado já
 * cadastrado -- o que a carga do leitor antigo deixou e que as importações novas
 * recusariam ou avisariam.
 */
uses(RefreshDatabase::class);

/**
 * Um contrato de R$ 900.000,00 numa unidade de tabela R$ 800.000,00, com uma
 * parcela lida mil vezes maior, um pagamento acima da venda e uma vigência de
 * valor com dia e mês trocados.
 *
 * @return array{contract: Contract, inflated: ContractInstallment, overpaid: ContractInstallment, contractOffTable: Contract, valueJump: ConstructionUnitValue, swapped: ConstructionUnitValue}
 */
function plausibilityReportScenario(): array
{
    [, $construction] = unitEmissionAndConstruction(status: 'active');

    $unit = ConstructionUnit::factory()->forConstruction($construction)->create([
        'block' => '01', 'unit' => '305', 'base_value' => '800000.00', 'base_value_reference_date' => '2024-01-01',
    ]);

    $contract = Contract::factory()->forUnit($unit)->forClient(Client::factory()->create())
        ->create(['code' => 'CVC-00123', 'sale_date' => '2024-03-10', 'sale_value' => 900000.00]);

    $inflated = ContractInstallment::factory()->forContract($contract)->create([
        'number' => '001', 'due_date' => '2024-04-10', 'expected_value' => 5539190,
    ]);
    $overpaid = ContractInstallment::factory()->forContract($contract)->create([
        'number' => '002', 'due_date' => '2024-05-10', 'expected_value' => 7500, 'payment_date' => '2024-05-10', 'paid_value' => 950000,
    ]);
    ContractInstallment::factory()->forContract($contract)->create([
        'number' => '003', 'due_date' => '2024-06-10', 'expected_value' => 7500,
    ]);

    $otherUnit = ConstructionUnit::factory()->forConstruction($construction)->create([
        'block' => '01', 'unit' => '306', 'base_value' => '800000.00', 'base_value_reference_date' => '2024-01-01',
    ]);
    $contractOffTable = Contract::factory()->forUnit($otherUnit)->forClient(Client::factory()->create())
        ->create(['code' => 'CVC-00124', 'sale_date' => '2024-03-10', 'sale_value' => 8000000.00]);

    $valueJump = ConstructionUnitValue::factory()->create([
        'construction_unit_id' => $unit->id, 'value' => '8000000.00', 'effective_from' => '2025-01-01',
    ]);
    $swapped = ConstructionUnitValue::factory()->create([
        'construction_unit_id' => $otherUnit->id, 'value' => '820000.00', 'effective_from' => '2026-03-09',
    ]);
    ConstructionUnitValue::factory()->create([
        'construction_unit_id' => $otherUnit->id, 'value' => '820000.00', 'effective_from' => '2026-09-03',
    ]);

    return compact('contract', 'inflated', 'overpaid', 'contractOffTable', 'valueJump', 'swapped');
}

it('reports the count and the ids of each rule', function () {
    $scenario = plausibilityReportScenario();

    $this->artisan('imports:plausibility-report')
        ->expectsTable(
            ['Regra', 'Registros', 'Ids'],
            [
                ['Parcela: erro (previsto acima do dobro da venda)', 1, (string) $scenario['inflated']->id],
                ['Parcela: aviso (parcela acima do valor da venda)', 1, (string) $scenario['overpaid']->id],
                ['Parcela: aviso (pago muito acima do previsto)', 1, (string) $scenario['overpaid']->id],
                ['Contrato: erro (venda a dez vezes ou mais da tabela, ou a um décimo ou menos)', 1, (string) $scenario['contractOffTable']->id],
                ['Valor de unidade: erro (valor a dez vezes ou mais do anterior, ou a um décimo ou menos)', 1, (string) $scenario['valueJump']->id],
                ['Valor de unidade: vigência com dia e mês trocados', 1, (string) $scenario['swapped']->id],
            ],
        )
        ->expectsOutputToContain('Somente leitura: nenhum registro foi alterado.')
        ->assertSuccessful();
});

it('writes nothing to the database', function () {
    plausibilityReportScenario();

    $statements = [];

    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        $statements[] = strtolower(ltrim($query->sql));
    });

    $this->artisan('imports:plausibility-report')->assertSuccessful();

    $writes = array_filter($statements, fn (string $sql): bool => str_starts_with($sql, 'insert')
        || str_starts_with($sql, 'update')
        || str_starts_with($sql, 'delete'));

    expect($statements)->not->toBeEmpty()
        ->and($writes)->toBe([]);
});

it('limits the report to the emission asked for', function () {
    plausibilityReportScenario();

    [$otherEmission] = unitEmissionAndConstruction('CRI Outra', 'Outra Obra', 'active');

    $this->artisan('imports:plausibility-report', ['--emission' => $otherEmission->id])
        ->expectsOutputToContain('Nenhum registro cadastrado fora das regras de plausibilidade.')
        ->assertSuccessful();
});

/**
 * Um erro de digitação no filtro não pode virar o relatório de outra Emissão,
 * nem um "nada a corrigir" com código de sucesso.
 */
it('refuses an emission that is not the id of a registered one', function (string $case) {
    plausibilityReportScenario();

    $emissionId = (string) Emission::query()->value('id');

    [$value, $message] = match ($case) {
        'id com letra' => [$emissionId.'a', 'Emissão inválida: "'.$emissionId.'a". Informe o id numérico.'],
        'lista' => [$emissionId.',2', 'Emissão inválida: "'.$emissionId.',2". Informe o id numérico.'],
        'nome' => ['abc', 'Emissão inválida: "abc". Informe o id numérico.'],
        'vazio' => ['', 'Emissão inválida: "". Informe o id numérico.'],
        'inexistente' => ['999999', 'Emissão 999999 não encontrada. Informe o id de uma emissão cadastrada.'],
    };

    $this->artisan('imports:plausibility-report', ['--emission' => $value])
        ->expectsOutputToContain($message)
        ->doesntExpectOutputToContain('Nenhum')
        ->assertFailed();
})->with(['id com letra', 'lista', 'nome', 'vazio', 'inexistente']);

/**
 * O relatório usa a regra da importação de um contrato novo: a permuta fica
 * fora -- não tem preço de tabela --, e a venda sem tabela na data é medida
 * contra a tabela da data da posição, como a derivação.
 */
it('leaves the exchange contract out and measures a sale with no table on its day against the position', function () {
    $this->travelTo('2026-09-20 12:00:00');

    [, $construction] = unitEmissionAndConstruction(status: 'active');

    $exchangeUnit = ConstructionUnit::factory()->forConstruction($construction)->withBaseValue('500000.00', '2024-01-01')->create(['block' => '01', 'unit' => '305']);
    $exchange = Contract::factory()->forUnit($exchangeUnit)->forClient(Client::factory()->create())->exchanged()
        ->create(['code' => 'PERM-305', 'sale_date' => '2024-03-10', 'sale_value' => 30000.00]);
    ConstructionUnitExchange::factory()->forUnit($exchangeUnit)->forContract($exchange)->effectiveFrom('2024-03-10')->create();

    $lateTableUnit = ConstructionUnit::factory()->forConstruction($construction)->withBaseValue('480000.00', '2026-06-01')->create(['block' => '01', 'unit' => '306']);
    $beforeTable = Contract::factory()->forUnit($lateTableUnit)->forClient(Client::factory()->create())
        ->create(['code' => 'CVC-00306', 'sale_date' => '2026-02-15', 'sale_value' => 4800000.00]);

    $this->artisan('imports:plausibility-report')
        ->expectsTable(
            ['Regra', 'Registros', 'Ids'],
            [['Contrato: erro (venda a dez vezes ou mais da tabela, ou a um décimo ou menos)', 1, (string) $beforeTable->id]],
        )
        ->assertSuccessful();
});
