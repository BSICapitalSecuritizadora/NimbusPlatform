<?php

use App\Filament\Resources\Nimbus\Submissions\Pages\ViewSubmission;
use App\Models\Nimbus\PortalUser;
use App\Models\Nimbus\Submission;
use App\Models\Nimbus\SubmissionStatusHistory;
use App\Models\User;
use App\Services\Nimbus\SubmissionWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

function makeVisibilityPortalUser(string $prefix = 'vis'): PortalUser
{
    static $seq = 5000;
    $seq++;
    $doc = str_pad((string) (20000000000 + $seq), 11, '0', STR_PAD_LEFT);

    return PortalUser::query()->create([
        'full_name' => 'Visibility User',
        'email' => $prefix.$seq.'@test.com',
        'document_number' => $doc,
        'phone_number' => '119'.str_pad((string) $seq, 8, '0', STR_PAD_LEFT),
        'status' => 'ACTIVE',
    ]);
}

function makeVisibilitySubmission(string $status, ?PortalUser $portalUser = null): Submission
{
    $portalUser ??= makeVisibilityPortalUser();
    $submission = Submission::query()->create([
        'nimbus_portal_user_id' => $portalUser->id,
        'reference_code' => 'NMB-VIS-'.strtoupper(Str::ulid()),
        'submission_type' => 'REGISTRATION',
        'title' => 'Visibility Test',
        'status' => $status,
        'submitted_at' => now(),
    ]);
    app(SubmissionWorkflowService::class)->recordCreation($submission, $portalUser);

    return $submission->refresh();
}

// 1
it('request_correction creates USER_VISIBLE note', function () {
    $admin = User::factory()->withTwoFactor()->create(['email' => 'vis-corr1@test.com']);
    $admin->assignRole('admin');
    $sub = makeVisibilitySubmission(Submission::STATUS_UNDER_REVIEW);
    $this->actingAs($admin);
    Livewire::test(ViewSubmission::class, ['record' => $sub->getRouteKey()])
        ->mountAction('alterar_situacao')
        ->setActionData(['status' => Submission::STATUS_UNDER_REVIEW, 'visibility' => 'USER_VISIBLE', 'note' => 'Corrija o balanço por favor.'])
        ->callMountedAction(arguments: ['intent' => 'request_correction'])
        ->assertHasNoActionErrors();

    $note = $sub->fresh()->notes()->latest('id')->first();
    expect($note)->not->toBeNull()
        ->and($note->visibility)->toBe('USER_VISIBLE')
        ->and($note->message)->toBe('Corrija o balanço por favor.');
});

// 2
it('request_correction cannot create ADMIN_ONLY note even when ADMIN_ONLY is submitted', function () {
    $admin = User::factory()->withTwoFactor()->create(['email' => 'vis-corr2@test.com']);
    $admin->assignRole('admin');
    $sub = makeVisibilitySubmission(Submission::STATUS_UNDER_REVIEW);
    $this->actingAs($admin);
    Livewire::test(ViewSubmission::class, ['record' => $sub->getRouteKey()])
        ->mountAction('alterar_situacao')
        ->setActionData(['status' => Submission::STATUS_UNDER_REVIEW, 'visibility' => 'ADMIN_ONLY', 'note' => 'Tentativa admin only'])
        ->callMountedAction(arguments: ['intent' => 'request_correction'])
        ->assertHasNoActionErrors();

    $note = $sub->fresh()->notes()->latest('id')->first();
    expect($note)->not->toBeNull()
        ->and($note->visibility)->toBe('USER_VISIBLE')
        ->and($note->message)->toBe('Tentativa admin only');
});

// 3
it('correction reason is required for request_correction', function () {
    $admin = User::factory()->withTwoFactor()->create(['email' => 'vis-corr3@test.com']);
    $admin->assignRole('admin');
    $sub = makeVisibilitySubmission(Submission::STATUS_UNDER_REVIEW);
    $beforeHistory = SubmissionStatusHistory::where('nimbus_submission_id', $sub->id)->count();
    $beforeNotes = $sub->notes()->count();
    $this->actingAs($admin);
    $test = Livewire::test(ViewSubmission::class, ['record' => $sub->getRouteKey()])
        ->mountAction('alterar_situacao')
        ->setActionData(['status' => Submission::STATUS_UNDER_REVIEW, 'visibility' => 'USER_VISIBLE', 'note' => '   '])
        ->callMountedAction(arguments: ['intent' => 'request_correction']);
    $test->assertHasActionErrors();

    expect($sub->fresh()->status)->toBe(Submission::STATUS_UNDER_REVIEW)
        ->and(SubmissionStatusHistory::where('nimbus_submission_id', $sub->id)->count())->toBe($beforeHistory)
        ->and($sub->fresh()->notes()->count())->toBe($beforeNotes);
});

// 4
it('correction request transitions UNDER_REVIEW to NEEDS_CORRECTION', function () {
    $admin = User::factory()->withTwoFactor()->create(['email' => 'vis-corr4@test.com']);
    $admin->assignRole('admin');
    $sub = makeVisibilitySubmission(Submission::STATUS_UNDER_REVIEW);
    $this->actingAs($admin);
    Livewire::test(ViewSubmission::class, ['record' => $sub->getRouteKey()])
        ->mountAction('alterar_situacao')
        ->setActionData(['status' => Submission::STATUS_UNDER_REVIEW, 'visibility' => 'ADMIN_ONLY', 'note' => 'Corrija doc X'])
        ->callMountedAction(arguments: ['intent' => 'request_correction']);

    expect($sub->fresh()->status)->toBe(Submission::STATUS_NEEDS_CORRECTION);
});

// 5
it('correction request creates exactly one workflow history row', function () {
    $admin = User::factory()->withTwoFactor()->create(['email' => 'vis-corr5@test.com']);
    $admin->assignRole('admin');
    $sub = makeVisibilitySubmission(Submission::STATUS_UNDER_REVIEW);
    $before = SubmissionStatusHistory::where('nimbus_submission_id', $sub->id)->count();
    $this->actingAs($admin);
    Livewire::test(ViewSubmission::class, ['record' => $sub->getRouteKey()])
        ->mountAction('alterar_situacao')
        ->setActionData(['status' => Submission::STATUS_UNDER_REVIEW, 'visibility' => 'USER_VISIBLE', 'note' => 'Hist único'])
        ->callMountedAction(arguments: ['intent' => 'request_correction']);

    $histories = SubmissionStatusHistory::where('nimbus_submission_id', $sub->id)->orderBy('id')->get();
    expect($histories->count())->toBe($before + 1);
    $last = $histories->last();
    expect($last->new_status)->toBe(Submission::STATUS_NEEDS_CORRECTION)
        ->and($last->old_status)->toBe(Submission::STATUS_UNDER_REVIEW);
});

// 6
it('correction request creates expected audit event', function () {
    $admin = User::factory()->withTwoFactor()->create(['email' => 'vis-corr6@test.com']);
    $admin->assignRole('admin');
    $sub = makeVisibilitySubmission(Submission::STATUS_UNDER_REVIEW);
    $this->actingAs($admin);
    Livewire::test(ViewSubmission::class, ['record' => $sub->getRouteKey()])
        ->mountAction('alterar_situacao')
        ->setActionData(['status' => Submission::STATUS_UNDER_REVIEW, 'visibility' => 'ADMIN_ONLY', 'note' => 'Auditoria visível'])
        ->callMountedAction(arguments: ['intent' => 'request_correction']);

    $audit = Activity::where('log_name', 'nimbus')
        ->where('description', 'nimbus.submission.correction_requested')
        ->where('subject_type', Submission::class)
        ->where('subject_id', $sub->id)
        ->latest('id')->first();
    expect($audit)->not->toBeNull()
        ->and($audit->properties['visibility'] ?? null)->toBe('USER_VISIBLE');
});

// 7
it('portal user can see correction instruction via portalVisibleNotes', function () {
    $admin = User::factory()->withTwoFactor()->create(['email' => 'vis-corr7@test.com']);
    $admin->assignRole('admin');
    $sub = makeVisibilitySubmission(Submission::STATUS_UNDER_REVIEW);
    $this->actingAs($admin);
    Livewire::test(ViewSubmission::class, ['record' => $sub->getRouteKey()])
        ->mountAction('alterar_situacao')
        ->setActionData(['status' => Submission::STATUS_UNDER_REVIEW, 'visibility' => 'ADMIN_ONLY', 'note' => 'Instrução visível ao portal'])
        ->callMountedAction(arguments: ['intent' => 'request_correction']);

    $sub = $sub->fresh();
    expect($sub->status)->toBe(Submission::STATUS_NEEDS_CORRECTION)
        ->and($sub->portalVisibleNotes()->count())->toBe(1)
        ->and($sub->portalVisibleNotes()->first()->message)->toBe('Instrução visível ao portal')
        ->and($sub->portalVisibleNotes()->first()->visibility)->toBe('USER_VISIBLE');
});

// 8
it('portal user cannot see internal_comment via portalVisibleNotes', function () {
    $admin = User::factory()->withTwoFactor()->create(['email' => 'vis-corr8@test.com']);
    $admin->assignRole('admin');
    $sub = makeVisibilitySubmission(Submission::STATUS_UNDER_REVIEW);
    $this->actingAs($admin);
    Livewire::test(ViewSubmission::class, ['record' => $sub->getRouteKey()])
        ->mountAction('alterar_situacao')
        ->setActionData(['status' => Submission::STATUS_UNDER_REVIEW, 'visibility' => 'ADMIN_ONLY', 'note' => 'Comentário interno secreto'])
        ->callMountedAction(arguments: ['intent' => 'internal_comment'])
        ->assertHasNoActionErrors();

    expect($sub->fresh()->portalVisibleNotes()->count())->toBe(0)
        ->and($sub->fresh()->notes()->where('visibility', 'ADMIN_ONLY')->count())->toBe(1);
});

// 9
it('internal_comment remains ADMIN_ONLY even when USER_VISIBLE is submitted', function () {
    $admin = User::factory()->withTwoFactor()->create(['email' => 'vis-corr9@test.com']);
    $admin->assignRole('admin');
    $sub = makeVisibilitySubmission(Submission::STATUS_UNDER_REVIEW);
    $this->actingAs($admin);
    Livewire::test(ViewSubmission::class, ['record' => $sub->getRouteKey()])
        ->mountAction('alterar_situacao')
        ->setActionData(['status' => Submission::STATUS_UNDER_REVIEW, 'visibility' => 'USER_VISIBLE', 'note' => 'Tentativa USER_VISIBLE interna'])
        ->callMountedAction(arguments: ['intent' => 'internal_comment']);

    $note = $sub->fresh()->notes()->latest('id')->first();
    expect($note->visibility)->toBe('ADMIN_ONLY')
        ->and($note->message)->toBe('Tentativa USER_VISIBLE interna');
});

// 10
it('internal_comment does not change status', function () {
    $admin = User::factory()->withTwoFactor()->create(['email' => 'vis-corr10@test.com']);
    $admin->assignRole('admin');
    $sub = makeVisibilitySubmission(Submission::STATUS_UNDER_REVIEW);
    $beforeHistory = SubmissionStatusHistory::where('nimbus_submission_id', $sub->id)->count();
    $this->actingAs($admin);
    Livewire::test(ViewSubmission::class, ['record' => $sub->getRouteKey()])
        ->mountAction('alterar_situacao')
        ->setActionData(['status' => Submission::STATUS_COMPLETED, 'visibility' => 'ADMIN_ONLY', 'note' => 'Só um comentário'])
        ->callMountedAction(arguments: ['intent' => 'internal_comment']);

    expect($sub->fresh()->status)->toBe(Submission::STATUS_UNDER_REVIEW)
        ->and(SubmissionStatusHistory::where('nimbus_submission_id', $sub->id)->count())->toBe($beforeHistory);
});

// 11
it('failed correction request does not leave partial note/history/status', function () {
    $admin = User::factory()->withTwoFactor()->create(['email' => 'vis-corr11@test.com']);
    $admin->assignRole('admin');
    $sub = makeVisibilitySubmission(Submission::STATUS_UNDER_REVIEW);
    $beforeHistory = SubmissionStatusHistory::where('nimbus_submission_id', $sub->id)->count();
    $beforeNotes = $sub->notes()->count();
    $beforeAuditCorrection = Activity::where('log_name', 'nimbus')->where('description', 'nimbus.submission.correction_requested')->count();
    $this->actingAs($admin);
    // Empty note => validation error, no transition
    $test = Livewire::test(ViewSubmission::class, ['record' => $sub->getRouteKey()])
        ->mountAction('alterar_situacao')
        ->setActionData(['status' => Submission::STATUS_UNDER_REVIEW, 'visibility' => 'USER_VISIBLE', 'note' => ''])
        ->callMountedAction(arguments: ['intent' => 'request_correction']);
    $test->assertHasActionErrors();

    expect($sub->fresh()->status)->toBe(Submission::STATUS_UNDER_REVIEW)
        ->and($sub->fresh()->notes()->count())->toBe($beforeNotes)
        ->and(SubmissionStatusHistory::where('nimbus_submission_id', $sub->id)->count())->toBe($beforeHistory)
        ->and(Activity::where('log_name', 'nimbus')->where('description', 'nimbus.submission.correction_requested')->count())->toBe($beforeAuditCorrection);
});

// 12
it('ReplyToSubmission from portal still transitions NEEDS_CORRECTION to UNDER_REVIEW and remains unchanged', function () {
    Storage::fake('local');
    config()->set('filesystems.private_disk', 'local');
    $portalUser = makeVisibilityPortalUser('replyvis');
    $sub = makeVisibilitySubmission(Submission::STATUS_NEEDS_CORRECTION, $portalUser);
    $response = $this->actingAs($portalUser, 'nimbus')
        ->post(route('nimbus.submissions.reply', $sub), ['comment' => 'Resposta do portal']);
    $response->assertRedirect();
    expect($sub->fresh()->status)->toBe(Submission::STATUS_UNDER_REVIEW)
        ->and($sub->fresh()->notes()->count())->toBeGreaterThanOrEqual(1);
});

it('correction visibility does not affect approve/reject note visibility passthrough', function () {
    $admin = User::factory()->withTwoFactor()->create(['email' => 'vis-corr12@test.com']);
    $admin->assignRole('admin');
    $subApprove = makeVisibilitySubmission(Submission::STATUS_UNDER_REVIEW);
    $subReject = makeVisibilitySubmission(Submission::STATUS_UNDER_REVIEW);
    $this->actingAs($admin);
    Livewire::test(ViewSubmission::class, ['record' => $subApprove->getRouteKey()])
        ->mountAction('alterar_situacao')
        ->setActionData(['status' => Submission::STATUS_COMPLETED, 'visibility' => 'ADMIN_ONLY', 'note' => 'Aprovado interno'])
        ->callMountedAction(arguments: ['intent' => 'approve']);
    expect($subApprove->fresh()->status)->toBe(Submission::STATUS_COMPLETED)
        ->and($subApprove->fresh()->notes()->latest('id')->first()->visibility)->toBe('ADMIN_ONLY');

    Livewire::test(ViewSubmission::class, ['record' => $subReject->getRouteKey()])
        ->mountAction('alterar_situacao')
        ->setActionData(['status' => Submission::STATUS_REJECTED, 'visibility' => 'USER_VISIBLE', 'note' => 'Rejeitado visível'])
        ->callMountedAction(arguments: ['intent' => 'reject']);
    expect($subReject->fresh()->status)->toBe(Submission::STATUS_REJECTED)
        ->and($subReject->fresh()->notes()->latest('id')->first()->visibility)->toBe('USER_VISIBLE');
});

it('rejects direct PENDING → NEEDS_CORRECTION as invalid current business rule', function () {
    $admin = User::factory()->withTwoFactor()->create(['email' => 'vis-pending-corr@test.com']);
    $admin->assignRole('admin');
    $sub = makeVisibilitySubmission(Submission::STATUS_PENDING);
    $beforeHistory = SubmissionStatusHistory::where('nimbus_submission_id', $sub->id)->count();
    $beforeNotes = $sub->notes()->count();
    $svc = app(SubmissionWorkflowService::class);
    expect(fn () => $svc->transition($sub->refresh(), Submission::STATUS_NEEDS_CORRECTION, $admin, 'Tentativa direta'))
        ->toThrow(ValidationException::class);
    expect($sub->fresh()->status)->toBe(Submission::STATUS_PENDING)
        ->and(SubmissionStatusHistory::where('nimbus_submission_id', $sub->id)->count())->toBe($beforeHistory)
        ->and($sub->fresh()->notes()->count())->toBe($beforeNotes);

    // Filament UI must also reject: Livewire action with PENDING should not transition
    $this->actingAs($admin);
    $test = Livewire::test(ViewSubmission::class, ['record' => $sub->getRouteKey()])
        ->mountAction('alterar_situacao')
        ->setActionData(['status' => Submission::STATUS_PENDING, 'visibility' => 'USER_VISIBLE', 'note' => 'Correcao direta pendente'])
        ->callMountedAction(arguments: ['intent' => 'request_correction']);
    // Workflow throws, Filament surfaces as action error or status unchanged — no partial history
    expect($sub->fresh()->status)->toBe(Submission::STATUS_PENDING)
        ->and(SubmissionStatusHistory::where('nimbus_submission_id', $sub->id)->count())->toBe($beforeHistory);
});

it('still allows PENDING → UNDER_REVIEW and UNDER_REVIEW → NEEDS_CORRECTION chain', function () {
    $admin = User::factory()->withTwoFactor()->create(['email' => 'vis-chain@test.com']);
    $admin->assignRole('admin');
    $sub = makeVisibilitySubmission(Submission::STATUS_PENDING);
    $svc = app(SubmissionWorkflowService::class);
    $svc->transition($sub->refresh(), Submission::STATUS_UNDER_REVIEW, $admin);
    expect($sub->fresh()->status)->toBe(Submission::STATUS_UNDER_REVIEW);
    $this->actingAs($admin);
    Livewire::test(ViewSubmission::class, ['record' => $sub->getRouteKey()])
        ->mountAction('alterar_situacao')
        ->setActionData(['status' => Submission::STATUS_UNDER_REVIEW, 'visibility' => 'USER_VISIBLE', 'note' => 'Correcao apos analise'])
        ->callMountedAction(arguments: ['intent' => 'request_correction'])
        ->assertHasNoActionErrors();
    expect($sub->fresh()->status)->toBe(Submission::STATUS_NEEDS_CORRECTION)
        ->and($sub->fresh()->portalVisibleNotes()->first()->visibility)->toBe('USER_VISIBLE');
});
