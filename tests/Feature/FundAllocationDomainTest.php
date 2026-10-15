<?php

use App\Exceptions\Funds\FundAllocationException;
use App\Models\BankApplicationBalanceSnapshot;
use App\Models\Fund;
use App\Models\FundAllocationLine;
use App\Models\FundAllocationPeriod;
use App\Models\FundAllocationRevision;
use App\Support\Funds\FundAllocationMoney;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\FundAllocationFixture;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

// ── Scenario 4: manual reference balance creation ────────────────────────────

it('records a manual reference balance with version 1 and an explicit manual source', function () {
    $recorder = FundAllocationFixture::userWithRole('editor');
    $application = FundAllocationFixture::application();

    $snapshot = FundAllocationFixture::service()->recordReferenceBalance(
        $recorder, $application, '2026-09', '100000.00', 'Saldo informado pelo backoffice.'
    );

    expect($snapshot->version)->toBe(1)
        ->and($snapshot->source)->toBe(BankApplicationBalanceSnapshot::SOURCE_MANUAL)
        ->and($snapshot->isManual())->toBeTrue()
        ->and($snapshot->amount)->toBe('100000.00')
        ->and($snapshot->competence->toDateString())->toBe('2026-09-01')
        ->and($snapshot->recorded_by_user_id)->toBe($recorder->id)
        ->and($snapshot->supersedes_snapshot_id)->toBeNull()
        ->and($snapshot->sourceLabel())->toBe('Manual (informado pelo usuário)')
        ->and($snapshot->sourceLabel())->not->toContain('Bradesco (confirmado');
});

it('versions reference balances without touching the previous row', function () {
    $recorder = FundAllocationFixture::userWithRole('editor');
    $application = FundAllocationFixture::application();
    $service = FundAllocationFixture::service();

    $first = $service->recordReferenceBalance($recorder, $application, '2026-09', '100000.00');
    $second = $service->recordReferenceBalance($recorder, $application, '2026-09', '100500.00', 'Retificação.');

    expect($second->version)->toBe(2)
        ->and($second->supersedes_snapshot_id)->toBe($first->id)
        ->and($first->refresh()->amount)->toBe('100000.00')
        ->and($first->version)->toBe(1);

    expect(fn () => $first->update(['amount' => '1.00']))->toThrow(LogicException::class);
    expect(fn () => $first->delete())->toThrow(LogicException::class);
});

it('rejects negative and malformed reference balances', function () {
    $recorder = FundAllocationFixture::userWithRole('editor');
    $application = FundAllocationFixture::application();
    $service = FundAllocationFixture::service();

    expect(fn () => $service->recordReferenceBalance($recorder, $application, '2026-09', '-0.01'))
        ->toThrow(FundAllocationException::class, 'não pode ser negativo');

    expect(fn () => $service->recordReferenceBalance($recorder, $application, '2026-09', 'abc'))
        ->toThrow(FundAllocationException::class, 'Valor inválido');

    expect(fn () => $service->recordReferenceBalance($recorder, $application, 'setembro', '10.00'))
        ->toThrow(FundAllocationException::class, 'Competência inválida');
});

it('requires the reference permission to record balances', function () {
    $stranger = FundAllocationFixture::userWithPermissions(['fund-allocations.view']);
    $application = FundAllocationFixture::application();

    expect(fn () => FundAllocationFixture::service()->recordReferenceBalance($stranger, $application, '2026-09', '10.00'))
        ->toThrow(FundAllocationException::class, 'permissão');
});

// ── Scenario 5: exact monetary precision ─────────────────────────────────────

it('computes exact cents without binary floating point', function () {
    // The classic float trap: 0.1 + 0.2 !== 0.3 in binary floating point.
    expect(FundAllocationMoney::toCents('0.1'))->toBe(10)
        ->and(FundAllocationMoney::toCents('0.2'))->toBe(20)
        ->and(FundAllocationMoney::sum([10, 20]))->toBe(30)
        ->and(FundAllocationMoney::fromCents(30))->toBe('0.30')
        ->and(FundAllocationMoney::toCents(0.1) + FundAllocationMoney::toCents(0.2))->toBe(30);
});

it('rounds half-up to cents explicitly', function () {
    expect(FundAllocationMoney::toCents('10.005'))->toBe(1001)
        ->and(FundAllocationMoney::toCents('10.004'))->toBe(1000)
        ->and(FundAllocationMoney::toCents('10.015'))->toBe(1002)
        ->and(FundAllocationMoney::fromCents(1001))->toBe('10.01');
});

it('rejects malformed and out-of-range money', function () {
    expect(fn () => FundAllocationMoney::toCents('1,00'))->toThrow(InvalidArgumentException::class);
    expect(fn () => FundAllocationMoney::toCents(''))->toThrow(InvalidArgumentException::class);
    expect(fn () => FundAllocationMoney::toCents('10.0000001'))->toThrow(InvalidArgumentException::class);
    expect(fn () => FundAllocationMoney::toCents('99999999999999.99'))->toThrow(InvalidArgumentException::class);
    expect(fn () => FundAllocationMoney::toCents(NAN))->toThrow(InvalidArgumentException::class);
});

it('splits thirds without losing a cent', function () {
    $maker = FundAllocationFixture::maker();
    $checker = FundAllocationFixture::checker();
    $application = FundAllocationFixture::application();
    $service = FundAllocationFixture::service();

    $snapshot = $service->recordReferenceBalance($maker, $application, '2026-09', '100.00');
    [$emissionA] = FundAllocationFixture::classification();
    [$emissionB] = FundAllocationFixture::classification();
    [$emissionC] = FundAllocationFixture::classification();
    $fundA = FundAllocationFixture::linkedFund($application, $emissionA);
    $fundB = FundAllocationFixture::linkedFund($application, $emissionB);
    $fundC = FundAllocationFixture::linkedFund($application, $emissionC);

    $draft = $service->createDraft($maker, $application, '2026-09', $snapshot->id, [
        ['fund_id' => $fundA->id, 'amount' => '33.33'],
        ['fund_id' => $fundB->id, 'amount' => '33.33'],
        ['fund_id' => $fundC->id, 'amount' => '33.34'],
    ]);
    $submitted = $service->submitForReview($maker, $draft);
    $approved = $service->approve($checker, $submitted, 'thirds-1');

    expect($approved->total_amount)->toBe('100.00')
        ->and($approved->unallocated_amount)->toBe('0.00');
});

// ── Scenario 1: application with one fund ────────────────────────────────────

it('approves a single-fund allocation end to end', function () {
    $maker = FundAllocationFixture::maker();
    $checker = FundAllocationFixture::checker();
    $application = FundAllocationFixture::application();
    [$emission] = FundAllocationFixture::classification();
    $fund = FundAllocationFixture::linkedFund($application, $emission);
    $service = FundAllocationFixture::service();

    $snapshot = $service->recordReferenceBalance($maker, $application, '2026-09', '60000.00');
    $draft = $service->createDraft($maker, $application, '2026-09', $snapshot->id, [
        ['fund_id' => $fund->id, 'amount' => '60000.00'],
    ]);

    expect($draft->status)->toBe(FundAllocationRevision::STATUS_DRAFT)
        ->and($draft->revision_number)->toBe(1)
        ->and($draft->lines)->toHaveCount(1)
        ->and($draft->lines->first()->emission_id)->toBe($emission->id);

    $submitted = $service->submitForReview($maker, $draft);

    expect($submitted->status)->toBe(FundAllocationRevision::STATUS_PENDING_REVIEW);

    $approved = $service->approve($checker, $submitted, 'single-fund-1');

    expect($approved->status)->toBe(FundAllocationRevision::STATUS_APPROVED)
        ->and($approved->total_amount)->toBe('60000.00')
        ->and($approved->unallocated_amount)->toBe('0.00')
        ->and($approved->reference_amount)->toBe('60000.00')
        ->and($approved->approved_by_user_id)->toBe($checker->id);

    $period = FundAllocationPeriod::query()->first();

    expect((int) $period->effective_revision_id)->toBe($approved->id)
        ->and($period->open_revision_id)->toBeNull();

    $effective = $service->effectiveRevision($checker, $application, '2026-09');

    expect($effective->getKey())->toBe($approved->id);
});

// ── Scenario 2: shared application across emissions ──────────────────────────

it('distributes one application balance across multiple emissions', function () {
    $maker = FundAllocationFixture::maker();
    $checker = FundAllocationFixture::checker();
    $application = FundAllocationFixture::application();
    [$emissionA] = FundAllocationFixture::classification('Operação Alfa');
    [$emissionB] = FundAllocationFixture::classification('Operação Beta');
    $fundA = FundAllocationFixture::linkedFund($application, $emissionA);
    $fundB = FundAllocationFixture::linkedFund($application, $emissionB);
    $service = FundAllocationFixture::service();

    expect($application->isShared())->toBeTrue();

    $snapshot = $service->recordReferenceBalance($maker, $application, '2026-09', '100000.00');
    $draft = $service->createDraft($maker, $application, '2026-09', $snapshot->id, [
        ['fund_id' => $fundA->id, 'amount' => '60000.00'],
        ['fund_id' => $fundB->id, 'amount' => '30000.00'],
    ]);
    $approved = $service->approve($checker, $service->submitForReview($maker, $draft), 'shared-1');

    expect($approved->total_amount)->toBe('90000.00')
        ->and($approved->unallocated_amount)->toBe('10000.00');

    $byEmission = $approved->lines->mapWithKeys(fn (FundAllocationLine $line) => [$line->emission_id => (string) $line->amount]);

    expect($byEmission->get($emissionA->id))->toBe('60000.00')
        ->and($byEmission->get($emissionB->id))->toBe('30000.00');
});

// ── Scenario 3: multiple funds, one emission ────────────────────────────────

it('allocates multiple funds of the same emission', function () {
    $maker = FundAllocationFixture::maker();
    $checker = FundAllocationFixture::checker();
    $application = FundAllocationFixture::application();
    [$emission] = FundAllocationFixture::classification();
    $fundA = FundAllocationFixture::linkedFund($application, $emission);
    $fundB = FundAllocationFixture::linkedFund($application, $emission);
    $service = FundAllocationFixture::service();

    $snapshot = $service->recordReferenceBalance($maker, $application, '2026-09', '50000.00');
    $draft = $service->createDraft($maker, $application, '2026-09', $snapshot->id, [
        ['fund_id' => $fundA->id, 'amount' => '20000.00'],
        ['fund_id' => $fundB->id, 'amount' => '25000.00'],
    ]);
    $approved = $service->approve($checker, $service->submitForReview($maker, $draft), 'multi-fund-1');

    expect($approved->lines)->toHaveCount(2)
        ->and($approved->total_amount)->toBe('45000.00')
        ->and($approved->unallocated_amount)->toBe('5000.00');
});

// ── Scenario 6: valid distribution ───────────────────────────────────────────

it('accepts a distribution that matches the reference exactly', function () {
    $maker = FundAllocationFixture::maker();
    $application = FundAllocationFixture::application();
    $fund = FundAllocationFixture::linkedFund($application);
    $service = FundAllocationFixture::service();

    $snapshot = $service->recordReferenceBalance($maker, $application, '2026-09', '1234.56');
    $draft = $service->createDraft($maker, $application, '2026-09', $snapshot->id, [
        ['fund_id' => $fund->id, 'amount' => '1234.56'],
    ]);

    expect($draft->total_amount)->toBe('1234.56')
        ->and($draft->unallocated_amount)->toBe('0.00');
});

it('accepts an explicit zero-value line', function () {
    $maker = FundAllocationFixture::maker();
    $checker = FundAllocationFixture::checker();
    $application = FundAllocationFixture::application();
    $fundA = FundAllocationFixture::linkedFund($application);
    $fundB = FundAllocationFixture::linkedFund($application);
    $service = FundAllocationFixture::service();

    $snapshot = $service->recordReferenceBalance($maker, $application, '2026-09', '100.00');
    $draft = $service->createDraft($maker, $application, '2026-09', $snapshot->id, [
        ['fund_id' => $fundA->id, 'amount' => '100.00'],
        ['fund_id' => $fundB->id, 'amount' => '0.00'],
    ]);
    $approved = $service->approve($checker, $service->submitForReview($maker, $draft), 'zero-line-1');

    expect($approved->total_amount)->toBe('100.00')
        ->and($approved->lines->firstWhere('fund_id', $fundB->id)->amount)->toBe('0.00');
});

// ── Scenario 7: partially unallocated ────────────────────────────────────────

it('records the unallocated remainder explicitly', function () {
    $maker = FundAllocationFixture::maker();
    $checker = FundAllocationFixture::checker();
    $application = FundAllocationFixture::application();
    $fund = FundAllocationFixture::linkedFund($application);
    $service = FundAllocationFixture::service();

    $snapshot = $service->recordReferenceBalance($maker, $application, '2026-09', '100000.00');
    $draft = $service->createDraft($maker, $application, '2026-09', $snapshot->id, [
        ['fund_id' => $fund->id, 'amount' => '1.00'],
    ]);
    $approved = $service->approve($checker, $service->submitForReview($maker, $draft), 'partial-1');

    // Stored on the revision and reproducible from reference minus lines.
    expect($approved->unallocated_amount)->toBe('99999.00');

    $recomputed = FundAllocationMoney::toCents((string) $approved->reference_amount)
        - FundAllocationMoney::sum($approved->lines->map(fn (FundAllocationLine $line) => FundAllocationMoney::toCents((string) $line->amount))->all());

    expect(FundAllocationMoney::fromCents($recomputed))->toBe((string) $approved->unallocated_amount);
});

// ── Scenario 8: exceeding the reference ──────────────────────────────────────

it('rejects allocations above the reference balance', function () {
    $maker = FundAllocationFixture::maker();
    $application = FundAllocationFixture::application();
    $fund = FundAllocationFixture::linkedFund($application);
    $service = FundAllocationFixture::service();

    $snapshot = $service->recordReferenceBalance($maker, $application, '2026-09', '100.00');

    expect(fn () => $service->createDraft($maker, $application, '2026-09', $snapshot->id, [
        ['fund_id' => $fund->id, 'amount' => '100.01'],
    ]))->toThrow(FundAllocationException::class, 'excede o saldo de referência');

    // One cent over across two lines is still over.
    $other = FundAllocationFixture::linkedFund($application);

    expect(fn () => $service->createDraft($maker, $application, '2026-09', $snapshot->id, [
        ['fund_id' => $fund->id, 'amount' => '60.00'],
        ['fund_id' => $other->id, 'amount' => '40.01'],
    ]))->toThrow(FundAllocationException::class, 'excede o saldo de referência');

    expect(FundAllocationRevision::query()->count())->toBe(0);
});

// ── Scenario 9: negatives ────────────────────────────────────────────────────

it('rejects negative allocation amounts', function () {
    $maker = FundAllocationFixture::maker();
    $application = FundAllocationFixture::application();
    $fund = FundAllocationFixture::linkedFund($application);
    $service = FundAllocationFixture::service();

    $snapshot = $service->recordReferenceBalance($maker, $application, '2026-09', '100.00');

    expect(fn () => $service->createDraft($maker, $application, '2026-09', $snapshot->id, [
        ['fund_id' => $fund->id, 'amount' => '-0.01'],
    ]))->toThrow(FundAllocationException::class, 'não pode ser negativo');
});

// ── Scenario 10: duplicate fund lines ────────────────────────────────────────

it('rejects duplicate fund lines within one revision', function () {
    $maker = FundAllocationFixture::maker();
    $application = FundAllocationFixture::application();
    $fund = FundAllocationFixture::linkedFund($application);
    $service = FundAllocationFixture::service();

    $snapshot = $service->recordReferenceBalance($maker, $application, '2026-09', '100.00');

    expect(fn () => $service->createDraft($maker, $application, '2026-09', $snapshot->id, [
        ['fund_id' => $fund->id, 'amount' => '10.00'],
        ['fund_id' => $fund->id, 'amount' => '20.00'],
    ]))->toThrow(FundAllocationException::class, 'mais de uma vez');
});

// ── Scenario 11: fund from another application ───────────────────────────────

it('rejects funds that do not belong to the period application', function () {
    $maker = FundAllocationFixture::maker();
    $application = FundAllocationFixture::application();
    $otherApplication = FundAllocationFixture::application();
    $foreignFund = FundAllocationFixture::linkedFund($otherApplication);
    $unlinkedFund = Fund::factory()->create();
    $service = FundAllocationFixture::service();

    expect($unlinkedFund->bank_application_id)->toBeNull();

    $snapshot = $service->recordReferenceBalance($maker, $application, '2026-09', '100.00');

    expect(fn () => $service->createDraft($maker, $application, '2026-09', $snapshot->id, [
        ['fund_id' => $foreignFund->id, 'amount' => '10.00'],
    ]))->toThrow(FundAllocationException::class, 'não pertence a esta aplicação');

    expect(fn () => $service->createDraft($maker, $application, '2026-09', $snapshot->id, [
        ['fund_id' => $unlinkedFund->id, 'amount' => '10.00'],
    ]))->toThrow(FundAllocationException::class, 'não pertence a esta aplicação');

    expect(fn () => $service->createDraft($maker, $application, '2026-09', $snapshot->id, [
        ['fund_id' => 999999, 'amount' => '10.00'],
    ]))->toThrow(FundAllocationException::class, 'não existe');
});

it('rejects a snapshot from another application or competence', function () {
    $maker = FundAllocationFixture::maker();
    $application = FundAllocationFixture::application();
    $otherApplication = FundAllocationFixture::application();
    $fund = FundAllocationFixture::linkedFund($application);
    $service = FundAllocationFixture::service();

    $foreign = $service->recordReferenceBalance($maker, $otherApplication, '2026-09', '100.00');
    $otherCompetence = $service->recordReferenceBalance($maker, $application, '2026-08', '100.00');
    $service->recordReferenceBalance($maker, $application, '2026-09', '100.00');

    expect(fn () => $service->createDraft($maker, $application, '2026-09', $foreign->id, [
        ['fund_id' => $fund->id, 'amount' => '10.00'],
    ]))->toThrow(FundAllocationException::class, 'não pertence a esta aplicação e competência');

    expect(fn () => $service->createDraft($maker, $application, '2026-09', $otherCompetence->id, [
        ['fund_id' => $fund->id, 'amount' => '10.00'],
    ]))->toThrow(FundAllocationException::class, 'não pertence a esta aplicação e competência');
});

// ── Scenario 24: zero reference ──────────────────────────────────────────────

it('handles a zero reference balance with all-zero lines', function () {
    $maker = FundAllocationFixture::maker();
    $checker = FundAllocationFixture::checker();
    $application = FundAllocationFixture::application();
    $fund = FundAllocationFixture::linkedFund($application);
    $service = FundAllocationFixture::service();

    // Rule: a zero reference is valid; every line must then be zero and the
    // unallocated remainder is zero.
    $snapshot = $service->recordReferenceBalance($maker, $application, '2026-09', '0.00');

    expect(fn () => $service->createDraft($maker, $application, '2026-09', $snapshot->id, [
        ['fund_id' => $fund->id, 'amount' => '0.01'],
    ]))->toThrow(FundAllocationException::class, 'excede o saldo de referência');

    $draft = $service->createDraft($maker, $application, '2026-09', $snapshot->id, [
        ['fund_id' => $fund->id, 'amount' => '0.00'],
    ]);
    $approved = $service->approve($checker, $service->submitForReview($maker, $draft), 'zero-ref-1');

    expect($approved->total_amount)->toBe('0.00')
        ->and($approved->unallocated_amount)->toBe('0.00');
});

it('normalizes competences to the first day of the month', function () {
    $maker = FundAllocationFixture::maker();
    $application = FundAllocationFixture::application();
    $service = FundAllocationFixture::service();

    $snapshot = $service->recordReferenceBalance($maker, $application, '2026-09-17', '10.00');

    expect($snapshot->competence->toDateString())->toBe('2026-09-01');
    expect($service->currentSnapshot($maker, $application, '2026-09-30')?->getKey())->toBe($snapshot->id);
});
