<?php

use App\Exceptions\Funds\FundAllocationException;
use App\Models\Fund;
use App\Models\FundAllocationRevision;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\FundAllocationFixture;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('blocks fund linkage edits while a draft revision is open', function () {
    $maker = FundAllocationFixture::maker();
    $application = FundAllocationFixture::application();
    [$emission] = FundAllocationFixture::classification();
    [$otherEmission] = FundAllocationFixture::classification();
    $fund = FundAllocationFixture::linkedFund($application, $emission);
    $service = FundAllocationFixture::service();

    $snapshot = $service->recordReferenceBalance($maker, $application, '2026-09', '1000.00');
    $service->createDraft($maker, $application, '2026-09', $snapshot->id, [
        ['fund_id' => $fund->id, 'amount' => '100.00'],
    ]);

    expect(fn () => $fund->forceFill(['emission_id' => $otherEmission->id])->save())
        ->toThrow(FundAllocationException::class, 'alocação em andamento');

    expect((int) $fund->refresh()->emission_id)->toBe($emission->id);
});

it('blocks fund linkage edits while a pending revision is open', function () {
    $maker = FundAllocationFixture::maker();
    $application = FundAllocationFixture::application();
    $otherApplication = FundAllocationFixture::application();
    $fund = FundAllocationFixture::linkedFund($application);
    $service = FundAllocationFixture::service();

    $snapshot = $service->recordReferenceBalance($maker, $application, '2026-09', '1000.00');
    $draft = $service->createDraft($maker, $application, '2026-09', $snapshot->id, [
        ['fund_id' => $fund->id, 'amount' => '100.00'],
    ]);
    $service->submitForReview($maker, $draft);

    expect(fn () => $fund->forceFill([
        'bank_account_id' => $otherApplication->bank_account_id,
        'bank_application_id' => $otherApplication->id,
    ])->save())->toThrow(FundAllocationException::class, 'alocação em andamento');

    expect((int) $fund->refresh()->bank_application_id)->toBe($application->id);
});

it('allows unrelated fund changes while an allocation revision is open', function () {
    $maker = FundAllocationFixture::maker();
    $application = FundAllocationFixture::application();
    $fund = FundAllocationFixture::linkedFund($application);
    $service = FundAllocationFixture::service();

    $snapshot = $service->recordReferenceBalance($maker, $application, '2026-09', '1000.00');
    $service->createDraft($maker, $application, '2026-09', $snapshot->id, [
        ['fund_id' => $fund->id, 'amount' => '100.00'],
    ]);

    $fund->forceFill([
        'trade_name' => 'Novo nome fantasia',
        'balance' => '123.45',
        'minimum_balance' => '10.00',
    ])->save();

    expect($fund->refresh()->trade_name)->toBe('Novo nome fantasia')
        ->and((string) $fund->balance)->toBe('123.45');
});

it('allows linkage edits for funds outside every open revision', function () {
    $maker = FundAllocationFixture::maker();
    $application = FundAllocationFixture::application();
    $otherApplication = FundAllocationFixture::application();
    $allocatedFund = FundAllocationFixture::linkedFund($application);
    $freeFund = FundAllocationFixture::linkedFund($application);
    $service = FundAllocationFixture::service();

    $snapshot = $service->recordReferenceBalance($maker, $application, '2026-09', '1000.00');
    $service->createDraft($maker, $application, '2026-09', $snapshot->id, [
        ['fund_id' => $allocatedFund->id, 'amount' => '100.00'],
    ]);

    $freeFund->forceFill([
        'bank_account_id' => $otherApplication->bank_account_id,
        'bank_application_id' => $otherApplication->id,
    ])->save();

    expect((int) $freeFund->refresh()->bank_application_id)->toBe($otherApplication->id);
});

it('allows the linkage edit again once the open revision is discarded', function () {
    $maker = FundAllocationFixture::maker();
    $application = FundAllocationFixture::application();
    [$emission] = FundAllocationFixture::classification();
    [$otherEmission] = FundAllocationFixture::classification();
    $fund = FundAllocationFixture::linkedFund($application, $emission);
    $service = FundAllocationFixture::service();

    $snapshot = $service->recordReferenceBalance($maker, $application, '2026-09', '1000.00');
    $draft = $service->createDraft($maker, $application, '2026-09', $snapshot->id, [
        ['fund_id' => $fund->id, 'amount' => '100.00'],
    ]);

    expect(fn () => $fund->forceFill(['emission_id' => $otherEmission->id])->save())
        ->toThrow(FundAllocationException::class);

    $service->discardDraft($maker, $draft);

    $fund->forceFill(['emission_id' => $otherEmission->id])->save();

    expect((int) $fund->refresh()->emission_id)->toBe($otherEmission->id);
});

it('never blocks reassociation because of historical approved allocations', function () {
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
    $service->approve($checker, $service->submitForReview($maker, $draft), 'guard-history-1');

    $fund->forceFill([
        'bank_account_id' => $otherApplication->bank_account_id,
        'bank_application_id' => $otherApplication->id,
    ])->save();

    expect((int) $fund->refresh()->bank_application_id)->toBe($otherApplication->id)
        ->and($service->effectiveRevision($checker, $application, '2026-09')->status)
        ->toBe(FundAllocationRevision::STATUS_APPROVED);
});

it('rejects submission when the linkage changed behind the draft', function () {
    $maker = FundAllocationFixture::maker();
    $application = FundAllocationFixture::application();
    $otherApplication = FundAllocationFixture::application();
    $fund = FundAllocationFixture::linkedFund($application);
    $service = FundAllocationFixture::service();

    $snapshot = $service->recordReferenceBalance($maker, $application, '2026-09', '1000.00');
    $draft = $service->createDraft($maker, $application, '2026-09', $snapshot->id, [
        ['fund_id' => $fund->id, 'amount' => '100.00'],
    ]);

    // Uncoordinated write bypassing the guard: submission must refuse the
    // stale draft instead of letting it reach pending review.
    Fund::query()->whereKey($fund->getKey())->update([
        'bank_account_id' => $otherApplication->bank_account_id,
        'bank_application_id' => $otherApplication->id,
    ]);

    expect(fn () => $service->submitForReview($maker, $draft))
        ->toThrow(FundAllocationException::class, 'não está mais vinculado');

    expect($draft->refresh()->status)->toBe(FundAllocationRevision::STATUS_DRAFT);
});
