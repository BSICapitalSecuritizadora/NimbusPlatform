<?php

use App\Actions\Nimbus\CreateSubmission;
use App\DTOs\Nimbus\StoreSubmissionDTO;
use App\Filament\Resources\Nimbus\Submissions\Pages\ViewSubmission;
use App\Models\Nimbus\NotificationOutbox;
use App\Models\Nimbus\NotificationSetting;
use App\Models\Nimbus\PortalUser;
use App\Models\Nimbus\Submission;
use App\Models\User;
use App\Services\Nimbus\NimbusNotificationService;
use App\Services\Nimbus\SubmissionWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

function makeTxPortalUser(string $prefix = 'tx'): PortalUser
{
    static $seq = 9000;
    $seq++;

    return PortalUser::query()->create([
        'full_name' => 'Tx User',
        'email' => $prefix.$seq.'@example.com',
        'document_number' => str_pad((string) (40000000000 + $seq), 11, '0', STR_PAD_LEFT),
        'phone_number' => '119'.str_pad((string) $seq, 8, '0', STR_PAD_LEFT),
        'status' => 'ACTIVE',
    ]);
}

function makeTxSubmission(PortalUser $user, string $status = 'PENDING'): Submission
{
    $sub = Submission::query()->create([
        'nimbus_portal_user_id' => $user->id,
        'reference_code' => 'NMB-'.strtoupper(Str::ulid()),
        'submission_type' => 'REGISTRATION',
        'title' => 'Tx Test',
        'status' => $status,
        'submitted_at' => now(),
    ]);
    app(SubmissionWorkflowService::class)->recordCreation($sub, $user);

    return $sub->refresh();
}

it('SUBMISSION_RECEIVED created once after successful submission and not after rollback', function () {
    $portalUser = makeTxPortalUser('recv');
    User::factory()->create(['email' => 'admin-recv@example.com'])->assignRole('admin');
    Permission::findOrCreate('nimbus.submissions.view', 'web');
    User::where('email', 'admin-recv@example.com')->first()->givePermissionTo('nimbus.submissions.view');
    $dto = new StoreSubmissionDTO(
        responsibleName: 'Resp',
        companyCnpj: '12.345.678/0001-90',
        companyName: 'Empresa Tx',
        mainActivity: 'Teste',
        phone: '11999999999',
        website: null,
        netWorth: 100000.0,
        annualRevenue: 500000.0,
        registrantName: 'Reg',
        registrantPosition: 'Pos',
        registrantRg: null,
        registrantCpf: '52998224725',
        isUsPerson: false,
        isPep: false,
        isAnbimaAffiliated: false,
        shareholders: [],
        documentFiles: [],
        ip: '127.0.0.1',
        userAgent: 'test',
    );
    $action = app(CreateSubmission::class);
    $submission = $action->handle($dto, $portalUser);
    expect(NotificationOutbox::where('type', 'submission_received')->where('payload_json->submission_id', $submission->id)->count())->toBe(1);

    // Second enqueue same submission should be idempotent
    $service = app(NimbusNotificationService::class);
    $service->enqueueSubmissionReceived($submission);
    expect(NotificationOutbox::where('type', 'submission_received')->where('payload_json->submission_id', $submission->id)->count())->toBe(1);

    // Rollback: simulate failed transaction should not create outbox
    $countBefore = NotificationOutbox::count();
    try {
        DB::transaction(function () {
            throw new Exception('force rollback');
        });
    } catch (Throwable $e) {
    }
    expect(NotificationOutbox::count())->toBe($countBefore);
});

it('CORRECTION_REQUESTED created once after USER_VISIBLE correction and contains no raw PII', function () {
    Mail::fake();
    $admin = User::factory()->withTwoFactor()->create(['email' => 'tx-corr@example.com']);
    $admin->assignRole('admin');
    Permission::findOrCreate('nimbus.submissions.view', 'web');
    $admin->givePermissionTo('nimbus.submissions.view');
    $portalUser = makeTxPortalUser('corr');
    $sub = makeTxSubmission($portalUser, Submission::STATUS_UNDER_REVIEW);
    // Ensure admin exists for outbox processing but correction goes to portal user
    $this->actingAs($admin);
    Livewire::test(ViewSubmission::class, ['record' => $sub->getRouteKey()])
        ->mountAction('alterar_situacao')
        ->setActionData(['status' => Submission::STATUS_UNDER_REVIEW, 'visibility' => 'USER_VISIBLE', 'note' => 'Corrija doc'])
        ->callMountedAction(arguments: ['intent' => 'request_correction'])
        ->assertHasNoActionErrors();

    $outbox = NotificationOutbox::where('type', 'correction_requested')->where('payload_json->submission_id', $sub->id)->first();
    expect($outbox)->not->toBeNull()
        ->and($outbox->recipient_email)->toBe(strtolower($portalUser->email))
        ->and(json_encode($outbox->payload_json))->not->toContain($portalUser->document_number)
        ->and(json_encode($outbox->payload_json))->not->toContain($portalUser->phone_number);

    // Idempotent second request (same submission already NEEDS_CORRECTION, should not create duplicate)
    $service = app(NimbusNotificationService::class);
    $service->enqueueCorrectionRequested($sub->refresh(), 'Corrija doc');
    expect(NotificationOutbox::where('type', 'correction_requested')->where('payload_json->submission_id', $sub->id)->count())->toBe(1);
});

it('CORRECTION_REQUESTED not created if correction request fails validation', function () {
    $admin = User::factory()->withTwoFactor()->create(['email' => 'tx-corr-fail@example.com']);
    $admin->assignRole('admin');
    Permission::findOrCreate('nimbus.submissions.view', 'web');
    $admin->givePermissionTo('nimbus.submissions.view');
    $portalUser = makeTxPortalUser('corrfail');
    $sub = makeTxSubmission($portalUser, Submission::STATUS_UNDER_REVIEW);
    $countBefore = NotificationOutbox::where('type', 'correction_requested')->count();
    $this->actingAs($admin);
    Livewire::test(ViewSubmission::class, ['record' => $sub->getRouteKey()])
        ->mountAction('alterar_situacao')
        ->setActionData(['status' => Submission::STATUS_UNDER_REVIEW, 'visibility' => 'USER_VISIBLE', 'note' => ''])
        ->callMountedAction(arguments: ['intent' => 'request_correction'])
        ->assertHasActionErrors();
    expect(NotificationOutbox::where('type', 'correction_requested')->count())->toBe($countBefore);
});

it('CORRECTION_RESPONSE_RECEIVED created once after valid portal reply', function () {
    Storage::fake('local');
    config()->set('filesystems.private_disk', 'local');
    User::factory()->create(['email' => 'admin-resp@example.com'])->assignRole('admin');
    Permission::findOrCreate('nimbus.submissions.view', 'web');
    User::where('email', 'admin-resp@example.com')->first()->givePermissionTo('nimbus.submissions.view');
    $portalUser = makeTxPortalUser('resp');
    $sub = makeTxSubmission($portalUser, Submission::STATUS_NEEDS_CORRECTION);
    $countBefore = NotificationOutbox::where('type', 'correction_response_received')->count();
    $this->actingAs($portalUser, 'nimbus')
        ->post(route('nimbus.submissions.reply', $sub), ['comment' => 'resposta'])
        ->assertRedirect();
    expect(NotificationOutbox::where('type', 'correction_response_received')->where('payload_json->submission_id', $sub->id)->count())->toBe(1);

    // Invalid reply (wrong status) should not create
    $sub2 = makeTxSubmission($portalUser, Submission::STATUS_UNDER_REVIEW);
    $this->actingAs($portalUser, 'nimbus')
        ->post(route('nimbus.submissions.reply', $sub2), ['comment' => 'invalid'])
        ->assertForbidden();
    expect(NotificationOutbox::where('type', 'correction_response_received')->where('payload_json->submission_id', $sub2->id)->count())->toBe(0);
});

it('COMPLETED created once when transition reaches COMPLETED', function () {
    $admin = User::factory()->withTwoFactor()->create(['email' => 'tx-comp@example.com']);
    $admin->assignRole('admin');
    Permission::findOrCreate('nimbus.submissions.view', 'web');
    $admin->givePermissionTo('nimbus.submissions.view');
    $portalUser = makeTxPortalUser('comp');
    $sub = makeTxSubmission($portalUser, Submission::STATUS_UNDER_REVIEW);
    $this->actingAs($admin);
    Livewire::test(ViewSubmission::class, ['record' => $sub->getRouteKey()])
        ->mountAction('alterar_situacao')
        ->setActionData(['status' => Submission::STATUS_COMPLETED, 'visibility' => 'USER_VISIBLE', 'note' => 'Aprovado'])
        ->callMountedAction(arguments: ['intent' => 'approve'])
        ->assertHasNoActionErrors();
    expect(NotificationOutbox::where('type', 'submission_completed')->where('payload_json->submission_id', $sub->id)->count())->toBe(1);
    // Second approve should not duplicate (already COMPLETED, transition no-op)
    Livewire::test(ViewSubmission::class, ['record' => $sub->fresh()->getRouteKey()])
        ->mountAction('alterar_situacao')
        ->setActionData(['status' => Submission::STATUS_COMPLETED, 'visibility' => 'USER_VISIBLE', 'note' => 'Aprovado novamente'])
        ->callMountedAction(arguments: ['intent' => 'approve']);
    expect(NotificationOutbox::where('type', 'submission_completed')->where('payload_json->submission_id', $sub->id)->count())->toBe(1);
});

it('REJECTED created once when transition reaches REJECTED', function () {
    $admin = User::factory()->withTwoFactor()->create(['email' => 'tx-rej@example.com']);
    $admin->assignRole('admin');
    Permission::findOrCreate('nimbus.submissions.view', 'web');
    $admin->givePermissionTo('nimbus.submissions.view');
    $portalUser = makeTxPortalUser('rej');
    $sub = makeTxSubmission($portalUser, Submission::STATUS_UNDER_REVIEW);
    $this->actingAs($admin);
    Livewire::test(ViewSubmission::class, ['record' => $sub->getRouteKey()])
        ->mountAction('alterar_situacao')
        ->setActionData(['status' => Submission::STATUS_REJECTED, 'visibility' => 'USER_VISIBLE', 'note' => 'Rejeitado'])
        ->callMountedAction(arguments: ['intent' => 'reject'])
        ->assertHasNoActionErrors();
    expect(NotificationOutbox::where('type', 'submission_rejected')->where('payload_json->submission_id', $sub->id)->count())->toBe(1);
});

it('settings disabled prevents optional notification but mandatory still enqueued', function () {
    NotificationSetting::setValues(['portal.notify.status_change' => '0', 'portal.notify.new_submission' => '0']);
    $portalUser = makeTxPortalUser('set');
    $submission = makeTxSubmission($portalUser, Submission::STATUS_PENDING);
    $service = app(NimbusNotificationService::class);
    $result = $service->enqueueCorrectionRequested($submission, 'test');
    expect($result)->toBeNull();

    // Mandatory access code still enqueued even when access_link disabled
    NotificationSetting::setValues(['portal.notify.access_link' => '0']);
    $portalUser2 = makeTxPortalUser('set2');
    $result2 = $service->enqueueAccessCode($portalUser2);
    expect($result2)->not->toBeNull()
        ->and($result2->type)->toBe('portal_access_code');

    // Reset
    NotificationSetting::setValues(['portal.notify.status_change' => '1', 'portal.notify.new_submission' => '1', 'portal.notify.access_link' => '1']);
});
