<?php

use App\Exceptions\Funds\FundAllocationException;
use App\Models\Fund;
use App\Models\FundAllocationPeriod;
use App\Models\FundAllocationRevision;
use App\Services\Funds\FundAllocationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\FundAllocationFixture;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

// ── Scenario 12: draft creation ──────────────────────────────────────────────

it('creates a draft that is not financially effective', function () {
    $maker = FundAllocationFixture::maker();
    $application = FundAllocationFixture::application();
    [$emission] = FundAllocationFixture::classification();
    $fund = FundAllocationFixture::linkedFund($application, $emission);
    $service = FundAllocationFixture::service();

    $snapshot = $service->recordReferenceBalance($maker, $application, '2026-09', '1000.00');
    $draft = $service->createDraft($maker, $application, '2026-09', $snapshot->id, [
        ['fund_id' => $fund->id, 'amount' => '1000.00'],
    ]);

    expect($draft->status)->toBe(FundAllocationRevision::STATUS_DRAFT)
        ->and($draft->proposed_by_user_id)->toBe($maker->id)
        ->and($draft->approved_by_user_id)->toBeNull()
        ->and($draft->bank_application_balance_snapshot_id)->toBe($snapshot->id);

    $period = FundAllocationPeriod::query()->first();

    expect((int) $period->open_revision_id)->toBe($draft->id)
        ->and($period->effective_revision_id)->toBeNull();

    // A draft is never the effective allocation.
    expect($service->effectiveRevision($maker, $application, '2026-09'))->toBeNull();
});

it('allows only one open revision per period', function () {
    $maker = FundAllocationFixture::maker();
    $application = FundAllocationFixture::application();
    $fund = FundAllocationFixture::linkedFund($application);
    $service = FundAllocationFixture::service();

    $snapshot = $service->recordReferenceBalance($maker, $application, '2026-09', '1000.00');
    $service->createDraft($maker, $application, '2026-09', $snapshot->id, [
        ['fund_id' => $fund->id, 'amount' => '10.00'],
    ]);

    expect(fn () => $service->createDraft($maker, $application, '2026-09', $snapshot->id, [
        ['fund_id' => $fund->id, 'amount' => '20.00'],
    ]))->toThrow(FundAllocationException::class, 'revisão aberta');
});

it('edits draft lines and discards drafts', function () {
    $maker = FundAllocationFixture::maker();
    $application = FundAllocationFixture::application();
    $fundA = FundAllocationFixture::linkedFund($application);
    $fundB = FundAllocationFixture::linkedFund($application);
    $service = FundAllocationFixture::service();

    $snapshot = $service->recordReferenceBalance($maker, $application, '2026-09', '1000.00');
    $draft = $service->createDraft($maker, $application, '2026-09', $snapshot->id, [
        ['fund_id' => $fundA->id, 'amount' => '100.00'],
    ]);

    $updated = $service->updateDraft($maker, $draft, [
        ['fund_id' => $fundA->id, 'amount' => '150.00'],
        ['fund_id' => $fundB->id, 'amount' => '50.00'],
    ]);

    expect($updated->total_amount)->toBe('200.00')
        ->and($updated->unallocated_amount)->toBe('800.00')
        ->and($updated->lines)->toHaveCount(2);

    $discarded = $service->discardDraft($maker, $updated, 'Refazer a distribuição.');

    expect($discarded->status)->toBe(FundAllocationRevision::STATUS_DISCARDED)
        ->and(FundAllocationPeriod::query()->first()->open_revision_id)->toBeNull();

    // Discarded history stays; a new draft is accepted afterwards.
    $replacement = $service->createDraft($maker, $application, '2026-09', $snapshot->id, [
        ['fund_id' => $fundA->id, 'amount' => '10.00'],
    ]);

    expect($replacement->revision_number)->toBe(2)
        ->and(FundAllocationRevision::query()->count())->toBe(2);
});

// ── Scenario 13: review submission ───────────────────────────────────────────

it('submits drafts for review exactly once', function () {
    $maker = FundAllocationFixture::maker();
    $application = FundAllocationFixture::application();
    $fund = FundAllocationFixture::linkedFund($application);
    $service = FundAllocationFixture::service();

    $snapshot = $service->recordReferenceBalance($maker, $application, '2026-09', '1000.00');
    $draft = $service->createDraft($maker, $application, '2026-09', $snapshot->id, [
        ['fund_id' => $fund->id, 'amount' => '10.00'],
    ]);

    $submitted = $service->submitForReview($maker, $draft);

    expect($submitted->status)->toBe(FundAllocationRevision::STATUS_PENDING_REVIEW)
        ->and($submitted->submitted_by_user_id)->toBe($maker->id)
        ->and($submitted->submitted_at)->not->toBeNull();

    expect(fn () => $service->submitForReview($maker, $submitted))
        ->toThrow(FundAllocationException::class, 'Transição inválida');

    expect(fn () => $service->updateDraft($maker, $submitted, [
        ['fund_id' => $fund->id, 'amount' => '20.00'],
    ]))->toThrow(FundAllocationException::class, 'Transição inválida');
});

it('requires the submit permission to send a draft for review', function () {
    $maker = FundAllocationFixture::maker();
    $powerless = FundAllocationFixture::userWithPermissions(['fund-allocations.view', 'fund-allocations.create']);
    $application = FundAllocationFixture::application();
    $fund = FundAllocationFixture::linkedFund($application);
    $service = FundAllocationFixture::service();

    $snapshot = $service->recordReferenceBalance($maker, $application, '2026-09', '1000.00');
    $draft = $service->createDraft($maker, $application, '2026-09', $snapshot->id, [
        ['fund_id' => $fund->id, 'amount' => '10.00'],
    ]);

    expect(fn () => $service->submitForReview($powerless, $draft))
        ->toThrow(FundAllocationException::class, 'permissão');
});

// ── Scenario 14: approval authorization ──────────────────────────────────────

it('enforces the approval permission at the domain layer', function () {
    $maker = FundAllocationFixture::maker();
    // The editor role operates the workflow but never approves.
    expect($maker->can('fund-allocations.approve'))->toBeFalse();

    $application = FundAllocationFixture::application();
    $fund = FundAllocationFixture::linkedFund($application);
    $service = FundAllocationFixture::service();

    $snapshot = $service->recordReferenceBalance($maker, $application, '2026-09', '1000.00');
    $draft = $service->createDraft($maker, $application, '2026-09', $snapshot->id, [
        ['fund_id' => $fund->id, 'amount' => '10.00'],
    ]);
    $submitted = $service->submitForReview($maker, $draft);

    expect(fn () => $service->approve($maker, $submitted, 'no-permit-1'))
        ->toThrow(FundAllocationException::class, 'permissão');

    $stranger = FundAllocationFixture::userWithPermissions([]);

    expect(fn () => $service->approve($stranger, $submitted, 'no-permit-2'))
        ->toThrow(FundAllocationException::class, 'permissão');

    expect($submitted->refresh()->status)->toBe(FundAllocationRevision::STATUS_PENDING_REVIEW);
});

// ── Scenario 15: proposer/approver separation ────────────────────────────────

it('separates proposing and approving users', function () {
    $proposer = FundAllocationFixture::userWithPermissions([
        'fund-allocations.view', 'fund-allocations.create', 'fund-allocations.submit',
        'fund-allocations.approve', 'fund-allocations.correct', 'fund-allocations.manage_references',
    ]);
    $checker = FundAllocationFixture::checker();
    $application = FundAllocationFixture::application();
    $fund = FundAllocationFixture::linkedFund($application);
    $service = FundAllocationFixture::service();

    $snapshot = $service->recordReferenceBalance($proposer, $application, '2026-09', '1000.00');
    $draft = $service->createDraft($proposer, $application, '2026-09', $snapshot->id, [
        ['fund_id' => $fund->id, 'amount' => '10.00'],
    ]);
    $submitted = $service->submitForReview($proposer, $draft);

    // Even holding the permission, the proposer cannot approve their own work.
    expect(fn () => $service->approve($proposer, $submitted, 'sod-1'))
        ->toThrow(FundAllocationException::class, 'segundo usuário');

    $approved = $service->approve($checker, $submitted, 'sod-2');

    expect($approved->status)->toBe(FundAllocationRevision::STATUS_APPROVED)
        ->and($approved->approved_by_user_id)->toBe($checker->id);
});

it('bars the submitter from approving as well', function () {
    $proposer = FundAllocationFixture::userWithPermissions([
        'fund-allocations.view', 'fund-allocations.create', 'fund-allocations.manage_references',
    ]);
    $submitter = FundAllocationFixture::userWithPermissions([
        'fund-allocations.view', 'fund-allocations.submit', 'fund-allocations.approve',
    ]);
    $checker = FundAllocationFixture::checker();
    $application = FundAllocationFixture::application();
    $fund = FundAllocationFixture::linkedFund($application);
    $service = FundAllocationFixture::service();

    $snapshot = $service->recordReferenceBalance($proposer, $application, '2026-09', '1000.00');
    $draft = $service->createDraft($proposer, $application, '2026-09', $snapshot->id, [
        ['fund_id' => $fund->id, 'amount' => '10.00'],
    ]);
    $submitted = $service->submitForReview($submitter, $draft);

    expect(fn () => $service->approve($submitter, $submitted, 'sod-submit-1'))
        ->toThrow(FundAllocationException::class, 'segundo usuário');

    expect($service->approve($checker, $submitted, 'sod-submit-2')->status)
        ->toBe(FundAllocationRevision::STATUS_APPROVED);
});

// ── Scenario 16: approved revision immutability ──────────────────────────────

it('keeps approved revisions immutable', function () {
    $maker = FundAllocationFixture::maker();
    $checker = FundAllocationFixture::checker();
    $application = FundAllocationFixture::application();
    $fund = FundAllocationFixture::linkedFund($application);
    $service = FundAllocationFixture::service();

    $snapshot = $service->recordReferenceBalance($maker, $application, '2026-09', '1000.00');
    $draft = $service->createDraft($maker, $application, '2026-09', $snapshot->id, [
        ['fund_id' => $fund->id, 'amount' => '400.00'],
    ]);
    $approved = $service->approve($checker, $service->submitForReview($maker, $draft), 'immutable-1');

    expect(fn () => $service->updateDraft($maker, $approved, [
        ['fund_id' => $fund->id, 'amount' => '500.00'],
    ]))->toThrow(FundAllocationException::class, 'Transição inválida');

    expect(fn () => $service->submitForReview($maker, $approved))
        ->toThrow(FundAllocationException::class, 'Transição inválida');

    expect(fn () => $service->discardDraft($maker, $approved))
        ->toThrow(FundAllocationException::class, 'Transição inválida');

    expect(fn () => $service->createDraft($maker, $application, '2026-09', $snapshot->id, [
        ['fund_id' => $fund->id, 'amount' => '10.00'],
    ]))->toThrow(FundAllocationException::class, 'correção');

    $stored = $approved->refresh()->load('lines');

    expect($stored->total_amount)->toBe('400.00')
        ->and($stored->lines->first()->amount)->toBe('400.00');
});

// ── Scenario 17: correction through a new revision ───────────────────────────

it('corrects an approved distribution through a new revision', function () {
    $maker = FundAllocationFixture::maker();
    $checker = FundAllocationFixture::checker();
    $application = FundAllocationFixture::application();
    [$emission] = FundAllocationFixture::classification();
    $fund = FundAllocationFixture::linkedFund($application, $emission);
    $service = FundAllocationFixture::service();

    $snapshot = $service->recordReferenceBalance($maker, $application, '2026-09', '1000.00');
    $first = $service->createDraft($maker, $application, '2026-09', $snapshot->id, [
        ['fund_id' => $fund->id, 'amount' => '400.00'],
    ]);
    $approvedFirst = $service->approve($checker, $service->submitForReview($maker, $first), 'correct-1');

    expect(fn () => $service->proposeCorrection($maker, $application, '2026-09', $snapshot->id, [
        ['fund_id' => $fund->id, 'amount' => '500.00'],
    ], '  '))->toThrow(FundAllocationException::class, 'motivo');

    $correction = $service->proposeCorrection($maker, $application, '2026-09', $snapshot->id, [
        ['fund_id' => $fund->id, 'amount' => '500.00'],
    ], 'Rateio revisto pelo backoffice.');

    expect($correction->status)->toBe(FundAllocationRevision::STATUS_DRAFT)
        ->and($correction->revision_number)->toBe(2)
        ->and($correction->supersedes_revision_id)->toBe($approvedFirst->id)
        ->and($correction->isCorrection())->toBeTrue();

    // The first approval stays effective until the correction is approved.
    expect($service->effectiveRevision($checker, $application, '2026-09')->getKey())->toBe($approvedFirst->id);

    $approvedSecond = $service->approve($checker, $service->submitForReview($maker, $correction), 'correct-2');

    expect($approvedSecond->status)->toBe(FundAllocationRevision::STATUS_APPROVED)
        ->and($approvedFirst->refresh()->status)->toBe(FundAllocationRevision::STATUS_SUPERSEDED)
        ->and($approvedFirst->total_amount)->toBe('400.00')
        ->and($approvedSecond->total_amount)->toBe('500.00')
        ->and($service->effectiveRevision($checker, $application, '2026-09')->getKey())->toBe($approvedSecond->id);
});

it('governs correction drafts by the correction permission', function () {
    $maker = FundAllocationFixture::maker();
    $checker = FundAllocationFixture::checker();
    $application = FundAllocationFixture::application();
    $fund = FundAllocationFixture::linkedFund($application);
    $service = FundAllocationFixture::service();

    $snapshot = $service->recordReferenceBalance($maker, $application, '2026-09', '1000.00');
    $draft = $service->createDraft($maker, $application, '2026-09', $snapshot->id, [
        ['fund_id' => $fund->id, 'amount' => '100.00'],
    ]);
    $service->approve($checker, $service->submitForReview($maker, $draft), 'correct-perm-1');

    $correction = $service->proposeCorrection($maker, $application, '2026-09', $snapshot->id, [
        ['fund_id' => $fund->id, 'amount' => '200.00'],
    ], 'Ajuste.');

    $creatorOnly = FundAllocationFixture::userWithPermissions(['fund-allocations.view', 'fund-allocations.create']);

    expect(fn () => $service->updateDraft($creatorOnly, $correction, [
        ['fund_id' => $fund->id, 'amount' => '300.00'],
    ]))->toThrow(FundAllocationException::class, 'permissão');

    $updated = $service->updateDraft($maker, $correction, [
        ['fund_id' => $fund->id, 'amount' => '300.00'],
    ]);

    expect($updated->total_amount)->toBe('300.00');
});

it('requires the correction permission and an existing approval', function () {
    $maker = FundAllocationFixture::maker();
    $application = FundAllocationFixture::application();
    $fund = FundAllocationFixture::linkedFund($application);
    $service = FundAllocationFixture::service();

    $snapshot = $service->recordReferenceBalance($maker, $application, '2026-09', '1000.00');

    expect(fn () => $service->proposeCorrection($maker, $application, '2026-09', $snapshot->id, [
        ['fund_id' => $fund->id, 'amount' => '10.00'],
    ], 'Sem aprovação anterior.'))->toThrow(FundAllocationException::class, 'primeiro rascunho');

    $powerless = FundAllocationFixture::userWithPermissions(['fund-allocations.view', 'fund-allocations.create']);

    expect(fn () => $service->proposeCorrection($powerless, $application, '2026-09', $snapshot->id, [
        ['fund_id' => $fund->id, 'amount' => '10.00'],
    ], 'Sem permissão.'))->toThrow(FundAllocationException::class, 'permissão');
});

it('corrects against a newer reference version without rewriting history', function () {
    $maker = FundAllocationFixture::maker();
    $checker = FundAllocationFixture::checker();
    $application = FundAllocationFixture::application();
    $fund = FundAllocationFixture::linkedFund($application);
    $service = FundAllocationFixture::service();

    $first = $service->recordReferenceBalance($maker, $application, '2026-09', '1000.00');
    $draft = $service->createDraft($maker, $application, '2026-09', $first->id, [
        ['fund_id' => $fund->id, 'amount' => '1000.00'],
    ]);
    $approvedFirst = $service->approve($checker, $service->submitForReview($maker, $draft), 'ref-change-1');

    $second = $service->recordReferenceBalance($maker, $application, '2026-09', '1200.00', 'Extrato retificado.');

    $correction = $service->proposeCorrection($maker, $application, '2026-09', $second->id, [
        ['fund_id' => $fund->id, 'amount' => '1100.00'],
    ], 'Acompanha o saldo retificado.');
    $approvedSecond = $service->approve($checker, $service->submitForReview($maker, $correction), 'ref-change-2');

    expect($approvedFirst->refresh()->reference_amount)->toBe('1000.00')
        ->and($approvedFirst->bank_application_balance_snapshot_id)->toBe($first->id)
        ->and($approvedSecond->reference_amount)->toBe('1200.00')
        ->and($approvedSecond->bank_application_balance_snapshot_id)->toBe($second->id);
});

// ── Scenario 18: deterministic effective revision ────────────────────────────

it('resolves exactly one effective revision per period', function () {
    $maker = FundAllocationFixture::maker();
    $checker = FundAllocationFixture::checker();
    $application = FundAllocationFixture::application();
    $fund = FundAllocationFixture::linkedFund($application);
    $service = FundAllocationFixture::service();

    expect($service->effectiveRevision($maker, $application, '2026-09'))->toBeNull();

    $snapshot = $service->recordReferenceBalance($maker, $application, '2026-09', '1000.00');
    $draft = $service->createDraft($maker, $application, '2026-09', $snapshot->id, [
        ['fund_id' => $fund->id, 'amount' => '10.00'],
    ]);
    $approved = $service->approve($checker, $service->submitForReview($maker, $draft), 'effective-1');

    $firstRead = $service->effectiveRevision($maker, $application, '2026-09');
    $secondRead = $service->effectiveRevision($checker, $application, '2026-09-30');

    expect($firstRead->getKey())->toBe($approved->id)
        ->and($secondRead->getKey())->toBe($approved->id)
        ->and(FundAllocationRevision::query()->where('status', FundAllocationRevision::STATUS_APPROVED)->count())->toBe(1);

    $stranger = FundAllocationFixture::userWithPermissions([]);

    expect(fn () => $service->effectiveRevision($stranger, $application, '2026-09'))
        ->toThrow(FundAllocationException::class, 'permissão');
});

// ── Scenario 19: historical emission identity ────────────────────────────────

it('preserves the emission identity frozen at approval time', function () {
    $maker = FundAllocationFixture::maker();
    $checker = FundAllocationFixture::checker();
    $application = FundAllocationFixture::application();
    [$originalEmission] = FundAllocationFixture::classification('Operação Original');
    [$newEmission] = FundAllocationFixture::classification('Operação Nova');
    $fund = FundAllocationFixture::linkedFund($application, $originalEmission);
    $service = FundAllocationFixture::service();

    $snapshot = $service->recordReferenceBalance($maker, $application, '2026-09', '1000.00');
    $draft = $service->createDraft($maker, $application, '2026-09', $snapshot->id, [
        ['fund_id' => $fund->id, 'amount' => '700.00'],
    ]);
    $approved = $service->approve($checker, $service->submitForReview($maker, $draft), 'history-1');

    // The fund moves to another emission after approval.
    $fund->forceFill(['emission_id' => $newEmission->id])->save();

    $line = $approved->refresh()->lines->first();

    expect((int) $line->emission_id)->toBe($originalEmission->id)
        ->and((string) $line->amount)->toBe('700.00')
        ->and((int) $fund->refresh()->emission_id)->toBe($newEmission->id);
});

it('preserves approved lines when fund relationships change later', function () {
    $maker = FundAllocationFixture::maker();
    $checker = FundAllocationFixture::checker();
    $application = FundAllocationFixture::application();
    $otherApplication = FundAllocationFixture::application();
    $fund = FundAllocationFixture::linkedFund($application);
    $service = FundAllocationFixture::service();

    $snapshot = $service->recordReferenceBalance($maker, $application, '2026-09', '1000.00');
    $draft = $service->createDraft($maker, $application, '2026-09', $snapshot->id, [
        ['fund_id' => $fund->id, 'amount' => '700.00'],
    ]);
    $approved = $service->approve($checker, $service->submitForReview($maker, $draft), 'history-2');

    // Account/application relationships change after approval: history stands.
    $fund->forceFill([
        'bank_account_id' => $otherApplication->bank_account_id,
        'bank_application_id' => $otherApplication->id,
    ])->save();

    $line = $approved->refresh()->lines->first();

    expect((int) $line->fund_id)->toBe($fund->id)
        ->and((string) $line->amount)->toBe('700.00')
        ->and($service->effectiveRevision($checker, $application, '2026-09')->getKey())->toBe($approved->id);
});

it('rejects approval when a fund association changed after drafting', function () {
    $maker = FundAllocationFixture::maker();
    $checker = FundAllocationFixture::checker();
    $application = FundAllocationFixture::application();
    $otherApplication = FundAllocationFixture::application();
    $fund = FundAllocationFixture::linkedFund($application);
    $service = FundAllocationFixture::service();

    $snapshot = $service->recordReferenceBalance($maker, $application, '2026-09', '1000.00');
    $draft = $service->createDraft($maker, $application, '2026-09', $snapshot->id, [
        ['fund_id' => $fund->id, 'amount' => '100.00'],
    ]);
    $submitted = $service->submitForReview($maker, $draft);

    // The fund is unlinked from the application before approval through a
    // mass update, which bypasses Eloquent events and therefore the linkage
    // guard: the approval itself must still reject the stale distribution
    // against its locked (current) Fund reads. Ordinary saves take the
    // earlier, clearer refusal (see 'blocks fund linkage edits while an
    // allocation revision is open').
    Fund::query()->whereKey($fund->getKey())->update([
        'bank_account_id' => $otherApplication->bank_account_id,
        'bank_application_id' => $otherApplication->id,
    ]);

    expect(fn () => $service->approve($checker, $submitted, 'moved-1'))
        ->toThrow(FundAllocationException::class, 'não está mais vinculado');

    expect($submitted->refresh()->status)->toBe(FundAllocationRevision::STATUS_PENDING_REVIEW)
        ->and(FundAllocationPeriod::query()->first()->effective_revision_id)->toBeNull();
});

// ── Scenario 20: stale balance rejection ─────────────────────────────────────

it('rejects submission and approval against a stale reference', function () {
    $maker = FundAllocationFixture::maker();
    $checker = FundAllocationFixture::checker();
    $application = FundAllocationFixture::application();
    $fund = FundAllocationFixture::linkedFund($application);
    $service = FundAllocationFixture::service();

    $first = $service->recordReferenceBalance($maker, $application, '2026-09', '1000.00');
    $draft = $service->createDraft($maker, $application, '2026-09', $first->id, [
        ['fund_id' => $fund->id, 'amount' => '100.00'],
    ]);

    $service->recordReferenceBalance($maker, $application, '2026-09', '1100.00');

    expect(fn () => $service->submitForReview($maker, $draft))
        ->toThrow(FundAllocationException::class, 'mudou desde a criação');

    // Drafting directly against the superseded version is refused as well.
    expect(fn () => $service->createDraft($maker, $application, '2026-09', $first->id, [
        ['fund_id' => $fund->id, 'amount' => '100.00'],
    ]))->toThrow(FundAllocationException::class, 'mudou desde a criação');
});

it('rejects approval when the reference changes after submission', function () {
    $maker = FundAllocationFixture::maker();
    $checker = FundAllocationFixture::checker();
    $application = FundAllocationFixture::application();
    $fund = FundAllocationFixture::linkedFund($application);
    $service = FundAllocationFixture::service();

    $first = $service->recordReferenceBalance($maker, $application, '2026-09', '1000.00');
    $draft = $service->createDraft($maker, $application, '2026-09', $first->id, [
        ['fund_id' => $fund->id, 'amount' => '100.00'],
    ]);
    $submitted = $service->submitForReview($maker, $draft);

    $service->recordReferenceBalance($maker, $application, '2026-09', '900.00');

    expect(fn () => $service->approve($checker, $submitted, 'stale-approve-1'))
        ->toThrow(FundAllocationException::class, 'mudou desde a criação');

    expect($submitted->refresh()->status)->toBe(FundAllocationRevision::STATUS_PENDING_REVIEW);

    // The rejected attempt is auditable.
    expect(Activity::query()->where('log_name', FundAllocationService::AUDIT_LOG)->where('description', 'approval_rejected')->count())->toBe(1);
});

// ── Scenario 22: idempotent approval ─────────────────────────────────────────

it('replays repeated approvals with the same key without duplicating', function () {
    $maker = FundAllocationFixture::maker();
    $checker = FundAllocationFixture::checker();
    $application = FundAllocationFixture::application();
    $fund = FundAllocationFixture::linkedFund($application);
    $service = FundAllocationFixture::service();

    $snapshot = $service->recordReferenceBalance($maker, $application, '2026-09', '1000.00');
    $draft = $service->createDraft($maker, $application, '2026-09', $snapshot->id, [
        ['fund_id' => $fund->id, 'amount' => '100.00'],
    ]);
    $submitted = $service->submitForReview($maker, $draft);

    $approved = $service->approve($checker, $submitted, 'idem-1');
    $auditCount = Activity::query()->where('log_name', FundAllocationService::AUDIT_LOG)->count();

    $replay = $service->approve($checker, $submitted, 'idem-1');

    expect($replay->getKey())->toBe($approved->id)
        ->and($replay->status)->toBe(FundAllocationRevision::STATUS_APPROVED)
        ->and(FundAllocationRevision::query()->count())->toBe(1)
        ->and(FundAllocationPeriod::query()->first()->effective_revision_id)->toBe($approved->id)
        ->and(Activity::query()->where('log_name', FundAllocationService::AUDIT_LOG)->count())->toBe($auditCount);
});

it('rejects a different key against an approved revision', function () {
    $maker = FundAllocationFixture::maker();
    $checker = FundAllocationFixture::checker();
    $application = FundAllocationFixture::application();
    $fund = FundAllocationFixture::linkedFund($application);
    $service = FundAllocationFixture::service();

    $snapshot = $service->recordReferenceBalance($maker, $application, '2026-09', '1000.00');
    $draft = $service->createDraft($maker, $application, '2026-09', $snapshot->id, [
        ['fund_id' => $fund->id, 'amount' => '100.00'],
    ]);
    $approved = $service->approve($checker, $service->submitForReview($maker, $draft), 'idem-2');

    expect(fn () => $service->approve($checker, $approved, 'idem-other'))
        ->toThrow(FundAllocationException::class, 'já foi aprovada');
});

// ── Scenario 23: transactional rollback ──────────────────────────────────────

it('rolls back the entire approval when a failure strikes mid-transaction', function () {
    $maker = FundAllocationFixture::maker();
    $checker = FundAllocationFixture::checker();
    $application = FundAllocationFixture::application();
    $fund = FundAllocationFixture::linkedFund($application);
    $service = FundAllocationFixture::service();

    $snapshot = $service->recordReferenceBalance($maker, $application, '2026-09', '1000.00');
    $draft = $service->createDraft($maker, $application, '2026-09', $snapshot->id, [
        ['fund_id' => $fund->id, 'amount' => '100.00'],
    ]);
    $submitted = $service->submitForReview($maker, $draft);

    $auditBefore = Activity::query()->where('log_name', FundAllocationService::AUDIT_LOG)->count();

    // Simulate a crash after the approval writes: the listener fires inside
    // the approval transaction and aborts it. The dispatcher is restored
    // afterwards so no other test observes the sabotage.
    FundAllocationRevision::updated(function () {
        throw new RuntimeException('boom');
    });

    try {
        $service->approve($checker, $submitted, 'rollback-1');
        $this->fail('Expected the simulated failure to abort approval.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('boom');
    } finally {
        // The sabotage listener lives on the shared dispatcher: forget it so
        // no other test observes it. This model has no legitimate listeners.
        app('events')->forget('eloquent.updated: '.FundAllocationRevision::class);
    }

    expect($submitted->refresh()->status)->toBe(FundAllocationRevision::STATUS_PENDING_REVIEW)
        ->and($submitted->approved_by_user_id)->toBeNull()
        ->and($submitted->approval_idempotency_key)->toBeNull();

    $period = FundAllocationPeriod::query()->first();

    expect($period->effective_revision_id)->toBeNull()
        ->and((int) $period->open_revision_id)->toBe($submitted->id);

    // The rolled-back approval left no 'approved' trail behind.
    expect(Activity::query()->where('log_name', FundAllocationService::AUDIT_LOG)->where('description', 'approved')->count())->toBe(0)
        ->and(Activity::query()->where('log_name', FundAllocationService::AUDIT_LOG)->count())->toBe($auditBefore);
});

// ── Audit trail ──────────────────────────────────────────────────────────────

it('audits every financial action under the protected allocation log', function () {
    expect(config('audit.protected_logs'))->toContain(FundAllocationService::AUDIT_LOG);

    $maker = FundAllocationFixture::maker();
    $checker = FundAllocationFixture::checker();
    $application = FundAllocationFixture::application();
    [$emission] = FundAllocationFixture::classification();
    $fund = FundAllocationFixture::linkedFund($application, $emission);
    $service = FundAllocationFixture::service();

    $snapshot = $service->recordReferenceBalance($maker, $application, '2026-09', '1000.00', 'Primeira referência.');
    $draft = $service->createDraft($maker, $application, '2026-09', $snapshot->id, [
        ['fund_id' => $fund->id, 'amount' => '600.00'],
    ]);
    $submitted = $service->submitForReview($maker, $draft);
    $approved = $service->approve($checker, $submitted, 'audit-1');

    $events = Activity::query()
        ->where('log_name', FundAllocationService::AUDIT_LOG)
        ->orderBy('id')
        ->pluck('description')
        ->all();

    expect($events)->toBe(['reference_recorded', 'draft_created', 'submitted_for_review', 'approved']);

    $approval = Activity::query()
        ->where('log_name', FundAllocationService::AUDIT_LOG)
        ->where('description', 'approved')
        ->firstOrFail();

    expect($approval->causer_id)->toBe($checker->id)
        ->and($approval->subject_id)->toBe($approved->id)
        ->and($approval->properties['revision_number'])->toBe(1)
        ->and($approval->properties['snapshot_version'])->toBe(1)
        ->and($approval->properties['reference_amount'])->toBe('1000.00')
        ->and($approval->properties['total_amount'])->toBe('600.00')
        ->and($approval->properties['unallocated_amount'])->toBe('400.00')
        ->and($approval->properties['proposed_by_user_id'])->toBe($maker->id)
        ->and($approval->properties['approved_by_user_id'])->toBe($checker->id);

    $reference = Activity::query()
        ->where('log_name', FundAllocationService::AUDIT_LOG)
        ->where('description', 'reference_recorded')
        ->firstOrFail();

    expect($reference->properties['source'])->toBe('manual')
        ->and($reference->causer_id)->toBe($maker->id);
});

it('reports the allocation status read-only from the console', function () {
    $maker = FundAllocationFixture::maker();
    $checker = FundAllocationFixture::checker();
    $application = FundAllocationFixture::application();
    $fund = FundAllocationFixture::linkedFund($application);
    $service = FundAllocationFixture::service();

    $snapshot = $service->recordReferenceBalance($maker, $application, '2026-09', '1000.00');
    $draft = $service->createDraft($maker, $application, '2026-09', $snapshot->id, [
        ['fund_id' => $fund->id, 'amount' => '250.00'],
    ]);
    $service->approve($checker, $service->submitForReview($maker, $draft), 'status-1');

    $exitCode = Artisan::call('fund-allocations:status', [
        'application' => $application->id,
        'competence' => '2026-09',
        '--format' => 'json',
    ]);

    expect($exitCode)->toBe(0);

    $output = Artisan::output();

    expect($output)->toContain('"unallocated_amount": "750.00"')
        ->and($output)->toContain('"source": "manual"')
        ->and($output)->toContain('"source_label": "Manual (informado pelo usuário)"');

    // The command writes nothing.
    expect(FundAllocationRevision::query()->count())->toBe(1)
        ->and(Fund::query()->count())->toBe(1);
});
