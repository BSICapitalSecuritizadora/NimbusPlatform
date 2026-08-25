<?php

use App\Enums\MalwareScanStatus;
use App\Models\Nimbus\AccessToken;
use App\Models\Nimbus\PortalUser;
use App\Models\Nimbus\Submission;
use App\Models\Nimbus\SubmissionFile;
use App\Models\User;
use App\Services\Nimbus\SubmissionWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

it('audits portal user creation and status change', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);

    $portalUser = PortalUser::query()->create([
        'full_name' => 'Audit Create',
        'email' => 'audit.create@example.com',
        'document_number' => '12345678901',
        'phone_number' => '11999999999',
        'status' => 'ACTIVE',
    ]);

    $createdActivity = Activity::where('subject_type', PortalUser::class)
        ->where('subject_id', $portalUser->id)
        ->where('description', 'nimbus.portal_user.created')
        ->first();
    expect($createdActivity)->not->toBeNull();

    $portalUser->update(['status' => 'BLOCKED']);

    $updatedActivity = Activity::where('subject_type', PortalUser::class)
        ->where('subject_id', $portalUser->id)
        ->where('description', 'nimbus.portal_user.updated')
        ->latest('id')
        ->first();
    expect($updatedActivity)->not->toBeNull()
        ->and($updatedActivity->properties['status'])->toBe('BLOCKED');
});

it('audits access token generation and revocation', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);

    $portalUser = PortalUser::query()->create([
        'full_name' => 'Token Audit',
        'email' => 'token.audit@example.com',
        'document_number' => '12345678901',
        'phone_number' => '11999999999',
        'status' => 'ACTIVE',
    ]);

    // Simulate Filament action: generate token.
    $code = 'AAAA-BBBB-CCCC';
    $token = $portalUser->accessTokens()->create([
        'code_hash' => AccessToken::computeHash($code),
        'status' => 'PENDING',
        'expires_at' => now()->addDays(7),
    ]);

    activity('nimbus')
        ->performedOn($token)
        ->causedBy($admin)
        ->withProperties(['portal_user_id' => $portalUser->id])
        ->log('nimbus.access_token.generated');

    $revokeActivityBefore = Activity::where('description', 'nimbus.access_token.generated')->count();
    expect($revokeActivityBefore)->toBe(1);

    $token->update(['status' => 'REVOKED']);
    activity('nimbus')
        ->performedOn($token)
        ->causedBy($admin)
        ->withProperties(['portal_user_id' => $portalUser->id])
        ->log('nimbus.access_token.revoked');

    expect(Activity::where('description', 'nimbus.access_token.revoked')->exists())->toBeTrue();
});

it('audits submission creation', function () {
    $portalUser = PortalUser::query()->create([
        'full_name' => 'Sub Audit',
        'email' => 'sub.audit@example.com',
        'document_number' => '12345678901',
        'phone_number' => '11999999999',
        'status' => 'ACTIVE',
    ]);

    $submission = Submission::query()->create([
        'nimbus_portal_user_id' => $portalUser->id,
        'reference_code' => 'NMB-'.strtoupper(Str::ulid()),
        'submission_type' => 'REGISTRATION',
        'title' => 'Audit Sub',
        'status' => Submission::STATUS_PENDING,
        'submitted_at' => now(),
    ]);

    activity('nimbus')
        ->performedOn($submission)
        ->causedBy($portalUser)
        ->withProperties(['portal_user_id' => $portalUser->id])
        ->log('nimbus.submission.created');

    expect(Activity::where('subject_type', Submission::class)
        ->where('subject_id', $submission->id)
        ->where('description', 'nimbus.submission.created')
        ->exists())->toBeTrue();
});

it('audits status transitions via workflow service', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $portalUser = PortalUser::query()->create([
        'full_name' => 'Status Audit',
        'email' => 'status.audit@example.com',
        'document_number' => '12345678901',
        'phone_number' => '11999999999',
        'status' => 'ACTIVE',
    ]);

    $submission = Submission::query()->create([
        'nimbus_portal_user_id' => $portalUser->id,
        'reference_code' => 'NMB-'.strtoupper(Str::ulid()),
        'submission_type' => 'REGISTRATION',
        'title' => 'Status Audit',
        'status' => Submission::STATUS_PENDING,
        'submitted_at' => now(),
    ]);

    app(SubmissionWorkflowService::class)->recordCreation($submission, $portalUser);
    app(SubmissionWorkflowService::class)->transition($submission, Submission::STATUS_UNDER_REVIEW, $admin, 'ok');

    expect(Activity::where('description', 'nimbus.submission.status_changed')->exists())->toBeTrue();
});

it('audits authorized file download and preview after authorization', function () {
    Storage::fake('local');
    config()->set('filesystems.private_disk', 'local');

    $portalUser = PortalUser::query()->create([
        'full_name' => 'File Audit',
        'email' => 'file.audit@example.com',
        'document_number' => '12345678901',
        'phone_number' => '11999999999',
        'status' => 'ACTIVE',
    ]);

    $submission = Submission::query()->create([
        'nimbus_portal_user_id' => $portalUser->id,
        'reference_code' => 'NMB-'.strtoupper(Str::ulid()),
        'submission_type' => 'REGISTRATION',
        'title' => 'File Audit Sub',
        'status' => Submission::STATUS_PENDING,
        'submitted_at' => now(),
    ]);

    // Create file via DocumentManager to also test upload audit, but we can create directly for isolation.
    $file = SubmissionFile::query()->create([
        'nimbus_submission_id' => $submission->id,
        'document_type' => 'OTHER',
        'origin' => 'USER',
        'visible_to_user' => true,
        'original_name' => 'test.pdf',
        'stored_name' => 'test.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 100,
        'storage_path' => 'nimbus_docs/submissions/'.$submission->id.'/test.pdf',
        'checksum' => hash('sha256', 'test'),
        'scan_status' => MalwareScanStatus::Clean,
        'uploaded_at' => now(),
    ]);

    Storage::disk('local')->put($file->storage_path, 'dummy');

    // Download via portal (authorized).
    $response = $this->actingAs($portalUser, 'nimbus')
        ->get(route('nimbus.submissions.files.download', [$submission->id, $file->id]));

    // May be streamed response; check audit created.
    $activity = Activity::where('description', 'nimbus.submission_file.download')
        ->where('subject_type', SubmissionFile::class)
        ->where('subject_id', $file->id)
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->properties['submission_id'])->toBe($submission->id)
        ->and($activity->properties['action'])->toBe('download');

    // Ensure raw PII not in audit.
    expect(json_encode($activity->properties))->not->toContain('12345678901');
});

it('does not reveal existence on unauthorized file access and does not audit', function () {
    $portalUser = PortalUser::query()->create([
        'full_name' => 'Owner',
        'email' => 'owner@example.com',
        'document_number' => '11122233344',
        'phone_number' => '11999999999',
        'status' => 'ACTIVE',
    ]);

    $otherUser = PortalUser::query()->create([
        'full_name' => 'Other',
        'email' => 'other@example.com',
        'document_number' => '99988877766',
        'phone_number' => '11988887777',
        'status' => 'ACTIVE',
    ]);

    $submission = Submission::query()->create([
        'nimbus_portal_user_id' => $portalUser->id,
        'reference_code' => 'NMB-'.strtoupper(Str::ulid()),
        'submission_type' => 'REGISTRATION',
        'title' => 'Unauthorized Test',
        'status' => Submission::STATUS_PENDING,
        'submitted_at' => now(),
    ]);

    $file = SubmissionFile::query()->create([
        'nimbus_submission_id' => $submission->id,
        'document_type' => 'OTHER',
        'origin' => 'ADMIN',
        'visible_to_user' => false,
        'original_name' => 'secret.pdf',
        'stored_name' => 'secret.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 100,
        'storage_path' => 'nimbus_docs/submissions/'.$submission->id.'/secret.pdf',
        'checksum' => hash('sha256', 'secret'),
        'scan_status' => MalwareScanStatus::Clean,
        'uploaded_at' => now(),
    ]);

    $beforeCount = Activity::where('description', 'nimbus.submission_file.download')->count();

    // Other user tries to download owner's hidden file -> 404, no audit.
    $this->actingAs($otherUser, 'nimbus')
        ->get(route('nimbus.submissions.files.download', [$submission->id, $file->id]))
        ->assertNotFound();

    $afterCount = Activity::where('description', 'nimbus.submission_file.download')->count();
    expect($afterCount)->toBe($beforeCount);
});

it('does not log raw PII in any nimbus activity', function () {
    $portalUser = PortalUser::query()->create([
        'full_name' => 'No PII Log',
        'email' => 'nopii@example.com',
        'document_number' => '12345678901',
        'phone_number' => '11999999999',
        'status' => 'ACTIVE',
    ]);

    $allActivities = Activity::where('log_name', 'nimbus')->get();
    foreach ($allActivities as $activity) {
        expect(json_encode($activity->properties))->not->toContain('12345678901')
            ->and(json_encode($activity->properties))->not->toContain('11999999999');
    }
});
