<?php

use App\Enums\ContractSettlementState;
use App\Enums\ContractStatus;
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
function rawInstallment(
    Contract $contract,
    string $number,
    string $expected,
    ?string $paymentDate = null,
    ?string $paid = null,
    ?string $cancellationDate = null,
    ?string $discount = null,
): void {
    ContractInstallment::query()->insert([
        'contract_id' => $contract->id,
        'number' => $number,
        'number_normalized' => $number,
        'due_date' => '2026-07-10',
        'expected_value' => $expected,
        'payment_date' => $paymentDate,
        'paid_value' => $paid,
        'discount_value' => $discount,
        'cancellation_date' => $cancellationDate,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * O resolvedor recebe o mapa de distratos dos contratos já carregados -- aqui,
 * o do próprio contrato, como a derivação passaria.
 *
 * @return array<int, string>
 */
function distratoMapOf(Contract $contract): array
{
    $contract->refresh();

    return $contract->cancellation_date === null ? [] : [$contract->id => $contract->cancellation_date->toDateString()];
}

/**
 * @return array<string, array{state: ContractSettlementState, valid: int, paid: int}>
 */
function settlementAt(Contract $contract, string ...$days): array
{
    $resolved = app(ContractSettlementResolver::class)->resolveForContractsAtDates(
        [$contract->id],
        array_map(fn (string $day): CarbonImmutable => CarbonImmutable::parse($day), $days),
        distratoMapOf($contract),
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

describe('distrato posterior à data', function () {
    it('keeps the installments cancelled before the distrato open when they alone would settle the contract', function () {
        $construction = DerivationFixture::construction();
        $contract = DerivationFixture::contract(DerivationFixture::unit($construction, '101'), '2026-01-10', '600000.00', cancellationDate: '2026-07-05', status: ContractStatus::Cancelled);
        DerivationFixture::installment($contract, '001', '2026-02-10', '200000.00', '2026-02-10', '200000.00');
        DerivationFixture::installment($contract, '002', '2026-08-10', '200000.00', cancellationDate: '2026-06-25');
        DerivationFixture::installment($contract, '003', '2026-09-10', '200000.00', cancellationDate: '2026-06-25');

        expect(settlementAt($contract, '2026-06-24', '2026-06-30'))->toBe([
            // Antes do cancelamento: as três existiam, uma paga.
            '2026-06-24' => ['state' => ContractSettlementState::Outstanding, 'valid' => 3, 'paid' => 1],
            // Depois do cancelamento e antes do distrato: o cancelamento sozinho
            // quitaria o contrato; as canceladas voltam a contar, em aberto.
            '2026-06-30' => ['state' => ContractSettlementState::Outstanding, 'valid' => 3, 'paid' => 1],
        ]);
    });

    it('leaves a live renegotiated schedule settling without its cancelled installments', function () {
        $construction = DerivationFixture::construction();
        $contract = DerivationFixture::contract(DerivationFixture::unit($construction, '101'), '2026-01-10', '600000.00');
        DerivationFixture::installment($contract, '001', '2026-02-10', '300000.00', '2026-02-10', '300000.00');
        DerivationFixture::installment($contract, '002', '2026-08-10', '300000.00', cancellationDate: '2026-06-20');
        DerivationFixture::installment($contract, 'R01', '2026-06-20', '290000.00', '2026-06-20', '290000.00');

        // Sem distrato, a renegociação continua quitando pelo cronograma novo.
        expect(settlementAt($contract, '2026-06-30')['2026-06-30'])->toBe([
            'state' => ContractSettlementState::Settled,
            'valid' => 2,
            'paid' => 2,
        ]);
    });

    it('does not recount a distratado contract that was already open', function () {
        $construction = DerivationFixture::construction();
        $contract = DerivationFixture::contract(DerivationFixture::unit($construction, '101'), '2025-01-10', '600000.00', cancellationDate: '2026-08-05', status: ContractStatus::Cancelled);
        DerivationFixture::installment($contract, '001', '2025-02-10', '200000.00', '2025-02-10', '200000.00');
        DerivationFixture::installment($contract, '002', '2025-06-10', '200000.00', cancellationDate: '2025-05-20');
        DerivationFixture::installment($contract, '003', '2025-07-10', '200000.00', cancellationDate: '2025-05-20');
        DerivationFixture::installment($contract, 'R01', '2026-06-10', '400000.00', cancellationDate: '2026-08-05');

        // A parcela renegociada em aberto já mantém o contrato em aberto: as
        // canceladas em 2025 não voltam, e a contagem dos meses intermediários
        // não muda.
        expect(settlementAt($contract, '2025-12-31', '2026-07-31'))->toBe([
            '2025-12-31' => ['state' => ContractSettlementState::Outstanding, 'valid' => 2, 'paid' => 1],
            '2026-07-31' => ['state' => ContractSettlementState::Outstanding, 'valid' => 2, 'paid' => 1],
        ]);
    });

    it('turns an all-cancelled schedule of a distratado contract into an open one before the distrato', function () {
        $construction = DerivationFixture::construction();
        $contract = DerivationFixture::contract(DerivationFixture::unit($construction, '101'), '2026-01-10', '600000.00', cancellationDate: '2026-07-05', status: ContractStatus::Cancelled);
        DerivationFixture::installment($contract, '001', '2026-08-10', '300000.00', cancellationDate: '2026-06-25');
        DerivationFixture::installment($contract, '002', '2026-09-10', '300000.00', cancellationDate: '2026-06-25');

        $withoutDistrato = app(ContractSettlementResolver::class)->resolveForContracts([$contract->id], CarbonImmutable::parse('2026-06-30'), []);

        // Sem o mapa de distratos o cronograma vazio é indeterminado; com ele, o
        // contrato que ainda vai ser distratado está em aberto.
        expect($withoutDistrato[$contract->id]->state)->toBe(ContractSettlementState::Undetermined)
            ->and(settlementAt($contract, '2026-06-30')['2026-06-30'])->toBe([
                'state' => ContractSettlementState::Outstanding,
                'valid' => 2,
                'paid' => 0,
            ]);
    });
});

describe('desconto registrado', function () {
    it('counts a registered discount towards the payment, by the exact cent, in both databases', function (string $discount, string $state) {
        $construction = DerivationFixture::construction();
        $contract = DerivationFixture::contract(DerivationFixture::unit($construction, '101'), '2026-01-10');

        match ($discount) {
            'sem pagamento' => rawInstallment($contract, '001', '300000.00', discount: '3000.00'),
            'sem desconto' => rawInstallment($contract, '001', '300000.00', '2026-02-10', '297000.00'),
            default => rawInstallment($contract, '001', '300000.00', '2026-02-10', '297000.00', discount: $discount),
        };

        expect(settlementAt($contract, '2026-07-31')['2026-07-31']['state'])->toBe(ContractSettlementState::from($state));
    })->with([
        'desconto que cobre o previsto' => ['3000.00', 'quitado'],
        'um centavo a menos' => ['2999.99', 'em_aberto'],
        'sem desconto' => ['sem desconto', 'em_aberto'],
        'desconto sem pagamento' => ['sem pagamento', 'em_aberto'],
    ]);

    it('reports the paid-below-expected shortfall and the installments with payment', function () {
        $construction = DerivationFixture::construction();
        $contract = DerivationFixture::contract(DerivationFixture::unit($construction, '101'), '2026-01-10');
        rawInstallment($contract, '001', '300000.00', '2026-02-10', '297000.00');
        rawInstallment($contract, '002', '300000.00', '2026-03-10', '299999.99');
        rawInstallment($contract, '003', '300000.00', '2026-04-10', '298000.00', discount: '2000.00');
        rawInstallment($contract, '004', '300000.00', '2026-08-10', '300000.00');

        $summary = app(ContractSettlementResolver::class)->resolveForContracts([$contract->id], CarbonImmutable::parse('2026-07-31'), [])[$contract->id];

        // 001 e 002 abaixo do previsto (R$ 3.000,01 no total); 003 coberta pelo
        // desconto; 004 paga depois da data.
        expect($summary->validInstallments)->toBe(4)
            ->and($summary->paidInstallments)->toBe(1)
            ->and($summary->installmentsWithPayment)->toBe(3)
            ->and($summary->shortfallCents)->toBe(300_001)
            ->and($summary->underpaidInstallments())->toBe(2)
            ->and($summary->isHeldOnlyByShortfall())->toBeFalse();

        $atTheEnd = app(ContractSettlementResolver::class)->resolveForContracts([$contract->id], CarbonImmutable::parse('2026-08-31'), [])[$contract->id];

        expect($atTheEnd->installmentsWithPayment)->toBe(4)
            ->and($atTheEnd->isHeldOnlyByShortfall())->toBeTrue();
    });
});

it('reports dates before 1990 and payments after today as schedule facts', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-25 12:00:00'));

    $construction = DerivationFixture::construction();
    $contract = DerivationFixture::contract(DerivationFixture::unit($construction, '101'), '2026-01-10');
    rawInstallment($contract, '001', '300000.00', '0026-02-10', '300000.00');
    rawInstallment($contract, '002', '300000.00', cancellationDate: '0202-03-10');
    rawInstallment($contract, '003', '300000.00', '2026-09-26', '300000.00');
    rawInstallment($contract, '004', '300000.00', '2026-10-15', '300000.00');
    rawInstallment($contract, '005', '300000.00', '2026-09-25', '300000.00');

    $clean = DerivationFixture::contract(DerivationFixture::unit($construction, '102'), '2026-01-10');
    rawInstallment($clean, '001', '300000.00', '1990-01-01', '300000.00', cancellationDate: '1990-01-01');

    $facts = app(ContractSettlementResolver::class)
        ->resolveScheduleAtDates([$contract->id, $clean->id], [CarbonImmutable::parse('2026-07-31')], [])
        ->facts;

    expect($facts[$contract->id]->datesBefore1990)->toBe(2)
        ->and($facts[$contract->id]->firstDateBefore1990)->toBe('0026-02-10')
        ->and($facts[$contract->id]->firstDateBefore1990Number)->toBe('001')
        ->and($facts[$contract->id]->firstDateBefore1990Field)->toBe('payment')
        ->and($facts[$contract->id]->paymentsAfterToday)->toBe(2)
        ->and($facts[$contract->id]->firstPaymentAfterToday)->toBe('2026-09-26')
        ->and($facts[$contract->id]->firstPaymentAfterTodayNumber)->toBe('003')
        // 01/01/1990 e um pagamento de hoje não são fatos de alarme.
        ->and($facts)->not->toHaveKey($clean->id);
});

it('reports the largest expected and paid installment and a payment a hundred times its expected value', function () {
    $construction = DerivationFixture::construction();
    $contract = DerivationFixture::contract(DerivationFixture::unit($construction, '101'), '2026-01-10');
    rawInstallment($contract, '001', '600.00', '2026-02-10', '553919.00');
    rawInstallment($contract, '002', '450000.00', '2026-03-10', '450000.00');
    rawInstallment($contract, '003', '480000.00');

    $exactlyHundredfold = DerivationFixture::contract(DerivationFixture::unit($construction, '102'), '2026-01-10');
    rawInstallment($exactlyHundredfold, '001', '599.99', '2026-03-10', '59999.00');

    $oneCentShort = DerivationFixture::contract(DerivationFixture::unit($construction, '103'), '2026-01-10');
    rawInstallment($oneCentShort, '001', '599.99', '2026-03-10', '59998.99');

    $summaries = app(ContractSettlementResolver::class)->resolveForContracts(
        [$contract->id, $exactlyHundredfold->id, $oneCentShort->id],
        CarbonImmutable::parse('2026-07-31'),
        [],
    );
    $summary = $summaries[$contract->id];

    expect($summary->maxExpectedCents)->toBe(48_000_000)
        ->and($summary->maxExpectedNumber)->toBe('003')
        ->and($summary->maxPaidCents)->toBe(55_391_900)
        ->and($summary->maxPaidNumber)->toBe('001')
        // 553.919,00 sobre 600,00 previstos: o pagamento parcial lido como milhar.
        ->and($summary->paidFarAboveExpectedNumber)->toBe('001')
        // A fronteira é inclusiva: exatamente cem vezes já é atípico, um centavo
        // abaixo não.
        ->and($summaries[$exactlyHundredfold->id]->paidFarAboveExpectedNumber)->toBe('001')
        ->and($summaries[$oneCentShort->id]->paidFarAboveExpectedNumber)->toBeNull();
});
