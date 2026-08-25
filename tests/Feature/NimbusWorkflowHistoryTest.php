<?php

use App\Models\Nimbus\PortalUser;
use App\Models\Nimbus\Submission;
use App\Models\Nimbus\SubmissionStatusHistory;
use App\Models\User;
use App\Services\Nimbus\SubmissionWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function createPortalUser(string $email = 'workflow@example.com'): PortalUser
{
    static $seq = 0;
    $seq++;
    // Generate valid 11-digit CPF-like unique via seq padding (not real CPF but digits only for test uniqueness).
    $doc = str_pad((string) (10000000000 + $seq), 11, '0', STR_PAD_LEFT);
    $phone = '119'.str_pad((string) $seq, 8, '0', STR_PAD_LEFT);

    return PortalUser::query()->create([
        'full_name' => 'Workflow User',
        'email' => $email,
        'document_number' => $doc,
        'phone_number' => $phone,
        'status' => 'ACTIVE',
    ]);
}

function createAdmin(): User
{
    $user = User::factory()->create();
    $user->assignRole('admin');

    return $user;
}

function createSubmissionForHistory(?string $status = Submission::STATUS_PENDING): Submission
{
    $portalUser = createPortalUser('sub-'.uniqid().'@example.com');

    $submission = Submission::query()->create([
        'nimbus_portal_user_id' => $portalUser->id,
        'reference_code' => 'NMB-'.strtoupper(Str::ulid()),
        'submission_type' => 'REGISTRATION',
        'title' => 'Teste Workflow',
        'status' => $status,
        'submitted_at' => now(),
    ]);

    // Simulate creation history if service not used in raw create.
    app(SubmissionWorkflowService::class)->recordCreation($submission, $portalUser);

    return $submission->refresh();
}

it('creates initial history on submission creation', function () {
    $portalUser = createPortalUser();
    $submission = Submission::query()->create([
        'nimbus_portal_user_id' => $portalUser->id,
        'reference_code' => 'NMB-'.strtoupper(Str::ulid()),
        'submission_type' => 'REGISTRATION',
        'title' => 'Criacao',
        'status' => Submission::STATUS_PENDING,
        'submitted_at' => now(),
    ]);

    app(SubmissionWorkflowService::class)->recordCreation($submission, $portalUser);

    $history = SubmissionStatusHistory::where('nimbus_submission_id', $submission->id)->get();
    expect($history)->toHaveCount(1)
        ->and($history->first()->old_status)->toBeNull()
        ->and($history->first()->new_status)->toBe(Submission::STATUS_PENDING);
});

it('creates history for PENDING -> UNDER_REVIEW', function () {
    $submission = createSubmissionForHistory(Submission::STATUS_PENDING);
    $admin = createAdmin();
    $service = app(SubmissionWorkflowService::class);

    $service->transition($submission, Submission::STATUS_UNDER_REVIEW, $admin, 'Iniciar análise');

    $history = SubmissionStatusHistory::where('nimbus_submission_id', $submission->id)->orderBy('created_at')->get();
    expect($history)->toHaveCount(2)
        ->and($history->last()->old_status)->toBe(Submission::STATUS_PENDING)
        ->and($history->last()->new_status)->toBe(Submission::STATUS_UNDER_REVIEW)
        ->and($history->last()->actor_type)->toBe(User::class)
        ->and((int) $history->last()->actor_id)->toBe($admin->id)
        ->and($history->last()->reason)->toBe('Iniciar análise');
});

it('creates history for UNDER_REVIEW -> NEEDS_CORRECTION', function () {
    $submission = createSubmissionForHistory(Submission::STATUS_UNDER_REVIEW);
    $admin = createAdmin();
    $service = app(SubmissionWorkflowService::class);

    // Ensure initial history exists for UNDER_REVIEW.
    $service->transition($submission->refresh(), Submission::STATUS_NEEDS_CORRECTION, $admin, 'Corrigir CNPJ');

    $last = SubmissionStatusHistory::where('nimbus_submission_id', $submission->id)->latest('id')->first();
    expect($last->old_status)->toBe(Submission::STATUS_UNDER_REVIEW)
        ->and($last->new_status)->toBe(Submission::STATUS_NEEDS_CORRECTION)
        ->and($last->reason)->toBe('Corrigir CNPJ');
});

it('creates history for NEEDS_CORRECTION -> UNDER_REVIEW via portal reply', function () {
    $portalUser = createPortalUser('reply@example.com');
    $submission = Submission::query()->create([
        'nimbus_portal_user_id' => $portalUser->id,
        'reference_code' => 'NMB-'.strtoupper(Str::ulid()),
        'submission_type' => 'REGISTRATION',
        'title' => 'Correcao Reply',
        'status' => Submission::STATUS_NEEDS_CORRECTION,
        'submitted_at' => now(),
    ]);

    app(SubmissionWorkflowService::class)->recordCreation($submission, $portalUser);

    $service = app(SubmissionWorkflowService::class);
    $service->transition($submission->refresh(), Submission::STATUS_UNDER_REVIEW, $portalUser, 'Resposta com anexo');

    $last = SubmissionStatusHistory::where('nimbus_submission_id', $submission->id)->latest('id')->first();
    expect($last->old_status)->toBe(Submission::STATUS_NEEDS_CORRECTION)
        ->and($last->new_status)->toBe(Submission::STATUS_UNDER_REVIEW)
        ->and($last->actor_type)->toBe(PortalUser::class);
});

it('creates history for COMPLETED and REJECTED transitions', function () {
    $admin = createAdmin();
    $service = app(SubmissionWorkflowService::class);

    $sub1 = createSubmissionForHistory(Submission::STATUS_UNDER_REVIEW);
    $service->transition($sub1, Submission::STATUS_COMPLETED, $admin, 'Aprovado');

    $sub2 = createSubmissionForHistory(Submission::STATUS_UNDER_REVIEW);
    $service->transition($sub2, Submission::STATUS_REJECTED, $admin, 'Rejeitado');

    expect(SubmissionStatusHistory::where('nimbus_submission_id', $sub1->id)->latest('id')->first()->new_status)->toBe(Submission::STATUS_COMPLETED);
    expect(SubmissionStatusHistory::where('nimbus_submission_id', $sub2->id)->latest('id')->first()->new_status)->toBe(Submission::STATUS_REJECTED);
});

it('rejects invalid transitions without creating history', function () {
    $submission = createSubmissionForHistory(Submission::STATUS_PENDING);
    $admin = createAdmin();
    $service = app(SubmissionWorkflowService::class);

    $initialCount = SubmissionStatusHistory::where('nimbus_submission_id', $submission->id)->count();

    expect(fn () => $service->transition($submission, Submission::STATUS_COMPLETED, $admin))->toThrow(ValidationException::class);

    expect(SubmissionStatusHistory::where('nimbus_submission_id', $submission->id)->count())->toBe($initialCount)
        ->and($submission->refresh()->status)->toBe(Submission::STATUS_PENDING);
});

it('does not leave orphan history on failed transaction', function () {
    $submission = createSubmissionForHistory(Submission::STATUS_PENDING);
    $admin = createAdmin();

    $initialCount = SubmissionStatusHistory::count();

    try {
        DB::transaction(function () use ($submission, $admin): void {
            app(SubmissionWorkflowService::class)->transition($submission, Submission::STATUS_UNDER_REVIEW, $admin, 'ok');

            throw new RuntimeException('simulate failure');
        });
    } catch (RuntimeException $e) {
        // Expected.
    }

    // History creation inside transition was rolled back.
    expect(SubmissionStatusHistory::count())->toBe($initialCount)
        ->and($submission->refresh()->status)->toBe(Submission::STATUS_PENDING);
});

it('prevents history update or delete (append-only)', function () {
    $submission = createSubmissionForHistory();
    $history = SubmissionStatusHistory::where('nimbus_submission_id', $submission->id)->first();

    // Attempt to update should be blocked (booted returning false).
    $result = $history->update(['reason' => 'tampered']);
    expect($result)->toBeFalse();

    expect($history->refresh()->reason)->not->toBe('tampered');

    // Delete should be blocked.
    $deleteResult = $history->delete();
    expect($deleteResult)->toBeFalse();
    expect(SubmissionStatusHistory::where('id', $history->id)->exists())->toBeTrue();
});

it('derives correction count from history', function () {
    $submission = createSubmissionForHistory(Submission::STATUS_PENDING);
    $admin = createAdmin();
    $service = app(SubmissionWorkflowService::class);
    $portalUser = $submission->portalUser;

    $service->transition($submission->refresh(), Submission::STATUS_UNDER_REVIEW, $admin);
    $service->transition($submission->refresh(), Submission::STATUS_NEEDS_CORRECTION, $admin, '1');
    $service->transition($submission->refresh(), Submission::STATUS_UNDER_REVIEW, $portalUser);
    $service->transition($submission->refresh(), Submission::STATUS_NEEDS_CORRECTION, $admin, '2');

    expect(SubmissionWorkflowService::correctionCount($submission->refresh()))->toBe(2);
    expect(SubmissionWorkflowService::lastCorrectionRequestedAt($submission->refresh()))->not->toBeNull();
});
