<?php

use App\DTOs\SalesBoards\SalesBoardSnapshot;
use App\Enums\ContractStatus;
use App\Enums\SalesBoardMovementType;
use App\Enums\SalesBoardStaleImpact;
use App\Models\Client;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\ConstructionUnitValue;
use App\Models\Contract;
use App\Models\ContractInstallment;
use App\Models\SalesBoardCycle;
use App\Models\SalesDiscountPolicy;
use App\Services\SalesBoards\SalesBoardFingerprintService;
use App\Support\SalesBoards\CanonicalDigest;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Support\SalesBoards\CycleFixture;
use Tests\Support\SalesBoards\DerivationFixture;

uses(RefreshDatabase::class);

/**
 * Fonte com uma venda da competência, um contrato antigo e uma unidade em
 * estoque -- o suficiente para exercitar unidades, valores, políticas,
 * contratos e parcelas.
 *
 * @return array{0: Construction, 1: list<ConstructionUnit>, 2: Contract, 3: Contract}
 */
function fingerprintScenario(): array
{
    [$construction, $units] = CycleFixture::readyConstruction(3);

    $sold = DerivationFixture::contract($units[0], '2026-07-05', '600000.00');
    DerivationFixture::installment($sold, '001', '2026-08-10', '600000.00');

    $older = DerivationFixture::contract($units[1], '2026-02-10', '550000.00');
    DerivationFixture::installment($older, '001', '2026-03-10', '550000.00', '2026-03-09', '550000.00');

    return [$construction, $units, $sold, $older];
}

function sourceFingerprint(Construction $construction): string
{
    return app(SalesBoardFingerprintService::class)
        ->observeForConstruction($construction->fresh(), CarbonImmutable::parse('2026-07-01'))
        ->fingerprint();
}

function snapshotFingerprint(Construction $construction): string
{
    return SalesBoardSnapshot::fromDerivedPosition(DerivationFixture::derive($construction->fresh()))->fingerprint();
}

it('produces the same source fingerprint for the same source', function () {
    [$construction] = fingerprintScenario();

    expect(sourceFingerprint($construction))->toBe(sourceFingerprint($construction))
        ->and(sourceFingerprint($construction))->toHaveLength(64);
});

it('produces the same snapshot fingerprint regardless of the order the lines are held in', function () {
    [$construction] = fingerprintScenario();

    $position = DerivationFixture::derive($construction);
    $snapshot = SalesBoardSnapshot::fromDerivedPosition($position);

    $reversed = new SalesBoardSnapshot(
        constructionId: $snapshot->constructionId,
        referenceMonth: $snapshot->referenceMonth,
        positionDate: $snapshot->positionDate,
        unitsTotal: $snapshot->unitsTotal,
        stockUnits: $snapshot->stockUnits,
        stockValueCents: $snapshot->stockValueCents,
        financedUnits: $snapshot->financedUnits,
        financedValueCents: $snapshot->financedValueCents,
        settledUnits: $snapshot->settledUnits,
        settledValueCents: $snapshot->settledValueCents,
        exchangedUnits: $snapshot->exchangedUnits,
        exchangedValueCents: $snapshot->exchangedValueCents,
        undeterminedUnits: $snapshot->undeterminedUnits,
        isComplete: $snapshot->isComplete,
        lines: array_reverse($snapshot->lines),
        movements: array_reverse($snapshot->movements),
    );

    expect($reversed->fingerprint())->toBe($snapshot->fingerprint());
});

it('changes the source fingerprint when a sale value changes', function () {
    [$construction, , $sold] = fingerprintScenario();
    $before = sourceFingerprint($construction);

    $sold->update(['sale_value' => '610000.00']);

    expect(sourceFingerprint($construction))->not->toBe($before);
});

it('changes the source fingerprint when a relevant policy lands', function () {
    [$construction] = fingerprintScenario();
    $before = sourceFingerprint($construction);

    SalesDiscountPolicy::factory()
        ->forConstruction($construction)
        ->effectiveFrom('2026-07-01')
        ->allowing('4.00')
        ->create();

    expect(sourceFingerprint($construction))->not->toBe($before);
});

it('ignores a policy that only takes effect after every sale of the competência', function () {
    [$construction] = fingerprintScenario();
    $before = sourceFingerprint($construction);
    $snapshotBefore = snapshotFingerprint($construction);

    SalesDiscountPolicy::factory()
        ->forConstruction($construction)
        ->effectiveFrom('2026-07-20')
        ->allowing('1.00')
        ->create();

    expect(sourceFingerprint($construction))->toBe($before)
        ->and(snapshotFingerprint($construction))->toBe($snapshotBefore);
});

it('ignores a unit value that only takes effect after the position date', function () {
    [$construction, $units] = fingerprintScenario();
    $before = sourceFingerprint($construction);

    ConstructionUnitValue::factory()->create([
        'construction_unit_id' => $units[2]->id,
        'value' => '900000.00',
        'effective_from' => '2026-09-01',
    ]);

    expect(sourceFingerprint($construction))->toBe($before);
});

it('changes the source fingerprint when a unit value inside the competência lands', function () {
    [$construction, $units] = fingerprintScenario();
    $before = sourceFingerprint($construction);

    ConstructionUnitValue::factory()->create([
        'construction_unit_id' => $units[2]->id,
        'value' => '900000.00',
        'effective_from' => '2026-07-01',
    ]);

    expect(sourceFingerprint($construction))->not->toBe($before);
});

it('ignores client contact data, which decides nothing in the Quadro', function () {
    [$construction, , $sold] = fingerprintScenario();
    $before = sourceFingerprint($construction);
    $snapshotBefore = snapshotFingerprint($construction);

    $client = Client::factory()->create();
    $sold->clients()->attach($client->id);
    $client->update(['phone' => '(11) 99999-0000', 'email' => 'outro@exemplo.com', 'name' => 'Nome Alterado']);

    expect(sourceFingerprint($construction))->toBe($before)
        ->and(snapshotFingerprint($construction))->toBe($snapshotBefore);
});

it('ignores the due date of an installment, which decides nothing in the derivation', function () {
    [$construction, , $sold] = fingerprintScenario();
    $before = sourceFingerprint($construction);

    ContractInstallment::query()->where('contract_id', $sold->id)->firstOrFail()->update(['due_date' => '2026-12-31']);

    expect(sourceFingerprint($construction))->toBe($before);
});

it('changes the source fingerprint when a payment lands on an installment', function () {
    [$construction, , $sold] = fingerprintScenario();
    $before = sourceFingerprint($construction);

    ContractInstallment::query()
        ->where('contract_id', $sold->id)
        ->firstOrFail()
        ->update(['payment_date' => '2026-07-30', 'paid_value' => '600000.00']);

    expect(sourceFingerprint($construction))->not->toBe($before);
});

it('changes the source fingerprint when a unit is added to the development', function () {
    [$construction] = fingerprintScenario();
    $before = sourceFingerprint($construction);

    DerivationFixture::unit($construction, '999');

    expect(sourceFingerprint($construction))->not->toBe($before);
});

it('attributes a source change to the unit that owns it', function () {
    [$construction, $units, $sold] = fingerprintScenario();

    $observer = app(SalesBoardFingerprintService::class);
    $month = CarbonImmutable::parse('2026-07-01');

    $before = $observer->observeForConstruction($construction, $month);
    $sold->update(['sale_value' => '610000.00']);
    $after = $observer->observeForConstruction($construction->fresh(), $month);

    expect($after->fingerprintForUnit($units[0]->id))->not->toBe($before->fingerprintForUnit($units[0]->id))
        ->and($after->fingerprintForUnit($units[1]->id))->toBe($before->fingerprintForUnit($units[1]->id))
        ->and($after->fingerprintForUnit($units[2]->id))->toBe($before->fingerprintForUnit($units[2]->id));
});

it('refuses to hash a float', function () {
    CanonicalDigest::field(1.5);
})->throws(InvalidArgumentException::class, 'Fingerprints não aceitam float');

it('does not let a separator inside a text field forge another field', function () {
    expect(CanonicalDigest::row(['A|1', 'x']))->not->toBe(CanonicalDigest::row(['A', '1|x']));
});

it('keeps null distinct from an empty string', function () {
    expect(CanonicalDigest::row([null]))->not->toBe(CanonicalDigest::row(['']));
});

it('summarises the whole snapshot, not only its totals', function () {
    [$construction, , $sold] = fingerprintScenario();

    $before = snapshotFingerprint($construction);
    $position = DerivationFixture::derive($construction);

    // O valor muda dentro do mesmo balde: os quatro totais de unidades ficam
    // idênticos, e mesmo assim a posição congelada é outra.
    $sold->update(['sale_value' => '601000.00']);

    $after = DerivationFixture::derive($construction->fresh());

    expect($after->stockUnits)->toBe($position->stockUnits)
        ->and($after->financedUnits)->toBe($position->financedUnits)
        ->and($after->settledUnits)->toBe($position->settledUnits)
        ->and($after->exchangedUnits)->toBe($position->exchangedUnits)
        ->and(snapshotFingerprint($construction))->not->toBe($before);
});

/**
 * A competência 07/2026 é a posição em 31/07/2026. Pagamento, cancelamento de
 * parcela, distrato e status registrados depois disso não mudam nada naquela
 * data -- a derivação os ignora --, e o resumo da fonte também precisa
 * ignorá-los: senão todo pagamento importado depois do fechamento marcaria o
 * mês fechado como "fonte alterada".
 */
it('ignores facts dated after the position date', function (Closure $change) {
    [$construction, , $sold, $older] = fingerprintScenario();
    $before = sourceFingerprint($construction);
    $snapshotBefore = snapshotFingerprint($construction);

    $change($sold, $older);

    expect(snapshotFingerprint($construction))->toBe($snapshotBefore)
        ->and(sourceFingerprint($construction))->toBe($before);
})->with([
    'payment in August' => [fn (Contract $sold) => ContractInstallment::query()->where('contract_id', $sold->id)->sole()
        ->update(['payment_date' => '2026-08-10', 'paid_value' => '600000.00'])],
    'installment cancelled in August' => [fn (Contract $sold) => ContractInstallment::query()->where('contract_id', $sold->id)->sole()
        ->update(['cancellation_date' => '2026-08-20'])],
    'distrato in September' => [fn (Contract $sold, Contract $older) => $older->update(['cancellation_date' => '2026-09-15', 'status' => ContractStatus::Cancelled])],
    'contract marked as settled today' => [fn (Contract $sold, Contract $older) => $older->update(['status' => ContractStatus::Settled])],
    'paid value of a payment still in the future' => [function (Contract $sold): void {
        ContractInstallment::query()->where('contract_id', $sold->id)->sole()->update(['payment_date' => '2026-08-10', 'paid_value' => '600000.00']);
        ContractInstallment::query()->where('contract_id', $sold->id)->sole()->update(['paid_value' => '610000.00']);
    }],
]);

/**
 * O outro lado do recorte: tudo o que a derivação usa em 31/07 continua dentro.
 * Um fingerprint que deixasse de ver uma mudança real seria pior do que o
 * alarme falso que o recorte elimina.
 */
it('still detects every fact that can change the position at the position date', function (Closure $change) {
    [$construction, , $sold, $older] = fingerprintScenario();
    $before = sourceFingerprint($construction);

    $change($sold, $older);

    expect(sourceFingerprint($construction))->not->toBe($before);
})->with([
    'payment inside the competência' => [fn (Contract $sold) => ContractInstallment::query()->where('contract_id', $sold->id)->sole()
        ->update(['payment_date' => '2026-07-31', 'paid_value' => '600000.00'])],
    'paid value of a payment already made' => [fn (Contract $sold, Contract $older) => ContractInstallment::query()->where('contract_id', $older->id)->sole()
        ->update(['paid_value' => '549000.00'])],
    'payment date moved inside the past' => [fn (Contract $sold, Contract $older) => ContractInstallment::query()->where('contract_id', $older->id)->sole()
        ->update(['payment_date' => '2026-03-20'])],
    'installment cancelled on the position date' => [fn (Contract $sold) => ContractInstallment::query()->where('contract_id', $sold->id)->sole()
        ->update(['cancellation_date' => '2026-07-31'])],
    'expected value of an open installment' => [fn (Contract $sold) => ContractInstallment::query()->where('contract_id', $sold->id)->sole()
        ->update(['expected_value' => '590000.00'])],
    'new installment on the schedule' => [fn (Contract $sold) => DerivationFixture::installment($sold, '002', '2026-12-10', '1000.00')],
    'distrato inside the competência' => [fn (Contract $sold, Contract $older) => $older->update(['cancellation_date' => '2026-07-20', 'status' => ContractStatus::Cancelled])],
    'contract holding the unit marked as exchanged' => [fn (Contract $sold, Contract $older) => $older->update(['status' => ContractStatus::Exchanged])],
]);

it('tells a payment moved past the position date apart from the one made before it', function () {
    [$construction, , $sold] = fingerprintScenario();
    $unpaid = sourceFingerprint($construction);
    $installment = ContractInstallment::query()->where('contract_id', $sold->id)->sole();

    $installment->update(['payment_date' => '2026-07-30', 'paid_value' => '600000.00']);
    $paidInJuly = sourceFingerprint($construction);

    $installment->update(['payment_date' => '2026-08-02']);

    // Em 31/07 o pagamento de agosto não existia: o resumo volta a ser o da
    // parcela em aberto, e não o da parcela paga em julho.
    expect($paidInJuly)->not->toBe($unpaid)
        ->and(sourceFingerprint($construction))->not->toBe($paidInJuly)
        ->and(sourceFingerprint($construction))->toBe($unpaid);
});

it('does not mark a closed competência as changed for a payment or distrato after it', function (Closure $change) {
    [$construction, , $sold, $older] = fingerprintScenario();
    DerivationFixture::installment($older, '002', '2026-09-10', '250000.00');

    CycleFixture::generate($construction);
    $cycle = SalesBoardCycle::sole();

    $change($sold, $older);

    $assessment = CycleFixture::check($cycle);

    expect($assessment->impact)->toBe(SalesBoardStaleImpact::None)
        ->and($assessment->sourceChanged)->toBeFalse()
        ->and($assessment->snapshotChanged)->toBeFalse()
        ->and($assessment->baseline->fresh()->is_stale)->toBeFalse()
        ->and($assessment->diff->isEmpty())->toBeTrue();
})->with([
    'payment in August' => [fn (Contract $sold) => ContractInstallment::query()->where('contract_id', $sold->id)->sole()
        ->update(['payment_date' => '2026-08-10', 'paid_value' => '600000.00'])],
    'distrato in September' => [fn (Contract $sold, Contract $older) => $older->update(['cancellation_date' => '2026-09-15', 'status' => ContractStatus::Cancelled])],
]);

/**
 * As parcelas são a fonte que cresce com a obra. Hidratar cada uma como model
 * e guardar uma linha por parcela fazia a observação de uma obra madura custar
 * tanto quanto a derivação; o custo por parcela está medido em
 * `SalesBoardVolumePerformanceTest`.
 */
it('reads installments as plain rows and keeps one summary per contract', function () {
    [$construction, , $sold, $older] = fingerprintScenario();

    foreach (range(2, 40) as $number) {
        DerivationFixture::installment($sold, str_pad((string) $number, 3, '0', STR_PAD_LEFT), '2026-09-10', '1000.00');
    }

    $hydrated = 0;
    Event::listen('eloquent.retrieved: '.ContractInstallment::class, function () use (&$hydrated): void {
        $hydrated++;
    });

    $observation = app(SalesBoardFingerprintService::class)
        ->observeForConstruction($construction->fresh(), CarbonImmutable::parse('2026-07-01'));

    $contractIds = [$sold->id, $older->id];
    sort($contractIds);

    $summaries = array_keys($observation->installmentDigests);
    sort($summaries);

    expect($hydrated)->toBe(0)
        ->and($summaries)->toBe($contractIds)
        ->and($observation->installmentDigests)->each->toHaveLength(64);
});

it('keeps each installment in its own contract summary when schedules interleave', function () {
    [$construction, $units] = CycleFixture::readyConstruction(2);

    $first = DerivationFixture::contract($units[0], '2026-02-10', '500000.00');
    $second = DerivationFixture::contract($units[1], '2026-02-10', '500000.00');

    // Ids intercalados: a leitura em fluxo agrupa por contrato, não pela ordem
    // de gravação.
    $secondInstallments = [];
    foreach (range(1, 4) as $number) {
        $code = str_pad((string) $number, 3, '0', STR_PAD_LEFT);
        DerivationFixture::installment($first, $code, '2026-0'.($number + 2).'-10', '125000.00', '2026-0'.($number + 2).'-10', '125000.00');
        $secondInstallments[] = DerivationFixture::installment($second, $code, '2026-0'.($number + 2).'-10', '125000.00');
    }

    $observer = app(SalesBoardFingerprintService::class);
    $month = CarbonImmutable::parse('2026-07-01');

    $before = $observer->observeForConstruction($construction->fresh(), $month);
    $secondInstallments[2]->update(['expected_value' => '124000.00']);
    $after = $observer->observeForConstruction($construction->fresh(), $month);

    expect($after->installmentDigests[$first->id])->toBe($before->installmentDigests[$first->id])
        ->and($after->installmentDigests[$second->id])->not->toBe($before->installmentDigests[$second->id])
        ->and($after->fingerprintForUnit($units[0]->id))->toBe($before->fingerprintForUnit($units[0]->id))
        ->and($after->fingerprintForUnit($units[1]->id))->not->toBe($before->fingerprintForUnit($units[1]->id))
        ->and($after->fingerprintForMovement(SalesBoardMovementType::Settlement, $first->id))
        ->toBe($before->fingerprintForMovement(SalesBoardMovementType::Settlement, $first->id))
        ->and($after->fingerprintForMovement(SalesBoardMovementType::Settlement, $second->id))
        ->not->toBe($before->fingerprintForMovement(SalesBoardMovementType::Settlement, $second->id));
});

/**
 * A parcela é lida sem model, então sem os casts: o SQLite devolve a data com
 * hora e o decimal como número, o MySQL devolve o dia e o decimal como texto. O
 * resumo tem de ser o mesmo nos dois -- e o mesmo que os casts produziriam.
 */
it('canonicalizes a raw installment row exactly as the typed model would', function () {
    [$construction, $units] = CycleFixture::readyConstruction(1);

    $contract = DerivationFixture::contract($units[0], '2026-02-10', '500000.00');
    $paid = DerivationFixture::installment($contract, '001', '2026-03-10', '4166.67', '2026-03-09', '4166.67');
    $cancelled = DerivationFixture::installment($contract, '002', '2026-04-10', '7500.00', null, null, '2026-06-30');
    $paidLater = DerivationFixture::installment($contract, '003', '2026-05-10', '1234567.89', '2026-08-01', '1234567.89');
    $cancelledLater = DerivationFixture::installment($contract, '004', '2026-06-10', '0.10', '2026-07-31', '0.10', '2026-12-01');

    $expected = (new CanonicalDigest)
        ->append(CanonicalDigest::row([$paid->id, $contract->id, 416667, 416667, '2026-03-09', null]))
        ->append(CanonicalDigest::row([$cancelled->id, $contract->id, 750000, null, null, '2026-06-30']))
        ->append(CanonicalDigest::row([$paidLater->id, $contract->id, 123456789, null, null, null]))
        ->append(CanonicalDigest::row([$cancelledLater->id, $contract->id, 10, 10, '2026-07-31', null]))
        ->digest();

    $observation = app(SalesBoardFingerprintService::class)
        ->observeForConstruction($construction->fresh(), CarbonImmutable::parse('2026-07-01'));

    expect($observation->installmentDigests[$contract->id])->toBe($expected);
})->group('parity');
