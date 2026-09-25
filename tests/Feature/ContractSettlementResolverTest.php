<?php

use App\Enums\ContractSettlementState;
use App\Models\Contract;
use App\Models\ContractInstallment;
use App\Services\SalesBoards\ContractSettlementResolver;
use App\Services\SalesBoards\SalesBoardDerivationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Support\SalesBoards\DerivationFixture;

uses(RefreshDatabase::class);

/**
 * O resolvedor lê as parcelas cruas, sem os casts do model, e a forma crua não
 * é a mesma nos dois bancos: data com ou sem hora, decimal como string, inteiro
 * ou `float`. A regra precisa responder igual nos dois, e é por isso que o
 * arquivo roda também no MySQL.
 */
pest()->group('parity');

/**
 * Parcela gravada por `insert()` em lote, como a importação grava: no SQLite a
 * data fica `Y-m-d`, sem a hora que o Eloquent acrescenta.
 */
function rawInstallment(Contract $contract, string $number, string $expected, ?string $paymentDate = null, ?string $paid = null, ?string $cancellationDate = null): void
{
    ContractInstallment::query()->insert([
        'contract_id' => $contract->id,
        'number' => $number,
        'number_normalized' => $number,
        'due_date' => '2026-07-10',
        'expected_value' => $expected,
        'payment_date' => $paymentDate,
        'paid_value' => $paid,
        'cancellation_date' => $cancellationDate,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @return array<string, array{state: ContractSettlementState, valid: int, paid: int}>
 */
function settlementAt(Contract $contract, string ...$days): array
{
    $resolved = app(ContractSettlementResolver::class)->resolveForContractsAtDates(
        [$contract->id],
        array_map(fn (string $day): CarbonImmutable => CarbonImmutable::parse($day), $days),
    );

    return collect($resolved)->map(fn (array $summaries): array => [
        'state' => $summaries[$contract->id]->state,
        'valid' => $summaries[$contract->id]->validInstallments,
        'paid' => $summaries[$contract->id]->paidInstallments,
    ])->all();
}

it('resolves the settlement without hydrating a model per installment', function () {
    $construction = DerivationFixture::construction();
    $contract = DerivationFixture::contract(DerivationFixture::unit($construction, '101'), '2026-01-10');

    foreach (range(1, 6) as $number) {
        DerivationFixture::installment($contract, (string) $number, '2026-02-10', '100000.00', '2026-02-10', '100000.00');
    }

    $hydrated = 0;
    Event::listen('eloquent.retrieved: '.ContractInstallment::class, function () use (&$hydrated): void {
        $hydrated++;
    });

    $position = app(SalesBoardDerivationService::class)->deriveForConstruction($construction, CarbonImmutable::parse('2026-07-01'));

    expect($position->settledUnits)->toBe(1)
        ->and(DerivationFixture::lineFor($position, $contract->constructionUnit)->settlementInstallmentsPaid)->toBe(6)
        ->and($hydrated)->toBe(0);
});

it('answers the same whether the dates were written by Eloquent or by a bulk insert', function () {
    $construction = DerivationFixture::construction();

    $eloquent = DerivationFixture::contract(DerivationFixture::unit($construction, '101'), '2026-01-10');
    DerivationFixture::installment($eloquent, '001', '2026-07-10', '300000.00', '2026-07-31', '300000.00');
    DerivationFixture::installment($eloquent, '002', '2026-07-10', '300000.00', cancellationDate: '2026-07-31');
    DerivationFixture::installment($eloquent, '003', '2026-07-10', '300000.00', '2026-08-01', '300000.00', cancellationDate: '2026-08-01');

    $bulk = DerivationFixture::contract(DerivationFixture::unit($construction, '102'), '2026-01-10');
    rawInstallment($bulk, '001', '300000.00', '2026-07-31', '300000.00');
    rawInstallment($bulk, '002', '300000.00', cancellationDate: '2026-07-31');
    rawInstallment($bulk, '003', '300000.00', '2026-08-01', '300000.00', cancellationDate: '2026-08-01');

    $days = ['2026-07-30', '2026-07-31', '2026-08-01'];

    $expected = [
        // Everything still valid, nothing paid yet.
        '2026-07-30' => ['state' => ContractSettlementState::Outstanding, 'valid' => 3, 'paid' => 0],
        // Paid on the day counts; cancelled on the day no longer exists.
        '2026-07-31' => ['state' => ContractSettlementState::Outstanding, 'valid' => 2, 'paid' => 1],
        '2026-08-01' => ['state' => ContractSettlementState::Settled, 'valid' => 1, 'paid' => 1],
    ];

    expect(settlementAt($eloquent, ...$days))->toBe($expected)
        ->and(settlementAt($bulk, ...$days))->toBe($expected);
});

it('decides the payment by the exact cent, whatever the storage form of the amount', function () {
    $construction = DerivationFixture::construction();

    $shortByOneCent = DerivationFixture::contract(DerivationFixture::unit($construction, '101'), '2026-01-10');
    rawInstallment($shortByOneCent, '001', '600000.00', '2026-02-10', '599999.99');

    $withInterest = DerivationFixture::contract(DerivationFixture::unit($construction, '102'), '2026-01-10');
    rawInstallment($withInterest, '001', '600000.00', '2026-02-10', '600000.01');

    $paidWithoutValue = DerivationFixture::contract(DerivationFixture::unit($construction, '103'), '2026-01-10');
    rawInstallment($paidWithoutValue, '001', '600000.00', '2026-02-10');

    expect(settlementAt($shortByOneCent, '2026-07-31')['2026-07-31']['state'])->toBe(ContractSettlementState::Outstanding)
        ->and(settlementAt($withInterest, '2026-07-31')['2026-07-31']['state'])->toBe(ContractSettlementState::Settled)
        ->and(settlementAt($paidWithoutValue, '2026-07-31')['2026-07-31']['state'])->toBe(ContractSettlementState::Outstanding);
});

it('leaves soft deleted installments out and never settles a contract by vacuity', function () {
    $construction = DerivationFixture::construction();
    $contract = DerivationFixture::contract(DerivationFixture::unit($construction, '101'), '2026-01-10');

    DerivationFixture::installment($contract, '001', '2026-02-10', '300000.00')->delete();

    expect(settlementAt($contract, '2026-07-31')['2026-07-31'])->toBe([
        'state' => ContractSettlementState::Undetermined,
        'valid' => 0,
        'paid' => 0,
    ]);
});
