<?php

use App\Models\Proposal;
use App\Models\ProposalRepresentative;
use App\Models\ProposalSector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

function continuationAccessForTest(): App\Models\ProposalContinuationAccess
{
    $sector = ProposalSector::query()->create(['name' => 'Incorporação']);

    ProposalRepresentative::factory()->create([
        'name' => 'Representante Comercial',
        'queue_position' => 1,
    ]);

    submitInitialProposalThroughComponent($sector);

    $access = Proposal::query()
        ->with('latestContinuationAccess')
        ->firstOrFail()
        ->latestContinuationAccess;

    expect($access)->not->toBeNull();

    return $access;
}

it('renders the continuation access page from a valid signed magic link', function () {
    Mail::fake();

    $access = continuationAccessForTest();

    $this->get($access->generated_url)
        ->assertOk()
        ->assertViewIs('site.proposal.access')
        ->assertSee($access->proposal->company->name);

    expect(session()->has($access->magicLinkSessionKey()))->toBeTrue();

    $access->refresh();

    expect($access->first_accessed_at)->not->toBeNull()
        ->and($access->last_accessed_at)->not->toBeNull();
});

it('aborts with 403 when the magic link signature is invalid', function () {
    Mail::fake();

    $access = continuationAccessForTest();

    // Same named route, but without the required valid signature.
    $this->get(route('site.proposal.continuation.access', $access))
        ->assertForbidden();
});

it('redirects to the continuation form when the session is already verified', function () {
    Mail::fake();

    $access = continuationAccessForTest();

    $this->withSession([$access->verifiedSessionKey() => true])
        ->get($access->generated_url)
        ->assertRedirect(route('site.proposal.continuation.form', $access));
});
