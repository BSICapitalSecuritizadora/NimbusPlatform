<?php

use App\Jobs\SendProposalContinuationEmail;
use App\Livewire\Proposals\CreateProposalForm;
use App\Models\Proposal;
use App\Models\ProposalContact;
use App\Models\ProposalContinuationAccess;
use App\Models\ProposalRepresentative;
use App\Models\ProposalSector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('stores multiple sectors and separate whatsapp preferences', function () {
    Mail::fake();
    $realEstate = ProposalSector::query()->create(['name' => 'Incorporação']);
    $infrastructure = ProposalSector::query()->create(['name' => 'Infraestrutura']);
    ProposalRepresentative::factory()->create(['queue_position' => 1]);

    $state = proposalCreateFormState($realEstate);
    $state['sectorIds'] = [$realEstate->id, $infrastructure->id];
    $state['isWhatsapp'] = true;
    $state['whatsappContactConsent'] = false;

    submitProposalCreateForm($state);

    $proposal = Proposal::query()->with(['company.sectors', 'contact'])->firstOrFail();

    expect($proposal->company->sectors->pluck('id')->sort()->values()->all())
        ->toBe(collect([$realEstate->id, $infrastructure->id])->sort()->values()->all())
        ->and($proposal->contact->is_whatsapp)->toBeTrue()
        ->and($proposal->contact->whatsapp_contact_consent)->toBeFalse()
        ->and($proposal->contact->whatsapp_url)->toBe('https://wa.me/5511999990000')
        ->and($proposal->contact_mailto_url)->toStartWith('mailto:');
});

it('keeps historical whatsapp meaning unknown instead of inferring it', function () {
    $contact = new ProposalContact([
        'whatsapp' => true,
        'is_whatsapp' => null,
        'whatsapp_contact_consent' => null,
        'phone_personal' => '(11) 99999-0000',
    ]);

    expect($contact->whatsapp_availability_label)->toContain('registro histórico')
        ->and($contact->whatsapp_consent_label)->toContain('registro histórico')
        ->and($contact->whatsapp_url)->toBeNull();
});

it('uses one submission token to prevent an accidental double submit', function () {
    Mail::fake();
    Queue::fake();
    $sector = ProposalSector::query()->create(['name' => 'Incorporação']);
    ProposalRepresentative::factory()->create(['queue_position' => 1]);
    $state = proposalCreateFormState($sector);
    fakeProposalCreateLookups($state);

    $component = Livewire::test(CreateProposalForm::class);
    foreach ($state as $property => $value) {
        $component->set("form.{$property}", $value);
    }

    $component->call('save')->assertHasNoErrors();
    $component->call('save')->assertHasNoErrors();

    expect(Proposal::query()->count())->toBe(1)
        ->and(Proposal::query()->firstOrFail()->submission_token)->not->toBeNull()
        ->and(Proposal::query()->firstOrFail()->continuationAccesses()->count())->toBe(1);

    Queue::assertPushed(SendProposalContinuationEmail::class, 1);
});

it('tracks a definitive continuation email failure without deleting the proposal', function () {
    Mail::fake();
    Queue::fake();
    $sector = ProposalSector::query()->create(['name' => 'Incorporação']);
    ProposalRepresentative::factory()->create(['queue_position' => 1]);
    submitInitialProposalThroughComponent($sector);

    $proposal = Proposal::query()->with('latestContinuationAccess')->firstOrFail();
    $access = $proposal->latestContinuationAccess;

    (new SendProposalContinuationEmail($access->id))->failed(new RuntimeException('SMTP unavailable'));

    expect($proposal->fresh())->not->toBeNull()
        ->and($access->fresh()->mail_queued_at)->not->toBeNull()
        ->and($access->fresh()->mail_failed_at)->not->toBeNull()
        ->and($access->fresh()->status_label)->toBe('Falha no envio');
});

it('distinguishes an email waiting in the queue from one already sent', function () {
    $access = new ProposalContinuationAccess([
        'sent_at' => null,
        'mail_queued_at' => now(),
        'mail_failed_at' => null,
        'expires_at' => now()->addDay(),
    ]);

    expect($access->status_label)->toBe('Aguardando envio');

    $access->sent_at = now();

    expect($access->status_label)->toBe('Enviado');
});

it('makes the queued continuation email idempotent and configures retries', function () {
    Mail::fake();
    Queue::fake();
    $sector = ProposalSector::query()->create(['name' => 'Incorporação']);
    ProposalRepresentative::factory()->create(['queue_position' => 1]);
    submitInitialProposalThroughComponent($sector);
    $access = Proposal::query()->with('latestContinuationAccess')->firstOrFail()->latestContinuationAccess;
    $job = new SendProposalContinuationEmail($access->id);

    $job->handle();
    $job->handle();

    Mail::assertSentCount(1);
    expect($access->fresh()->sent_at)->not->toBeNull()
        ->and($job->tries)->toBe(5)
        ->and($job->backoff)->toBe([60, 300, 900, 1800])
        ->and($job->uniqueId())->toBe((string) $access->id);
});
