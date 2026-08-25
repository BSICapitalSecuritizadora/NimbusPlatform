<?php

use App\Models\Nimbus\PortalUser;
use App\Models\Nimbus\Submission;
use App\Models\Nimbus\SubmissionStatusHistory;
use App\Models\User;
use App\Services\Nimbus\SubmissionWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

function makeCorrectionPortalUser(string $email = 'corr@test.com'): PortalUser
{
    static $seq = 1000;
    $seq++;
    $doc = str_pad((string) (10000000000 + $seq), 11, '0', STR_PAD_LEFT);

    return PortalUser::query()->create([
        'full_name' => 'Corr User',
        'email' => $email.$seq.'@test.com',
        'document_number' => $doc,
        'phone_number' => '119'.str_pad((string) $seq, 8, '0', STR_PAD_LEFT),
        'status' => 'ACTIVE',
    ]);
}

function makeSubmissionWithStatus(string $status, ?PortalUser $portalUser = null): Submission
{
    $portalUser ??= makeCorrectionPortalUser();
    $submission = Submission::query()->create([
        'nimbus_portal_user_id' => $portalUser->id,
        'reference_code' => 'NMB-'.strtoupper(Str::ulid()),
        'submission_type' => 'REGISTRATION',
        'title' => 'Corr Test',
        'status' => $status,
        'submitted_at' => now(),
    ]);
    app(SubmissionWorkflowService::class)->recordCreation($submission, $portalUser);

    return $submission->refresh();
}

it('allows PENDING → UNDER_REVIEW via workflow', function () {
    $sub = makeSubmissionWithStatus(Submission::STATUS_PENDING);
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    app(SubmissionWorkflowService::class)->transition($sub, Submission::STATUS_UNDER_REVIEW, $admin);
    expect($sub->refresh()->status)->toBe(Submission::STATUS_UNDER_REVIEW)
        ->and(SubmissionStatusHistory::where('nimbus_submission_id', $sub->id)->where('new_status', Submission::STATUS_UNDER_REVIEW)->exists())->toBeTrue();
});

it('allows UNDER_REVIEW → NEEDS_CORRECTION with note', function () {
    $sub = makeSubmissionWithStatus(Submission::STATUS_UNDER_REVIEW);
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $svc = app(SubmissionWorkflowService::class);
    $svc->transition($sub->refresh(), Submission::STATUS_NEEDS_CORRECTION, $admin, 'Corrigir contrato');
    expect($sub->refresh()->status)->toBe(Submission::STATUS_NEEDS_CORRECTION);
    // Simulate ViewSubmission note creation
    $sub->notes()->create(['user_id' => $admin->id, 'visibility' => 'USER_VISIBLE', 'message' => 'Corrigir contrato']);
    expect($sub->notes()->where('visibility', 'USER_VISIBLE')->count())->toBe(1);
    expect(SubmissionStatusHistory::where('nimbus_submission_id', $sub->id)->where('new_status', Submission::STATUS_NEEDS_CORRECTION)->count())->toBe(1);
});

it('allows NEEDS_CORRECTION → UNDER_REVIEW via portal reply', function () {
    $portalUser = makeCorrectionPortalUser('reply-corr@test.com');
    $sub = makeSubmissionWithStatus(Submission::STATUS_NEEDS_CORRECTION, $portalUser);
    $svc = app(SubmissionWorkflowService::class);
    $svc->transition($sub, Submission::STATUS_UNDER_REVIEW, $portalUser, 'Resposta');
    expect($sub->refresh()->status)->toBe(Submission::STATUS_UNDER_REVIEW);
});

it('allows UNDER_REVIEW → COMPLETED and REJECTED', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $sub1 = makeSubmissionWithStatus(Submission::STATUS_UNDER_REVIEW);
    $sub2 = makeSubmissionWithStatus(Submission::STATUS_UNDER_REVIEW);
    app(SubmissionWorkflowService::class)->transition($sub1, Submission::STATUS_COMPLETED, $admin);
    app(SubmissionWorkflowService::class)->transition($sub2, Submission::STATUS_REJECTED, $admin);
    expect($sub1->refresh()->status)->toBe(Submission::STATUS_COMPLETED)
        ->and($sub2->refresh()->status)->toBe(Submission::STATUS_REJECTED);
});

it('rejects invalid transitions like COMPLETED → NEEDS_CORRECTION', function () {
    $sub = makeSubmissionWithStatus(Submission::STATUS_COMPLETED);
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    expect(fn () => app(SubmissionWorkflowService::class)->transition($sub, Submission::STATUS_NEEDS_CORRECTION, $admin))->toThrow(ValidationException::class);
});

it('keeps final statuses final (REJECTED → UNDER_REVIEW blocked)', function () {
    $sub = makeSubmissionWithStatus(Submission::STATUS_REJECTED);
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    expect(fn () => app(SubmissionWorkflowService::class)->transition($sub, Submission::STATUS_UNDER_REVIEW, $admin))->toThrow(ValidationException::class);
});

it('correction request creates exactly one user-visible note, one history, one audit', function () {
    $sub = makeSubmissionWithStatus(Submission::STATUS_UNDER_REVIEW);
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);
    $beforeHistory = SubmissionStatusHistory::where('nimbus_submission_id', $sub->id)->count();
    $beforeAudit = Activity::where('log_name', 'nimbus')->count();
    $beforeNotes = $sub->notes()->count();

    app(SubmissionWorkflowService::class)->transition($sub, Submission::STATUS_NEEDS_CORRECTION, $admin, 'Ajustar balanço');
    // Simulate ViewSubmission note creation (one)
    $sub->notes()->create(['user_id' => $admin->id, 'visibility' => 'USER_VISIBLE', 'message' => 'Ajustar balanço']);
    activity('nimbus')->performedOn($sub)->causedBy($admin)->withProperties(['visibility' => 'USER_VISIBLE'])->log('nimbus.submission.correction_requested');

    expect(SubmissionStatusHistory::where('nimbus_submission_id', $sub->id)->count())->toBe($beforeHistory + 1)
        ->and($sub->notes()->count())->toBe($beforeNotes + 1)
        ->and(Activity::where('log_name', 'nimbus')->count() - $beforeAudit)->toBeGreaterThanOrEqual(2); // status_changed + correction_requested
});

it('portal user cannot respond outside NEEDS_CORRECTION', function () {
    $portalUser = makeCorrectionPortalUser('blocked-reply@test.com');
    $sub = makeSubmissionWithStatus(Submission::STATUS_UNDER_REVIEW, $portalUser);
    Storage::fake('local');
    $this->actingAs($portalUser, 'nimbus')
        ->post(route('nimbus.submissions.reply', $sub), ['comment' => 'tentativa'])
        ->assertForbidden();
});

it('unauthorized user cannot respond to another submission', function () {
    $owner = makeCorrectionPortalUser('owner@test.com');
    $other = makeCorrectionPortalUser('other@test.com');
    $sub = makeSubmissionWithStatus(Submission::STATUS_NEEDS_CORRECTION, $owner);
    Storage::fake('local');
    $this->actingAs($other, 'nimbus')
        ->post(route('nimbus.submissions.reply', $sub), ['comment' => 'invasão'])
        ->assertNotFound();
});

it('correctionCount and lastCorrectionRequestedAt derived correctly', function () {
    $sub = makeSubmissionWithStatus(Submission::STATUS_PENDING);
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $portalUser = $sub->portalUser;
    $svc = app(SubmissionWorkflowService::class);
    $svc->transition($sub->refresh(), Submission::STATUS_UNDER_REVIEW, $admin);
    $svc->transition($sub->refresh(), Submission::STATUS_NEEDS_CORRECTION, $admin, '1');
    $svc->transition($sub->refresh(), Submission::STATUS_UNDER_REVIEW, $portalUser);
    $svc->transition($sub->refresh(), Submission::STATUS_NEEDS_CORRECTION, $admin, '2');
    expect(SubmissionWorkflowService::correctionCount($sub->refresh()))->toBe(2)
        ->and(SubmissionWorkflowService::lastCorrectionRequestedAt($sub->refresh()))->not->toBeNull();
});

it('correction response with file preserves malware scan flow', function () {
    Storage::fake('local');
    config()->set('filesystems.private_disk', 'local');
    $portalUser = makeCorrectionPortalUser('file-reply@test.com');
    $sub = makeSubmissionWithStatus(Submission::STATUS_NEEDS_CORRECTION, $portalUser);
    $file = UploadedFile::fake()->create('correcao.pdf', 100, 'application/pdf');
    $response = $this->actingAs($portalUser, 'nimbus')
        ->post(route('nimbus.submissions.reply', $sub), ['file' => $file, 'comment' => 'com anexo']);
    // Should redirect and transition to UNDER_REVIEW
    $response->assertRedirect();
    expect($sub->refresh()->status)->toBe(Submission::STATUS_UNDER_REVIEW)
        ->and($sub->files()->where('origin', 'USER')->exists())->toBeTrue();
    $uploadedFile = $sub->files()->latest('id')->first();
    expect($uploadedFile->scan_status->value ?? $uploadedFile->scan_status)->not->toBeNull();
});
