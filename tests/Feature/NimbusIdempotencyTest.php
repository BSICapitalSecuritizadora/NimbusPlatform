<?php

use App\Filament\Resources\Nimbus\Submissions\Pages\ViewSubmission;
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
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

function makeIdempPortalUser(string $prefix = 'idem'): PortalUser
{
    static $seq = 11000;
    $seq++;

    return PortalUser::query()->create([
        'full_name' => 'Idemp User',
        'email' => $prefix.$seq.'@example.com',
        'document_number' => str_pad((string) (60000000000 + $seq), 11, '0', STR_PAD_LEFT),
        'phone_number' => '119'.str_pad((string) $seq, 8, '0', STR_PAD_LEFT),
        'status' => 'ACTIVE',
    ]);
}

function makeIdempSubmission(PortalUser $user, string $status = 'PENDING'): Submission
{
    $sub = Submission::query()->create([
        'nimbus_portal_user_id' => $user->id,
        'reference_code' => 'NMB-'.strtoupper(Str::ulid()),
        'submission_type' => 'REGISTRATION',
        'title' => 'Idemp Test',
        'status' => $status,
        'submitted_at' => now(),
    ]);
    app(SubmissionWorkflowService::class)->recordCreation($sub, $user);

    return $sub->refresh();
}

function makeNimbusAdmin(string $email): User
{
    Permission::findOrCreate('nimbus.submissions.view', 'web');
    $u = User::factory()->create(['email' => $email]);
    $u->assignRole('admin');
    $u->givePermissionTo('nimbus.submissions.view');

    return $u;
}

it('correction request repeatability: two cycles have different correlation, same event retry is idempotent', function () {
    Mail::fake();
    $admin = makeNimbusAdmin('idem-corr@example.com');
    $portalUser = makeIdempPortalUser('idemcorr');
    $sub = makeIdempSubmission($portalUser, Submission::STATUS_UNDER_REVIEW);

    $this->actingAs($admin);
    // First correction
    Livewire::test(ViewSubmission::class, ['record' => $sub->getRouteKey()])
        ->mountAction('alterar_situacao')
        ->setActionData(['status' => Submission::STATUS_UNDER_REVIEW, 'visibility' => 'USER_VISIBLE', 'note' => 'Corrija 1'])
        ->callMountedAction(arguments: ['intent' => 'request_correction'])
        ->assertHasNoActionErrors();

    $firstOutbox = NotificationOutbox::where('type', 'correction_requested')->where('payload_json->submission_id', $sub->id)->latest('id')->first();
    expect($firstOutbox)->not->toBeNull();
    $firstCorrelation = $firstOutbox->correlation_id;

    // Portal response back to UNDER_REVIEW
    $this->actingAs($portalUser, 'nimbus')
        ->post(route('nimbus.submissions.reply', $sub->fresh()), ['comment' => 'resp 1'])
        ->assertRedirect();

    // Second correction cycle
    $this->actingAs($admin);
    Livewire::test(ViewSubmission::class, ['record' => $sub->fresh()->getRouteKey()])
        ->mountAction('alterar_situacao')
        ->setActionData(['status' => Submission::STATUS_UNDER_REVIEW, 'visibility' => 'USER_VISIBLE', 'note' => 'Corrija 2'])
        ->callMountedAction(arguments: ['intent' => 'request_correction'])
        ->assertHasNoActionErrors();

    $secondOutbox = NotificationOutbox::where('type', 'correction_requested')->where('payload_json->submission_id', $sub->id)->orderBy('id', 'desc')->first();
    expect($secondOutbox)->not->toBeNull()
        ->and($secondOutbox->id)->not->toBe($firstOutbox->id)
        ->and($secondOutbox->correlation_id)->not->toBe($firstCorrelation);

    // Retry same first correction: should return same outbox (idempotent)
    $service = app(NimbusNotificationService::class);
    $historyFirst = $sub->fresh()->statusHistories()->where('old_status', Submission::STATUS_UNDER_REVIEW)->where('new_status', Submission::STATUS_NEEDS_CORRECTION)->reorder()->orderBy('id')->first();
    $retryFirst = $service->enqueueCorrectionRequested($sub->fresh(), 'Corrija 1', $historyFirst->id);
    expect($retryFirst->id)->toBe($firstOutbox->id);

    $historySecond = $sub->fresh()->statusHistories()->where('old_status', Submission::STATUS_UNDER_REVIEW)->where('new_status', Submission::STATUS_NEEDS_CORRECTION)->reorder()->orderByDesc('id')->first();
    $retrySecond = $service->enqueueCorrectionRequested($sub->fresh(), 'Corrija 2', $historySecond->id);
    expect($retrySecond->id)->toBe($secondOutbox->id);
});

it('correction response repeatability: two cycles with different correlation', function () {
    $admin = makeNimbusAdmin('idem-resp@example.com');
    $portalUser = makeIdempPortalUser('idemresp');
    $sub = makeIdempSubmission($portalUser, Submission::STATUS_UNDER_REVIEW);

    // First correction request
    $this->actingAs($admin);
    Livewire::test(ViewSubmission::class, ['record' => $sub->getRouteKey()])
        ->mountAction('alterar_situacao')
        ->setActionData(['status' => Submission::STATUS_UNDER_REVIEW, 'visibility' => 'USER_VISIBLE', 'note' => 'Corrija A'])
        ->callMountedAction(arguments: ['intent' => 'request_correction']);

    // First response
    $this->actingAs($portalUser, 'nimbus')
        ->post(route('nimbus.submissions.reply', $sub->fresh()), ['comment' => 'resp A'])
        ->assertRedirect();
    $firstResp = NotificationOutbox::where('type', 'correction_response_received')->where('payload_json->submission_id', $sub->id)->latest('id')->first();
    expect($firstResp)->not->toBeNull();
    $firstCorr = $firstResp->correlation_id;

    // Second correction + response
    $this->actingAs($admin);
    Livewire::test(ViewSubmission::class, ['record' => $sub->fresh()->getRouteKey()])
        ->mountAction('alterar_situacao')
        ->setActionData(['status' => Submission::STATUS_UNDER_REVIEW, 'visibility' => 'USER_VISIBLE', 'note' => 'Corrija B'])
        ->callMountedAction(arguments: ['intent' => 'request_correction']);

    $this->actingAs($portalUser, 'nimbus')
        ->post(route('nimbus.submissions.reply', $sub->fresh()), ['comment' => 'resp B'])
        ->assertRedirect();
    $secondResp = NotificationOutbox::where('type', 'correction_response_received')->where('payload_json->submission_id', $sub->id)->orderBy('id', 'desc')->first();
    expect($secondResp->id)->not->toBe($firstResp->id)
        ->and($secondResp->correlation_id)->not->toBe($firstCorr);

    // Retry same response event should be idempotent
    $service = app(NimbusNotificationService::class);
    $history = $sub->fresh()->statusHistories()->where('old_status', Submission::STATUS_NEEDS_CORRECTION)->where('new_status', Submission::STATUS_UNDER_REVIEW)->reorder()->orderByDesc('id')->first();
    $retry = $service->enqueueCorrectionResponseReceived($sub->fresh(), $history->id);
    expect($retry->id)->toBe($secondResp->id);
});

it('access-code repeatability: immediate double-click reuses, after window new outbox', function () {
    $portalUser = makeIdempPortalUser('idemac');
    $service = app(NimbusNotificationService::class);

    $first = $service->enqueueAccessCode($portalUser);
    expect($first)->not->toBeNull();
    $firstId = $first->id;

    // Immediate second within 30s should reuse
    $second = $service->enqueueAccessCode($portalUser);
    expect($second->id)->toBe($firstId);

    // Travel past dedup window (31 seconds) and request again — should be new
    // Clear permission cache after travel
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->travel(31)->seconds();
    $third = $service->enqueueAccessCode($portalUser);
    expect($third->id)->not->toBe($firstId)
        ->and($third->correlation_id)->not->toBe($first->correlation_id);

    // Both remain security-safe (no code in payload)
    expect(json_encode($first->payload_json))->not->toMatch('/[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}/');
    expect(json_encode($third->payload_json))->not->toMatch('/[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}/');

    // After processing first, second new request still creates new (not dedup with SENT)
    Mail::fake();
    (new ProcessNimbusNotificationOutbox($first->id))->handle();
    $this->travel(1)->seconds();
    $fourth = $service->enqueueAccessCode($portalUser);
    // Since first is now SENT, not PENDING, dedup should not return first
    expect($fourth->id)->not->toBe($firstId);
});

it('internal recipient resolution: only Nimbus permission receives, unrelated admin does not', function () {
    // Clean slate: ensure no leftover permissions from previous tests
    Permission::findOrCreate('nimbus.submissions.view', 'web');
    // Detach all existing nimbus permissions to isolate
    User::query()->each(function ($u) {
        try {
            $u->revokePermissionTo('nimbus.submissions.view');
        } catch (Throwable $e) {
        }
    });
    $nimbusAdmin = User::factory()->create(['email' => 'nimbus-perm@example.com']);
    $nimbusAdmin->assignRole('admin');
    $nimbusAdmin->givePermissionTo('nimbus.submissions.view');
    // Ensure role itself does not have permission, so unrelated via role does not get it
    try {
        $role = Role::findByName('admin', 'web');
        $role->revokePermissionTo('nimbus.submissions.view');
    } catch (Throwable $e) {
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $unrelated = User::factory()->create(['email' => 'unrelated@example.com']);
    $unrelated->assignRole('admin');
    // No permission

    $service = app(NimbusNotificationService::class);
    $recipients = $service->resolveAdminRecipients();
    expect($recipients->pluck('email'))->toContain('nimbus-perm@example.com')
        ->and($recipients->pluck('email'))->not->toContain('unrelated@example.com');

    // Enqueue submission received should go to nimbus admin only
    $portalUser = makeIdempPortalUser('idemrecip');
    $sub = makeIdempSubmission($portalUser, Submission::STATUS_PENDING);
    $outbox = $service->enqueueSubmissionReceived($sub);
    expect($outbox)->not->toBeNull()
        ->and($outbox->recipient_email)->toBe('nimbus-perm@example.com');
});

it('no eligible recipient produces safe no-outbox behavior', function () {
    // Ensure no user has permission
    Permission::findOrCreate('nimbus.submissions.view', 'web');
    // Remove all permissions from users
    User::query()->each(function ($u) {
        $u->permissions()->detach();
        $u->roles()->detach();
    });
    $service = app(NimbusNotificationService::class);
    $recipients = $service->resolveAdminRecipients();
    expect($recipients)->toBeEmpty();

    $portalUser = makeIdempPortalUser('idemnorec');
    $sub = makeIdempSubmission($portalUser, Submission::STATUS_PENDING);
    $outbox = $service->enqueueSubmissionReceived($sub);
    expect($outbox)->toBeNull();
    // No outbox created, no broad mail to unrelated admins
    expect(NotificationOutbox::where('type', 'submission_received')->where('payload_json->submission_id', $sub->id)->count())->toBe(0);
});

it('one-time events remain idempotent per submission', function () {
    $portalUser = makeIdempPortalUser('idemonce');
    $sub = makeIdempSubmission($portalUser, Submission::STATUS_PENDING);
    $admin = makeNimbusAdmin('idem-once@example.com');
    $service = app(NimbusNotificationService::class);

    $first = $service->enqueueCompleted($sub);
    $second = $service->enqueueCompleted($sub);
    expect($first->id)->toBe($second->id);

    $firstRej = $service->enqueueRejected($sub);
    $secondRej = $service->enqueueRejected($sub);
    // Completed and rejected are different types, should be different outboxes, but same type repeated is same
    expect($firstRej->id)->toBe($secondRej->id)
        ->and($first->correlation_id)->not->toBe($firstRej->correlation_id);
});

it('access-link setting is mandatory and cannot be disabled', function () {
    NotificationSetting::setValues(['portal.notify.access_link' => '0']);
    $portalUser = makeIdempPortalUser('idemset');
    $service = app(NimbusNotificationService::class);
    $outbox = $service->enqueueAccessCode($portalUser);
    expect($outbox)->not->toBeNull();
    // Direct check that service considers it mandatory
    expect($service->isMandatory(NimbusNotificationService::TYPE_ACCESS_CODE))->toBeTrue();
    // Reset
    NotificationSetting::setValues(['portal.notify.access_link' => '1']);
});
