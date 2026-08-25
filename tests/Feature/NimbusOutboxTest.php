<?php

use App\Jobs\ProcessNimbusNotificationOutbox;
use App\Mail\Nimbus\NimbusSubmissionReceivedMail;
use App\Models\Nimbus\NotificationOutbox;
use App\Models\Nimbus\PortalUser;
use App\Models\Nimbus\Submission;
use App\Models\User;
use App\Services\Nimbus\NimbusNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

function makeTestPortalUser(string $prefix = 'outbox'): PortalUser
{
    static $seq = 8000;
    $seq++;

    return PortalUser::query()->create([
        'full_name' => 'Outbox User',
        'email' => $prefix.$seq.'@example.com',
        'document_number' => str_pad((string) (30000000000 + $seq), 11, '0', STR_PAD_LEFT),
        'phone_number' => '119'.str_pad((string) $seq, 8, '0', STR_PAD_LEFT),
        'status' => 'ACTIVE',
    ]);
}

function makeTestSubmission(PortalUser $portalUser, string $status = 'PENDING'): Submission
{
    return Submission::query()->create([
        'nimbus_portal_user_id' => $portalUser->id,
        'reference_code' => 'NMB-'.strtoupper(Str::ulid()),
        'submission_type' => 'REGISTRATION',
        'title' => 'Outbox Test',
        'status' => $status,
        'submitted_at' => now(),
    ]);
}

function makeOutbox(array $overrides = []): NotificationOutbox
{
    return NotificationOutbox::factory()->create(array_merge([
        'status' => 'PENDING',
        'attempts' => 0,
        'max_attempts' => 5,
        'next_attempt_at' => null,
    ], $overrides));
}

it('domain event creates one outbox row and repeated does not duplicate', function () {
    $service = app(NimbusNotificationService::class);
    $portalUser = makeTestPortalUser('dup');
    $submission = makeTestSubmission($portalUser, Submission::STATUS_PENDING);
    $first = $service->enqueueCorrectionRequested($submission, 'Corrija X');
    $second = $service->enqueueCorrectionRequested($submission, 'Corrija X');
    expect($first->id)->toBe($second->id)
        ->and(NotificationOutbox::where('correlation_id', $first->correlation_id)->count())->toBe(1);
});

it('PENDING can be claimed and transitions to SENDING then SENT on success', function () {
    Mail::fake();
    $portalUser = makeTestPortalUser('sent');
    $submission = makeTestSubmission($portalUser, Submission::STATUS_PENDING);
    User::factory()->create(['email' => 'admin-sent@example.com'])->assignRole('admin');
    Permission::findOrCreate('nimbus.submissions.view', 'web');
    User::where('email', 'admin-sent@example.com')->first()->givePermissionTo('nimbus.submissions.view');
    $outbox = makeOutbox([
        'type' => 'submission_received',
        'payload_json' => ['submission_id' => $submission->id],
        'recipient_email' => 'admin-sent@example.com',
    ]);
    $job = new ProcessNimbusNotificationOutbox($outbox->id);
    $job->handle();
    $outbox->refresh();
    expect($outbox->status)->toBe('SENT')
        ->and($outbox->sent_at)->not->toBeNull()
        ->and($outbox->attempts)->toBe(1);
});

it('concurrent double processing does not send twice', function () {
    Mail::fake();
    $portalUser = makeTestPortalUser('conc');
    $submission = makeTestSubmission($portalUser, Submission::STATUS_PENDING);
    User::factory()->create(['email' => 'admin-conc@example.com'])->assignRole('admin');
    Permission::findOrCreate('nimbus.submissions.view', 'web');
    User::where('email', 'admin-conc@example.com')->first()->givePermissionTo('nimbus.submissions.view');
    $outbox = makeOutbox([
        'type' => 'submission_received',
        'payload_json' => ['submission_id' => $submission->id],
        'recipient_email' => 'admin-conc@example.com',
    ]);
    $job1 = new ProcessNimbusNotificationOutbox($outbox->id);
    $job2 = new ProcessNimbusNotificationOutbox($outbox->id);
    $job1->handle();
    $job2->handle();
    $outbox->refresh();
    expect($outbox->status)->toBe('SENT')
        ->and($outbox->attempts)->toBe(1);
    Mail::assertSent(NimbusSubmissionReceivedMail::class, 1);
});

it('failed send transitions to FAILED with attempts and last_error sanitized', function () {
    Mail::fake();
    $outbox = makeOutbox([
        'type' => 'submission_received',
        'payload_json' => ['submission_id' => 999999],
        'recipient_email' => 'admin@example.com',
    ]);
    $job = new ProcessNimbusNotificationOutbox($outbox->id);
    $job->handle();
    $outbox->refresh();
    expect($outbox->status)->toBe('FAILED')
        ->and($outbox->attempts)->toBe(1)
        ->and($outbox->last_error)->toContain('Submission not found')
        ->and($outbox->next_attempt_at)->not->toBeNull();
    expect($outbox->last_error)->not->toMatch('/\d{3}\.\d{3}\.\d{3}-\d{2}/');
});

it('respects max attempts and next_attempt_at not due is not processed', function () {
    Mail::fake();
    $outbox = makeOutbox([
        'type' => 'submission_received',
        'payload_json' => ['submission_id' => 999999],
        'max_attempts' => 2,
        'attempts' => 2,
        'status' => 'FAILED',
        'next_attempt_at' => now()->addHour(),
    ]);
    $job = new ProcessNimbusNotificationOutbox($outbox->id);
    $job->handle();
    $outbox->refresh();
    expect($outbox->status)->toBe('FAILED')
        ->and($outbox->attempts)->toBe(2);
});

it('max attempts respected - no retry after max', function () {
    $outbox = makeOutbox([
        'type' => 'submission_received',
        'payload_json' => ['submission_id' => 999999],
        'max_attempts' => 1,
        'attempts' => 0,
        'status' => 'PENDING',
    ]);
    Mail::fake();
    $job = new ProcessNimbusNotificationOutbox($outbox->id);
    $job->handle();
    $outbox->refresh();
    expect($outbox->status)->toBe('FAILED')
        ->and($outbox->attempts)->toBe(1)
        ->and($outbox->next_attempt_at)->toBeNull();
    $job2 = new ProcessNimbusNotificationOutbox($outbox->id);
    $job2->handle();
    $outbox->refresh();
    expect($outbox->attempts)->toBe(1);
});

it('CANCELLED does not send', function () {
    Mail::fake();
    $outbox = makeOutbox(['status' => 'CANCELLED', 'type' => 'submission_received', 'payload_json' => ['submission_id' => 1]]);
    $job = new ProcessNimbusNotificationOutbox($outbox->id);
    $job->handle();
    $outbox->refresh();
    expect($outbox->status)->toBe('CANCELLED')
        ->and($outbox->attempts)->toBe(0);
    Mail::assertNothingSent();
});

it('stale SENDING can recover', function () {
    Mail::fake();
    $portalUser = makeTestPortalUser('stale');
    $submission = makeTestSubmission($portalUser, Submission::STATUS_PENDING);
    User::factory()->create(['email' => 'admin-stale@example.com'])->assignRole('admin');
    Permission::findOrCreate('nimbus.submissions.view', 'web');
    User::where('email', 'admin-stale@example.com')->first()->givePermissionTo('nimbus.submissions.view');
    $outbox = makeOutbox([
        'status' => 'SENDING',
        'type' => 'submission_received',
        'payload_json' => ['submission_id' => $submission->id],
        'recipient_email' => 'admin-stale@example.com',
        'attempts' => 1,
    ]);
    DB::table('nimbus_notification_outboxes')->where('id', $outbox->id)->update(['updated_at' => now()->subMinutes(20)]);
    $job = new ProcessNimbusNotificationOutbox($outbox->id);
    $job->handle();
    $outbox->refresh();
    expect($outbox->status)->toBe('SENT');
});

it('manual reprocess still works', function () {
    $outbox = makeOutbox(['status' => 'FAILED', 'attempts' => 2, 'next_attempt_at' => now()->addHour()]);
    $outbox->update(['status' => 'PENDING', 'attempts' => 0, 'next_attempt_at' => null, 'last_error' => null]);
    expect($outbox->refresh()->status)->toBe('PENDING')
        ->and($outbox->attempts)->toBe(0);
});

it('not yet due FAILED is not processed early', function () {
    $outbox = makeOutbox([
        'status' => 'FAILED',
        'attempts' => 1,
        'max_attempts' => 5,
        'next_attempt_at' => now()->addMinutes(10),
        'type' => 'submission_received',
        'payload_json' => ['submission_id' => 1],
    ]);
    Mail::fake();
    $job = new ProcessNimbusNotificationOutbox($outbox->id);
    $job->handle();
    $outbox->refresh();
    expect($outbox->status)->toBe('FAILED')
        ->and($outbox->attempts)->toBe(1);
});
