<?php

use App\DTOs\Funds\FundAllocationSuggestion;
use App\Exceptions\Funds\FundAllocationException;
use App\Models\FundAllocationPeriod;
use App\Models\FundAllocationRevision;
use App\Services\Funds\FundAllocationSuggestionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\FundAllocationFixture;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

function approveAllocation($maker, $checker, $application, string $competence, string $reference, array $fundAmounts): FundAllocationRevision
{
    $service = FundAllocationFixture::service();

    $snapshot = $service->recordReferenceBalance($maker, $application, $competence, $reference);

    $lines = [];
    foreach ($fundAmounts as $fundId => $amount) {
        $lines[] = ['fund_id' => $fundId, 'amount' => $amount];
    }

    $draft = $service->createDraft($maker, $application, $competence, $snapshot->id, $lines);

    return $service->approve($checker, $service->submitForReview($maker, $draft), "suggest-{$competence}");
}

it('replays prior proportions over the new reference preserving the unallocated share', function () {
    $maker = FundAllocationFixture::maker();
    $checker = FundAllocationFixture::checker();
    $application = FundAllocationFixture::application();
    $fundA = FundAllocationFixture::linkedFund($application);
    $fundB = FundAllocationFixture::linkedFund($application);
    $service = FundAllocationFixture::service();

    // Prior competence: R$ 100.000 reference, 30% / 20% / 50% unallocated.
    approveAllocation($maker, $checker, $application, '2026-08', '100000.00', [
        $fundA->id => '30000.00',
        $fundB->id => '20000.00',
    ]);

    $service->recordReferenceBalance($maker, $application, '2026-09', '120000.00');

    $suggestion = app(FundAllocationSuggestionService::class)->suggest($maker, $application, '2026-09');

    expect($suggestion->isActionable())->toBeTrue()
        ->and($suggestion->reason)->toBeNull()
        ->and($suggestion->sourceRevisionId)->not->toBeNull()
        ->and($suggestion->sourceCompetence)->toBe('2026-08-01');

    $byFund = collect($suggestion->lines)->keyBy('fund_id');

    // 30% / 20% of R$ 120.000 — the 50% unallocated share is preserved as
    // R$ 60.000, never distributed among the funds.
    expect($byFund[$fundA->id]['suggested_cents'])->toBe(3600000)
        ->and($byFund[$fundB->id]['suggested_cents'])->toBe(2400000)
        ->and($suggestion->totalCents)->toBe(6000000)
        ->and($suggestion->unallocatedCents)->toBe(6000000);
});

it('does not renormalize allocated shares to one hundred percent', function () {
    $maker = FundAllocationFixture::maker();
    $checker = FundAllocationFixture::checker();
    $application = FundAllocationFixture::application();
    $fundA = FundAllocationFixture::linkedFund($application);
    $service = FundAllocationFixture::service();

    // A single fund holding 10% of the prior reference must stay at 10% of
    // the new reference — never inflated to the full balance.
    approveAllocation($maker, $checker, $application, '2026-08', '1000.00', [
        $fundA->id => '100.00',
    ]);

    $service->recordReferenceBalance($maker, $application, '2026-09', '2000.00');

    $suggestion = app(FundAllocationSuggestionService::class)->suggest($maker, $application, '2026-09');

    expect($suggestion->totalCents)->toBe(20000)
        ->and($suggestion->unallocatedCents)->toBe(180000);
});

it('distributes leftover cents by largest remainder with a deterministic tiebreak', function () {
    $maker = FundAllocationFixture::maker();
    $checker = FundAllocationFixture::checker();
    $application = FundAllocationFixture::application();
    $fundA = FundAllocationFixture::linkedFund($application);
    $fundB = FundAllocationFixture::linkedFund($application);
    $fundC = FundAllocationFixture::linkedFund($application);
    $service = FundAllocationFixture::service();

    // Each fund held exactly 1/3 of R$ 0.03; over R$ 0.05 each exact share is
    // 1.666... cents: floors 1/1/1 plus 2 leftover cents by remainder (a
    // three-way tie broken by ascending fund id).
    approveAllocation($maker, $checker, $application, '2026-08', '0.03', [
        $fundA->id => '0.01',
        $fundB->id => '0.01',
        $fundC->id => '0.01',
    ]);

    $service->recordReferenceBalance($maker, $application, '2026-09', '0.05');

    $suggestion = app(FundAllocationSuggestionService::class)->suggest($maker, $application, '2026-09');

    $byFund = collect($suggestion->lines)->keyBy('fund_id');
    $ordered = [$fundA->id, $fundB->id, $fundC->id];
    sort($ordered);

    expect($byFund[$ordered[0]]['suggested_cents'])->toBe(2)
        ->and($byFund[$ordered[1]]['suggested_cents'])->toBe(2)
        ->and($byFund[$ordered[2]]['suggested_cents'])->toBe(1)
        ->and($suggestion->totalCents)->toBe(5)
        ->and($suggestion->unallocatedCents)->toBe(0);
});

it('never exceeds the current reference balance', function () {
    $maker = FundAllocationFixture::maker();
    $checker = FundAllocationFixture::checker();
    $application = FundAllocationFixture::application();
    $funds = [
        FundAllocationFixture::linkedFund($application),
        FundAllocationFixture::linkedFund($application),
        FundAllocationFixture::linkedFund($application),
    ];
    $service = FundAllocationFixture::service();

    approveAllocation($maker, $checker, $application, '2026-08', '100.00', [
        $funds[0]->id => '33.33',
        $funds[1]->id => '33.33',
        $funds[2]->id => '33.34',
    ]);

    $service->recordReferenceBalance($maker, $application, '2026-09', '10.00');

    $suggestion = app(FundAllocationSuggestionService::class)->suggest($maker, $application, '2026-09');

    expect($suggestion->totalCents)->toBeLessThanOrEqual(1000)
        ->and($suggestion->unallocatedCents)->toBeGreaterThanOrEqual(0)
        ->and($suggestion->totalCents + $suggestion->unallocatedCents)->toBe(1000);
});

it('keeps the missing historical share unallocated instead of reassigning it', function () {
    $maker = FundAllocationFixture::maker();
    $checker = FundAllocationFixture::checker();
    $application = FundAllocationFixture::application();
    $otherApplication = FundAllocationFixture::application();
    $fundA = FundAllocationFixture::linkedFund($application);
    $fundB = FundAllocationFixture::linkedFund($application);
    $service = FundAllocationFixture::service();

    approveAllocation($maker, $checker, $application, '2026-08', '100000.00', [
        $fundA->id => '30000.00',
        $fundB->id => '20000.00',
    ]);

    // fundB leaves the application after the approval: reassociation is
    // allowed (no open revision), and its prior share must stay unallocated.
    $fundB->forceFill([
        'bank_account_id' => $otherApplication->bank_account_id,
        'bank_application_id' => $otherApplication->id,
    ])->save();

    $service->recordReferenceBalance($maker, $application, '2026-09', '120000.00');

    $suggestion = app(FundAllocationSuggestionService::class)->suggest($maker, $application, '2026-09');

    $byFund = collect($suggestion->lines)->keyBy('fund_id');

    expect($byFund->has($fundB->id))->toBeFalse()
        ->and($byFund[$fundA->id]['suggested_cents'])->toBe(3600000)
        ->and($suggestion->missingFunds)->toHaveCount(1)
        ->and($suggestion->missingFunds[0]['fund_id'])->toBe($fundB->id)
        ->and($suggestion->missingFunds[0]['prior_cents'])->toBe(2000000)
        // R$ 60.000 preserved unallocated + R$ 24.000 missing share.
        ->and($suggestion->unallocatedCents)->toBe(8400000);
});

it('flags newly linked funds with a zero suggestion', function () {
    $maker = FundAllocationFixture::maker();
    $checker = FundAllocationFixture::checker();
    $application = FundAllocationFixture::application();
    $fundA = FundAllocationFixture::linkedFund($application);
    $service = FundAllocationFixture::service();

    approveAllocation($maker, $checker, $application, '2026-08', '100000.00', [
        $fundA->id => '30000.00',
    ]);

    $newFund = FundAllocationFixture::linkedFund($application);

    $service->recordReferenceBalance($maker, $application, '2026-09', '120000.00');

    $suggestion = app(FundAllocationSuggestionService::class)->suggest($maker, $application, '2026-09');

    $byFund = collect($suggestion->lines)->keyBy('fund_id');

    expect($byFund[$fundA->id]['suggested_cents'])->toBe(3600000)
        ->and($byFund[$newFund->id]['suggested_cents'])->toBe(0)
        ->and($byFund[$newFund->id]['is_new'])->toBeTrue()
        ->and($suggestion->newFundIds)->toBe([$newFund->id]);
});

it('handles several funds within the same emission at fund level', function () {
    $maker = FundAllocationFixture::maker();
    $checker = FundAllocationFixture::checker();
    $application = FundAllocationFixture::application();
    [$emission] = FundAllocationFixture::classification();
    $fundA = FundAllocationFixture::linkedFund($application, $emission);
    $fundB = FundAllocationFixture::linkedFund($application, $emission);
    $service = FundAllocationFixture::service();

    approveAllocation($maker, $checker, $application, '2026-08', '1000.00', [
        $fundA->id => '100.00',
        $fundB->id => '300.00',
    ]);

    $service->recordReferenceBalance($maker, $application, '2026-09', '2000.00');

    $suggestion = app(FundAllocationSuggestionService::class)->suggest($maker, $application, '2026-09');

    $byFund = collect($suggestion->lines)->keyBy('fund_id');

    expect($byFund[$fundA->id]['suggested_cents'])->toBe(20000)
        ->and($byFund[$fundB->id]['suggested_cents'])->toBe(60000)
        ->and($byFund[$fundA->id]['emission_id'])->toBe($byFund[$fundB->id]['emission_id']);
});

it('reports no history instead of inventing allocations', function () {
    $maker = FundAllocationFixture::maker();
    $application = FundAllocationFixture::application();
    FundAllocationFixture::linkedFund($application);
    $service = FundAllocationFixture::service();

    $service->recordReferenceBalance($maker, $application, '2026-09', '5000.00');

    $suggestion = app(FundAllocationSuggestionService::class)->suggest($maker, $application, '2026-09');

    expect($suggestion->isActionable())->toBeFalse()
        ->and($suggestion->reason)->toBe(FundAllocationSuggestion::REASON_NO_HISTORY)
        ->and($suggestion->sourceRevisionId)->toBeNull()
        ->and($suggestion->totalCents)->toBe(0)
        ->and($suggestion->unallocatedCents)->toBe(500000)
        ->and($suggestion->reasonMessage())->toContain('anterior aprovada');

    // Nothing was persisted: no period, no revision, no snapshot beyond the
    // reference the test itself recorded.
    expect(FundAllocationPeriod::query()->count())->toBe(0)
        ->and(FundAllocationRevision::query()->count())->toBe(0);
});

it('refuses percentage suggestions from a zero prior reference', function () {
    $maker = FundAllocationFixture::maker();
    $checker = FundAllocationFixture::checker();
    $application = FundAllocationFixture::application();
    $fund = FundAllocationFixture::linkedFund($application);
    $service = FundAllocationFixture::service();

    approveAllocation($maker, $checker, $application, '2026-08', '0.00', [
        $fund->id => '0.00',
    ]);

    $service->recordReferenceBalance($maker, $application, '2026-09', '1000.00');

    $suggestion = app(FundAllocationSuggestionService::class)->suggest($maker, $application, '2026-09');

    expect($suggestion->isActionable())->toBeFalse()
        ->and($suggestion->reason)->toBe(FundAllocationSuggestion::REASON_ZERO_REFERENCE)
        ->and($suggestion->sourceRevisionId)->not->toBeNull()
        ->and($suggestion->totalCents)->toBe(0);
});

it('suggests zeros against a zero current reference', function () {
    $maker = FundAllocationFixture::maker();
    $checker = FundAllocationFixture::checker();
    $application = FundAllocationFixture::application();
    $fund = FundAllocationFixture::linkedFund($application);
    $service = FundAllocationFixture::service();

    approveAllocation($maker, $checker, $application, '2026-08', '1000.00', [
        $fund->id => '400.00',
    ]);

    $service->recordReferenceBalance($maker, $application, '2026-09', '0.00');

    $suggestion = app(FundAllocationSuggestionService::class)->suggest($maker, $application, '2026-09');

    expect($suggestion->isActionable())->toBeTrue()
        ->and($suggestion->totalCents)->toBe(0)
        ->and($suggestion->unallocatedCents)->toBe(0);
});

it('derives from the latest approved earlier competence, skipping gaps', function () {
    $maker = FundAllocationFixture::maker();
    $checker = FundAllocationFixture::checker();
    $application = FundAllocationFixture::application();
    $fund = FundAllocationFixture::linkedFund($application);
    $service = FundAllocationFixture::service();

    approveAllocation($maker, $checker, $application, '2026-06', '1000.00', [
        $fund->id => '100.00',
    ]);
    // 2026-07 approved later with a different share: it wins as the latest.
    approveAllocation($maker, $checker, $application, '2026-07', '1000.00', [
        $fund->id => '500.00',
    ]);

    $service->recordReferenceBalance($maker, $application, '2026-09', '1000.00');

    $suggestion = app(FundAllocationSuggestionService::class)->suggest($maker, $application, '2026-09');

    expect($suggestion->sourceCompetence)->toBe('2026-07-01')
        ->and(collect($suggestion->lines)->first()['suggested_cents'])->toBe(50000);
});

it('ignores drafts and later competences as suggestion sources', function () {
    $maker = FundAllocationFixture::maker();
    $checker = FundAllocationFixture::checker();
    $application = FundAllocationFixture::application();
    $fund = FundAllocationFixture::linkedFund($application);
    $service = FundAllocationFixture::service();

    approveAllocation($maker, $checker, $application, '2026-06', '1000.00', [
        $fund->id => '100.00',
    ]);

    // A later approved competence and a nearer draft must not feed an
    // earlier target: only approved competences strictly before it count.
    approveAllocation($maker, $checker, $application, '2026-10', '1000.00', [
        $fund->id => '900.00',
    ]);

    $snapshot = $service->recordReferenceBalance($maker, $application, '2026-09', '1000.00');
    $service->createDraft($maker, $application, '2026-09', $snapshot->id, [
        ['fund_id' => $fund->id, 'amount' => '800.00'],
    ]);

    $suggestion = app(FundAllocationSuggestionService::class)->suggest($maker, $application, '2026-09');

    expect($suggestion->sourceCompetence)->toBe('2026-06-01')
        ->and(collect($suggestion->lines)->first()['suggested_cents'])->toBe(10000);
});

it('requires the view permission to compute suggestions', function () {
    $outsider = FundAllocationFixture::userWithPermissions([]);
    $application = FundAllocationFixture::application();

    expect(fn () => app(FundAllocationSuggestionService::class)->suggest($outsider, $application, '2026-09'))
        ->toThrow(FundAllocationException::class, 'permissão');
});

it('materializes only through the governed draft workflow', function () {
    $maker = FundAllocationFixture::maker();
    $checker = FundAllocationFixture::checker();
    $application = FundAllocationFixture::application();
    $fund = FundAllocationFixture::linkedFund($application);
    $service = FundAllocationFixture::service();

    approveAllocation($maker, $checker, $application, '2026-08', '1000.00', [
        $fund->id => '400.00',
    ]);

    $snapshot = $service->recordReferenceBalance($maker, $application, '2026-09', '2000.00');

    $suggestion = app(FundAllocationSuggestionService::class)->suggest($maker, $application, '2026-09');

    // The suggestion itself persisted nothing.
    expect(FundAllocationRevision::query()->count())->toBe(1);

    $draft = $service->createDraft($maker, $application, '2026-09', $snapshot->id, $suggestion->toDraftLines());

    expect($draft->status)->toBe(FundAllocationRevision::STATUS_DRAFT)
        ->and((string) $draft->total_amount)->toBe('800.00')
        ->and((string) $draft->unallocated_amount)->toBe('1200.00')
        ->and($service->effectiveRevision($maker, $application, '2026-09'))->toBeNull();
});
