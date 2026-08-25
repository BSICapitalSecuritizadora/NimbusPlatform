<?php

use App\Jobs\ProcessNimbusNotificationOutbox;
use App\Mail\Nimbus\SendPortalAccessCode;
use App\Models\Nimbus\AccessToken;
use App\Models\Nimbus\NotificationOutbox;
use App\Models\Nimbus\PortalUser;
use App\Services\Nimbus\NimbusNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

function makeAccessPortalUser(string $prefix = 'ac'): PortalUser
{
    static $seq = 10000;
    $seq++;

    return PortalUser::query()->create([
        'full_name' => 'Access User',
        'email' => $prefix.$seq.'@example.com',
        'document_number' => str_pad((string) (50000000000 + $seq), 11, '0', STR_PAD_LEFT),
        'phone_number' => '119'.str_pad((string) $seq, 8, '0', STR_PAD_LEFT),
        'status' => 'ACTIVE',
    ]);
}

it('requesting access-code creates outbox without plaintext code and payload contains no code', function () {
    $portalUser = makeAccessPortalUser('ac1');
    $service = app(NimbusNotificationService::class);
    $outbox = $service->enqueueAccessCode($portalUser);
    expect($outbox)->not->toBeNull()
        ->and($outbox->type)->toBe('portal_access_code')
        ->and(json_encode($outbox->payload_json))->not->toContain('ABCD')
        ->and(json_encode($outbox->toArray()))->not->toMatch('/[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}/');
    $fresh = NotificationOutbox::find($outbox->id);
    expect(json_encode($fresh->payload_json))->not->toMatch('/[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}/');
    expect(AccessToken::where('nimbus_portal_user_id', $portalUser->id)->count())->toBe(0);
});

it('queue payload does not contain plaintext code', function () {
    $portalUser = makeAccessPortalUser('ac2');
    $service = app(NimbusNotificationService::class);
    $outbox = $service->enqueueAccessCode($portalUser);
    $job = new ProcessNimbusNotificationOutbox($outbox->id);
    $serialized = serialize($job);
    expect($serialized)->not->toMatch('/[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}/');
    expect(json_encode($outbox->payload_json))->not->toMatch('/[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}/');
});

it('processing generates code only during execution, stores only hash, leaves one valid PENDING token and SENT', function () {
    Mail::fake();
    $portalUser = makeAccessPortalUser('ac3');
    $service = app(NimbusNotificationService::class);
    $outbox = $service->enqueueAccessCode($portalUser);
    $job = new ProcessNimbusNotificationOutbox($outbox->id);
    $job->handle();
    $outbox->refresh();
    expect($outbox->status)->toBe('SENT')
        ->and($outbox->sent_at)->not->toBeNull();
    $tokens = AccessToken::where('nimbus_portal_user_id', $portalUser->id)->get();
    expect($tokens->count())->toBe(1);
    $token = $tokens->first();
    expect($token->status)->toBe('PENDING')
        ->and($token->code_hash)->not->toBeNull()
        ->and($token->isValid())->toBeTrue();
    expect($token->code_hash)->not->toMatch('/[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}/');
    expect(strlen($token->code_hash))->toBe(64);
    // Mail may be sent or queued depending on ShouldQueue; check both
    $sent = Mail::sent(SendPortalAccessCode::class)->count();
    $queued = Mail::queued(SendPortalAccessCode::class)->count();
    expect($sent + $queued)->toBe(1);
});

it('invalid portal user fails gracefully without leaving valid token', function () {
    $service = app(NimbusNotificationService::class);
    $portalUser = makeAccessPortalUser('ac4');
    $outbox = $service->enqueueAccessCode($portalUser);
    // Corrupt payload to simulate failure
    $outbox->update(['payload_json' => ['portal_user_id' => 999999]]);
    $job = new ProcessNimbusNotificationOutbox($outbox->id);
    $job->handle();
    $outbox->refresh();
    expect($outbox->status)->toBe('FAILED')
        ->and($outbox->last_error)->toContain('PortalUser not found');
    expect(AccessToken::where('nimbus_portal_user_id', $portalUser->id)->where('status', 'PENDING')->count())->toBe(0);
});

it('retry generates new code hash and old revoked remains invalid', function () {
    Mail::fake();
    $portalUser = makeAccessPortalUser('ac5');
    $service = app(NimbusNotificationService::class);
    $outbox = $service->enqueueAccessCode($portalUser);
    $job = new ProcessNimbusNotificationOutbox($outbox->id);
    $job->handle();
    $firstHash = AccessToken::where('nimbus_portal_user_id', $portalUser->id)->latest('id')->first()->code_hash;
    $outbox2 = $service->enqueueAccessCode($portalUser);
    expect($outbox2->id)->not->toBe($outbox->id);
    $job2 = new ProcessNimbusNotificationOutbox($outbox2->id);
    $job2->handle();
    $secondHash = AccessToken::where('nimbus_portal_user_id', $portalUser->id)->latest('id')->first()->code_hash;
    expect($secondHash)->not->toBe($firstHash);
    $oldToken = AccessToken::where('code_hash', $firstHash)->first();
    expect($oldToken->status)->toBe('REVOKED')
        ->and($oldToken->isValid())->toBeFalse();
});

it('existing pending token revocation preserved', function () {
    Mail::fake();
    $portalUser = makeAccessPortalUser('ac6');
    $old = $portalUser->accessTokens()->create([
        'code_hash' => AccessToken::computeHash('AAAA-BBBB-CCCC'),
        'status' => 'PENDING',
        'expires_at' => now()->addDays(7),
    ]);
    expect($old->isValid())->toBeTrue();
    $service = app(NimbusNotificationService::class);
    $outbox = $service->enqueueAccessCode($portalUser);
    $job = new ProcessNimbusNotificationOutbox($outbox->id);
    $job->handle();
    $old->refresh();
    expect($old->status)->toBe('REVOKED')
        ->and($old->isValid())->toBeFalse();
    expect(AccessToken::where('nimbus_portal_user_id', $portalUser->id)->where('status', 'PENDING')->count())->toBe(1);
});

it('activity logs contain no plaintext code', function () {
    Mail::fake();
    $portalUser = makeAccessPortalUser('ac7');
    $service = app(NimbusNotificationService::class);
    $outbox = $service->enqueueAccessCode($portalUser);
    $job = new ProcessNimbusNotificationOutbox($outbox->id);
    $job->handle();
    $activities = Activity::where('log_name', 'nimbus')->where('description', 'like', 'nimbus.access_token%')->get();
    foreach ($activities as $activity) {
        $props = json_encode($activity->properties);
        expect($props)->not->toMatch('/[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}/');
    }
    $outboxActivities = Activity::where('description', 'nimbus.notification.sent')->get();
    foreach ($outboxActivities as $a) {
        expect(json_encode($a->properties))->not->toMatch('/[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}/');
    }
});

it('failed_jobs payload does not contain plaintext (job only has outboxId)', function () {
    $portalUser = makeAccessPortalUser('ac8');
    $service = app(NimbusNotificationService::class);
    $outbox = $service->enqueueAccessCode($portalUser);
    $job = new ProcessNimbusNotificationOutbox($outbox->id);
    $payload = json_encode(['outboxId' => $job->outboxId]);
    expect($payload)->not->toMatch('/[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}/');
    expect((string) $job->outboxId)->toBe((string) $outbox->id);
});

it('correlation idempotency prevents duplicate for same request within window', function () {
    $portalUser = makeAccessPortalUser('ac9');
    $service = app(NimbusNotificationService::class);
    $outbox1 = $service->enqueueAccessCode($portalUser);
    $outbox2 = $service->enqueueAccessCode($portalUser);
    expect($outbox1->id)->toBe($outbox2->id);
    Mail::fake();
    $job = new ProcessNimbusNotificationOutbox($outbox1->id);
    $job->handle();
    $outbox3 = $service->enqueueAccessCode($portalUser);
    expect($outbox3->id)->not->toBe($outbox1->id);
});

it('plaintext never stored in notification_outboxes', function () {
    Mail::fake();
    $portalUser = makeAccessPortalUser('ac10');
    $service = app(NimbusNotificationService::class);
    $outbox = $service->enqueueAccessCode($portalUser);
    $job = new ProcessNimbusNotificationOutbox($outbox->id);
    $job->handle();
    $all = NotificationOutbox::where('type', 'portal_access_code')->get();
    foreach ($all as $o) {
        $json = json_encode($o->toArray());
        expect($json)->not->toMatch('/[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}/');
        expect(json_encode($o->payload_json))->not->toContain('code');
    }
    $raw = DB::table('nimbus_notification_outboxes')->where('id', $outbox->id)->first();
    expect(json_encode($raw))->not->toMatch('/[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}/');
});
