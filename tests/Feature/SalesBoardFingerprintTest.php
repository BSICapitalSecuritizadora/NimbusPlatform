<?php

use App\DTOs\SalesBoards\SalesBoardSnapshot;
use App\Models\Client;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\ConstructionUnitValue;
use App\Models\Contract;
use App\Models\ContractInstallment;
use App\Models\SalesDiscountPolicy;
use App\Services\SalesBoards\SalesBoardFingerprintService;
use App\Support\SalesBoards\CanonicalDigest;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SalesBoards\CycleFixture;
use Tests\Support\SalesBoards\DerivationFixture;

uses(RefreshDatabase::class);

/**
 * Fonte com uma venda da competência, um contrato antigo e uma unidade em
 * estoque -- o suficiente para exercitar unidades, valores, políticas,
 * contratos e parcelas.
 *
 * @return array{0: Construction, 1: list<ConstructionUnit>, 2: Contract}
 */
function fingerprintScenario(): array
{
    [$construction, $units] = CycleFixture::readyConstruction(3);

    $sold = DerivationFixture::contract($units[0], '2026-07-05', '600000.00');
    DerivationFixture::installment($sold, '001', '2026-08-10', '600000.00');

    $older = DerivationFixture::contract($units[1], '2026-02-10', '550000.00');
    DerivationFixture::installment($older, '001', '2026-03-10', '550000.00', '2026-03-09', '550000.00');

    return [$construction, $units, $sold];
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
