<?php

use App\Jobs\ProcessNimbusNotificationOutbox;
use App\Models\Nimbus\NotificationOutbox;
use App\Models\Nimbus\NotificationSetting;
use App\Models\Nimbus\PortalUser;
use App\Models\Nimbus\Submission;
use App\Models\User;
use App\Services\Nimbus\NimbusNotificationService;
use App\Services\Nimbus\SubmissionWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

function makeD11PortalUser(string $prefix = 'd11'): PortalUser
{
    static $seq = 12000;
    $seq++;

    return PortalUser::query()->create([
        'full_name' => 'D11 User',
        'email' => $prefix.$seq.'@example.com',
        'document_number' => str_pad((string) (70000000000 + $seq), 11, '0', STR_PAD_LEFT),
        'phone_number' => '119'.str_pad((string) $seq, 8, '0', STR_PAD_LEFT),
        'status' => 'ACTIVE',
    ]);
}

function makeD11Submission(PortalUser $user, string $status = 'PENDING'): Submission
{
    $sub = Submission::query()->create([
        'nimbus_portal_user_id' => $user->id,
        'reference_code' => 'NMB-'.strtoupper(Str::ulid()),
        'submission_type' => 'REGISTRATION',
        'title' => 'D11 Test',
        'status' => $status,
        'submitted_at' => now(),
    ]);
    app(SubmissionWorkflowService::class)->recordCreation($sub, $user);

    return $sub->refresh();
}

function makeD11Admin(string $email): User
{
    Permission::findOrCreate('nimbus.submissions.view', 'web');
    $u = User::factory()->create(['email' => $email]);
    $u->assignRole('admin');
    $u->givePermissionTo('nimbus.submissions.view');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $u;
}

// 1-10 Access-code dedup tests
it('access-code dedup: PENDING double-click reuses same outbox', function () {
    $portalUser = makeD11PortalUser('d11a1');
    $service = app(NimbusNotificationService::class);
    $first = $service->enqueueAccessCode($portalUser);
    expect($first->status)->toBe('PENDING');
    $second = $service->enqueueAccessCode($portalUser);
    expect($second->id)->toBe($first->id)
        ->and(NotificationOutbox::where('type', 'portal_access_code')->where('recipient_email', Str::lower($portalUser->email))->count())->toBe(1);
    expect(json_encode($first->payload_json))->not->toMatch('/[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}/');
});

it('access-code dedup: SENDING double-click reuses same outbox (race after claim)', function () {
    $portalUser = makeD11PortalUser('d11a2');
    $service = app(NimbusNotificationService::class);
    $first = $service->enqueueAccessCode($portalUser);
    // Simulate worker claim: PENDING -> SENDING
    $first->update(['status' => 'SENDING']);
    expect($first->refresh()->status)->toBe('SENDING');

    $second = $service->enqueueAccessCode($portalUser);
    expect($second->id)->toBe($first->id)
        ->and(NotificationOutbox::where('type', 'portal_access_code')->where('recipient_email', Str::lower($portalUser->email))->count())->toBe(1);
    expect($second->correlation_id)->toBe($first->correlation_id);
});

it('access-code dedup: after 31 seconds new request creates new outbox with different correlation', function () {
    $portalUser = makeD11PortalUser('d11a3');
    $service = app(NimbusNotificationService::class);
    $first = $service->enqueueAccessCode($portalUser);
    $firstId = $first->id;
    $firstCorr = $first->correlation_id;

    $this->travel(31)->seconds();
    $second = $service->enqueueAccessCode($portalUser);
    expect($second->id)->not->toBe($firstId)
        ->and($second->correlation_id)->not->toBe($firstCorr)
        ->and(NotificationOutbox::where('type', 'portal_access_code')->where('recipient_email', Str::lower($portalUser->email))->count())->toBe(2);
    // No plaintext
    expect(json_encode($second->payload_json))->not->toMatch('/[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}/');
});

it('access-code dedup: SENT historical does not block future request', function () {
    Mail::fake();
    $portalUser = makeD11PortalUser('d11a4');
    $service = app(NimbusNotificationService::class);
    $first = $service->enqueueAccessCode($portalUser);
    (new ProcessNimbusNotificationOutbox($first->id))->handle();
    expect($first->refresh()->status)->toBe('SENT');

    $second = $service->enqueueAccessCode($portalUser);
    expect($second->id)->not->toBe($first->id);
});

it('access-code dedup: CANCELLED historical does not block future request', function () {
    $portalUser = makeD11PortalUser('d11a5');
    $service = app(NimbusNotificationService::class);
    $first = $service->enqueueAccessCode($portalUser);
    $first->update(['status' => 'CANCELLED']);
    $second = $service->enqueueAccessCode($portalUser);
    expect($second->id)->not->toBe($first->id)
        ->and($second->status)->toBe('PENDING');
});

it('access-code dedup: no plaintext persisted during dedup scenarios', function () {
    $portalUser = makeD11PortalUser('d11a6');
    $service = app(NimbusNotificationService::class);
    $first = $service->enqueueAccessCode($portalUser);
    $first->update(['status' => 'SENDING']);
    $second = $service->enqueueAccessCode($portalUser);
    expect($second->id)->toBe($first->id);
    $all = NotificationOutbox::where('type', 'portal_access_code')->get();
    foreach ($all as $o) {
        expect(json_encode($o->toArray()))->not->toMatch('/[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}/');
    }
});

// Missing recipient tests
it('missing recipient: eligible Nimbus permission receives outbox, unrelated admin does not', function () {
    Permission::findOrCreate('nimbus.submissions.view', 'web');
    // Clean
    User::query()->each(function ($u) {
        try {
            $u->revokePermissionTo('nimbus.submissions.view');
        } catch (Throwable $e) {
        }
    });
    try {
        Role::findByName('admin', 'web')->revokePermissionTo('nimbus.submissions.view');
    } catch (Throwable $e) {
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $nimbusAdmin = User::factory()->create(['email' => 'd11-nimbus-eligible@example.com']);
    $nimbusAdmin->assignRole('admin');
    $nimbusAdmin->givePermissionTo('nimbus.submissions.view');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $unrelated = User::factory()->create(['email' => 'd11-unrelated@example.com']);
    $unrelated->assignRole('admin');

    $service = app(NimbusNotificationService::class);
    $recipients = $service->resolveAdminRecipients();
    expect($recipients->pluck('email'))->toContain('d11-nimbus-eligible@example.com')
        ->and($recipients->pluck('email'))->not->toContain('d11-unrelated@example.com');
});

it('missing recipient: no eligible produces no outbox but exactly one audit and no PII', function () {
    Permission::findOrCreate('nimbus.submissions.view', 'web');
    // Ensure no eligible
    User::query()->each(function ($u) {
        try {
            $u->revokePermissionTo('nimbus.submissions.view');
        } catch (Throwable $e) {
        }
        $u->roles()->detach();
    });
    try {
        $role = Role::findByName('admin', 'web');
        $role->revokePermissionTo('nimbus.submissions.view');
    } catch (Throwable $e) {
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $portalUser = makeD11PortalUser('d11miss');
    $sub = makeD11Submission($portalUser, Submission::STATUS_PENDING);
    $service = app(NimbusNotificationService::class);

    $beforeOutbox = NotificationOutbox::count();
    $beforeAudit = Activity::where('description', 'nimbus.notification.no_eligible_recipient')->count();

    $result = $service->enqueueSubmissionReceived($sub);
    expect($result)->toBeNull()
        ->and(NotificationOutbox::count())->toBe($beforeOutbox);

    $audit = Activity::where('description', 'nimbus.notification.no_eligible_recipient')->latest('id')->first();
    expect($audit)->not->toBeNull()
        ->and($audit->properties['notification_type'] ?? null)->toBe('submission_received')
        ->and($audit->properties['required_permission'] ?? null)->toBe('nimbus.submissions.view')
        ->and($audit->properties['submission_id'] ?? null)->toBe($sub->id);
    // No PII
    $propsJson = json_encode($audit->properties);
    expect($propsJson)->not->toContain($portalUser->email)
        ->and($propsJson)->not->toMatch('/\d{3}\.\d{3}\.\d{3}-\d{2}/')
        ->and($propsJson)->not->toMatch('/[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}/');

    // Retry same business event should not create duplicate audit noise
    $service->enqueueSubmissionReceived($sub);
    expect(Activity::where('description', 'nimbus.notification.no_eligible_recipient')->count())->toBe($beforeAudit + 1);
});

it('missing recipient: disabled setting produces no outbox and no missing-recipient audit', function () {
    Permission::findOrCreate('nimbus.submissions.view', 'web');
    User::query()->each(function ($u) {
        try {
            $u->revokePermissionTo('nimbus.submissions.view');
        } catch (Throwable $e) {
        }
        $u->roles()->detach();
    });
    try {
        Role::findByName('admin', 'web')->revokePermissionTo('nimbus.submissions.view');
    } catch (Throwable $e) {
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    NotificationSetting::setValues(['portal.notify.new_submission' => '0']);
    $portalUser = makeD11PortalUser('d11dis');
    $sub = makeD11Submission($portalUser, Submission::STATUS_PENDING);
    $beforeAudit = Activity::where('description', 'nimbus.notification.no_eligible_recipient')->count();
    $service = app(NimbusNotificationService::class);
    $result = $service->enqueueSubmissionReceived($sub);
    expect($result)->toBeNull();
    expect(Activity::where('description', 'nimbus.notification.no_eligible_recipient')->count())->toBe($beforeAudit);
    // Reset
    NotificationSetting::setValues(['portal.notify.new_submission' => '1']);
});

it('missing recipient: correction response also audited when no eligible', function () {
    Permission::findOrCreate('nimbus.submissions.view', 'web');
    User::query()->each(function ($u) {
        try {
            $u->revokePermissionTo('nimbus.submissions.view');
        } catch (Throwable $e) {
        }
        $u->roles()->detach();
    });
    try {
        Role::findByName('admin', 'web')->revokePermissionTo('nimbus.submissions.view');
    } catch (Throwable $e) {
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $portalUser = makeD11PortalUser('d11miss2');
    $sub = makeD11Submission($portalUser, Submission::STATUS_NEEDS_CORRECTION);
    // Create a history for response
    $sub->statusHistories()->create(['old_status' => Submission::STATUS_NEEDS_CORRECTION, 'new_status' => Submission::STATUS_UNDER_REVIEW, 'actor_type' => null, 'actor_id' => null]);

    $service = app(NimbusNotificationService::class);
    $result = $service->enqueueCorrectionResponseReceived($sub);
    expect($result)->toBeNull();
    $audit = Activity::where('description', 'nimbus.notification.no_eligible_recipient')->latest('id')->first();
    expect($audit->properties['notification_type'] ?? null)->toBe('correction_response_received');
});
